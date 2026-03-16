<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class OTA_iCal_Parser {

    /**
     * Parse iCal string. Returns events within today → today+365 days.
     * Each event: ['uid' => string, 'start' => DateTime, 'end' => DateTime]
     */
    public function parse( string $ical ): array {
        if ( empty( $ical ) || strpos( $ical, 'BEGIN:VCALENDAR' ) === false ) {
            return [];
        }

        $events = [];
        $now    = new DateTime( 'today', new DateTimeZone( 'UTC' ) );
        $limit  = ( new DateTime( 'today', new DateTimeZone( 'UTC' ) ) )->modify( '+365 days' );

        preg_match_all( '/BEGIN:VEVENT(.*?)END:VEVENT/s', $ical, $matches );

        foreach ( $matches[1] as $block ) {
            $event = $this->parse_vevent( $block );
            if ( $event === null ) continue;
            if ( $event['start'] < $now || $event['start'] > $limit ) continue;
            $events[] = $event;
        }

        return $events;
    }

    private function parse_vevent( string $block ): ?array {
        $uid   = $this->get_value( $block, 'UID' );
        $start = $this->parse_dt( $block, 'DTSTART' );
        $end   = $this->parse_dt( $block, 'DTEND' );

        if ( ! $uid || ! $start ) return null;

        if ( ! $end ) {
            $end = clone $start;
            $end->modify( '+1 hour' );
        }

        return [ 'uid' => $uid, 'start' => $start, 'end' => $end ];
    }

    private function get_value( string $block, string $key ): ?string {
        if ( preg_match( '/^' . $key . '[;:](.+)$/m', $block, $m ) ) {
            return trim( $m[1] );
        }
        return null;
    }

    private function parse_dt( string $block, string $key ): ?DateTime {
        if ( ! preg_match( '/^' . $key . '(?:;[^:]+)?:(.+)$/m', $block, $m ) ) {
            return null;
        }
        $raw = trim( $m[1] );

        try {
            if ( preg_match( '/^\d{8}$/', $raw ) ) {
                $dt = DateTime::createFromFormat( 'Ymd', $raw, new DateTimeZone( 'UTC' ) );
                $dt->setTime( 0, 0, 0 );
                return $dt;
            } elseif ( preg_match( '/^\d{8}T\d{6}Z$/', $raw ) ) {
                return DateTime::createFromFormat( 'Ymd\THis\Z', $raw, new DateTimeZone( 'UTC' ) );
            } elseif ( preg_match( '/^\d{8}T\d{6}$/', $raw ) ) {
                return DateTime::createFromFormat( 'Ymd\THis', $raw, new DateTimeZone( 'UTC' ) );
            }
        } catch ( \Exception $e ) {
            return null;
        }

        return null;
    }
}
