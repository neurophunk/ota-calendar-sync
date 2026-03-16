<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class OTA_Sync_Admin_Page {

    private OTA_Sync_Feed_Manager $manager;
    private OTA_Bookly_Bridge     $bridge;

    public function __construct() {
        $this->manager = new OTA_Sync_Feed_Manager();
        $this->bridge  = new OTA_Bookly_Bridge();
    }

    public function init(): void {
        add_action( 'admin_menu', [ $this, 'add_menu' ] );
        add_action( 'admin_post_ota_sync_save_feed',   [ $this, 'handle_save_feed' ] );
        add_action( 'admin_post_ota_sync_delete_feed', [ $this, 'handle_delete_feed' ] );
        add_action( 'admin_post_ota_sync_now',         [ $this, 'handle_sync_now' ] );
        add_action( 'admin_post_ota_sync_enable_feed', [ $this, 'handle_enable_feed' ] );
    }

    public function add_menu(): void {
        add_menu_page(
            __( 'OTA Calendar Sync', 'ota-calendar-sync' ),
            __( 'OTA Sync', 'ota-calendar-sync' ),
            'manage_options',
            'ota-calendar-sync',
            [ $this, 'render_page' ],
            'dashicons-calendar-alt',
            80
        );
    }

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Insufficient permissions.', 'ota-calendar-sync' ) );
        }

        $feeds         = $this->manager->get_feeds();
        $services      = $this->bridge->get_services();
        $staff         = $this->bridge->get_staff();
        $edit_id       = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
        $bookly_active = $this->bridge->is_available();
        $wpcron_ok     = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;

        include OTA_SYNC_PATH . 'admin/views/page.php';
    }

    public function handle_save_feed(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die();
        check_admin_referer( 'ota_sync_save_feed' );

        $data = [
            'id'                => absint( $_POST['feed_id'] ?? 0 ),
            'ota_name'          => sanitize_text_field( $_POST['ota_name'] ?? '' ),
            'ical_url'          => sanitize_text_field( $_POST['ical_url'] ?? '' ),
            'bookly_service_id' => absint( $_POST['bookly_service_id'] ?? 0 ),
            'bookly_staff_id'   => absint( $_POST['bookly_staff_id'] ?? 0 ),
            'label'             => sanitize_text_field( $_POST['label'] ?? '' ),
        ];

        $v = $this->manager->validate_feed_data( $data );
        if ( ! $v['valid'] ) {
            wp_redirect( add_query_arg( 'ota_error', urlencode( $v['error'] ), admin_url( 'admin.php?page=ota-calendar-sync' ) ) );
            exit;
        }

        $this->manager->save_feed( $data );
        wp_redirect( admin_url( 'admin.php?page=ota-calendar-sync&saved=1' ) );
        exit;
    }

    public function handle_delete_feed(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die();
        check_admin_referer( 'ota_sync_delete_feed' );

        $id = absint( $_POST['feed_id'] ?? 0 );
        if ( $id ) $this->manager->delete_feed( $id );

        wp_redirect( admin_url( 'admin.php?page=ota-calendar-sync&deleted=1' ) );
        exit;
    }

    public function handle_sync_now(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die();
        check_admin_referer( 'ota_sync_now' );

        $this->manager->run_sync();
        wp_redirect( admin_url( 'admin.php?page=ota-calendar-sync&synced=1' ) );
        exit;
    }

    public function handle_enable_feed(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die();
        check_admin_referer( 'ota_sync_enable_feed' );

        global $wpdb;
        $id = absint( $_POST['feed_id'] ?? 0 );
        if ( $id ) {
            $wpdb->update(
                $wpdb->prefix . 'ota_sync_feeds',
                [ 'enabled' => 1, 'failure_count' => 0 ],
                [ 'id' => $id ]
            );
        }

        wp_redirect( admin_url( 'admin.php?page=ota-calendar-sync&enabled=1' ) );
        exit;
    }
}
