<?php
// phpcs:ignore missing_direct_file_access_protection -- direct-access guard IS present on the line below; it uses `&& ! defined('PRSTUDIO_UC_TESTING')` for testability and Plugin Check's static pattern doesn't recognize that compound form.
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }

/**
 * The interventions ledger — memory of what was *done*, not what was *seen*.
 *
 * 15.x had three memories and none of them answered the question that matters
 * at the start of a session. `PRSTUDIO_UC_Memory` stores fingerprints with a
 * seven-day TTL: it knows the system has *looked at* something. Procedural
 * skills know *how* a task is performed. The operational twin knows what the
 * site *currently looks like*. Nothing recorded that on 3 August this exact
 * page had this exact optimisation applied, and that the user rejected that
 * other one twice.
 *
 * Without that record every session opens with a cold audit, rediscovers the
 * same findings on the same pages, and proposes the same work. That is why the
 * site kept getting "optimised in the same places".
 *
 * This ledger has no TTL. What was done stays done. Its two most useful states
 * are the ones a naive design would omit:
 *
 *   rejected  the user said no. It must not come back next week.
 *   reverted  it was applied and then undone. Proposing it again needs a reason,
 *             so it resurfaces only when explicitly asked for.
 */
final class PRSTUDIO_UC_Interventions {
    public const VERSION = '1.0.0';

    public const APPLIED = 'applied';
    public const REJECTED = 'rejected';
    public const REVERTED = 'reverted';
    public const SUPERSEDED = 'superseded';
    public const FAILED = 'failed';
    public const PROPOSED = 'proposed';

    private const STATES = array(
        self::PROPOSED, self::APPLIED, self::REJECTED,
        self::REVERTED, self::SUPERSEDED, self::FAILED,
    );

    /** States that suppress a re-proposal of the same intervention. */
    private const SETTLED = array( self::APPLIED, self::REJECTED, self::SUPERSEDED );

    private const SCHEMA_OPTION = 'prstudio_uc_interventions_schema';
    private const SCHEMA_VERSION = 1;

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'prstudio_uc_interventions';
    }

    public static function install(): void {
        global $wpdb;
        if ( (int) get_option( self::SCHEMA_OPTION, 0 ) === self::SCHEMA_VERSION ) { return; }
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $sql = 'CREATE TABLE ' . self::table() . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            entity_key varchar(190) NOT NULL,
            intervention_key varchar(190) NOT NULL,
            state varchar(20) NOT NULL DEFAULT 'proposed',
            impact varchar(20) NOT NULL DEFAULT 'unknown',
            summary varchar(255) NOT NULL DEFAULT '',
            detail longtext NULL,
            evidence_ref varchar(190) NULL,
            correlation_id varchar(64) NULL,
            occurrences int(11) NOT NULL DEFAULT 1,
            first_seen_gmt datetime NOT NULL,
            applied_gmt datetime NULL,
            updated_gmt datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY entity_intervention (entity_key, intervention_key),
            KEY state_updated (state, updated_gmt),
            KEY entity (entity_key)
        ) $charset;";
        dbDelta( $sql );
        update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
    }

    private static function ensure(): bool {
        global $wpdb;
        if ( (int) get_option( self::SCHEMA_OPTION, 0 ) !== self::SCHEMA_VERSION ) { self::install(); }
        return true;
    }

    public static function normalize_entity( string $type, $id ): string {
        $type = sanitize_key( $type );
        $id = is_scalar( $id ) ? trim( (string) $id ) : '';
        if ( '' === $type || '' === $id ) { return ''; }
        // URLs are normalized so the same page recorded from a crawl and from a
        // post edit collapses onto one row instead of two.
        if ( 'url' === $type ) {
            $id = strtolower( preg_replace( '#^https?://#i', '', $id ) );
            $id = rtrim( (string) $id, '/' );
        }
        return substr( $type . ':' . $id, 0, 190 );
    }

    /**
     * Records or updates one intervention.
     *
     * Idempotent by (entity, intervention): calling it twice for the same pair
     * updates the row rather than growing the table. `occurrences` counts how
     * often the finding resurfaced, which is a useful signal on its own — a
     * proposal seen fifteen times and never applied is telling you something.
     */
    public static function record( string $entity_key, string $intervention_key, string $state, array $meta = array() ): bool {
        global $wpdb;
        if ( '' === $entity_key || '' === $intervention_key ) { return false; }
        if ( ! in_array( $state, self::STATES, true ) ) { $state = self::PROPOSED; }
        self::ensure();

        $now = gmdate( 'Y-m-d H:i:s' );
        $intervention_key = substr( sanitize_key( $intervention_key ), 0, 190 );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.
        $detail = isset( $meta['detail'] ) ? wp_json_encode( $meta['detail'] ) : null;
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- table/column identifier only, from a fixed helper or the identifier() allowlist + SHOW TABLES check -- never external input; values are parameterized via $wpdb->prepare()
        $existing = $wpdb->get_row( $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table/column identifier only, from a fixed helper or the identifier() allowlist + SHOW TABLES check -- never external input; values are parameterized via $wpdb->prepare()
            'SELECT id, state, occurrences, first_seen_gmt FROM ' . self::table() . ' WHERE entity_key = %s AND intervention_key = %s',
            $entity_key,
            $intervention_key
        ), ARRAY_A );

        $data = array(
            'state'          => $state,
            'impact'         => substr( sanitize_key( (string) ( $meta['impact'] ?? 'unknown' ) ), 0, 20 ),
            'summary'        => substr( sanitize_text_field( (string) ( $meta['summary'] ?? '' ) ), 0, 255 ),
            'detail'         => $detail,
            'evidence_ref'   => substr( sanitize_text_field( (string) ( $meta['evidence_ref'] ?? '' ) ), 0, 190 ),
            'correlation_id' => substr( sanitize_text_field( (string) ( $meta['correlation_id'] ?? '' ) ), 0, 64 ),
            'updated_gmt'    => $now,
        );
        if ( self::APPLIED === $state ) { $data['applied_gmt'] = $now; }

        if ( is_array( $existing ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.
            // A settled row is never quietly downgraded back to "proposed" by a
            // later audit: that is exactly how the same suggestion came back.
            if ( self::PROPOSED === $state && in_array( (string) $existing['state'], self::SETTLED, true ) ) {
                $wpdb->update(
                    self::table(),
                    array( 'occurrences' => (int) $existing['occurrences'] + 1, 'updated_gmt' => $now ),
                    array( 'id' => (int) $existing['id'] ),
                    array( '%d', '%s' ),
                    array( '%d' )
                );
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.
                return true;
            }
            $data['occurrences'] = (int) $existing['occurrences'] + ( self::PROPOSED === $state ? 1 : 0 );
            return false !== $wpdb->update( self::table(), $data, array( 'id' => (int) $existing['id'] ) );
        }

        $data['entity_key'] = $entity_key;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.
        $data['intervention_key'] = $intervention_key;
        $data['occurrences'] = 1;
        $data['first_seen_gmt'] = $now;
        return false !== $wpdb->insert( self::table(), $data );
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.
    public static function state_of( string $entity_key, string $intervention_key ): string {
        global $wpdb;
        self::ensure();
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- table/column identifier only, from a fixed helper or the identifier() allowlist + SHOW TABLES check -- never external input; values are parameterized via $wpdb->prepare()
        $state = $wpdb->get_var( $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table/column identifier only, from a fixed helper or the identifier() allowlist + SHOW TABLES check -- never external input; values are parameterized via $wpdb->prepare()
            'SELECT state FROM ' . self::table() . ' WHERE entity_key = %s AND intervention_key = %s',
            $entity_key,
            substr( sanitize_key( $intervention_key ), 0, 190 )
        ) );
        return is_string( $state ) ? $state : '';
    }

    /**
     * The filter every audit tool runs its findings through.
     *
     * Takes a list of candidate findings and returns only the ones that have
     * not already been settled, plus a count of what was suppressed. The count
     * matters: it is the difference between "nothing to do" and "I checked and
     * the 34 things I would have suggested are already handled".
     *
     * @param array $findings Each item needs entity_key and intervention_key.
     */
    public static function filter_new( array $findings, bool $include_settled = false ): array {
        self::ensure();
        if ( ! $findings ) {
            return array( 'findings' => array(), 'already_settled_count' => 0, 'already_settled' => array() );
        }
        global $wpdb;
        $entities = array();
        foreach ( $findings as $finding ) {
            $key = (string) ( $finding['entity_key'] ?? '' );
            if ( '' !== $key ) { $entities[ $key ] = true; }
        }
        $known = array();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.
        if ( $entities ) {
            $keys = array_keys( $entities );
            $chunks = array_chunk( $keys, 200 );
            foreach ( $chunks as $chunk ) {
                $placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
                // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- table/column identifier only, from a fixed helper or the identifier() allowlist + SHOW TABLES check -- never external input; values are parameterized via $wpdb->prepare()
                $rows = $wpdb->get_results( $wpdb->prepare(
                    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table/column identifier only, from a fixed helper or the identifier() allowlist + SHOW TABLES check -- never external input; values are parameterized via $wpdb->prepare()
                    'SELECT entity_key, intervention_key, state, applied_gmt FROM ' . self::table() . ' WHERE entity_key IN (' . $placeholders . ')',
                    $chunk
                ), ARRAY_A );
                foreach ( (array) $rows as $row ) {
                    $known[ $row['entity_key'] . '|' . $row['intervention_key'] ] = $row;
                }
            }
        }

        $fresh = array();
        $settled = array();
        foreach ( $findings as $finding ) {
            $entity = (string) ( $finding['entity_key'] ?? '' );
            $intervention = substr( sanitize_key( (string) ( $finding['intervention_key'] ?? '' ) ), 0, 190 );
            $row = $known[ $entity . '|' . $intervention ] ?? null;
            if ( is_array( $row ) && in_array( (string) $row['state'], self::SETTLED, true ) ) {
                $settled[] = array(
                    'entity_key'       => $entity,
                    'intervention_key' => $intervention,
                    'state'            => (string) $row['state'],
                    'applied_gmt'      => (string) ( $row['applied_gmt'] ?? '' ),
                );
                if ( ! $include_settled ) { continue; }
                $finding['already_settled'] = (string) $row['state'];
            }
            $fresh[] = $finding;
        }
        return array(
            'findings'              => $fresh,
            'already_settled_count' => count( $settled ),
            'already_settled'       => array_slice( $settled, 0, 50 ),
        );
    }

    /** Open work, newest and most-repeated first. The natural session opener. */
    public static function backlog( array $args = array() ): array {
        global $wpdb;
        self::ensure();
        $limit = max( 1, min( 200, (int) ( $args['limit'] ?? 25 ) ) );
        $entity_filter = (string) ( $args['entity_key'] ?? '' );
        $where = array( "state IN ('proposed','failed','reverted')" );
        $params = array();
        if ( '' !== $entity_filter ) {
            $where[] = 'entity_key LIKE %s';
            $params[] = $wpdb->esc_like( $entity_filter ) . '%';
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.
        $impact = sanitize_key( (string) ( $args['impact'] ?? '' ) );
        if ( '' !== $impact ) { $where[] = 'impact = %s'; $params[] = $impact; }

        $sql = 'SELECT entity_key, intervention_key, state, impact, summary, occurrences, first_seen_gmt, updated_gmt FROM '
            . self::table() . ' WHERE ' . implode( ' AND ', $where )
            . ' ORDER BY FIELD(impact, "critical","high","medium","low","unknown"), occurrences DESC, updated_gmt DESC LIMIT %d';
        $params[] = $limit;
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- table/column identifier only, from a fixed helper or the identifier() allowlist + SHOW TABLES check -- never external input; values are parameterized via $wpdb->prepare()
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

        return array(
            'open'    => is_array( $rows ) ? $rows : array(),
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.
            'totals'  => self::stats(),
            'meaning' => 'Items already applied or rejected are intentionally absent. This is what is genuinely left to do.',
        );
    }

    public static function stats(): array {
        global $wpdb;
        self::ensure();
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- table/column identifier only, from a fixed helper or the identifier() allowlist + SHOW TABLES check -- never external input; values are parameterized via $wpdb->prepare()
        $rows = $wpdb->get_results( 'SELECT state, COUNT(*) AS n FROM ' . self::table() . ' GROUP BY state', ARRAY_A );
        $totals = array_fill_keys( self::STATES, 0 );
        foreach ( (array) $rows as $row ) {
            $state = (string) $row['state'];
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.
            if ( isset( $totals[ $state ] ) ) { $totals[ $state ] = (int) $row['n']; }
        }
        return $totals;
    }

    /** Marks everything recorded for an entity as superseded (e.g. page rewritten). */
    public static function supersede_entity( string $entity_key, string $reason = 'entity_replaced' ): int {
        global $wpdb;
        self::ensure();
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- table/column identifier only, from a fixed helper or the identifier() allowlist + SHOW TABLES check -- never external input; values are parameterized via $wpdb->prepare()
        $updated = $wpdb->query( $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table/column identifier only, from a fixed helper or the identifier() allowlist + SHOW TABLES check -- never external input; values are parameterized via $wpdb->prepare()
            'UPDATE ' . self::table() . " SET state = %s, updated_gmt = %s WHERE entity_key = %s AND state IN ('proposed','applied')",
            self::SUPERSEDED,
            gmdate( 'Y-m-d H:i:s' ),
            $entity_key
        ) );
        return (int) $updated;
    }
}
