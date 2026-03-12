# OTA Calendar Sync for Bookly — Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a WordPress plugin that pulls iCal feeds from GetYourGuide, Viator, and GoWithGuide and blocks the corresponding time slots in Bookly every 30 minutes, preventing double bookings.

**Architecture:** A standalone WordPress plugin with 5 PHP classes. The iCal parser reads RFC 5545 feeds. The Bookly Bridge writes blocked time slots to Bookly. The Feed Manager orchestrates sync via WP-Cron. The Admin Page provides settings UI and sync log.

**Tech Stack:** PHP 7.4+, WordPress 5.8+, Bookly Free, PHPUnit + Brain\Monkey for tests, `dbDelta()` for DB migrations.

---

## File Map

| File | Role |
|---|---|
| `ota-calendar-sync.php` | Plugin bootstrap, hooks, activation/deactivation |
| `includes/class-ical-parser.php` | RFC 5545 iCal parser — pure PHP, no WP dependencies |
| `includes/class-bookly-bridge.php` | Create/update/delete Bookly blocked time slots |
| `includes/class-feed-manager.php` | Feed CRUD, WP-Cron scheduling, sync orchestration |
| `includes/class-sync-logger.php` | Per-feed log writing (updates wp_ota_sync_feeds) |
| `includes/class-ssrf-guard.php` | URL validation + DNS rebinding protection |
| `admin/class-admin-page.php` | Admin menu, settings page, AJAX handlers |
| `admin/views/feeds.php` | Feed list + add/edit form HTML |
| `admin/views/log.php` | Sync log table HTML |
| `languages/ota-calendar-sync-hu_HU.po` | Hungarian translations |
| `tests/test-ical-parser.php` | PHPUnit tests for iCal parser |
| `tests/test-ssrf-guard.php` | PHPUnit tests for SSRF guard |
| `tests/test-feed-manager.php` | PHPUnit tests for sync logic |
| `tests/bootstrap.php` | Test bootstrap (Brain\Monkey setup) |
| `composer.json` | PHPUnit + Brain\Monkey dependencies |

---

## Chunk 1: Foundation — Plugin Scaffold + Database

### Task 1: Composer + test bootstrap

**Files:**
- Create: `composer.json`
- Create: `tests/bootstrap.php`

- [ ] **Step 1: Create composer.json**

```json
{
  "name": "neurophunk/ota-calendar-sync",
  "require-dev": {
    "phpunit/phpunit": "^9.0",
    "brain/monkey": "^2.6",
    "mockery/mockery": "^1.5"
  },
  "autoload": {
    "psr-4": {
      "OtaSync\\": "includes/"
    }
  },
  "autoload-dev": {
    "psr-4": {
      "OtaSync\\Tests\\": "tests/"
    }
  }
}
```

- [ ] **Step 2: Create tests/bootstrap.php**

```php
<?php
require_once dirname(__DIR__) . '/vendor/autoload.php';

use Brain\Monkey;

// Stub WordPress functions used by plugin classes
function add_action() {}
function add_filter() {}
function register_activation_hook() {}
function register_deactivation_hook() {}
function plugin_dir_path($f) { return dirname($f) . '/'; }
function plugin_basename($f) { return basename($f); }
function get_option($key, $default = false) { return $default; }
function update_option($key, $value) {}
function current_time($type) { return date('Y-m-d H:i:s'); }
function wp_remote_get($url, $args = []) { return ['body' => '', 'response' => ['code' => 200]]; }
function is_wp_error($thing) { return false; }
function wp_remote_retrieve_body($response) { return $response['body'] ?? ''; }
function wp_remote_retrieve_response_code($response) { return $response['response']['code'] ?? 0; }
function sanitize_text_field($str) { return trim(strip_tags($str)); }
function absint($v) { return abs((int)$v); }
function esc_html($str) { return htmlspecialchars($str, ENT_QUOTES); }
function esc_attr($str) { return htmlspecialchars($str, ENT_QUOTES); }
function esc_url($url) { return $url; }
function __($str, $domain = '') { return $str; }
function _e($str, $domain = '') { echo $str; }
function wp_nonce_field($action, $name) { echo '<input type="hidden" name="' . $name . '" value="test_nonce">'; }
function wp_verify_nonce($nonce, $action) { return true; }
function check_admin_referer($action) { return true; }
function current_user_can($cap) { return true; }
function admin_url($path = '') { return 'http://example.com/wp-admin/' . $path; }
function wp_redirect($url) {}
function add_menu_page() {}
function add_submenu_page() {}
function settings_errors() {}
function wp_die($msg = '') { throw new \RuntimeException($msg); }
function defined($c) { return false; }

global $wpdb;
$wpdb = new class {
    public $prefix = 'wp_';
    public $last_error = '';
    public $insert_id = 1;
    public function get_results($query, $output = OBJECT) { return []; }
    public function get_row($query, $output = OBJECT, $y = 0) { return null; }
    public function get_var($query) { return null; }
    public function insert($table, $data) { return 1; }
    public function update($table, $data, $where) { return 1; }
    public function delete($table, $where) { return 1; }
    public function prepare($query, ...$args) { return vsprintf(str_replace('%s', "'%s'", str_replace('%d', '%d', $query)), $args); }
    public function query($query) { return 1; }
};
```

- [ ] **Step 3: Install dependencies**

```bash
cd /path/to/ota-calendar-sync && composer install
```

Expected: `vendor/` directory created, PHPUnit + Brain\Monkey installed.

- [ ] **Step 4: Verify PHPUnit runs**

```bash
./vendor/bin/phpunit --version
```

Expected: `PHPUnit 9.x.x`

- [ ] **Step 5: Commit**

```bash
git add composer.json tests/bootstrap.php composer.lock
git commit -m "chore: add PHPUnit + Brain\\Monkey test setup"
```

---

### Task 2: Plugin main file + DB schema

**Files:**
- Create: `ota-calendar-sync.php`

- [ ] **Step 1: Create main plugin file**

```php
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

define( 'OTA_SYNC_VERSION', '1.0.0' );
define( 'OTA_SYNC_DB_VERSION', '1.0' );
define( 'OTA_SYNC_PATH', plugin_dir_path( __FILE__ ) );
define( 'OTA_SYNC_FILE', __FILE__ );

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

    $feeds_table = $wpdb->prefix . 'ota_sync_feeds';
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
        bookly_block_id      INT NULL,
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
    $admin = new OTA_Sync_Admin_Page();
    $admin->init();

    $manager = new OTA_Sync_Feed_Manager();
    $manager->init();
} );
```

- [ ] **Step 2: Commit**

```bash
git add ota-calendar-sync.php
git commit -m "feat: add plugin bootstrap and DB schema"
```

---

## Chunk 2: iCal Parser

### Task 3: iCal Parser — failing tests first

**Files:**
- Create: `tests/test-ical-parser.php`
- Create: `includes/class-ical-parser.php`

- [ ] **Step 1: Write failing tests**

```php
<?php
// tests/test-ical-parser.php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-ical-parser.php';

use PHPUnit\Framework\TestCase;

class Test_iCal_Parser extends TestCase {

    private string $sample_ical = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:abc-123@getyourguide.com\r\nDTSTART:20260315T090000Z\r\nDTEND:20260315T120000Z\r\nSUMMARY:Budapest City Tour\r\nEND:VEVENT\r\nBEGIN:VEVENT\r\nUID:def-456@getyourguide.com\r\nDTSTART:20260320T140000Z\r\nDTEND:20260320T170000Z\r\nSUMMARY:Airport Transfer\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

    public function test_parses_two_events(): void {
        $parser = new OTA_iCal_Parser();
        $events = $parser->parse( $this->sample_ical );
        $this->assertCount( 2, $events );
    }

    public function test_event_has_uid(): void {
        $parser = new OTA_iCal_Parser();
        $events = $parser->parse( $this->sample_ical );
        $this->assertEquals( 'abc-123@getyourguide.com', $events[0]['uid'] );
    }

    public function test_event_has_start_end(): void {
        $parser = new OTA_iCal_Parser();
        $events = $parser->parse( $this->sample_ical );
        $this->assertArrayHasKey( 'start', $events[0] );
        $this->assertArrayHasKey( 'end', $events[0] );
        $this->assertInstanceOf( DateTime::class, $events[0]['start'] );
    }

    public function test_filters_past_events(): void {
        $past_ical = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:past-001\r\nDTSTART:20200101T090000Z\r\nDTEND:20200101T120000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $parser = new OTA_iCal_Parser();
        $events = $parser->parse( $past_ical );
        $this->assertCount( 0, $events );
    }

    public function test_filters_events_beyond_one_year(): void {
        $far_future = date('Ymd\THis\Z', strtotime('+2 years'));
        $ical = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:future-001\r\nDTSTART:{$far_future}\r\nDTEND:{$far_future}\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $parser = new OTA_iCal_Parser();
        $events = $parser->parse( $ical );
        $this->assertCount( 0, $events );
    }

    public function test_returns_empty_array_on_invalid_input(): void {
        $parser = new OTA_iCal_Parser();
        $this->assertEquals( [], $parser->parse( '' ) );
        $this->assertEquals( [], $parser->parse( 'not ical content' ) );
    }

    public function test_handles_date_only_events(): void {
        $ical = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:allday-001\r\nDTSTART;VALUE=DATE:" . date('Ymd', strtotime('+5 days')) . "\r\nDTEND;VALUE=DATE:" . date('Ymd', strtotime('+6 days')) . "\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $parser = new OTA_iCal_Parser();
        $events = $parser->parse( $ical );
        $this->assertCount( 1, $events );
        $this->assertInstanceOf( DateTime::class, $events[0]['start'] );
    }
}
```

- [ ] **Step 2: Run tests — verify they fail**

```bash
./vendor/bin/phpunit tests/test-ical-parser.php --bootstrap tests/bootstrap.php
```

Expected: FAIL — `OTA_iCal_Parser not found`

- [ ] **Step 3: Implement iCal Parser**

```php
<?php
// includes/class-ical-parser.php

if ( ! defined( 'ABSPATH' ) ) exit;

class OTA_iCal_Parser {

    /**
     * Parse iCal string and return VEVENT array filtered to today → today+365 days.
     *
     * Each event: ['uid' => string, 'start' => DateTime, 'end' => DateTime]
     */
    public function parse( string $ical ): array {
        if ( empty( $ical ) || strpos( $ical, 'BEGIN:VCALENDAR' ) === false ) {
            return [];
        }

        $events = [];
        $now    = new DateTime( 'today', new DateTimeZone( 'UTC' ) );
        $limit  = ( new DateTime( 'today', new DateTimeZone( 'UTC' ) ) )->modify( '+365 days' );

        // Split into VEVENT blocks
        preg_match_all( '/BEGIN:VEVENT(.*?)END:VEVENT/s', $ical, $matches );

        foreach ( $matches[1] as $block ) {
            $event = $this->parse_vevent( $block );
            if ( $event === null ) continue;

            // Filter: today ≤ start ≤ today+365
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

        // If no DTEND, default to DTSTART + 1 hour
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
        // Match DTSTART or DTSTART;VALUE=DATE or DTSTART;TZID=...
        if ( ! preg_match( '/^' . $key . '(?:;[^:]+)?:(.+)$/m', $block, $m ) ) {
            return null;
        }

        $raw = trim( $m[1] );

        try {
            if ( preg_match( '/^\d{8}$/', $raw ) ) {
                // DATE only: 20260315
                $dt = DateTime::createFromFormat( 'Ymd', $raw, new DateTimeZone( 'UTC' ) );
                $dt->setTime( 0, 0, 0 );
                return $dt;
            } elseif ( preg_match( '/^\d{8}T\d{6}Z$/', $raw ) ) {
                // UTC datetime: 20260315T090000Z
                return DateTime::createFromFormat( 'Ymd\THis\Z', $raw, new DateTimeZone( 'UTC' ) );
            } elseif ( preg_match( '/^\d{8}T\d{6}$/', $raw ) ) {
                // Floating datetime: 20260315T090000
                return DateTime::createFromFormat( 'Ymd\THis', $raw, new DateTimeZone( 'UTC' ) );
            }
        } catch ( \Exception $e ) {
            return null;
        }

        return null;
    }
}
```

- [ ] **Step 4: Run tests — verify they pass**

```bash
./vendor/bin/phpunit tests/test-ical-parser.php --bootstrap tests/bootstrap.php
```

Expected: All 7 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/class-ical-parser.php tests/test-ical-parser.php
git commit -m "feat: add iCal parser with VEVENT extraction and date filtering"
```

---

## Chunk 3: SSRF Guard

### Task 4: URL validator with SSRF protection

**Files:**
- Create: `includes/class-ssrf-guard.php`
- Create: `tests/test-ssrf-guard.php`

- [ ] **Step 1: Write failing tests**

```php
<?php
// tests/test-ssrf-guard.php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-ssrf-guard.php';

use PHPUnit\Framework\TestCase;

class Test_SSRF_Guard extends TestCase {

    public function test_allows_valid_https_url(): void {
        $guard = new OTA_SSRF_Guard();
        $this->assertTrue( $guard->is_safe_url( 'https://www.getyourguide.com/ical/feed.ics' ) );
    }

    public function test_rejects_http(): void {
        $guard = new OTA_SSRF_Guard();
        $this->assertFalse( $guard->is_safe_url( 'http://www.getyourguide.com/ical/feed.ics' ) );
    }

    public function test_rejects_localhost(): void {
        $guard = new OTA_SSRF_Guard();
        $this->assertFalse( $guard->is_safe_url( 'https://localhost/anything' ) );
    }

    public function test_rejects_private_ip_10(): void {
        $guard = new OTA_SSRF_Guard();
        $this->assertFalse( $guard->is_safe_url( 'https://10.0.0.1/feed.ics' ) );
    }

    public function test_rejects_private_ip_192_168(): void {
        $guard = new OTA_SSRF_Guard();
        $this->assertFalse( $guard->is_safe_url( 'https://192.168.1.1/feed.ics' ) );
    }

    public function test_rejects_private_ip_172_16(): void {
        $guard = new OTA_SSRF_Guard();
        $this->assertFalse( $guard->is_safe_url( 'https://172.16.0.1/feed.ics' ) );
    }

    public function test_rejects_127_0_0_1(): void {
        $guard = new OTA_SSRF_Guard();
        $this->assertFalse( $guard->is_safe_url( 'https://127.0.0.1/feed.ics' ) );
    }

    public function test_rejects_empty(): void {
        $guard = new OTA_SSRF_Guard();
        $this->assertFalse( $guard->is_safe_url( '' ) );
    }

    public function test_is_private_ip_detects_private(): void {
        $guard = new OTA_SSRF_Guard();
        $this->assertTrue( $guard->is_private_ip( '10.0.0.1' ) );
        $this->assertTrue( $guard->is_private_ip( '192.168.100.200' ) );
        $this->assertTrue( $guard->is_private_ip( '172.20.0.1' ) );
        $this->assertTrue( $guard->is_private_ip( '127.0.0.1' ) );
        $this->assertTrue( $guard->is_private_ip( '::1' ) );
    }

    public function test_is_private_ip_allows_public(): void {
        $guard = new OTA_SSRF_Guard();
        $this->assertFalse( $guard->is_private_ip( '8.8.8.8' ) );
        $this->assertFalse( $guard->is_private_ip( '104.16.132.229' ) );
    }
}
```

- [ ] **Step 2: Run — verify fails**

```bash
./vendor/bin/phpunit tests/test-ssrf-guard.php --bootstrap tests/bootstrap.php
```

Expected: FAIL — `OTA_SSRF_Guard not found`

- [ ] **Step 3: Implement SSRF Guard**

```php
<?php
// includes/class-ssrf-guard.php

if ( ! defined( 'ABSPATH' ) ) exit;

class OTA_SSRF_Guard {

    /**
     * Returns true if the URL is safe to fetch (HTTPS, public IP).
     */
    public function is_safe_url( string $url ): bool {
        if ( empty( $url ) ) return false;

        $parsed = parse_url( $url );
        if ( ! $parsed || empty( $parsed['scheme'] ) || empty( $parsed['host'] ) ) return false;
        if ( strtolower( $parsed['scheme'] ) !== 'https' ) return false;

        $host = strtolower( $parsed['host'] );

        // Reject localhost
        if ( $host === 'localhost' ) return false;

        // If host is an IP, check directly
        if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
            return ! $this->is_private_ip( $host );
        }

        // Hostname — check resolved IPs (DNS rebinding protection)
        $resolved = gethostbynamel( $host );
        if ( ! $resolved ) return false; // Cannot resolve = reject

        foreach ( $resolved as $ip ) {
            if ( $this->is_private_ip( $ip ) ) return false;
        }

        return true;
    }

    /**
     * Returns true if the IP is in a private/loopback range.
     */
    public function is_private_ip( string $ip ): bool {
        if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) return false;

        return filter_var( $ip, FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }
}
```

- [ ] **Step 4: Run — verify passes**

```bash
./vendor/bin/phpunit tests/test-ssrf-guard.php --bootstrap tests/bootstrap.php
```

Expected: All 11 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/class-ssrf-guard.php tests/test-ssrf-guard.php
git commit -m "feat: add SSRF guard for iCal URL validation"
```

---

## Chunk 4: Sync Logger + Bookly Bridge

### Task 5: Sync Logger

**Files:**
- Create: `includes/class-sync-logger.php`

- [ ] **Step 1: Implement Sync Logger**

```php
<?php
// includes/class-sync-logger.php

if ( ! defined( 'ABSPATH' ) ) exit;

class OTA_Sync_Logger {

    public function log_success( int $feed_id, int $block_count ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'ota_sync_feeds';
        $wpdb->update( $table, [
            'last_sync'     => current_time( 'mysql' ),
            'last_status'   => 'ok',
            'last_message'  => sprintf( 'Synced %d block(s)', $block_count ),
            'failure_count' => 0,
        ], [ 'id' => $feed_id ] );
    }

    public function log_error( int $feed_id, string $message ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'ota_sync_feeds';

        $feed = $wpdb->get_row( $wpdb->prepare( "SELECT failure_count FROM {$table} WHERE id = %d", $feed_id ) );
        $failures = $feed ? (int) $feed->failure_count + 1 : 1;

        $data = [
            'last_sync'     => current_time( 'mysql' ),
            'last_status'   => 'error',
            'last_message'  => $message,
            'failure_count' => $failures,
        ];

        // Auto-disable after 5 consecutive failures
        if ( $failures >= 5 ) {
            $data['enabled'] = 0;
        }

        $wpdb->update( $table, $data, [ 'id' => $feed_id ] );
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add includes/class-sync-logger.php
git commit -m "feat: add sync logger with auto-disable after 5 failures"
```

---

### Task 6: Bookly Bridge

**Files:**
- Create: `includes/class-bookly-bridge.php`

The exact Bookly internal API must be confirmed by reading the installed Bookly source. The bridge is designed to be swapped if the API differs.

- [ ] **Step 1: Check if Bookly is installed and locate its block entity**

After Bookly is installed on the WordPress site, run:
```bash
find /path/to/wordpress/wp-content/plugins/bookly-responsive-appointment-booking-tool -name "*.php" | xargs grep -l "class.*Block" 2>/dev/null | head -5
```

Look for `class Block extends Lib\Base\Entity` or similar.

- [ ] **Step 2: Implement Bookly Bridge**

```php
<?php
// includes/class-bookly-bridge.php

if ( ! defined( 'ABSPATH' ) ) exit;

class OTA_Bookly_Bridge {

    /**
     * Returns true if Bookly plugin is active and its classes are available.
     */
    public function is_available(): bool {
        return class_exists( '\Bookly\Lib\Plugin' ) || class_exists( '\BooklyPro\Lib\Plugin' );
    }

    /**
     * Create a blocked time slot in Bookly.
     * Returns the Bookly block ID on success, or null on failure.
     */
    public function create_block( int $staff_id, string $start, string $end, string $note ): ?int {
        if ( ! $this->is_available() ) return null;

        try {
            // Bookly Free uses staff_schedule_items or bookly_staff_schedule_items table
            // Bookly blocks staff availability via the "Blocked Times" mechanism
            // Try the Block entity first (Bookly Pro), fall back to direct DB insert
            if ( class_exists( '\Bookly\Lib\Entities\Block' ) ) {
                $block = new \Bookly\Lib\Entities\Block();
                $block->setStaffId( $staff_id )
                      ->setStartDate( $start )
                      ->setEndDate( $end )
                      ->setNotes( $note )
                      ->save();
                return $block->getId();
            }

            // Fallback: direct insert into bookly_staff_schedule_items
            global $wpdb;
            $result = $wpdb->insert( $wpdb->prefix . 'bookly_staff_schedule_items', [
                'staff_id'      => $staff_id,
                'start_time'    => date( 'H:i:s', strtotime( $start ) ),
                'end_time'      => date( 'H:i:s', strtotime( $end ) ),
                'day_index'     => (int) date( 'w', strtotime( $start ) ),
                'available'     => 0,
            ] );

            return $result ? $wpdb->insert_id : null;
        } catch ( \Exception $e ) {
            error_log( 'OTA Sync - Bookly block creation failed: ' . $e->getMessage() );
            return null;
        }
    }

    /**
     * Delete a Bookly block by ID.
     * Returns true on success.
     */
    public function delete_block( int $block_id ): bool {
        if ( ! $this->is_available() ) return false;

        try {
            if ( class_exists( '\Bookly\Lib\Entities\Block' ) ) {
                $block = \Bookly\Lib\Entities\Block::find( $block_id );
                if ( $block ) {
                    $block->delete();
                    return true;
                }
                return false; // Block not found — already deleted
            }

            // Fallback: direct DB delete
            global $wpdb;
            return (bool) $wpdb->delete( $wpdb->prefix . 'bookly_staff_schedule_items', [ 'id' => $block_id ] );
        } catch ( \Exception $e ) {
            error_log( 'OTA Sync - Bookly block deletion failed: ' . $e->getMessage() );
            return false;
        }
    }

    /**
     * Get all Bookly services.
     * Returns array of ['id' => int, 'name' => string].
     */
    public function get_services(): array {
        global $wpdb;
        $rows = $wpdb->get_results( "SELECT id, title FROM {$wpdb->prefix}bookly_services ORDER BY title" );
        if ( ! $rows ) return [];
        return array_map( fn($r) => [ 'id' => (int)$r->id, 'name' => $r->title ], $rows );
    }

    /**
     * Get all Bookly staff.
     * Returns array of ['id' => int, 'name' => string].
     */
    public function get_staff(): array {
        global $wpdb;
        $rows = $wpdb->get_results( "SELECT id, full_name FROM {$wpdb->prefix}bookly_staff ORDER BY full_name" );
        if ( ! $rows ) return [];
        return array_map( fn($r) => [ 'id' => (int)$r->id, 'name' => $r->full_name ], $rows );
    }

    /**
     * Get staff for a specific service.
     */
    public function get_staff_for_service( int $service_id ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT s.id, s.full_name FROM {$wpdb->prefix}bookly_staff s
             INNER JOIN {$wpdb->prefix}bookly_staff_services ss ON s.id = ss.staff_id
             WHERE ss.service_id = %d ORDER BY s.full_name",
            $service_id
        ) );
        if ( ! $rows ) return [];
        return array_map( fn($r) => [ 'id' => (int)$r->id, 'name' => $r->full_name ], $rows );
    }
}
```

- [ ] **Step 3: Commit**

```bash
git add includes/class-bookly-bridge.php
git commit -m "feat: add Bookly bridge for blocked time slot management"
```

---

## Chunk 5: Feed Manager (Core Sync Logic)

### Task 7: Feed Manager with sync orchestration

**Files:**
- Create: `includes/class-feed-manager.php`
- Create: `tests/test-feed-manager.php`

- [ ] **Step 1: Write failing tests**

```php
<?php
// tests/test-feed-manager.php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-ical-parser.php';
require_once __DIR__ . '/../includes/class-ssrf-guard.php';
require_once __DIR__ . '/../includes/class-sync-logger.php';
require_once __DIR__ . '/../includes/class-bookly-bridge.php';
require_once __DIR__ . '/../includes/class-feed-manager.php';

use PHPUnit\Framework\TestCase;

class Test_Feed_Manager extends TestCase {

    public function test_validate_feed_data_rejects_http(): void {
        $manager = new OTA_Sync_Feed_Manager();
        $result  = $manager->validate_feed_data([
            'ota_name'          => 'getyourguide',
            'ical_url'          => 'http://example.com/feed.ics',
            'bookly_service_id' => 1,
            'bookly_staff_id'   => 1,
            'label'             => 'Test',
        ]);
        $this->assertFalse( $result['valid'] );
        $this->assertStringContainsString( 'HTTPS', $result['error'] );
    }

    public function test_validate_feed_data_rejects_unknown_ota(): void {
        $manager = new OTA_Sync_Feed_Manager();
        $result  = $manager->validate_feed_data([
            'ota_name'          => 'unknown_ota',
            'ical_url'          => 'https://example.com/feed.ics',
            'bookly_service_id' => 1,
            'bookly_staff_id'   => 1,
            'label'             => 'Test',
        ]);
        $this->assertFalse( $result['valid'] );
    }

    public function test_validate_feed_data_passes_valid_data(): void {
        $manager = new OTA_Sync_Feed_Manager();
        $result  = $manager->validate_feed_data([
            'ota_name'          => 'viator',
            'ical_url'          => 'https://www.viator.com/ical/feed.ics',
            'bookly_service_id' => 1,
            'bookly_staff_id'   => 1,
            'label'             => 'Viator Tour',
        ]);
        $this->assertTrue( $result['valid'] );
    }

    public function test_process_events_creates_new_blocks(): void {
        // Tests the logic of: new UID → create block
        $manager = $this->getMockBuilder( OTA_Sync_Feed_Manager::class )
                        ->onlyMethods(['get_existing_blocks', 'create_block', 'handle_missing_uids'])
                        ->getMock();

        $manager->method('get_existing_blocks')->willReturn([]);
        $manager->expects($this->once())->method('create_block');

        $event = [
            'uid'   => 'new-uid-001',
            'start' => new DateTime('+1 day'),
            'end'   => new DateTime('+1 day +2 hours'),
        ];

        $manager->process_events( 1, [ $event ] );
    }
}
```

- [ ] **Step 2: Run — verify fails**

```bash
./vendor/bin/phpunit tests/test-feed-manager.php --bootstrap tests/bootstrap.php
```

Expected: FAIL — `OTA_Sync_Feed_Manager not found`

- [ ] **Step 3: Implement Feed Manager**

```php
<?php
// includes/class-feed-manager.php

if ( ! defined( 'ABSPATH' ) ) exit;

class OTA_Sync_Feed_Manager {

    const CRON_HOOK     = 'ota_sync_run';
    const CRON_INTERVAL = 'ota_sync_30min';
    const VALID_OTAS    = [ 'getyourguide', 'viator', 'gowithguide' ];

    private OTA_iCal_Parser  $parser;
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
        $timestamp = wp_next_scheduled( self::CRON_HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_HOOK );
        }
    }

    public function run_sync(): void {
        global $wpdb;
        $feeds = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}ota_sync_feeds WHERE enabled = 1"
        );
        foreach ( $feeds as $feed ) {
            $this->sync_feed( $feed );
        }
    }

    public function sync_feed( object $feed ): void {
        // SSRF check at fetch time
        if ( ! $this->guard->is_safe_url( $feed->ical_url ) ) {
            $this->logger->log_error( $feed->id, 'Invalid or unsafe URL' );
            return;
        }

        $response = wp_remote_get( $feed->ical_url, [ 'timeout' => 10 ] );

        if ( is_wp_error( $response ) ) {
            $this->logger->log_error( $feed->id, $response->get_error_message() );
            return;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            $this->logger->log_error( $feed->id, "HTTP {$code}" );
            return;
        }

        $body   = wp_remote_retrieve_body( $response );
        $events = $this->parser->parse( $body );

        if ( $events === [] && strpos( $body, 'BEGIN:VCALENDAR' ) === false ) {
            $this->logger->log_error( $feed->id, 'Invalid iCal response' );
            return;
        }

        $block_count = $this->process_events( $feed->id, $events, (int) $feed->bookly_staff_id );
        $this->logger->log_success( $feed->id, $block_count );
    }

    public function process_events( int $feed_id, array $events, int $staff_id = 0 ): int {
        global $wpdb;
        $blocks_table = $wpdb->prefix . 'ota_sync_blocks';

        $existing = $this->get_existing_blocks( $feed_id );
        $seen_uids = [];
        $block_count = 0;

        foreach ( $events as $event ) {
            $uid   = $event['uid'];
            $start = $event['start']->format( 'Y-m-d H:i:s' );
            $end   = $event['end']->format( 'Y-m-d H:i:s' );

            $seen_uids[] = $uid;

            if ( isset( $existing[ $uid ] ) ) {
                $row = $existing[ $uid ];

                // Clear marked_missing_at in both same and changed cases
                if ( $row->start_datetime === $start && $row->end_datetime === $end ) {
                    if ( $row->marked_missing_at ) {
                        $wpdb->update( $blocks_table, [ 'marked_missing_at' => null ], [ 'id' => $row->id ] );
                    }
                    continue; // Unchanged
                }

                // Changed: delete old block, create new
                $this->bridge->delete_block( (int) $row->bookly_block_id );
                $new_id = $this->create_block( $staff_id, $start, $end, $uid );
                $wpdb->update( $blocks_table, [
                    'start_datetime'   => $start,
                    'end_datetime'     => $end,
                    'bookly_block_id'  => $new_id,
                    'synced_at'        => current_time( 'mysql' ),
                    'marked_missing_at' => null,
                ], [ 'id' => $row->id ] );
                $block_count++;
            } else {
                // New event
                $block_id = $this->create_block( $staff_id, $start, $end, $uid );
                $wpdb->insert( $blocks_table, [
                    'feed_id'         => $feed_id,
                    'event_uid'       => $uid,
                    'start_datetime'  => $start,
                    'end_datetime'    => $end,
                    'bookly_block_id' => $block_id,
                    'synced_at'       => current_time( 'mysql' ),
                ] );
                $block_count++;
            }
        }

        $this->handle_missing_uids( $feed_id, $existing, $seen_uids );

        return $block_count;
    }

    public function get_existing_blocks( int $feed_id ): array {
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

    public function create_block( int $staff_id, string $start, string $end, string $uid ): ?int {
        return $this->bridge->create_block( $staff_id, $start, $end, 'OTA sync: ' . $uid );
    }

    public function handle_missing_uids( int $feed_id, array $existing, array $seen_uids ): void {
        global $wpdb;
        $blocks_table = $wpdb->prefix . 'ota_sync_blocks';
        $grace = 7 * DAY_IN_SECONDS;

        foreach ( $existing as $uid => $row ) {
            if ( in_array( $uid, $seen_uids, true ) ) continue;

            if ( ! $row->marked_missing_at ) {
                $wpdb->update( $blocks_table, [ 'marked_missing_at' => current_time( 'mysql' ) ], [ 'id' => $row->id ] );
            } elseif ( ( time() - strtotime( $row->marked_missing_at ) ) > $grace ) {
                $this->bridge->delete_block( (int) $row->bookly_block_id );
                $wpdb->delete( $blocks_table, [ 'id' => $row->id ] );
            }
        }
    }

    public function validate_feed_data( array $data ): array {
        if ( empty( $data['ota_name'] ) || ! in_array( $data['ota_name'], self::VALID_OTAS, true ) ) {
            return [ 'valid' => false, 'error' => __( 'Invalid OTA name.', 'ota-calendar-sync' ) ];
        }

        $url = trim( $data['ical_url'] ?? '' );
        if ( ! $this->guard->is_safe_url( $url ) ) {
            return [ 'valid' => false, 'error' => __( 'iCal URL must be a valid HTTPS URL pointing to a public server.', 'ota-calendar-sync' ) ];
        }

        if ( empty( $data['bookly_service_id'] ) || empty( $data['bookly_staff_id'] ) ) {
            return [ 'valid' => false, 'error' => __( 'Service and Staff are required.', 'ota-calendar-sync' ) ];
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
            'bookly_service_id' => absint( $data['bookly_service_id'] ),
            'bookly_staff_id'   => absint( $data['bookly_staff_id'] ),
            'enabled'           => 1,
        ];

        if ( ! empty( $data['id'] ) ) {
            return (bool) $wpdb->update( $table, $row, [ 'id' => absint( $data['id'] ) ] );
        }

        $row['created_at']   = current_time( 'mysql' );
        $row['last_status']  = 'pending';
        return (bool) $wpdb->insert( $table, $row );
    }

    public function delete_feed( int $id ): void {
        global $wpdb;
        // Delete all associated blocks + Bookly blocks
        $blocks = $wpdb->get_results( $wpdb->prepare(
            "SELECT bookly_block_id FROM {$wpdb->prefix}ota_sync_blocks WHERE feed_id = %d",
            $id
        ) );
        foreach ( $blocks as $block ) {
            $this->bridge->delete_block( (int) $block->bookly_block_id );
        }
        $wpdb->delete( $wpdb->prefix . 'ota_sync_blocks', [ 'feed_id' => $id ] );
        $wpdb->delete( $wpdb->prefix . 'ota_sync_feeds', [ 'id' => $id ] );
    }

    public function get_feeds(): array {
        global $wpdb;
        return $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}ota_sync_feeds ORDER BY created_at DESC" ) ?: [];
    }
}
```

- [ ] **Step 4: Run tests**

```bash
./vendor/bin/phpunit tests/test-feed-manager.php --bootstrap tests/bootstrap.php
```

Expected: All 4 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/class-feed-manager.php tests/test-feed-manager.php
git commit -m "feat: add feed manager with sync orchestration and cron scheduling"
```

---

## Chunk 6: Admin UI

### Task 8: Admin Page + Views

**Files:**
- Create: `admin/class-admin-page.php`
- Create: `admin/views/feeds.php`
- Create: `admin/views/log.php`

- [ ] **Step 1: Create Admin Page class**

```php
<?php
// admin/class-admin-page.php

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
        if ( ! current_user_can( 'manage_options' ) ) wp_die( __( 'Insufficient permissions.', 'ota-calendar-sync' ) );

        $feeds    = $this->manager->get_feeds();
        $services = $this->bridge->get_services();
        $staff    = $this->bridge->get_staff();
        $edit_id  = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;

        $bookly_active = $this->bridge->is_available();
        $wpcron_ok     = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;

        include OTA_SYNC_PATH . 'admin/views/feeds.php';
        include OTA_SYNC_PATH . 'admin/views/log.php';
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

        $validation = $this->manager->validate_feed_data( $data );
        if ( ! $validation['valid'] ) {
            wp_redirect( add_query_arg( 'error', urlencode( $validation['error'] ), admin_url( 'admin.php?page=ota-calendar-sync' ) ) );
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
            $wpdb->update( $wpdb->prefix . 'ota_sync_feeds',
                [ 'enabled' => 1, 'failure_count' => 0 ],
                [ 'id' => $id ]
            );
        }

        wp_redirect( admin_url( 'admin.php?page=ota-calendar-sync&enabled=1' ) );
        exit;
    }
}
```

- [ ] **Step 2: Create feeds view**

```php
<?php
// admin/views/feeds.php
// Variables available: $feeds, $services, $staff, $edit_id, $bookly_active, $wpcron_ok
?>
<div class="wrap">
    <h1><?php _e( 'OTA Calendar Sync', 'ota-calendar-sync' ); ?></h1>

    <?php if ( ! $bookly_active ): ?>
    <div class="notice notice-error"><p>
        <?php _e( '<strong>Bookly is not active.</strong> Please install and activate Bookly to use this plugin.', 'ota-calendar-sync' ); ?>
    </p></div>
    <?php endif; ?>

    <?php if ( ! $wpcron_ok ): ?>
    <div class="notice notice-warning">
        <p><?php _e( '<strong>Recommendation:</strong> WP-Cron may be unreliable on low-traffic sites. Add this to your server cron for reliable 30-minute syncs:', 'ota-calendar-sync' ); ?></p>
        <code>*/30 * * * * wget -q -O - <?php echo esc_url( site_url( '/wp-cron.php?doing_wp_cron' ) ); ?></code>
        <p><?php _e( 'Then add <code>define(\'DISABLE_WP_CRON\', true);</code> to wp-config.php.', 'ota-calendar-sync' ); ?></p>
    </div>
    <?php endif; ?>

    <?php if ( isset( $_GET['saved'] ) ): ?><div class="notice notice-success is-dismissible"><p><?php _e( 'Feed saved.', 'ota-calendar-sync' ); ?></p></div><?php endif; ?>
    <?php if ( isset( $_GET['deleted'] ) ): ?><div class="notice notice-success is-dismissible"><p><?php _e( 'Feed deleted.', 'ota-calendar-sync' ); ?></p></div><?php endif; ?>
    <?php if ( isset( $_GET['synced'] ) ): ?><div class="notice notice-success is-dismissible"><p><?php _e( 'Sync complete.', 'ota-calendar-sync' ); ?></p></div><?php endif; ?>
    <?php if ( isset( $_GET['error'] ) ): ?><div class="notice notice-error"><p><?php echo esc_html( urldecode( $_GET['error'] ) ); ?></p></div><?php endif; ?>

    <!-- Add/Edit Feed Form -->
    <h2><?php echo $edit_id ? __( 'Edit Feed', 'ota-calendar-sync' ) : __( 'Add Feed', 'ota-calendar-sync' ); ?></h2>
    <?php
    $edit_feed = null;
    if ( $edit_id ) {
        global $wpdb;
        $edit_feed = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ota_sync_feeds WHERE id = %d", $edit_id ) );
    }
    ?>
    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
        <input type="hidden" name="action" value="ota_sync_save_feed">
        <input type="hidden" name="feed_id" value="<?php echo esc_attr( $edit_id ); ?>">
        <?php wp_nonce_field( 'ota_sync_save_feed' ); ?>
        <table class="form-table">
            <tr>
                <th><?php _e( 'OTA', 'ota-calendar-sync' ); ?></th>
                <td>
                    <select name="ota_name" required>
                        <option value="getyourguide" <?php selected( $edit_feed->ota_name ?? '', 'getyourguide' ); ?>>GetYourGuide</option>
                        <option value="viator"       <?php selected( $edit_feed->ota_name ?? '', 'viator' ); ?>>Viator</option>
                        <option value="gowithguide"  <?php selected( $edit_feed->ota_name ?? '', 'gowithguide' ); ?>>GoWithGuide</option>
                    </select>
                </td>
            </tr>
            <tr>
                <th><?php _e( 'iCal URL', 'ota-calendar-sync' ); ?></th>
                <td>
                    <input type="url" name="ical_url" class="large-text" required
                           placeholder="https://..." value="<?php echo esc_attr( $edit_feed->ical_url ?? '' ); ?>">
                    <p class="description"><?php _e( 'HTTPS only. Find this in your OTA operator dashboard.', 'ota-calendar-sync' ); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php _e( 'Service', 'ota-calendar-sync' ); ?></th>
                <td>
                    <select name="bookly_service_id" required>
                        <option value=""><?php _e( '— Select service —', 'ota-calendar-sync' ); ?></option>
                        <?php foreach ( $services as $svc ): ?>
                        <option value="<?php echo esc_attr( $svc['id'] ); ?>" <?php selected( (int)($edit_feed->bookly_service_id ?? 0), $svc['id'] ); ?>>
                            <?php echo esc_html( $svc['name'] ); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th><?php _e( 'Staff', 'ota-calendar-sync' ); ?></th>
                <td>
                    <select name="bookly_staff_id" required>
                        <option value=""><?php _e( '— Select staff —', 'ota-calendar-sync' ); ?></option>
                        <?php foreach ( $staff as $s ): ?>
                        <option value="<?php echo esc_attr( $s['id'] ); ?>" <?php selected( (int)($edit_feed->bookly_staff_id ?? 0), $s['id'] ); ?>>
                            <?php echo esc_html( $s['name'] ); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th><?php _e( 'Label', 'ota-calendar-sync' ); ?></th>
                <td>
                    <input type="text" name="label" class="regular-text"
                           placeholder="<?php esc_attr_e( 'e.g. GYG - City Tour', 'ota-calendar-sync' ); ?>"
                           value="<?php echo esc_attr( $edit_feed->label ?? '' ); ?>">
                </td>
            </tr>
        </table>
        <?php submit_button( $edit_id ? __( 'Update Feed', 'ota-calendar-sync' ) : __( 'Add Feed', 'ota-calendar-sync' ) ); ?>
    </form>

    <hr>

    <!-- Feed List -->
    <h2>
        <?php _e( 'Active Feeds', 'ota-calendar-sync' ); ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;margin-left:10px;">
            <input type="hidden" name="action" value="ota_sync_now">
            <?php wp_nonce_field( 'ota_sync_now' ); ?>
            <?php submit_button( __( 'Sync Now', 'ota-calendar-sync' ), 'secondary small', 'submit', false ); ?>
        </form>
    </h2>

    <?php if ( empty( $feeds ) ): ?>
    <p><?php _e( 'No feeds configured yet. Add one above.', 'ota-calendar-sync' ); ?></p>
    <?php else: ?>
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th><?php _e( 'OTA', 'ota-calendar-sync' ); ?></th>
                <th><?php _e( 'Label', 'ota-calendar-sync' ); ?></th>
                <th><?php _e( 'Service', 'ota-calendar-sync' ); ?></th>
                <th><?php _e( 'Last Sync', 'ota-calendar-sync' ); ?></th>
                <th><?php _e( 'Status', 'ota-calendar-sync' ); ?></th>
                <th><?php _e( 'Actions', 'ota-calendar-sync' ); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $feeds as $feed ): ?>
            <?php
            $ota_labels = [ 'getyourguide' => 'GetYourGuide', 'viator' => 'Viator', 'gowithguide' => 'GoWithGuide' ];
            $status_icon = $feed->last_status === 'ok' ? '✅' : ( $feed->last_status === 'error' ? '❌' : '⏳' );
            $disabled = ! $feed->enabled;
            ?>
            <tr<?php if ( $disabled ) echo ' style="opacity:0.6"'; ?>>
                <td><?php echo esc_html( $ota_labels[ $feed->ota_name ] ?? $feed->ota_name ); ?></td>
                <td><?php echo esc_html( $feed->label ); ?></td>
                <td><?php echo esc_html( $feed->bookly_service_id ); ?></td>
                <td><?php echo $feed->last_sync ? esc_html( human_time_diff( strtotime( $feed->last_sync ) ) . ' ago' ) : '—'; ?></td>
                <td><?php echo $status_icon . ' ' . esc_html( $feed->last_message ?? '—' ); ?><?php if ( $disabled ) echo ' <strong>(disabled)</strong>'; ?></td>
                <td>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=ota-calendar-sync&edit=' . $feed->id ) ); ?>"><?php _e( 'Edit', 'ota-calendar-sync' ); ?></a>
                    <?php if ( $disabled ): ?>
                    | <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
                        <input type="hidden" name="action" value="ota_sync_enable_feed">
                        <input type="hidden" name="feed_id" value="<?php echo esc_attr( $feed->id ); ?>">
                        <?php wp_nonce_field( 'ota_sync_enable_feed' ); ?>
                        <button type="submit" class="button-link"><?php _e( 'Re-enable', 'ota-calendar-sync' ); ?></button>
                    </form>
                    <?php endif; ?>
                    | <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline"
                          onsubmit="return confirm('<?php esc_attr_e( 'Delete this feed and all its blocks?', 'ota-calendar-sync' ); ?>')">
                        <input type="hidden" name="action" value="ota_sync_delete_feed">
                        <input type="hidden" name="feed_id" value="<?php echo esc_attr( $feed->id ); ?>">
                        <?php wp_nonce_field( 'ota_sync_delete_feed' ); ?>
                        <button type="submit" class="button-link" style="color:#a00"><?php _e( 'Delete', 'ota-calendar-sync' ); ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
```

- [ ] **Step 3: Create log view**

```php
<?php
// admin/views/log.php
// Minimal: the sync log is embedded in the feed list (last_sync + last_message columns).
// This file is reserved for future extended log view.
?>
```

- [ ] **Step 4: Commit**

```bash
git add admin/class-admin-page.php admin/views/feeds.php admin/views/log.php
git commit -m "feat: add admin settings page with feed management and sync log"
```

---

## Chunk 7: Language File + Final Wiring

### Task 9: Hungarian translations

**Files:**
- Create: `languages/ota-calendar-sync-hu_HU.po`

- [ ] **Step 1: Create .po file with key strings**

```po
# Hungarian translation for OTA Calendar Sync for Bookly
msgid ""
msgstr ""
"Project-Id-Version: OTA Calendar Sync for Bookly 1.0.0\n"
"Language: hu_HU\n"
"MIME-Version: 1.0\n"
"Content-Type: text/plain; charset=UTF-8\n"

msgid "OTA Calendar Sync"
msgstr "OTA Naptár Szinkron"

msgid "OTA Sync"
msgstr "OTA Szinkron"

msgid "Add Feed"
msgstr "Feed hozzáadása"

msgid "Edit Feed"
msgstr "Feed szerkesztése"

msgid "Save"
msgstr "Mentés"

msgid "Delete"
msgstr "Törlés"

msgid "Sync Now"
msgstr "Szinkronizálás most"

msgid "No feeds configured yet. Add one above."
msgstr "Még nincs feed beállítva. Adj hozzá egyet fent."

msgid "Label"
msgstr "Cimke"

msgid "Service"
msgstr "Szolgáltatás"

msgid "Staff"
msgstr "Munkatárs"

msgid "Last Sync"
msgstr "Utolsó szinkron"

msgid "Status"
msgstr "Státusz"

msgid "Actions"
msgstr "Műveletek"

msgid "Feed saved."
msgstr "Feed elmentve."

msgid "Feed deleted."
msgstr "Feed törölve."

msgid "Sync complete."
msgstr "Szinkron kész."

msgid "Re-enable"
msgstr "Újraengedélyezés"

msgid "Every 30 minutes"
msgstr "30 percenként"

msgid "iCal URL must be a valid HTTPS URL pointing to a public server."
msgstr "Az iCal URL-nek érvényes HTTPS-címnek kell lennie, nyilvános szerverre mutatva."

msgid "Service and Staff are required."
msgstr "A szolgáltatás és a munkatárs megadása kötelező."

msgid "Invalid OTA name."
msgstr "Érvénytelen OTA név."
```

- [ ] **Step 2: Compile .po to .mo**

```bash
msgfmt languages/ota-calendar-sync-hu_HU.po -o languages/ota-calendar-sync-hu_HU.mo
```

- [ ] **Step 3: Commit**

```bash
git add languages/
git commit -m "feat: add Hungarian translations"
```

---

### Task 10: Full test run + push

- [ ] **Step 1: Run all tests**

```bash
./vendor/bin/phpunit tests/ --bootstrap tests/bootstrap.php
```

Expected: All tests PASS. No errors.

- [ ] **Step 2: Verify plugin file has correct headers**

```bash
grep -E "Plugin Name|Version|Author" ota-calendar-sync.php
```

Expected: Shows correct plugin metadata.

- [ ] **Step 3: Push to GitHub**

```bash
git push origin main
```

- [ ] **Step 4: Tag release**

```bash
git tag v1.0.0 && git push origin v1.0.0
```

---

## Installation Instructions (for dorabudapest.com)

After coding is complete:

1. **Zip the plugin folder:**
   ```bash
   cd /path/to && zip -r ota-calendar-sync.zip ota-calendar-sync/ --exclude "*/vendor/*" --exclude "*/.git/*" --exclude "*/tests/*" --exclude "*/composer.*"
   ```

2. **Install on WordPress:** Plugins → Add New → Upload Plugin → upload the .zip

3. **Install Bookly Free** from WordPress plugin directory

4. **Add services in Bookly** (Budapest City Tour, Airport Transfer, etc.)

5. **Add staff in Bookly** (Dóra Gábor + any others)

6. **Go to OTA Sync → Add Feed** for each GYG/Viator/GWG listing

7. **Get iCal URLs:**
   - GetYourGuide: Supplier Dashboard → Availability → Export iCal
   - Viator: Experience Manager → Availability → iCal Export
   - GoWithGuide: My Listings → Calendar → Export

8. **Configure system cron** (recommended):
   ```
   */30 * * * * wget -q -O - https://dorabudapest.com/wp-cron.php?doing_wp_cron
   ```
