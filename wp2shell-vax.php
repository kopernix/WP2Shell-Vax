<?php
/**
 * Plugin Name: WP2Shell-Vax – wp2shell Emergency Guard
 * Description: Temporary wp2shell REST batch protection, version-aware automatic shutdown, and optional privacy-conscious block logging.
 * Version: 1.0.1
 * Update URI: false
 * Author: Kopernix
 * License: GPL-2.0-or-later
 * Text Domain: wp2shell-vax
 * Requires at least: 5.6
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class WP2Shell_Vax {
    const VERSION = '1.0.1';
    const CRON_HOOK = 'wp2shell-vax_daily_cleanup';
    const TABLE_OPT = 'wp2shell-vax_table_ready';
    const LOG_OPT = 'wp2shell-vax_logging';
    const MODE_OPT = 'wp2shell-vax_strict';
    const MAX_ROWS = 500;
    const MAX_SAMPLES_PER_HOUR = 20;
    const DAYS = 30;

    public static function classify_version( $version ) {
        $version = trim( (string) $version );
        if ( ! preg_match( '/^[0-9]+\.[0-9]+(?:\.|-|$)/', $version ) ) {
            return 'unknown';
        }
        if ( version_compare( $version, '6.8.0', '>=' ) && version_compare( $version, '6.8.6', '<' ) ) {
            return 'sqli_only';
        }
        if (
            ( version_compare( $version, '6.9.0', '>=' ) && version_compare( $version, '6.9.5', '<' ) ) ||
            ( version_compare( $version, '7.0.0', '>=' ) && version_compare( $version, '7.0.2', '<' ) ) ||
            1 === preg_match( '/^7\.1-(?:alpha\w*|beta1(?:\b|[-.]))/i', $version )
        ) {
            return 'wp2shell';
        }
        return 'not_affected';
    }

    public static function current_status() {
        global $wp_version;
        return self::classify_version( isset( $wp_version ) ? $wp_version : '' );
    }

    public static function boot() {
        add_filter( 'rest_pre_dispatch', array( __CLASS__, 'guard' ), -1000000, 3 );
        add_filter( 'rest_request_before_callbacks', array( __CLASS__, 'guard_before_callbacks' ), PHP_INT_MAX, 3 );
        add_action( 'admin_init', array( __CLASS__, 'auto_deactivate' ) );
        add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
        add_action( 'admin_post_wp2shell-vax_save', array( __CLASS__, 'save_settings' ) );
        add_action( 'admin_post_wp2shell-vax_clear', array( __CLASS__, 'clear_logs' ) );
        add_action( self::CRON_HOOK, array( __CLASS__, 'prune_logs' ) );
    }

    private static function is_batch( $request ) {
        if ( ! $request instanceof WP_REST_Request ) {
            return false;
        }
        return '/batch/v1' === strtolower( rtrim( $request->get_route(), '/' ) );
    }

    private static function should_block( $request ) {
        if ( ! self::is_batch( $request ) ) {
            return false;
        }
        if ( ! in_array( self::current_status(), array( 'wp2shell', 'unknown' ), true ) ) {
            return false;
        }
        return '1' === get_option( self::MODE_OPT, '0' ) || ! is_user_logged_in();
    }

    private static function rejection() {
        self::log_denial();
        return new WP_Error(
            'wp2shell-vax_batch_restricted',
            'REST batch requests are temporarily restricted. Update WordPress core.',
            array( 'status' => '1' === get_option( self::MODE_OPT, '0' ) ? 403 : 401 )
        );
    }

    public static function guard( $result, $server, $request ) {
        return self::should_block( $request ) ? self::rejection() : $result;
    }

    public static function guard_before_callbacks( $response, $handler, $request ) {
        return self::should_block( $request ) ? self::rejection() : $response;
    }

    public static function activate( $network_wide = false ) {
        if ( $network_wide ) {
            wp_die( esc_html__( 'WP2Shell-Vax does not support network activation. Activate it per site.', 'wp2shell-vax' ) );
        }
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
        }
    }

    public static function auto_deactivate() {
        static $done = false;
        $status = self::current_status();
        if ( $done || 'wp2shell' === $status || 'unknown' === $status || ! current_user_can( 'activate_plugins' ) ) {
            return;
        }
        if ( is_multisite() ) {
            if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            if ( is_plugin_active_for_network( plugin_basename( __FILE__ ) ) ) {
                return;
            }
        }
        if ( ! function_exists( 'deactivate_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $done = true;
        self::deactivate();
        deactivate_plugins( plugin_basename( __FILE__ ), true );
        add_action( 'admin_notices', static function () use ( $status ) {
            $message = 'WP2Shell-Vax has automatically deactivated: this WordPress version does not have the wp2shell RCE chain. Saved IP logs were deleted.';
            if ( 'sqli_only' === $status ) {
                $message .= ' WARNING: This version still has the separate CVE-2026-60137 SQL injection. Update to WordPress 6.8.6 or later.';
            }
            echo '<div class="notice notice-warning"><p>' . esc_html( $message ) . '</p></div>';
        } );
    }

    public static function deactivate() {
        wp_clear_scheduled_hook( self::CRON_HOOK );
        self::purge_logs();
    }

    /** Use underscores: an unquoted hyphen is interpreted as SQL subtraction. */
    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'wp2shell_vax_blocks';
    }

    private static function install_table() {
        global $wpdb;
        $table = self::table();
        // Do not trust the option alone; this also repairs installations from 1.0.0.
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
        if ( $exists === $table ) {
            update_option( self::TABLE_OPT, '1', false );
            return true;
        }
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            ip varchar(45) NOT NULL,
            first_seen datetime NOT NULL,
            last_seen datetime NOT NULL,
            samples int(10) unsigned NOT NULL DEFAULT 1,
            PRIMARY KEY  (id),
            UNIQUE KEY ip (ip),
            KEY last_seen (last_seen)
        ) {$collate};";
        dbDelta( $sql );
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
        if ( $exists !== $table ) {
            error_log( 'WP2Shell-Vax: unable to create logging table.' );
            return false;
        }
        update_option( self::TABLE_OPT, '1', false );
        return true;
    }

    private static function log_denial() {
        if ( '1' !== get_option( self::LOG_OPT, '0' ) || ! self::install_table() ) {
            return;
        }
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
            return;
        }
        $key = 'wp2shell-vax_ip_' . hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) );
        if ( get_transient( $key ) ) {
            return;
        }
        $budget = (int) get_transient( 'wp2shell-vax_log_hour_budget' );
        if ( $budget >= self::MAX_SAMPLES_PER_HOUR ) {
            return;
        }
        global $wpdb;
        $table = self::table();
        $now = gmdate( 'Y-m-d H:i:s' );
        $query = $wpdb->prepare(
            "INSERT INTO {$table} (ip, first_seen, last_seen, samples)
             VALUES (%s, %s, %s, 1)
             ON DUPLICATE KEY UPDATE last_seen = VALUES(last_seen), samples = samples + 1",
            $ip, $now, $now
        );
        $written = $wpdb->query( $query );
        if ( false === $written ) {
            return;
        }
        set_transient( $key, 1, HOUR_IN_SECONDS );
        set_transient( 'wp2shell-vax_log_hour_budget', $budget + 1, HOUR_IN_SECONDS );
        if ( 1 === $written ) {
            $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
            if ( $count > self::MAX_ROWS ) {
                $over = min( $count - self::MAX_ROWS, 1000 );
                $wpdb->query( "DELETE FROM {$table} ORDER BY last_seen ASC LIMIT " . (int) $over );
            }
        }
    }

    public static function prune_logs() {
        if ( '1' !== get_option( self::TABLE_OPT, '0' ) ) {
            return;
        }
        global $wpdb;
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - self::DAYS * DAY_IN_SECONDS );
        $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE last_seen < %s', $cutoff ) );
    }

    private static function purge_logs() {
        if ( '1' === get_option( self::TABLE_OPT, '0' ) ) {
            global $wpdb;
            $wpdb->query( 'DELETE FROM ' . self::table() );
        }
    }

    public static function admin_menu() {
        add_management_page( 'WP2Shell-Vax', 'WP2Shell-Vax', 'manage_options', 'wp2shell-vax', array( __CLASS__, 'admin_page' ) );
    }

    public static function save_settings() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Forbidden', '', array( 'response' => 403 ) );
        }
        check_admin_referer( 'wp2shell-vax_save' );
        if ( ! in_array( self::current_status(), array( 'wp2shell', 'unknown' ), true ) ) {
            wp_die( 'WP2Shell-Vax is no longer needed on this WordPress version.', '', array( 'response' => 409 ) );
        }
        $enabled = isset( $_POST['logging'] ) && '1' === $_POST['logging'];
        if ( $enabled && ! self::install_table() ) {
            wp_safe_redirect( add_query_arg( array( 'page' => 'wp2shell-vax', 'result' => 'db_error' ), admin_url( 'tools.php' ) ) );
            exit;
        }
        update_option( self::LOG_OPT, $enabled ? '1' : '0', false );
        update_option( self::MODE_OPT, isset( $_POST['strict'] ) && '1' === $_POST['strict'] ? '1' : '0', false );
        wp_safe_redirect( add_query_arg( array( 'page' => 'wp2shell-vax', 'result' => 'saved' ), admin_url( 'tools.php' ) ) );
        exit;
    }

    public static function clear_logs() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Forbidden', '', array( 'response' => 403 ) );
        }
        check_admin_referer( 'wp2shell-vax_clear' );
        self::purge_logs();
        wp_safe_redirect( add_query_arg( array( 'page' => 'wp2shell-vax', 'result' => 'cleared' ), admin_url( 'tools.php' ) ) );
        exit;
    }

    public static function admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        global $wpdb, $wp_version;
        self::prune_logs();
        $status = self::current_status();
        $strict = '1' === get_option( self::MODE_OPT, '0' );
        $logging = '1' === get_option( self::LOG_OPT, '0' );
        $messages = array(
            'wp2shell' => 'Vulnerable core: temporary batch protection is ACTIVE. Update WordPress immediately.',
            'sqli_only' => 'SQL injection affected: this batch guard does NOT fix CVE-2026-60137. Update to 6.8.6 or later.',
            'not_affected' => 'No wp2shell RCE chain detected in this version. This plugin will auto-deactivate on an administrator visit.',
            'unknown' => 'Unknown WordPress version: batch protection is active as a precaution. Verify the core version.',
        );
        echo '<div class="wrap"><h1>WP2Shell-Vax</h1>';
        echo '<p><strong>WordPress:</strong> ' . esc_html( (string) $wp_version ) . ' &mdash; ' . esc_html( $messages[ $status ] ) . '</p>';
        echo '<p>Emergency mitigation only; not a repair for the underlying WordPress vulnerabilities or a malware cleaner.</p>';
        if ( isset( $_GET['result'] ) ) {
            $result = is_string( $_GET['result'] ) ? sanitize_key( wp_unslash( $_GET['result'] ) ) : '';
            $notices = array( 'saved' => 'Settings saved.', 'cleared' => 'IP logs deleted.', 'db_error' => 'Could not create the logging table; no settings were changed.' );
            if ( isset( $notices[ $result ] ) ) {
                echo '<div class="notice notice-info"><p>' . esc_html( $notices[ $result ] ) . '</p></div>';
            }
        }
        echo '<h2>Protection &amp; privacy</h2>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'wp2shell-vax_save' );
        echo '<input type="hidden" name="action" value="wp2shell-vax_save">';
        echo '<p><label><input type="checkbox" name="strict" value="1" ' . checked( $strict, true, false ) . '> Strict mode: block ALL batch requests, including authenticated ones.</label></p>';
        echo '<p><label><input type="checkbox" name="logging" value="1" ' . checked( $logging, true, false ) . '> Store blocked REMOTE_ADDR IPs (optional; up to 500 IPs, 30 days).</label></p>';
        submit_button( 'Save settings' );
        echo '</form><h2>Recent blocked IPs</h2>';
        if ( '1' !== get_option( self::TABLE_OPT, '0' ) ) {
            echo '<p>No logging table created. Enable logging above to start recording events.</p>';
        } else {
            $rows = $wpdb->get_results( 'SELECT ip, first_seen, last_seen, samples FROM ' . self::table() . ' ORDER BY last_seen DESC LIMIT 100' );
            echo '<table class="widefat striped"><thead><tr><th>IP (REMOTE_ADDR)</th><th>First (UTC)</th><th>Last (UTC)</th><th>Samples</th></tr></thead><tbody>';
            if ( $rows ) {
                foreach ( $rows as $row ) {
                    echo '<tr><td>' . esc_html( $row->ip ) . '</td><td>' . esc_html( $row->first_seen ) . '</td><td>' . esc_html( $row->last_seen ) . '</td><td>' . esc_html( (string) $row->samples ) . '</td></tr>';
                }
            } else {
                echo '<tr><td colspan="4">No stored IPs.</td></tr>';
            }
            echo '</tbody></table><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
            wp_nonce_field( 'wp2shell-vax_clear' );
            echo '<input type="hidden" name="action" value="wp2shell-vax_clear">';
            submit_button( 'Delete all IP logs now', 'secondary' );
            echo '</form>';
        }
        echo '<p><strong>Important:</strong> logs are automatically erased when this plugin is deactivated.</p></div>';
    }
}

WP2Shell_Vax::boot();
register_activation_hook( __FILE__, array( 'WP2Shell_Vax', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WP2Shell_Vax', 'deactivate' ) );
