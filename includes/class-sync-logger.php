<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class OTA_Sync_Logger {

    public function log_success( int $feed_id, int $block_count ): void {
        global $wpdb;
        $wpdb->update( $wpdb->prefix . 'ota_sync_feeds', [
            'last_sync'     => current_time( 'mysql' ),
            'last_status'   => 'ok',
            'last_message'  => sprintf( '%d blokk szinkronizálva', $block_count ),
            'failure_count' => 0,
        ], [ 'id' => $feed_id ] );
    }

    public function log_error( int $feed_id, string $message ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'ota_sync_feeds';

        $feed     = $wpdb->get_row( $wpdb->prepare( "SELECT failure_count FROM {$table} WHERE id = %d", $feed_id ) );
        $failures = $feed ? (int) $feed->failure_count + 1 : 1;

        $data = [
            'last_sync'     => current_time( 'mysql' ),
            'last_status'   => 'error',
            'last_message'  => $message,
            'failure_count' => $failures,
        ];

        if ( $failures >= 5 ) {
            $data['enabled'] = 0;
        }

        $wpdb->update( $table, $data, [ 'id' => $feed_id ] );
    }
}
