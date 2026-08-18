<?php
// phpcs:ignore missing_direct_file_access_protection -- direct-access guard IS present on the line below; it uses `&& ! defined('PRSTUDIO_UC_TESTING')` for testability and Plugin Check's static pattern doesn't recognize that compound form.
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }

/**
 * SEO Autopilot campaign memory -- the server-side answer to "Prossimo".
 *
 * A brand-new ChatGPT chat has zero prior context. Before this class existed,
 * "continue the SEO work" could only mean "re-derive progress from the
 * conversation", which repeats completed products and loses claimed-but-
 * unfinished ones on every crash or new chat. This table is the single
 * source of truth: which of the ~800+ WooCommerce products in the active
 * campaign are PENDING, CLAIMED, APPLIED_UNVERIFIED, COMPLETED, BLOCKED or
 * REVIEW_REQUIRED, and which entity a caller gets next -- independent of
 * prompt, chat history or which session asks.
 *
 * Storage follows the pattern already proven by PRSTUDIO_UC_Store for tasks
 * and jobs: a real table, a transactional `SELECT ... FOR UPDATE` + `UPDATE`
 * claim, and a lease that a crashed worker eventually gives back. The
 * `ACTIVE_SEO_MISSION` alias itself lives in PRSTUDIO_UC_Memory::mission(),
 * which is exactly what that method is for -- a low-frequency named state
 * alias -- while campaign counters stay in this table so counter updates
 * never contend with Memory's sitewide flock().
 *
 * AGENTS.md LAW 1-3 bound what BLOCKED/REVIEW_REQUIRED may mean here: only a
 * genuine technical/business impossibility, never model uncertainty. Post-
 * execution uncertainty about a mutation that already ran renders as a
 * completed row with `verification.verified=false, degraded=true` -- it is
 * evidence attached to a done thing, not a gate that stops one.
 */
final class PRSTUDIO_UC_SEO_Autopilot {
    public const VERSION = '1.0.0';
    public const MISSION_ALIAS = 'ACTIVE_SEO_MISSION';

    public const PENDING = 'PENDING';
    public const CLAIMED = 'CLAIMED';
    public const APPLIED_UNVERIFIED = 'APPLIED_UNVERIFIED';
    public const COMPLETED = 'COMPLETED';
    public const BLOCKED = 'BLOCKED';
    public const REVIEW_REQUIRED = 'REVIEW_REQUIRED';

    private const STATES = array( self::PENDING, self::CLAIMED, self::APPLIED_UNVERIFIED, self::COMPLETED, self::BLOCKED, self::REVIEW_REQUIRED );
    private const CLAIM_LEASE_SECONDS = 1800;
    private const SEED_PAGE_SIZE = 200;
    private const SCHEMA_OPTION = 'prstudio_uc_seo_autopilot_schema';
    private const SCHEMA_VERSION = 1;

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'prstudio_uc_seo_campaign_entities';
    }

    public static function install(): void {
        global $wpdb;
        if ( (int) get_option( self::SCHEMA_OPTION, 0 ) === self::SCHEMA_VERSION ) { return; }
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $sql = 'CREATE TABLE ' . self::table() . " (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            campaign_id varchar(64) NOT NULL,
            entity_type varchar(32) NOT NULL,
            entity_id varchar(64) NOT NULL,
            entity_key varchar(190) NOT NULL,
            status varchar(24) NOT NULL DEFAULT 'PENDING',
            source_fingerprint char(64) NULL,
            completed_fingerprint char(64) NULL,
            claim_token varchar(64) NULL,
            claim_expires_gmt datetime NULL,
            worker_id varchar(160) NULL,
            attempt_count int unsigned NOT NULL DEFAULT 0,
            resolved_issues longtext NULL,
            remaining_issues longtext NULL,
            verification longtext NULL,
            block_reason varchar(255) NULL,
            created_gmt datetime NOT NULL,
            updated_gmt datetime NOT NULL,
            completed_gmt datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY campaign_entity (campaign_id, entity_type, entity_id),
            KEY campaign_status (campaign_id, status),
            KEY entity_id (entity_id)
        ) $charset;";
        dbDelta( $sql );
        update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
    }

    private static function ensure(): bool {
        if ( (int) get_option( self::SCHEMA_OPTION, 0 ) !== self::SCHEMA_VERSION ) { self::install(); }
        return true;
    }

    private static function now(): string { return gmdate( 'Y-m-d H:i:s' ); }

    private static function encode( $value ): string {
        return (string) wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    }

    private static function decode_row( array $row ): array {
        foreach ( array( 'resolved_issues', 'remaining_issues', 'verification' ) as $field ) {
            if ( array_key_exists( $field, $row ) ) {
                $decoded = null !== $row[ $field ] ? json_decode( (string) $row[ $field ], true ) : null;
                $row[ $field ] = is_array( $decoded ) ? $decoded : array();
            }
        }
        $row['attempt_count'] = (int) ( $row['attempt_count'] ?? 0 );
        return $row;
    }

    /** entity_type:entity_id -- stable, never the URL. Shared format with PRSTUDIO_UC_Interventions. */
    public static function entity_key( string $type, $id ): string {
        return class_exists( 'PRSTUDIO_UC_Interventions' )
            ? PRSTUDIO_UC_Interventions::normalize_entity( $type, $id )
            : substr( sanitize_key( $type ) . ':' . trim( (string) $id ), 0, 190 );
    }

    /** Verbatim template of PRSTUDIO_UC_Product_Audit::fingerprint() -- same fields, same canonicalisation. */
    private static function fingerprint_of( $product ): string {
        $id = (int) $product->get_id();
        $parts = array(
            'id' => $id,
            'modified' => function_exists( 'get_post_modified_time' ) ? (string) get_post_modified_time( 'U', true, $id ) : '',
            'name' => $product->get_name(),
            'slug' => $product->get_slug(),
            'status' => $product->get_status(),
            'stock' => $product->get_stock_quantity(),
            'stock_status' => $product->get_stock_status(),
            'image' => $product->get_image_id(),
        );
        foreach ( array( 'rank_math_focus_keyword', 'rank_math_title', 'rank_math_description', 'rank_math_canonical_url', 'rank_math_robots' ) as $k ) {
            $v = function_exists( 'get_post_meta' ) ? get_post_meta( $id, $k, true ) : '';
            $parts[ $k ] = is_scalar( $v ) ? (string) $v : $v;
        }
        return class_exists( 'PRSTUDIO_UC_Memory' ) ? PRSTUDIO_UC_Memory::fingerprint( $parts ) : hash( 'sha256', (string) wp_json_encode( $parts ) );
    }

    /* -------------------------------------------------------------------
     * Mission alias -- which campaign is "active" right now.
     * ---------------------------------------------------------------- */

    public static function active_mission(): array {
        return class_exists( 'PRSTUDIO_UC_Memory' ) ? PRSTUDIO_UC_Memory::mission( self::MISSION_ALIAS ) : array();
    }

    private static function set_active_mission( string $campaign_id ): void {
        if ( class_exists( 'PRSTUDIO_UC_Memory' ) ) {
            PRSTUDIO_UC_Memory::mission( self::MISSION_ALIAS, array( 'campaign_id' => $campaign_id ) );
        }
    }

    /* -------------------------------------------------------------------
     * Inventory -- seeding and reconciliation.
     * ---------------------------------------------------------------- */

    private static function upsert_pending_row( string $campaign_id, string $type, string $id, string $fingerprint ): bool {
        global $wpdb;
        $now = self::now();
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table identifier only, from the fixed table() helper -- never external input; values are parameterized via $wpdb->prepare()
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk campaign-inventory seeding is set-based by design, matching PRSTUDIO_UC_Store; INSERT IGNORE is the idempotency mechanism (unique key on campaign_id+entity_type+entity_id), not a fallback.
        $result = $wpdb->query( $wpdb->prepare(
            'INSERT IGNORE INTO ' . self::table() . ' (campaign_id, entity_type, entity_id, entity_key, status, source_fingerprint, created_gmt, updated_gmt) VALUES (%s,%s,%s,%s,%s,%s,%s,%s)',
            $campaign_id, sanitize_key( $type ), $id, self::entity_key( $type, $id ), self::PENDING, $fingerprint, $now, $now
        ) );
        return 1 === (int) $result;
    }

    /**
     * Walks the full WooCommerce catalogue (HPOS-safe, same wc_get_products()
     * call as PRSTUDIO_UC_Operational_Twin::commerce_entities()) once. New
     * products become PENDING rows; a COMPLETED row whose live fingerprint no
     * longer matches its completed_fingerprint reopens. PENDING/CLAIMED/
     * APPLIED_UNVERIFIED/BLOCKED/REVIEW_REQUIRED rows are never touched here --
     * this never overwrites in-flight or settled-with-a-reason work.
     */
    private static function sync_inventory( string $campaign_id ): array {
        if ( ! function_exists( 'wc_get_products' ) ) { return array( 'added' => 0, 'reopened' => 0 ); }
        $added = 0;
        $reopened = 0;
        $page = 1;
        do {
            $products = wc_get_products( array(
                'limit' => self::SEED_PAGE_SIZE, 'page' => $page,
                'status' => array( 'publish', 'draft', 'pending', 'private' ),
                'orderby' => 'ID', 'order' => 'ASC', 'return' => 'objects',
            ) );
            foreach ( (array) $products as $product ) {
                if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) { continue; }
                $id = (string) $product->get_id();
                $fingerprint = self::fingerprint_of( $product );
                $existing = self::get_entity( $campaign_id, 'product', $id );
                if ( ! $existing ) {
                    if ( self::upsert_pending_row( $campaign_id, 'product', $id, $fingerprint ) ) { ++$added; }
                    continue;
                }
                if ( self::COMPLETED === (string) $existing['status'] && ! hash_equals( (string) ( $existing['completed_fingerprint'] ?? '' ), $fingerprint ) ) {
                    self::reopen_if_changed( $campaign_id, 'product', $id, $fingerprint );
                    ++$reopened;
                }
            }
            ++$page;
        } while ( count( $products ) === self::SEED_PAGE_SIZE );
        return array( 'added' => $added, 'reopened' => $reopened );
    }

    /** Idempotent: a second call with no active campaign change is a no-op, never a second campaign. */
    public static function ensure_campaign( array $args = array() ): array {
        self::ensure();
        $mission = self::active_mission();
        $campaign_id = (string) ( $mission['campaign_id'] ?? '' );
        if ( '' !== $campaign_id ) {
            return array( 'campaign_id' => $campaign_id, 'created' => false );
        }
        $campaign_id = ! empty( $args['campaign_id'] )
            ? substr( sanitize_key( (string) $args['campaign_id'] ), 0, 64 )
            : substr( 'seo-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_uuid4(), 0, 64 );
        self::set_active_mission( $campaign_id );
        self::sync_inventory( $campaign_id );
        return array( 'campaign_id' => $campaign_id, 'created' => true );
    }

    public static function reconcile( string $campaign_id ): array {
        self::ensure();
        return array( 'campaign_id' => $campaign_id ) + self::sync_inventory( $campaign_id );
    }

    /* -------------------------------------------------------------------
     * Reads.
     * ---------------------------------------------------------------- */

    public static function get_entity( string $campaign_id, string $entity_type, string $entity_id ): ?array {
        global $wpdb;
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- table identifier only, from the fixed table() helper -- never external input; values are parameterized via $wpdb->prepare()
        $row = $wpdb->get_row( $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table identifier only, from the fixed table() helper -- never external input; values are parameterized via $wpdb->prepare()
            'SELECT * FROM ' . self::table() . ' WHERE campaign_id = %s AND entity_type = %s AND entity_id = %s',
            $campaign_id, sanitize_key( $entity_type ), (string) $entity_id
        ), ARRAY_A );
        return is_array( $row ) ? self::decode_row( $row ) : null;
    }

    private static function row_by_id( int $id ): ?array {
        global $wpdb;
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- table identifier only, from the fixed table() helper -- never external input; values are parameterized via $wpdb->prepare()
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A );
        return is_array( $row ) ? self::decode_row( $row ) : null;
    }

    private static function counters( string $campaign_id ): array {
        global $wpdb;
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- table identifier only, from the fixed table() helper -- never external input; values are parameterized via $wpdb->prepare()
        $rows = $wpdb->get_results( $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table identifier only, from the fixed table() helper -- never external input; values are parameterized via $wpdb->prepare()
            'SELECT status, COUNT(*) AS n FROM ' . self::table() . ' WHERE campaign_id = %s GROUP BY status',
            $campaign_id
        ), ARRAY_A );
        $totals = array_fill_keys( self::STATES, 0 );
        foreach ( (array) $rows as $row ) {
            $status = (string) $row['status'];
            if ( isset( $totals[ $status ] ) ) { $totals[ $status ] = (int) $row['n']; }
        }
        $totals['total'] = array_sum( $totals );
        return $totals;
    }

    public static function status( array $args = array() ): array {
        self::ensure();
        $mission = self::active_mission();
        $campaign_id = (string) ( $args['campaign_id'] ?? ( $mission['campaign_id'] ?? '' ) );
        if ( '' === $campaign_id ) {
            return array( 'active_campaign' => false, 'campaign_id' => '', 'counters' => array_fill_keys( array_merge( self::STATES, array( 'total' ) ), 0 ) );
        }
        return array( 'active_campaign' => true, 'campaign_id' => $campaign_id, 'counters' => self::counters( $campaign_id ) );
    }

    /* -------------------------------------------------------------------
     * Stale-claim recovery.
     *
     * CLAIMED (no mutation executed yet) safely reverts to PENDING on lease
     * expiry -- nothing was lost. APPLIED_UNVERIFIED (a mutation already
     * executed) never reverts to PENDING: LAW 2 forbids treating verification
     * uncertainty as grounds to re-execute. Its lease is released so a
     * verification pass can pick it back up, but claim_next() only ever
     * selects PENDING rows, so it structurally cannot be handed out again.
     * ---------------------------------------------------------------- */

    private static function recover_stale_claims( string $campaign_id ): int {
        global $wpdb;
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- table identifier only, from the fixed table() helper -- never external input; values are parameterized via $wpdb->prepare()
        $reverted = (int) $wpdb->query( $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table identifier only, from the fixed table() helper -- never external input; values are parameterized via $wpdb->prepare()
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: lease-expiry sweep is set-based by design, matching PRSTUDIO_UC_Store::recover_stale_tasks().
            'UPDATE ' . self::table() . ' SET status = %s, claim_token = NULL, claim_expires_gmt = NULL, worker_id = NULL, updated_gmt = %s WHERE campaign_id = %s AND status = %s AND claim_expires_gmt IS NOT NULL AND claim_expires_gmt < UTC_TIMESTAMP()',
            self::PENDING, self::now(), $campaign_id, self::CLAIMED
        ) );
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- table identifier only, from the fixed table() helper -- never external input; values are parameterized via $wpdb->prepare()
        $wpdb->query( $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table identifier only, from the fixed table() helper -- never external input; values are parameterized via $wpdb->prepare()
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: lease-release-only sweep for already-executed mutations (status is deliberately not changed here -- see method docblock).
            'UPDATE ' . self::table() . ' SET claim_token = NULL, claim_expires_gmt = NULL, worker_id = NULL, updated_gmt = %s WHERE campaign_id = %s AND status = %s AND claim_expires_gmt IS NOT NULL AND claim_expires_gmt < UTC_TIMESTAMP()',
            self::now(), $campaign_id, self::APPLIED_UNVERIFIED
        ) );
        return $reverted;
    }

    /* -------------------------------------------------------------------
     * Atomic claim -- transactional SELECT ... FOR UPDATE + UPDATE, the
     * exact pattern proven by PRSTUDIO_UC_Store::claim_next(). WHERE status
     * = PENDING structurally excludes COMPLETED and APPLIED_UNVERIFIED rows;
     * the row lock inside one transaction is what makes two concurrent
     * callers land on different rows instead of colliding.
     * ---------------------------------------------------------------- */

    public static function claim_next( string $campaign_id, string $worker_id ): ?array {
        self::ensure();
        global $wpdb;
        self::recover_stale_claims( $campaign_id );
        $token = bin2hex( random_bytes( 16 ) );
        $expires = gmdate( 'Y-m-d H:i:s', time() + self::CLAIM_LEASE_SECONDS );
        $table = self::table();

        $wpdb->query( 'START TRANSACTION' );
        try {
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- table identifier only, from the fixed table() helper -- never external input; values are parameterized via $wpdb->prepare()
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table identifier only, from the fixed table() helper -- never external input; values are parameterized via $wpdb->prepare()
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: transactional atomic claim, matching PRSTUDIO_UC_Store::claim_next() exactly; object-cache/WP_Query are unsuitable for a locked row claim.
                    "SELECT * FROM $table WHERE campaign_id = %s AND status = %s ORDER BY id ASC LIMIT 1 FOR UPDATE",
                    $campaign_id,
                    self::PENDING
                ),
                ARRAY_A
            );
            if ( ! is_array( $row ) ) {
                $wpdb->query( 'COMMIT' );
                return null;
            }
            $wpdb->update(
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: part of the same atomic claim transaction as the SELECT ... FOR UPDATE above.
                $table,
                array(
                    'status' => self::CLAIMED,
                    'claim_token' => $token,
                    'claim_expires_gmt' => $expires,
                    'worker_id' => substr( sanitize_text_field( $worker_id ), 0, 160 ),
                    'attempt_count' => (int) ( $row['attempt_count'] ?? 0 ) + 1,
                    'updated_gmt' => self::now(),
                ),
                array( 'id' => (int) $row['id'] ),
                array( '%s', '%s', '%s', '%s', '%d', '%s' ),
                array( '%d' )
            );
            $wpdb->query( 'COMMIT' );
        } catch ( Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            throw $e;
        }
        $entity = self::row_by_id( (int) $row['id'] );
        if ( $entity ) { $entity['claim_token'] = $token; }
        return $entity;
    }

    /* -------------------------------------------------------------------
     * Transitions past the claim.
     * ---------------------------------------------------------------- */

    public static function mark_applied_unverified( string $campaign_id, string $entity_type, string $entity_id, string $claim_token, array $resolved_issues = array() ): ?array {
        global $wpdb;
        $updated = $wpdb->update(
            self::table(),
            array( 'status' => self::APPLIED_UNVERIFIED, 'resolved_issues' => self::encode( $resolved_issues ), 'updated_gmt' => self::now() ),
            array( 'campaign_id' => $campaign_id, 'entity_type' => sanitize_key( $entity_type ), 'entity_id' => (string) $entity_id, 'claim_token' => $claim_token, 'status' => self::CLAIMED ),
            array( '%s', '%s', '%s' ),
            array( '%s', '%s', '%s', '%s', '%s' )
        );
        return 1 === (int) $updated ? self::get_entity( $campaign_id, $entity_type, $entity_id ) : null;
    }

    /**
     * CLAIMED or APPLIED_UNVERIFIED both complete. `meta.verified`/`meta.degraded`
     * are stored as evidence (LAW 2: `executed=true, verified=false,
     * degraded=true, blocking=false`) -- they never prevent completion.
     */
    public static function complete_entity( string $campaign_id, string $entity_type, string $entity_id, string $claim_token, array $meta = array() ): ?array {
        global $wpdb;
        $entity = self::get_entity( $campaign_id, $entity_type, $entity_id );
        if ( ! $entity || '' === $claim_token || ! hash_equals( (string) ( $entity['claim_token'] ?? '' ), $claim_token ) ) { return null; }
        if ( ! in_array( (string) $entity['status'], array( self::CLAIMED, self::APPLIED_UNVERIFIED ), true ) ) { return null; }
        $fingerprint = (string) ( $meta['completed_fingerprint'] ?? $entity['source_fingerprint'] ?? '' );
        $verified = (bool) ( $meta['verified'] ?? false );
        $updated = $wpdb->update(
            self::table(),
            array(
                'status' => self::COMPLETED,
                'completed_fingerprint' => $fingerprint,
                'remaining_issues' => self::encode( $meta['remaining_issues'] ?? array() ),
                'verification' => self::encode( array( 'verified' => $verified, 'degraded' => (bool) ( $meta['degraded'] ?? ! $verified ) ) ),
                'claim_token' => null, 'claim_expires_gmt' => null, 'worker_id' => null,
                'updated_gmt' => self::now(), 'completed_gmt' => self::now(),
            ),
            array( 'campaign_id' => $campaign_id, 'entity_type' => sanitize_key( $entity_type ), 'entity_id' => (string) $entity_id, 'claim_token' => $claim_token ),
            array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
            array( '%s', '%s', '%s', '%s' )
        );
        if ( 1 !== (int) $updated ) { return null; }
        if ( class_exists( 'PRSTUDIO_UC_Interventions' ) ) {
            PRSTUDIO_UC_Interventions::record(
                self::entity_key( $entity_type, $entity_id ), 'seo_autopilot_pass', PRSTUDIO_UC_Interventions::APPLIED,
                array( 'summary' => 'SEO Autopilot campaign pass completed.', 'correlation_id' => $campaign_id )
            );
        }
        return self::get_entity( $campaign_id, $entity_type, $entity_id );
    }

    /**
     * BLOCKED is constitutionally narrow (AGENTS.md: no feature but the
     * Anti-Crash guard may stop a technically valid action). Call this only
     * for a genuine technical/business impossibility -- never for model
     * uncertainty, which belongs in complete_entity()'s degraded=true path.
     */
    public static function block_entity( string $campaign_id, string $entity_type, string $entity_id, string $claim_token, string $reason ): ?array {
        global $wpdb;
        $entity = self::get_entity( $campaign_id, $entity_type, $entity_id );
        if ( ! $entity || '' === $claim_token || ! hash_equals( (string) ( $entity['claim_token'] ?? '' ), $claim_token ) ) { return null; }
        $updated = $wpdb->update(
            self::table(),
            array( 'status' => self::BLOCKED, 'block_reason' => substr( sanitize_text_field( $reason ), 0, 255 ), 'claim_token' => null, 'claim_expires_gmt' => null, 'worker_id' => null, 'updated_gmt' => self::now() ),
            array( 'campaign_id' => $campaign_id, 'entity_type' => sanitize_key( $entity_type ), 'entity_id' => (string) $entity_id, 'claim_token' => $claim_token ),
            array( '%s', '%s', '%s', '%s', '%s', '%s' ),
            array( '%s', '%s', '%s', '%s' )
        );
        return 1 === (int) $updated ? self::get_entity( $campaign_id, $entity_type, $entity_id ) : null;
    }

    /** Gives back a claim before any mutation happened (CLAIMED only -- see recover_stale_claims() for the automatic path). */
    public static function release_claim( string $campaign_id, string $entity_type, string $entity_id, string $claim_token ): ?array {
        global $wpdb;
        $entity = self::get_entity( $campaign_id, $entity_type, $entity_id );
        if ( ! $entity || '' === $claim_token || ! hash_equals( (string) ( $entity['claim_token'] ?? '' ), $claim_token ) || self::CLAIMED !== (string) $entity['status'] ) { return null; }
        $updated = $wpdb->update(
            self::table(),
            array( 'status' => self::PENDING, 'claim_token' => null, 'claim_expires_gmt' => null, 'worker_id' => null, 'updated_gmt' => self::now() ),
            array( 'campaign_id' => $campaign_id, 'entity_type' => sanitize_key( $entity_type ), 'entity_id' => (string) $entity_id, 'claim_token' => $claim_token ),
            array( '%s', '%s', '%s', '%s', '%s' ),
            array( '%s', '%s', '%s', '%s' )
        );
        return 1 === (int) $updated ? self::get_entity( $campaign_id, $entity_type, $entity_id ) : null;
    }

    /** A COMPLETED row whose live fingerprint drifted reopens to PENDING; an unchanged fingerprint is left untouched. */
    public static function reopen_if_changed( string $campaign_id, string $entity_type, string $entity_id, string $current_fingerprint ): ?array {
        global $wpdb;
        $entity = self::get_entity( $campaign_id, $entity_type, $entity_id );
        if ( ! $entity || self::COMPLETED !== (string) $entity['status'] ) { return $entity; }
        if ( hash_equals( (string) ( $entity['completed_fingerprint'] ?? '' ), $current_fingerprint ) ) { return $entity; }
        $updated = $wpdb->update(
            self::table(),
            array( 'status' => self::PENDING, 'source_fingerprint' => $current_fingerprint, 'updated_gmt' => self::now() ),
            array( 'campaign_id' => $campaign_id, 'entity_type' => sanitize_key( $entity_type ), 'entity_id' => (string) $entity_id, 'status' => self::COMPLETED ),
            array( '%s', '%s', '%s' ),
            array( '%s', '%s', '%s', '%s' )
        );
        return 1 === (int) $updated ? self::get_entity( $campaign_id, $entity_type, $entity_id ) : $entity;
    }

    /* -------------------------------------------------------------------
     * MCP entry points.
     * ---------------------------------------------------------------- */

    /** prstudio_seo_autopilot_status -- read-only. */
    public static function mcp_status( array $args = array() ): array {
        return self::status( $args );
    }

    /** prstudio_seo_autopilot_next -- resolves/creates the campaign, reconciles, atomically claims one entity. */
    public static function mcp_next( array $args = array() ): array {
        self::ensure();
        $bootstrap = self::ensure_campaign( $args );
        $campaign_id = (string) $bootstrap['campaign_id'];
        if ( empty( $args['skip_reconcile'] ) ) { self::sync_inventory( $campaign_id ); }
        $worker_id = (string) ( $args['worker_id'] ?? ( $args['_client_id'] ?? 'seo-autopilot' ) );
        $claim = self::claim_next( $campaign_id, $worker_id );
        if ( ! $claim ) {
            return array( 'campaign_id' => $campaign_id, 'has_next' => false, 'counters' => self::counters( $campaign_id ), 'meaning' => 'No PENDING entity remains in this campaign.' );
        }
        $settled = array();
        if ( class_exists( 'PRSTUDIO_UC_Interventions' ) ) {
            $check = PRSTUDIO_UC_Interventions::filter_new(
                array( array( 'entity_key' => self::entity_key( $claim['entity_type'], $claim['entity_id'] ), 'intervention_key' => 'seo_autopilot_pass' ) ),
                true
            );
            $settled = $check['already_settled'] ?? array();
        }
        return array(
            'campaign_id' => $campaign_id,
            'has_next' => true,
            'entity' => array(
                'entity_type' => $claim['entity_type'],
                'entity_id' => $claim['entity_id'],
                'entity_key' => $claim['entity_key'],
                'claim_token' => $claim['claim_token'],
                'claim_expires_gmt' => $claim['claim_expires_gmt'],
                'source_fingerprint' => $claim['source_fingerprint'],
                'attempt_count' => (int) $claim['attempt_count'],
            ),
            'already_settled_elsewhere' => $settled,
            'counters' => self::counters( $campaign_id ),
        );
    }

    /** prstudio_seo_autopilot_control -- action-based mutations on the claimed entity or the campaign itself. */
    public static function mcp_control( array $args = array() ): array {
        self::ensure();
        $action = sanitize_key( (string) ( $args['action'] ?? '' ) );
        $mission = self::active_mission();
        $campaign_id = (string) ( $args['campaign_id'] ?? ( $mission['campaign_id'] ?? '' ) );
        if ( '' === $campaign_id && 'init' !== $action ) {
            return array( 'ok' => false, 'reason' => 'no_active_campaign' );
        }
        $entity_type = (string) ( $args['entity_type'] ?? 'product' );
        $entity_id = (string) ( $args['entity_id'] ?? '' );
        $claim_token = (string) ( $args['claim_token'] ?? '' );

        switch ( $action ) {
            case 'init':
                return array( 'ok' => true ) + self::ensure_campaign( $args );
            case 'reconcile':
                return array( 'ok' => true ) + self::reconcile( $campaign_id );
            case 'mark_applied_unverified':
                $row = self::mark_applied_unverified( $campaign_id, $entity_type, $entity_id, $claim_token, (array) ( $args['resolved_issues'] ?? array() ) );
                return $row ? array( 'ok' => true, 'entity' => $row ) : array( 'ok' => false, 'reason' => 'claim_mismatch_or_wrong_state' );
            case 'complete':
                $row = self::complete_entity( $campaign_id, $entity_type, $entity_id, $claim_token, (array) $args );
                return $row ? array( 'ok' => true, 'entity' => $row, 'counters' => self::counters( $campaign_id ) ) : array( 'ok' => false, 'reason' => 'claim_mismatch_or_wrong_state' );
            case 'block':
                $row = self::block_entity( $campaign_id, $entity_type, $entity_id, $claim_token, (string) ( $args['reason'] ?? '' ) );
                return $row ? array( 'ok' => true, 'entity' => $row, 'counters' => self::counters( $campaign_id ) ) : array( 'ok' => false, 'reason' => 'claim_mismatch_or_wrong_state' );
            case 'release':
                $row = self::release_claim( $campaign_id, $entity_type, $entity_id, $claim_token );
                return $row ? array( 'ok' => true, 'entity' => $row ) : array( 'ok' => false, 'reason' => 'claim_mismatch_or_wrong_state' );
            default:
                return array( 'ok' => false, 'reason' => 'unknown_action', 'known_actions' => array( 'init', 'reconcile', 'mark_applied_unverified', 'complete', 'block', 'release' ) );
        }
    }
}
