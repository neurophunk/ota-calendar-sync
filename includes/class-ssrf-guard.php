<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class OTA_SSRF_Guard {

    public function is_safe_url( string $url ): bool {
        if ( empty( $url ) ) return false;

        $parsed = parse_url( $url );
        if ( ! $parsed || empty( $parsed['scheme'] ) || empty( $parsed['host'] ) ) return false;
        if ( strtolower( $parsed['scheme'] ) !== 'https' ) return false;

        $host = strtolower( $parsed['host'] );
        if ( $host === 'localhost' ) return false;

        if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
            return ! $this->is_private_ip( $host );
        }

        // DNS rebinding protection: resolve and check
        $resolved = @gethostbynamel( $host );
        if ( ! $resolved ) return true; // Can't resolve at save-time — allow, check at fetch

        foreach ( $resolved as $ip ) {
            if ( $this->is_private_ip( $ip ) ) return false;
        }

        return true;
    }

    public function is_private_ip( string $ip ): bool {
        if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) return false;
        return filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false;
    }
}
