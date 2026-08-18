<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * One-way upgrade cleanup for persisted pre-17 execution states.
 * IMPORTANT: legacy vocabulary is intentionally isolated in this migration file
 * and must never be referenced by deployable routing/execution code.
 */
final class PRSTUDIO_UC_One_Guard_Legacy_Migration {
    private const OPTION = 'prstudio_uc_one_guard_migration';
    private const VERSION = '1.0.0-one-guard-4';

    public static function run(): void {
        if ( self::VERSION === (string) get_option( self::OPTION, '' ) ) { return; }
        global $wpdb;
        $now = gmdate( 'Y-m-d H:i:s' );
        $tasks = $wpdb->prefix . 'prstudio_uc_tasks';
        $jobs  = $wpdb->prefix . 'prstudio_uc_jobs';

        if ( self::table_exists( $tasks ) ) {
            // Old human-park states re-enter the executable queue; no manual resume survives.
            $wpdb->query( "UPDATE `{$tasks}` SET status='queued', lease_token=NULL, lease_expires_gmt=NULL, updated_gmt='{$now}' WHERE LOWER(status) IN ('human_takeover','resuming')" );
            self::drop_column_if_present( $tasks, 'takeover_reason' );
        }
        if ( self::table_exists( $jobs ) ) {
            // Approval/review parking becomes executable; legacy safe-failure states become technical terminals.
            $wpdb->query( "UPDATE `{$jobs}` SET status='READY', lease_token=NULL, lease_expires_gmt=NULL, available_gmt='{$now}', updated_gmt='{$now}' WHERE UPPER(status) IN ('WAITING_FOR_APPROVAL')" );
            $wpdb->query( "UPDATE `{$jobs}` SET status='COMPLETED', lease_token=NULL, lease_expires_gmt=NULL, completed_gmt=COALESCE(completed_gmt,'{$now}'), updated_gmt='{$now}' WHERE UPPER(status) IN ('VERIFYING','ROLLED_BACK')" );
            $wpdb->query( "UPDATE `{$jobs}` SET status='TECHNICAL_ERROR', lease_token=NULL, lease_expires_gmt=NULL, completed_gmt=COALESCE(completed_gmt,'{$now}'), updated_gmt='{$now}' WHERE UPPER(status) IN ('FAILED_SAFE','ROLLING_BACK')" );
        }

        $legacy_migration_state = get_option( 'prstudio_uc_migration_pending', array() );
        if ( is_array( $legacy_migration_state ) && ! empty( $legacy_migration_state ) ) {
            $legacy_state = strtolower( (string) ( $legacy_migration_state['state'] ?? '' ) );
            $legacy_migration_state['state'] = 'completed' === $legacy_state ? 'completed' : ( 'failed_safe' === $legacy_state ? 'technical_error' : 'retryable' );
            if ( isset( $legacy_migration_state['reason'] ) && false !== strpos( (string) $legacy_migration_state['reason'], 'failed_safe' ) ) { $legacy_migration_state['reason'] = 'migration_technical_error'; }
            update_option( 'prstudio_uc_migration_state', $legacy_migration_state, false );
        }
        delete_option( 'prstudio_uc_migration_pending' );

        delete_option( 'prstudio_agency_approvals' );
        delete_option( 'prstudio_uc_autonomy_mode' );

        $agency_jobs = get_option( 'prstudio_agency_jobs', array() );
        if ( is_array( $agency_jobs ) ) {
            foreach ( $agency_jobs as &$job ) {
                if ( ! is_array( $job ) ) { continue; }
                $status = strtolower( (string) ( $job['status'] ?? '' ) );
                if ( in_array( $status, array( 'accepted_pending_integration', 'planned', 'blocked', 'needs_review', 'waiting_for_approval' ), true ) ) {
                    $job['status'] = 'queued'; $job['error'] = null;
                } elseif ( 'failed' === $status ) {
                    $job['status'] = 'technical_error';
                } elseif ( 'stored' === $status ) {
                    $job['status'] = 'degraded';
                    if ( ! isset( $job['result'] ) || ! is_array( $job['result'] ) ) { $job['result'] = array(); }
                    $job['result']['outcome'] = array( 'status'=>'degraded', 'executed'=>true, 'mutated'=>true, 'verified'=>false, 'degraded'=>true, 'blocking'=>false, 'reason'=>'legacy_persistence_evidence_incomplete' );
                }
            }
            unset( $job );
            update_option( 'prstudio_agency_jobs', $agency_jobs, false );
        }

        $settings = get_option( 'wpaib_settings', array() );
        if ( is_array( $settings ) ) {
            foreach ( array( 'approval_mode', 'enabled', 'allow_file_write', 'allow_plugin_actions', 'allow_theme_switch', 'allow_content_write', 'allow_core_write', 'allow_enterprise_execution' ) as $legacy_key ) { unset( $settings[ $legacy_key ] ); }
            update_option( 'wpaib_settings', $settings, false );
        }

        $agency = get_option( 'prstudio_agency_config', array() );
        if ( is_array( $agency ) && isset( $agency['agent_role_define']['data']['roles'] ) && is_array( $agency['agent_role_define']['data']['roles'] ) ) {
            foreach ( $agency['agent_role_define']['data']['roles'] as &$role ) {
                if ( ! is_array( $role ) ) { continue; }
                if ( isset( $role['allowed_actions'] ) && is_array( $role['allowed_actions'] ) ) {
                    $role['affinity_actions'] = array_values( array_unique( array_filter( array_map( 'sanitize_key', $role['allowed_actions'] ) ) ) );
                }
                unset( $role['allowed_actions'], $role['required_actions'], $role['enforced_by_task_router'] );
                $role['affinity_only'] = true;
            }
            unset( $role );
            update_option( 'prstudio_agency_config', $agency, false );
        }

        $editorial = get_option( 'prstudio_uc_editorial_autonomy_v2', array() );
        if ( is_array( $editorial ) ) {
            unset( $editorial['envelopes'], $editorial['usage'] );
            update_option( 'prstudio_uc_editorial_autonomy_v2', $editorial, false );
        }
        update_option( self::OPTION, self::VERSION, false );
    }

    private static function table_exists( string $table ): bool {
        global $wpdb;
        return $table === (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
    }

    private static function drop_column_if_present( string $table, string $column ): void {
        global $wpdb;
        $exists = $wpdb->get_var( $wpdb->prepare(
            'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            $table, $column
        ) );
        if ( $exists ) { $wpdb->query( "ALTER TABLE `{$table}` DROP COLUMN `{$column}`" ); }
    }
}
