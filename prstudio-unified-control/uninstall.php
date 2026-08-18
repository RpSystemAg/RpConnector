<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }
if ( ! defined( 'PRSTUDIO_UNIFIED_PURGE_DATA' ) || true !== PRSTUDIO_UNIFIED_PURGE_DATA ) { return; }
global $wpdb;
foreach ( array( 'prstudio_uc_devices', 'prstudio_uc_tasks', 'prstudio_uc_events' ) as $suffix ) {
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.
    // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- table/column identifier only, from a fixed helper or the identifier() allowlist + SHOW TABLES check -- never external input; values are parameterized via $wpdb->prepare()
    $wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . $suffix );
}
delete_option( 'prstudio_uc_secret' );
delete_option( 'prstudio_uc_migration' );
// OAuth clients, bridge tokens and browser runtime data are deliberately preserved.
