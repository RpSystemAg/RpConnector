<?php
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }

/**
 * Audit trail completo, append-only e tamper-evident.
 *
 * Riferimento: "D²ACCI: A Dual-Loop Diagnostic Protocol for
 * Evidence-Preserving Agent Memory" (arXiv, settimana 13-19 agosto 2026) —
 * aspetto audit/compliance.
 *
 * Ogni azione eseguita dal sistema viene registrata con attore, azione,
 * correlation ID, lane, intento, stato del risultato e riferimento evidenza.
 * La catena di hash (ogni record include lo sha256 del record precedente)
 * rende la manomissione rilevabile: `verify()` ripercorre il file e segnala
 * il primo record rotto.
 *
 * Storage: NDJSON con rotazione a cap dimensionale; directory iniettabile
 * per i test. Nessuna dipendenza WordPress obbligatoria.
 */
final class PRSTUDIO_UC_Audit_Trail {
    public const VERSION = '1.0.0';
    private const MAX_FILE_BYTES = 16777216;

    private static ?string $test_dir = null;

    public static function set_dir_for_test( string $dir ): void {
        self::$test_dir = rtrim( $dir, '/' );
    }

    public static function dir(): string {
        if ( null !== self::$test_dir ) {
            if ( ! is_dir( self::$test_dir ) ) { @mkdir( self::$test_dir, 0750, true ); }
            return self::$test_dir;
        }
        $base = class_exists( 'PRSTUDIO_UC_Memory' ) && method_exists( 'PRSTUDIO_UC_Memory', 'site_dir' )
            ? PRSTUDIO_UC_Memory::site_dir()
            : ( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : sys_get_temp_dir() );
        $dir = rtrim( (string) $base, '/' ) . '/audit-trail';
        if ( ! is_dir( $dir ) ) {
            function_exists( 'wp_mkdir_p' ) ? wp_mkdir_p( $dir ) : @mkdir( $dir, 0750, true );
        }
        return $dir;
    }

    private static function path(): string {
        return self::dir() . '/audit.ndjson';
    }

    /**
     * Registra un evento di audit.
     *
     * @param array{actor?:string,action?:string,correlation_id?:string,lane_handle?:string,intent?:string,result_state?:string,evidence_ref?:string,details?:array<string,mixed>} $event
     * @return array{ok:bool,seq:int,sha256:string}
     */
    public static function record( array $event ): array {
        $previous = self::last_hash();
        $payload = array(
            'gmt' => gmdate( 'c' ),
            'actor' => substr( sanitize_text_field( (string) ( $event['actor'] ?? 'system' ) ), 0, 120 ),
            'action' => substr( sanitize_key( (string) ( $event['action'] ?? '' ) ), 0, 120 ),
            'correlation_id' => substr( sanitize_text_field( (string) ( $event['correlation_id'] ?? '' ) ), 0, 160 ),
            'lane_handle' => substr( sanitize_text_field( (string) ( $event['lane_handle'] ?? '' ) ), 0, 160 ),
            'intent' => substr( (string) ( $event['intent'] ?? '' ), 0, 400 ),
            'result_state' => substr( sanitize_key( (string) ( $event['result_state'] ?? '' ) ), 0, 60 ),
            'evidence_ref' => substr( sanitize_text_field( (string) ( $event['evidence_ref'] ?? '' ) ), 0, 400 ),
            'details' => is_array( $event['details'] ?? null ) ? $event['details'] : array(),
            'prev_sha256' => $previous,
        );
        $payload['sha256'] = hash( 'sha256', $payload['prev_sha256'] . '|' . wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
        $seq = self::append( $payload );
        return array( 'ok' => true, 'seq' => $seq, 'sha256' => (string) $payload['sha256'] );
    }

    /**
     * Verifica l'integrità dell'intera catena.
     *
     * @return array{ok:bool,records:int,broken_at:?int,broken_seq:?string}
     */
    public static function verify(): array {
        $records = self::read_all();
        $previous = '';
        foreach ( $records as $index => $record ) {
            $expected = (string) ( $record['prev_sha256'] ?? '' );
            if ( $expected !== $previous ) {
                return array( 'ok' => false, 'records' => count( $records ), 'broken_at' => $index, 'broken_seq' => (string) ( $record['sha256'] ?? '' ) );
            }
            $canonical = $record;
            unset( $canonical['sha256'] );
            $computed = hash(
                'sha256',
                $previous . '|' . wp_json_encode( $canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
            );
            if ( ! hash_equals( $computed, (string) ( $record['sha256'] ?? '' ) ) ) {
                return array( 'ok' => false, 'records' => count( $records ), 'broken_at' => $index, 'broken_seq' => (string) ( $record['sha256'] ?? '' ) );
            }
            $previous = (string) $record['sha256'];
        }
        return array( 'ok' => true, 'records' => count( $records ), 'broken_at' => null, 'broken_seq' => null );
    }

    /**
     * Query con filtro per attore/azione/correlation id.
     *
     * @return array{count:int,records:array<int,array<string,mixed>>}
     */
    public static function query( array $filter = array(), int $limit = 100 ): array {
        $limit = max( 1, min( 500, $limit ) );
        $actor = (string) ( $filter['actor'] ?? '' );
        $action = (string) ( $filter['action'] ?? '' );
        $correlation = (string) ( $filter['correlation_id'] ?? '' );
        $rows = array();
        foreach ( self::read_all() as $record ) {
            if ( '' !== $actor && (string) ( $record['actor'] ?? '' ) !== $actor ) { continue; }
            if ( '' !== $action && (string) ( $record['action'] ?? '' ) !== $action ) { continue; }
            if ( '' !== $correlation && (string) ( $record['correlation_id'] ?? '' ) !== $correlation ) { continue; }
            $rows[] = $record;
            if ( count( $rows ) >= $limit ) { break; }
        }
        return array( 'count' => count( $rows ), 'records' => $rows );
    }

    /** @return array{count:int,first_gmt:string,last_gmt:string,file:string} */
    public static function stats(): array {
        $records = self::read_all();
        return array(
            'count' => count( $records ),
            'first_gmt' => count( $records ) > 0 ? (string) ( $records[0]['gmt'] ?? '' ) : '',
            'last_gmt' => count( $records ) > 0 ? (string) ( $records[ count( $records ) - 1 ]['gmt'] ?? '' ) : '',
            'file' => self::path(),
        );
    }

    private static function last_hash(): string {
        $records = self::read_all();
        return count( $records ) > 0 ? (string) ( $records[ count( $records ) - 1 ]['sha256'] ?? '' ) : '';
    }

    private static function append( array $payload ): int {
        $path = self::path();
        $line = (string) json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        if ( '' === $line ) { return 0; }
        $lock = @fopen( self::dir() . '/.audit-trail.lock', 'c+' );
        if ( ! is_resource( $lock ) || ! @flock( $lock, LOCK_EX ) ) {
            if ( is_resource( $lock ) ) { @fclose( $lock ); }
            @file_put_contents( $path, $line . "\n", FILE_APPEND | LOCK_EX );
            return self::count_lines( $path );
        }
        try {
            clearstatcache( true, $path );
            if ( is_file( $path ) && filesize( $path ) > self::MAX_FILE_BYTES ) {
                @unlink( $path . '.previous' );
                @rename( $path, $path . '.previous' );
            }
            @file_put_contents( $path, $line . "\n", FILE_APPEND | LOCK_EX );
            return self::count_lines( $path );
        } finally {
            @flock( $lock, LOCK_UN );
            @fclose( $lock );
        }
    }

    /** @return array<int,array<string,mixed>> */
    private static function read_all(): array {
        $path = self::path();
        if ( ! is_readable( $path ) ) { return array(); }
        $rows = array();
        foreach ( @file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) ?: array() as $line ) {
            $row = json_decode( (string) $line, true );
            if ( is_array( $row ) ) { $rows[] = $row; }
        }
        return $rows;
    }

    private static function count_lines( string $path ): int {
        if ( ! is_readable( $path ) ) { return 0; }
        $count = 0;
        foreach ( @file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) ?: array() as $unused ) { $count++; }
        return $count;
    }
}
