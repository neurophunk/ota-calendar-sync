<?php
/**
 * Plugin Name: OTA Calendar Sync for Bookly
 * Plugin URI:  https://github.com/neurophunk/ota-calendar-sync
 * Description: Syncs GetYourGuide, Viator, and GoWithGuide iCal feeds to Bookly blocked time slots.
 * Version:     1.0.0
 * Author:      neurophunk
 * License:     GPL-2.0+
 * Text Domain: ota-calendar-sync
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'OTA_SYNC_VERSION',    '1.0.0' );
define( 'OTA_SYNC_DB_VERSION', '1.0' );
define( 'OTA_SYNC_PATH',       plugin_dir_path( __FILE__ ) );
define( 'OTA_SYNC_FILE',       __FILE__ );

require_once OTA_SYNC_PATH . 'includes/class-ical-parser.php';
require_once OTA_SYNC_PATH . 'includes/class-ssrf-guard.php';
require_once OTA_SYNC_PATH . 'includes/class-sync-logger.php';
require_once OTA_SYNC_PATH . 'includes/class-bookly-bridge.php';
require_once OTA_SYNC_PATH . 'includes/class-feed-manager.php';
require_once OTA_SYNC_PATH . 'admin/class-admin-page.php';

register_activation_hook( __FILE__, 'ota_sync_activate' );
register_deactivation_hook( __FILE__, 'ota_sync_deactivate' );

function ota_sync_activate() {
    ota_sync_create_tables();
    OTA_Sync_Feed_Manager::schedule_cron();
}

function ota_sync_deactivate() {
    OTA_Sync_Feed_Manager::unschedule_cron();
}

function ota_sync_create_tables() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();

    $feeds_table  = $wpdb->prefix . 'ota_sync_feeds';
    $blocks_table = $wpdb->prefix . 'ota_sync_blocks';

    $sql = "
    CREATE TABLE {$feeds_table} (
        id                INT NOT NULL AUTO_INCREMENT,
        ota_name          VARCHAR(50) NOT NULL,
        label             VARCHAR(100) NOT NULL DEFAULT '',
        ical_url          TEXT NOT NULL,
        bookly_service_id INT NOT NULL DEFAULT 0,
        bookly_staff_id   INT NOT NULL DEFAULT 0,
        failure_count     TINYINT NOT NULL DEFAULT 0,
        enabled           TINYINT(1) NOT NULL DEFAULT 1,
        last_sync         DATETIME NULL,
        last_status       VARCHAR(20) NOT NULL DEFAULT 'pending',
        last_message      TEXT NULL,
        created_at        DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY ical_url (ical_url(255))
    ) {$charset};

    CREATE TABLE {$blocks_table} (
        id                   INT NOT NULL AUTO_INCREMENT,
        feed_id              INT NOT NULL,
        event_uid            VARCHAR(255) NOT NULL,
        start_datetime       DATETIME NOT NULL,
        end_datetime         DATETIME NOT NULL,
        bookly_appt_id       INT NULL,
        synced_at            DATETIME NOT NULL,
        marked_missing_at    DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY feed_event (feed_id, event_uid(191))
    ) {$charset};
    ";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );

    update_option( 'ota_sync_db_version', OTA_SYNC_DB_VERSION );
}

add_action( 'plugins_loaded', function() {
    $db_ver = get_option( 'ota_sync_db_version', '0' );
    if ( version_compare( $db_ver, OTA_SYNC_DB_VERSION, '<' ) ) {
        ota_sync_create_tables();
    }
    load_plugin_textdomain( 'ota-calendar-sync', false, dirname( plugin_basename( OTA_SYNC_FILE ) ) . '/languages' );
} );

add_action( 'init', function() {
    ( new OTA_Sync_Admin_Page() )->init();
    ( new OTA_Sync_Feed_Manager() )->init();
} );
