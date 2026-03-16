<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Bookly Bridge — creates/deletes "ghost" appointments to block time slots.
 * Bookly Free has no Block entity, so we insert directly into bookly_appointments.
 * A ghost appointment with internal_note 'OTA_SYNC_BLOCK' blocks the slot without
 * being visible to customers (no CustomerAppointment row = no customer-facing booking).
 */
class OTA_Bookly_Bridge {

    const NOTE_PREFIX = 'OTA_SYNC_BLOCK:';

    public function is_available(): bool {
        global $wpdb;
        return (bool) $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}bookly_appointments'" );
    }

    /**
     * Create a blocking appointment. Returns bookly_appointments.id or null.
     */
    public function create_block( int $staff_id, int $service_id, string $start, string $end, string $uid ): ?int {
        if ( ! $this->is_available() ) return null;

        global $wpdb;
        $result = $wpdb->insert( $wpdb->prefix . 'bookly_appointments', [
            'staff_id'      => $staff_id,
            'service_id'    => $service_id,
            'start_date'    => $start,
            'end_date'      => $end,
            'internal_note' => self::NOTE_PREFIX . $uid,
            'created_from'  => 'ota-sync',
            'created_at'    => current_time( 'mysql' ),
            'updated_at'    => current_time( 'mysql' ),
        ] );

        return $result ? (int) $wpdb->insert_id : null;
    }

    /**
     * Delete a blocking appointment by its ID.
     * If not found, returns true (already gone = OK).
     */
    public function delete_block( int $appt_id ): bool {
        if ( ! $this->is_available() || ! $appt_id ) return true;

        global $wpdb;
        $note = $wpdb->get_var( $wpdb->prepare(
            "SELECT internal_note FROM {$wpdb->prefix}bookly_appointments WHERE id = %d",
            $appt_id
        ) );

        // Safety: only delete rows we created
        if ( $note === null ) return true; // Already deleted
        if ( strpos( $note, self::NOTE_PREFIX ) !== 0 ) return false; // Not our block

        return (bool) $wpdb->delete( $wpdb->prefix . 'bookly_appointments', [ 'id' => $appt_id ] );
    }

    /**
     * Ensure an "OTA Sync Block" service exists in Bookly. Returns its ID.
     * Creates it automatically if missing.
     */
    public function get_or_create_block_service(): int {
        global $wpdb;
        $table = $wpdb->prefix . 'bookly_services';

        $id = $wpdb->get_var( "SELECT id FROM {$table} WHERE title = 'OTA Sync Block' LIMIT 1" );
        if ( $id ) return (int) $id;

        $wpdb->insert( $table, [
            'title'      => 'OTA Sync Block',
            'duration'   => 3600,
            'price'      => 0,
            'capacity_min' => 1,
            'capacity_max' => 1,
            'color'      => '#cccccc',
            'visibility' => 'private',
            'created_at' => current_time( 'mysql' ),
            'updated_at' => current_time( 'mysql' ),
        ] );

        return (int) $wpdb->insert_id;
    }

    public function get_services(): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT id, title FROM {$wpdb->prefix}bookly_services WHERE title != 'OTA Sync Block' ORDER BY title"
        );
        if ( ! $rows ) return [];
        return array_map( fn($r) => [ 'id' => (int) $r->id, 'name' => $r->title ], $rows );
    }

    public function get_staff(): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT id, full_name FROM {$wpdb->prefix}bookly_staff ORDER BY full_name"
        );
        if ( ! $rows ) return [];
        return array_map( fn($r) => [ 'id' => (int) $r->id, 'name' => $r->full_name ], $rows );
    }
}
