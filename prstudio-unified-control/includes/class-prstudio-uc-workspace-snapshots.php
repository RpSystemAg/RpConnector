<?php
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }

/**
 * Workspace versionato per il retry dell'Agency Runtime.
 *
 * Riferimento: "StagedWorkspace: A Versioned Workspace for Knowledge-Work
 * Agents" (arXiv, settimana 13-19 agosto 2026).
 *
 * Il retry di Law 5 riparte da uno stato verificato invece che dall'inizio:
 * per ogni correlation ID server-derived vengono conservati snapshot di
 * sessione versionati (lane, timestep, digest dello stato WordPress, payload)
 * con digest sha256 verificato al ripristino. Ripristino idempotente e
 * fail-closed su digest mancante.
 *
 * Backend:
 * - 'sql':  tabella `prstudio_uc_workspace_snapshots` (CREATE IF NOT EXISTS
 *           via $wpdb) quando il runtime WordPress è disponibile;
 * - 'file': archivio NDJSON per installazioni minimali e per i test
 *           deterministici (directory iniettabile).
 *
 * `schema_sql()` espone il DDL canonico per deployment e audit.
 */
final class PRSTUDIO_UC_Workspace_Snapshots {
    public const VERSION = '1.0.0';
    private const TABLE = 'prstudio_uc_workspace_snapshots';
    private const KEEP_DEFAULT = 10;

    private static ?string $test_dir = null;

    public static function set_dir_for_test( string $dir ): void {
        self::$test_dir = rtrim( $dir, '/' );
    }

    /** DDL canonico della tabella snapshot (usato da deploy e audit SQL). */
    public static function schema_sql(): string {
        global $wpdb;
        $table = ( isset( $wpdb ) && is_object( $wpdb ) && property_exists( $wpdb, 'prefix' ) )
            ? $wpdb->prefix . self::TABLE
            : self::TABLE;
        return 'CREATE TABLE IF NOT EXISTS ' . $table . " (
            snapshot_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            correlation_id VARCHAR(160) NOT NULL,
            version INT UNSIGNED NOT NULL DEFAULT 1,
            lane_handle VARCHAR(160) NOT NULL DEFAULT '',
            timestep_gmt VARCHAR(32) NOT NULL DEFAULT '',
            state_digest CHAR(64) NOT NULL DEFAULT '',
            wp_state_digest CHAR(64) NOT NULL DEFAULT '',
            payload LONGTEXT NOT NULL,
            created_gmt VARCHAR(32) NOT NULL DEFAULT '',
            PRIMARY KEY  (snapshot_id),
            UNIQUE KEY uq_corr_version (correlation_id(160), version)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
    }

    private static function backend(): string {
        global $wpdb;
        if ( isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'query' ) ) {
            $table = $wpdb->prefix . self::TABLE;
            $exists = null !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
            if ( ! $exists ) {
                // Tentativo di creazione lazy: se fallisce, si degrada al
                // backend file senza perdere la missione (Law 5).
                try {
                    $wpdb->query( self::schema_sql() );
                    $exists = null !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
                } catch ( Throwable $ignored ) { $exists = false; }
            }
            if ( $exists ) { return 'sql'; }
        }
        return 'file';
    }

    private static function file_path(): string {
        if ( null !== self::$test_dir ) {
            if ( ! is_dir( self::$test_dir ) ) { @mkdir( self::$test_dir, 0750, true ); }
            return self::$test_dir . '/workspace-snapshots.ndjson';
        }
        $base = class_exists( 'PRSTUDIO_UC_Memory' ) && method_exists( 'PRSTUDIO_UC_Memory', 'site_dir' )
            ? PRSTUDIO_UC_Memory::site_dir()
            : ( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : sys_get_temp_dir() );
        $dir = rtrim( (string) $base, '/' ) . '/workspace-snapshots';
        if ( ! is_dir( $dir ) ) {
            function_exists( 'wp_mkdir_p' ) ? wp_mkdir_p( $dir ) : @mkdir( $dir, 0750, true );
        }
        return $dir . '/snapshots.ndjson';
    }

    /**
     * Salva uno snapshot di sessione versionato.
     *
     * @param array<string,mixed> $state
     * @return array{ok:bool,snapshot_id:string,version:int,digest:string,backend:string,replaced:bool}
     */
    public static function save( string $correlation_id, array $state, array $options = array() ): array {
        $correlation_id = substr( sanitize_text_field( $correlation_id ), 0, 160 );
        if ( '' === $correlation_id ) {
            return array( 'ok' => false, 'snapshot_id' => '', 'version' => 0, 'digest' => '', 'backend' => self::backend(), 'replaced' => false );
        }
        $existing = self::list( $correlation_id );
        $explicit_version = (int) ( $options['version'] ?? 0 );
        if ( $explicit_version > 0 ) {
            $version = $explicit_version;
        } else {
            $version = 1;
            foreach ( $existing as $row ) {
                $version = max( $version, (int) ( $row['version'] ?? 0 ) + 1 );
            }
        }
        $canonical = wp_json_encode( $state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        $digest = hash( 'sha256', (string) $canonical );
        $row = array(
            'correlation_id' => $correlation_id,
            'version' => $version,
            'lane_handle' => substr( sanitize_text_field( (string) ( $options['lane_handle'] ?? '' ) ), 0, 160 ),
            'timestep_gmt' => substr( (string) ( $options['timestep_gmt'] ?? gmdate( 'c' ) ), 0, 32 ),
            'state_digest' => $digest,
            'wp_state_digest' => substr( sanitize_text_field( (string) ( $options['wp_state_digest'] ?? '' ) ), 0, 64 ),
            'payload' => (string) $canonical,
            'created_gmt' => gmdate( 'c' ),
        );
        $replaced = false;
        $id = '';
        if ( 'sql' === self::backend() ) {
            global $wpdb;
            $table = $wpdb->prefix . self::TABLE;
            $existing_row = $wpdb->get_row( $wpdb->prepare( 'SELECT snapshot_id FROM ' . $table . ' WHERE correlation_id = %s AND version = %d', $correlation_id, $version ), ARRAY_A );
            if ( is_array( $existing_row ) ) {
                $wpdb->update(
                    $table,
                    array( 'lane_handle' => $row['lane_handle'], 'timestep_gmt' => $row['timestep_gmt'], 'state_digest' => $row['state_digest'], 'wp_state_digest' => $row['wp_state_digest'], 'payload' => $row['payload'], 'created_gmt' => $row['created_gmt'] ),
                    array( 'snapshot_id' => (int) $existing_row['snapshot_id'] )
                );
                $id = (string) $existing_row['snapshot_id'];
                $replaced = true;
            } else {
                $wpdb->insert( $table, $row );
                $id = (string) $wpdb->insert_id;
            }
        } else {
            $rows = self::file_rows();
            $kept = array();
            foreach ( $rows as $r ) {
                if ( (string) ( $r['correlation_id'] ?? '' ) === $correlation_id && (int) ( $r['version'] ?? 0 ) === $version ) {
                    $replaced = true;
                    continue;
                }
                $kept[] = $r;
            }
            $kept[] = array( 'snapshot_id' => 'fs_' . substr( hash( 'sha256', $correlation_id . '|' . $version . '|' . $digest ), 0, 20 ), 'version' => $version ) + $row;
            self::file_write( $kept );
            $id = (string) $kept[ count( $kept ) - 1 ]['snapshot_id'];
            self::prune_file( $correlation_id, (int) ( $options['keep'] ?? self::KEEP_DEFAULT ) );
        }
        return array( 'ok' => true, 'snapshot_id' => $id, 'version' => $version, 'digest' => $digest, 'backend' => self::backend(), 'replaced' => $replaced );
    }

    /**
     * Ripristina uno snapshot (idempotente, fail-closed su digest).
     *
     * @return array{ok:bool,state:?array<string,mixed>,version:int,digest_verified:bool,backend:string,reason:string}
     */
    public static function restore( string $correlation_id, ?int $version = null ): array {
        $correlation_id = substr( sanitize_text_field( $correlation_id ), 0, 160 );
        $backend = self::backend();
        $rows = 'sql' === $backend ? self::sql_rows( $correlation_id ) : self::file_rows_for( $correlation_id );
        if ( ! $rows ) {
            return array( 'ok' => false, 'state' => null, 'version' => 0, 'digest_verified' => false, 'backend' => $backend, 'reason' => 'no_snapshot_for_correlation' );
        }
        if ( null === $version ) {
            $row = $rows[ count( $rows ) - 1 ];
        } else {
            $row = null;
            foreach ( $rows as $candidate ) {
                if ( (int) ( $candidate['version'] ?? 0 ) === $version ) { $row = $candidate; break; }
            }
            if ( null === $row ) {
                return array( 'ok' => false, 'state' => null, 'version' => $version, 'digest_verified' => false, 'backend' => $backend, 'reason' => 'version_not_found' );
            }
        }
        $state = json_decode( (string) ( $row['payload'] ?? 'null' ), true );
        if ( ! is_array( $state ) ) {
            return array( 'ok' => false, 'state' => null, 'version' => (int) ( $row['version'] ?? 0 ), 'digest_verified' => false, 'backend' => $backend, 'reason' => 'payload_corrupt' );
        }
        $computed = hash( 'sha256', (string) wp_json_encode( $state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
        $expected = (string) ( $row['state_digest'] ?? '' );
        if ( '' !== $expected && ! hash_equals( $expected, $computed ) ) {
            return array( 'ok' => false, 'state' => null, 'version' => (int) ( $row['version'] ?? 0 ), 'digest_verified' => false, 'backend' => $backend, 'reason' => 'digest_mismatch_fail_closed' );
        }
        return array(
            'ok' => true,
            'state' => $state,
            'version' => (int) ( $row['version'] ?? 0 ),
            'digest_verified' => true,
            'backend' => $backend,
            'reason' => 'restored',
        );
    }

    /** Elenco versioni per correlation ID (metadata, senza payload). */
    public static function list( string $correlation_id ): array {
        $correlation_id = substr( sanitize_text_field( $correlation_id ), 0, 160 );
        $backend = self::backend();
        $rows = 'sql' === $backend ? self::sql_rows( $correlation_id ) : self::file_rows_for( $correlation_id );
        $out = array();
        foreach ( $rows as $row ) {
            $out[] = array(
                'version' => (int) ( $row['version'] ?? 0 ),
                'snapshot_id' => (string) ( $row['snapshot_id'] ?? '' ),
                'lane_handle' => (string) ( $row['lane_handle'] ?? '' ),
                'timestep_gmt' => (string) ( $row['timestep_gmt'] ?? '' ),
                'state_digest' => (string) ( $row['state_digest'] ?? '' ),
                'created_gmt' => (string) ( $row['created_gmt'] ?? '' ),
            );
        }
        usort( $out, static fn( $a, $b ): int => (int) ( $a['version'] ?? 0 ) <=> (int) ( $b['version'] ?? 0 ) );
        return $out;
    }

    /** Potatura: conserva gli ultimi `keep` snapshot per correlation ID. */
    public static function prune( string $correlation_id, int $keep = self::KEEP_DEFAULT ): int {
        $correlation_id = substr( sanitize_text_field( $correlation_id ), 0, 160 );
        $keep = max( 1, $keep );
        if ( 'sql' === self::backend() ) {
            global $wpdb;
            $table = $wpdb->prefix . self::TABLE;
            $rows = self::sql_rows( $correlation_id );
            $removed = 0;
            foreach ( array_slice( $rows, 0, max( 0, count( $rows ) - $keep ) ) as $row ) {
                $wpdb->delete( $table, array( 'snapshot_id' => (int) ( $row['snapshot_id'] ?? 0 ) ) );
                $removed++;
            }
            return $removed;
        }
        return self::prune_file( $correlation_id, $keep );
    }

    /** @return array<int,array<string,mixed>> */
    private static function sql_rows( string $correlation_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $table . ' WHERE correlation_id = %s ORDER BY version ASC', $correlation_id ), ARRAY_A );
        return is_array( $rows ) ? $rows : array();
    }

    /** @return array<int,array<string,mixed>> */
    private static function file_rows(): array {
        $path = self::file_path();
        if ( ! is_readable( $path ) ) { return array(); }
        $rows = array();
        foreach ( @file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) ?: array() as $line ) {
            $row = json_decode( (string) $line, true );
            if ( is_array( $row ) ) { $rows[] = $row; }
        }
        return $rows;
    }

    /** @return array<int,array<string,mixed>> */
    private static function file_rows_for( string $correlation_id ): array {
        $out = array();
        foreach ( self::file_rows() as $row ) {
            if ( (string) ( $row['correlation_id'] ?? '' ) === $correlation_id ) { $out[] = $row; }
        }
        usort( $out, static fn( $a, $b ): int => (int) ( $a['version'] ?? 0 ) <=> (int) ( $b['version'] ?? 0 ) );
        return $out;
    }

    /** @param array<int,array<string,mixed>> $rows */
    private static function file_write( array $rows ): void {
        $lines = '';
        foreach ( $rows as $row ) {
            $lines .= (string) json_encode( $row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
        }
        @file_put_contents( self::file_path(), $lines, LOCK_EX );
    }

    private static function prune_file( string $correlation_id, int $keep ): int {
        $rows = self::file_rows();
        $kept = array();
        $removed = 0;
        $versions = array();
        foreach ( $rows as $row ) {
            if ( (string) ( $row['correlation_id'] ?? '' ) === $correlation_id ) { $versions[] = (int) ( $row['version'] ?? 0 ); }
        }
        sort( $versions );
        $allowed = array_slice( $versions, -$keep );
        foreach ( $rows as $row ) {
            if ( (string) ( $row['correlation_id'] ?? '' ) === $correlation_id && ! in_array( (int) ( $row['version'] ?? 0 ), $allowed, true ) ) {
                $removed++;
                continue;
            }
            $kept[] = $row;
        }
        if ( $removed > 0 ) { self::file_write( $kept ); }
        return $removed;
    }
}
