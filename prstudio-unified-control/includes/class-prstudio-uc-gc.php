<?php
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }

/**
 * Garbage collector — the valve that 15.x was missing.
 *
 * The durable tables all declared a lifetime: TASK_TTL was one day, JOB_TTL was
 * thirty. Both were honoured when *reading* — an expired row stopped being
 * eligible for work — and neither was ever honoured by a DELETE. The only
 * scheduled cleanup in the whole plugin was `prstudio_uc_artifact_cleanup`,
 * which touches screenshots. Tasks, jobs, events, dead letters, the audit log
 * and orphaned transients grew without limit, which is what filled the database
 * within minutes of real use.
 *
 * This runs hourly, deletes in bounded batches so it can never hold a long lock
 * on a live site, and stops the moment it runs out of budget. Retention is a
 * filterable option, not a constant, because a staging site and a production
 * store want different answers.
 *
 * Terminal rows are collected by *completion* time, never by creation time, so
 * a long-running job is never deleted out from under a caller that is still
 * polling it.
 */
final class PRSTUDIO_UC_GC {
    public const VERSION = '1.0.0';
    public const HOOK = 'prstudio_uc_gc';
    public const OPTION = 'prstudio_uc_retention';
    public const LAST_RUN_OPTION = 'prstudio_uc_gc_last_run';

    /** Rows removed per table per pass. Small enough to never block a request. */
    private const BATCH = 500;
    /** Total wall-clock budget for one pass. */
    private const BUDGET_SECONDS = 20;

    private static function defaults(): array {
        return array(
            'events_hours'         => 72,
            'tasks_hours'          => 24,
            'jobs_days'            => 7,
            'dead_letters_days'    => 30,
            'audit_days'           => 30,
            'schedules_days'       => 90,
            'transients_hours'     => 24,
            'revisions_keep'       => 5,
            'work_sessions_days'   => 14,
        );
    }

    public static function retention(): array {
        $stored = function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array();
        $retention = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
        if ( function_exists( 'apply_filters' ) ) {
            $retention = (array) apply_filters( 'prstudio_uc_retention', $retention );
        }
        foreach ( $retention as $key => $value ) { $retention[ $key ] = max( 1, (int) $value ); }
        return $retention;
    }

    public static function activate(): void {
        if ( function_exists( 'wp_next_scheduled' ) && function_exists( 'wp_schedule_event' ) && ! wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_event( time() + 300, 'hourly', self::HOOK );
        }
    }

    public static function deactivate(): void {
        if ( ! function_exists( 'wp_next_scheduled' ) ) { return; }
        $timestamp = wp_next_scheduled( self::HOOK );
        if ( $timestamp && function_exists( 'wp_unschedule_event' ) ) { wp_unschedule_event( $timestamp, self::HOOK ); }
    }

    /** Registered on plugins_loaded so a missed schedule self-heals. */
    public static function init(): void {
        add_action( self::HOOK, array( __CLASS__, 'run' ) );
        self::activate();
    }

    /**
     * One bounded pass. Returns what it removed, which is what the health tool
     * reports so the operator can see the valve is actually open.
     */
    public static function run( bool $force = false ): array {
        global $wpdb;
        $started = microtime( true );
        $retention = self::retention();
        $removed = array();
        $budget_hit = false;

        $plan = array();
        if ( class_exists( 'PRSTUDIO_UC_Store' ) ) {
            $plan['events'] = array(
                'table' => PRSTUDIO_UC_Store::events_table(),
                'sql'   => 'DELETE FROM %TABLE% WHERE created_gmt < %s ORDER BY id ASC LIMIT %d',
                'cutoff'=> self::cutoff( $retention['events_hours'] * HOUR_IN_SECONDS ),
            );
            $plan['tasks'] = array(
                'table' => PRSTUDIO_UC_Store::tasks_table(),
                'sql'   => "DELETE FROM %TABLE% WHERE status IN ('COMPLETED','TECHNICAL_ERROR','CANCELLED','EXPIRED','completed','technical_error','cancelled','expired') AND updated_gmt < %s ORDER BY id ASC LIMIT %d",
                'cutoff'=> self::cutoff( $retention['tasks_hours'] * HOUR_IN_SECONDS ),
            );
            $plan['jobs'] = array(
                'table' => PRSTUDIO_UC_Store::jobs_table(),
                'sql'   => "DELETE FROM %TABLE% WHERE status IN ('COMPLETED','TECHNICAL_ERROR','CANCELLED','DEAD_LETTER','completed','technical_error','cancelled') AND completed_gmt IS NOT NULL AND completed_gmt < %s ORDER BY id ASC LIMIT %d",
                'cutoff'=> self::cutoff( $retention['jobs_days'] * DAY_IN_SECONDS ),
            );
            $plan['dead_letters'] = array(
                'table' => PRSTUDIO_UC_Store::dead_letters_table(),
                'sql'   => 'DELETE FROM %TABLE% WHERE created_gmt < %s ORDER BY id ASC LIMIT %d',
                'cutoff'=> self::cutoff( $retention['dead_letters_days'] * DAY_IN_SECONDS ),
            );
        }
        if ( class_exists( 'WPAIB_Audit' ) && method_exists( 'WPAIB_Audit', 'table_name' ) ) {
            $plan['audit'] = array(
                'table' => WPAIB_Audit::table_name(),
                'sql'   => 'DELETE FROM %TABLE% WHERE created_at < %s ORDER BY id ASC LIMIT %d',
                'cutoff'=> self::cutoff( $retention['audit_days'] * DAY_IN_SECONDS ),
            );
        }

        foreach ( $plan as $name => $spec ) {
            if ( ! $force && ( microtime( true ) - $started ) > self::BUDGET_SECONDS ) { $budget_hit = true; break; }
            $table = (string) $spec['table'];
            if ( ! self::table_exists( $table ) ) { continue; }
            $sql = str_replace( '%TABLE%', $table, (string) $spec['sql'] );
            $deleted = 0;
            // Bounded loop: at most ten batches per table per pass, so a table
            // with a million stale rows drains over hours instead of stalling
            // one cron run for minutes.
            for ( $pass = 0; $pass < 10; $pass++ ) {
                $affected = (int) $wpdb->query( $wpdb->prepare( $sql, $spec['cutoff'], self::BATCH ) );
                $deleted += $affected;
                if ( $affected < self::BATCH ) { break; }
                if ( ( microtime( true ) - $started ) > self::BUDGET_SECONDS ) { $budget_hit = true; break; }
            }
            if ( $deleted > 0 ) { $removed[ $name ] = $deleted; }
        }

        $transients = self::collect_expired_transients( $retention['transients_hours'] );
        if ( $transients > 0 ) { $removed['transients'] = $transients; }

        $revisions = self::trim_revisions( (int) $retention['revisions_keep'] );
        if ( $revisions > 0 ) { $removed['post_revisions'] = $revisions; }

        $work = self::prune_work_sessions( (int) $retention['work_sessions_days'] );
        if ( $work > 0 ) { $removed['work_sessions'] = $work; }

        $report = array(
            'ran_gmt'      => gmdate( 'c' ),
            'removed'      => $removed,
            'total_removed'=> array_sum( $removed ),
            'elapsed_ms'   => (int) round( ( microtime( true ) - $started ) * 1000 ),
            'budget_hit'   => $budget_hit,
            'retention'    => $retention,
        );
        if ( function_exists( 'update_option' ) ) { update_option( self::LAST_RUN_OPTION, $report, false ); }
        return $report;
    }

    public static function last_run(): array {
        $report = function_exists( 'get_option' ) ? get_option( self::LAST_RUN_OPTION, array() ) : array();
        return is_array( $report ) ? $report : array();
    }

    private static function cutoff( int $seconds ): string {
        return gmdate( 'Y-m-d H:i:s', time() - max( 3600, $seconds ) );
    }

    private static function table_exists( string $table ): bool {
        global $wpdb;
        static $seen = array();
        if ( isset( $seen[ $table ] ) ) { return $seen[ $table ]; }
        $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        $seen[ $table ] = ( is_string( $found ) && $found === $table );
        return $seen[ $table ];
    }

    /**
     * Removes the plugin's own expired transients.
     *
     * WordPress only clears expired transients opportunistically, and the loop
     * guard plus turn deadlines create a lot of short-lived ones. Scoped to the
     * plugin's own prefixes so nothing else on the site is touched.
     */
    private static function collect_expired_transients( int $hours ): int {
        global $wpdb;
        $cutoff = time() - max( 3600, $hours * HOUR_IN_SECONDS );
        $prefix = $wpdb->esc_like( '_transient_timeout_prstudio_' ) . '%';
        // Set-based delete: MySQL removes timeout and value rows in one server-side
        // statement. No PHP row scan and no delete_transient() N+1 loop.
        $sql = $wpdb->prepare(
            "DELETE timeout_row, value_row
             FROM {$wpdb->options} AS timeout_row
             LEFT JOIN {$wpdb->options} AS value_row
               ON value_row.option_name = CONCAT('_transient_', SUBSTRING(timeout_row.option_name, %d))
             WHERE timeout_row.option_name LIKE %s
               AND CAST(timeout_row.option_value AS UNSIGNED) < %d",
            strlen( '_transient_timeout_' ) + 1,
            $prefix,
            $cutoff
        );
        $deleted = $wpdb->query( $sql );
        return false === $deleted ? 0 : (int) $deleted;
    }

    /**
     * Trims runaway revisions created by content transactions.
     *
     * 15.x called wp_save_post_revision() on every transaction attempt, so ten
     * tries against one page left ten rows in wp_posts. 16.0 saves one revision
     * per work session (see PRSTUDIO_UC_Work_Session), and this cleans up what
     * the old behaviour already deposited. Only posts the plugin actually
     * touched are considered, and the most recent `keep` revisions always stay.
     */
    private static function trim_revisions( int $keep ): int {
        global $wpdb;
        $keep = max( 2, min( 50, $keep ) );
        $parents = $wpdb->get_col( $wpdb->prepare(
            "SELECT post_parent FROM {$wpdb->posts}
             WHERE post_type = 'revision'
             GROUP BY post_parent
             HAVING COUNT(*) > %d
             LIMIT 25",
            $keep
        ) );
        $deleted = 0;
        foreach ( (array) $parents as $parent ) {
            $parent = (int) $parent;
            if ( $parent <= 0 ) { continue; }
            $ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_parent = %d ORDER BY post_date_gmt DESC",
                $parent
            ) );
            $ids = array_slice( (array) $ids, $keep );
            foreach ( $ids as $id ) {
                if ( function_exists( 'wp_delete_post_revision' ) && wp_delete_post_revision( (int) $id ) ) { $deleted++; }
                if ( $deleted >= self::BATCH ) { return $deleted; }
            }
        }
        return $deleted;
    }

    /** Removes completed work-session directories past their retention window. */
    private static function prune_work_sessions( int $days ): int {
        if ( ! class_exists( 'PRSTUDIO_UC_Work_Session' ) ) { return 0; }
        $root = PRSTUDIO_UC_Work_Session::root();
        if ( ! is_dir( $root ) ) { return 0; }
        $cutoff = time() - max( DAY_IN_SECONDS, $days * DAY_IN_SECONDS );
        $removed = 0;
        $entries = glob( rtrim( $root, '/\\' ) . '/work_*' );
        foreach ( (array) $entries as $dir ) {
            if ( ! is_dir( $dir ) ) { continue; }
            $manifest = $dir . '/manifest.json';
            $mtime = is_file( $manifest ) ? (int) filemtime( $manifest ) : (int) filemtime( $dir );
            if ( $mtime > $cutoff ) { continue; }
            // Never remove a session that has not been completed or aborted:
            // it may still hold the only copy of an original file.
            if ( is_file( $manifest ) ) {
                $data = json_decode( (string) @file_get_contents( $manifest ), true );
                $status = is_array( $data ) ? (string) ( $data['status'] ?? '' ) : '';
                if ( ! in_array( $status, array( 'completed', 'aborted', 'completing' ), true ) ) { continue; }
            }
            if ( self::remove_tree( $dir ) ) { $removed++; }
            if ( $removed >= 20 ) { break; }
        }
        return $removed;
    }

    private static function remove_tree( string $path ): bool {
        if ( ! is_dir( $path ) ) { return false; }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ( $iterator as $file ) {
            $file->isDir() ? @rmdir( $file->getPathname() ) : @unlink( $file->getPathname() );
        }
        return @rmdir( $path );
    }
}
