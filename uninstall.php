<?php
/** wp2shell-vax uninstallation: erase optional personal-data logging and settings. */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}
global $wpdb;
$table = $wpdb->prefix . 'wp2shell-vax_blocks';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
delete_option( 'wp2shell-vax_logging' );
delete_option( 'wp2shell-vax_strict' );
delete_option( 'wp2shell-vax_table_ready' );
wp_clear_scheduled_hook( 'wp2shell-vax_daily_cleanup' );
