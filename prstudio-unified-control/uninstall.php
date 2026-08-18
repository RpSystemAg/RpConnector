<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }
if ( ! defined( 'PRSTUDIO_UNIFIED_PURGE_DATA' ) || true !== PRSTUDIO_UNIFIED_PURGE_DATA ) { return; }
global $wpdb;
foreach ( array( 'prstudio_uc_devices', 'prstudio_uc_tasks', 'prstudio_uc_events' ) as $suffix ) {
    $wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . $suffix );
}
delete_option( 'prstudio_uc_secret' );
delete_option( 'prstudio_uc_migration' );
// OAuth clients, bridge tokens and browser runtime data are deliberately preserved.
