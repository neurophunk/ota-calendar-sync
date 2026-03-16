<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class OTA_Sync_Feed_Manager {

    const CRON_HOOK     = 'ota_sync_run';
    const CRON_INTERVAL = 'ota_sync_30min';
    const VALID_OTAS    = [ 'getyourguide', 'viator', 'gowithguide' ];

    private OTA_iCal_Parser   $parser;
    private OTA_Bookly_Bridge $bridge;
    private OTA_Sync_Logger   $logger;
    private OTA_SSRF_Guard    $guard;

    public function __construct() {
        $this->parser = new OTA_iCal_Parser();
        $this->bridge = new OTA_Bookly_Bridge();
        $this->logger = new OTA_Sync_Logger();
        $this->guard  = new OTA_SSRF_Guard();
    }

    public function init(): void {
        add_action( self::CRON_HOOK, [ $this, 'run_sync' ] );
        add_filter( 'cron_schedules', [ $this, 'add_cron_interval' ] );
    }

    public function add_cron_interval( array $schedules ): array {
        $schedules[ self::CRON_INTERVAL ] = [
            'interval' => 1800,
            'display'  => __( 'Every 30 minutes', 'ota-calendar-sync' ),
        ];
        return $schedules;
    }

    public static function schedule_cron(): void {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), self::CRON_INTERVAL, self::CRON_HOOK );
        }
    }

    public static function unschedule_cron(): void {
        $ts = wp_next_scheduled( self::CRON_HOOK );
        if ( $ts ) wp_unschedule_event( $ts, self::CRON_HOOK );
    }

    public function run_sync(): void {
        global $wpdb;
        $feeds = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}ota_sync_feeds WHERE enabled = 1" );
        foreach ( $feeds as $feed ) {
            $this->sync_feed( $feed );
        }
    }

    public function sync_feed( object $feed ): void {
        if ( ! $this->guard->is_safe_url( $feed->ical_url ) ) {
            $this->logger->log_error( $feed->id, 'Érvénytelen vagy nem biztonságos URL' );
            return;
        }

        $response = wp_remote_get( $feed->ical_url, [ 'timeout' => 15 ] );

        if ( is_wp_error( $response ) ) {
            $this->logger->log_error( (int) $feed->id, $response->get_error_message() );
            return;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            $this->logger->log_error( (int) $feed->id, "HTTP hiba: {$code}" );
            return;
        }

        $body   = wp_remote_retrieve_body( $response );
        $events = $this->parser->parse( $body );

        if ( empty( $events ) && strpos( $body, 'BEGIN:VCALENDAR' ) === false ) {
            $this->logger->log_error( (int) $feed->id, 'Érvénytelen iCal válasz' );
            return;
        }

        $block_count = $this->process_events( (int) $feed->id, $events, (int) $feed->bookly_staff_id, (int) $feed->bookly_service_id );
        $this->logger->log_success( (int) $feed->id, $block_count );
    }

    public function process_events( int $feed_id, array $events, int $staff_id, int $service_id = 0 ): int {
        global $wpdb;
        $blocks_table = $wpdb->prefix . 'ota_sync_blocks';

        // Use OTA Sync Block service if no service specified
        if ( ! $service_id ) {
            $service_id = $this->bridge->get_or_create_block_service();
        }

        $existing  = $this->get_existing_blocks( $feed_id );
        $seen_uids = [];
        $count     = 0;

        foreach ( $events as $event ) {
            $uid   = $event['uid'];
            $start = $event['start']->format( 'Y-m-d H:i:s' );
            $end   = $event['end']->format( 'Y-m-d H:i:s' );

            $seen_uids[] = $uid;

            if ( isset( $existing[ $uid ] ) ) {
                $row = $existing[ $uid ];

                if ( $row->start_datetime === $start && $row->end_datetime === $end ) {
                    // Unchanged — just clear marked_missing_at if set
                    if ( $row->marked_missing_at ) {
                        $wpdb->update( $blocks_table, [ 'marked_missing_at' => null ], [ 'id' => $row->id ] );
                    }
                    continue;
                }

                // Changed time — replace block
                $this->bridge->delete_block( (int) $row->bookly_appt_id );
                $new_appt_id = $this->bridge->create_block( $staff_id, $service_id, $start, $end, $uid );
                $wpdb->update( $blocks_table, [
                    'start_datetime'    => $start,
                    'end_datetime'      => $end,
                    'bookly_appt_id'    => $new_appt_id,
                    'synced_at'         => current_time( 'mysql' ),
                    'marked_missing_at' => null,
                ], [ 'id' => $row->id ] );
                $count++;

            } else {
                // New event
                $appt_id = $this->bridge->create_block( $staff_id, $service_id, $start, $end, $uid );
                $wpdb->insert( $blocks_table, [
                    'feed_id'        => $feed_id,
                    'event_uid'      => $uid,
                    'start_datetime' => $start,
                    'end_datetime'   => $end,
                    'bookly_appt_id' => $appt_id,
                    'synced_at'      => current_time( 'mysql' ),
                ] );
                $count++;
            }
        }

        $this->handle_missing_uids( $feed_id, $existing, $seen_uids );
        return $count;
    }

    private function get_existing_blocks( int $feed_id ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ota_sync_blocks WHERE feed_id = %d",
            $feed_id
        ) );
        $indexed = [];
        foreach ( $rows as $row ) {
            $indexed[ $row->event_uid ] = $row;
        }
        return $indexed;
    }

    private function handle_missing_uids( int $feed_id, array $existing, array $seen_uids ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'ota_sync_blocks';
        $grace = 7 * 86400; // 7 days in seconds

        foreach ( $existing as $uid => $row ) {
            if ( in_array( $uid, $seen_uids, true ) ) continue;

            if ( ! $row->marked_missing_at ) {
                $wpdb->update( $table, [ 'marked_missing_at' => current_time( 'mysql' ) ], [ 'id' => $row->id ] );
            } elseif ( ( time() - strtotime( $row->marked_missing_at ) ) > $grace ) {
                $this->bridge->delete_block( (int) $row->bookly_appt_id );
                $wpdb->delete( $table, [ 'id' => $row->id ] );
            }
        }
    }

    public function validate_feed_data( array $data ): array {
        if ( empty( $data['ota_name'] ) || ! in_array( $data['ota_name'], self::VALID_OTAS, true ) ) {
            return [ 'valid' => false, 'error' => __( 'Érvénytelen OTA név.', 'ota-calendar-sync' ) ];
        }

        $url = trim( $data['ical_url'] ?? '' );
        if ( ! $this->guard->is_safe_url( $url ) ) {
            return [ 'valid' => false, 'error' => __( 'Az iCal URL-nek érvényes HTTPS-re kell mutatnia (nyilvános szerver).', 'ota-calendar-sync' ) ];
        }

        if ( empty( $data['bookly_staff_id'] ) ) {
            return [ 'valid' => false, 'error' => __( 'Munkatárs megadása kötelező.', 'ota-calendar-sync' ) ];
        }

        return [ 'valid' => true ];
    }

    public function save_feed( array $data ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'ota_sync_feeds';

        $row = [
            'ota_name'          => sanitize_text_field( $data['ota_name'] ),
            'label'             => sanitize_text_field( $data['label'] ?? '' ),
            'ical_url'          => esc_url_raw( $data['ical_url'] ),
            'bookly_service_id' => absint( $data['bookly_service_id'] ?? 0 ),
            'bookly_staff_id'   => absint( $data['bookly_staff_id'] ),
            'enabled'           => 1,
        ];

        if ( ! empty( $data['id'] ) ) {
            return (bool) $wpdb->update( $table, $row, [ 'id' => absint( $data['id'] ) ] );
        }

        $row['created_at']  = current_time( 'mysql' );
        $row['last_status'] = 'pending';
        return (bool) $wpdb->insert( $table, $row );
    }

    public function delete_feed( int $id ): void {
        global $wpdb;
        $blocks = $wpdb->get_results( $wpdb->prepare(
            "SELECT bookly_appt_id FROM {$wpdb->prefix}ota_sync_blocks WHERE feed_id = %d",
            $id
        ) );
        foreach ( $blocks as $block ) {
            $this->bridge->delete_block( (int) $block->bookly_appt_id );
        }
        $wpdb->delete( $wpdb->prefix . 'ota_sync_blocks', [ 'feed_id' => $id ] );
        $wpdb->delete( $wpdb->prefix . 'ota_sync_feeds',  [ 'id' => $id ] );
    }

    public function get_feeds(): array {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}ota_sync_feeds ORDER BY created_at DESC"
        ) ?: [];
    }
}
