# OTA Calendar Sync for Bookly — Design Spec

**Date:** 2026-03-12
**Project:** WordPress plugin — dorabudapest.com
**Status:** Approved

---

## Overview

WordPress plugin that imports iCal feeds from GetYourGuide, Viator, and GoWithGuide and automatically blocks the corresponding time slots in Bookly, preventing double bookings when tours are booked via OTAs.

**Scope:** One-way sync (OTA → WordPress). Two-way sync requires OTA connectivity partner API access (not available without paid channel manager like Bókun).

---

## Context

- **Site:** dorabudapest.com — private tour guide, city transfers, ~15 service types
- **OTAs:** GetYourGuide, Viator, GoWithGuide
- **Booking system:** Bookly (to be installed)
- **Payment:** Stripe + PayPal (full amount at booking, via Bookly Pro)
- **Languages:** Hungarian + English

---

## Architecture

```
WordPress Admin
    │
    ├── OTA Sync Settings page
    │       ├── Feed list (URL + OTA type + Bookly service mapping)
    │       └── Manual "Sync Now" trigger
    │
    ├── WP-Cron (every 30 minutes)
    │       └── iCal Parser → Bookly Bridge
    │
    └── Sync Log panel
```

### Components

| Component | File | Responsibility |
|---|---|---|
| Main plugin | `ota-calendar-sync.php` | Bootstrap, hooks, activation/deactivation |
| iCal Parser | `includes/class-ical-parser.php` | RFC 5545 iCal parsing, VEVENT extraction |
| Bookly Bridge | `includes/class-bookly-bridge.php` | Create/update/delete Bookly blocked time slots |
| Feed Manager | `includes/class-feed-manager.php` | Feed CRUD, WP-cron scheduling, sync orchestration |
| Sync Logger | `includes/class-sync-logger.php` | Log sync results per feed |
| Admin Page | `admin/class-admin-page.php` | Settings UI + Sync Log display |

---

## File Structure

```
ota-calendar-sync/
├── ota-calendar-sync.php
├── includes/
│   ├── class-ical-parser.php
│   ├── class-bookly-bridge.php
│   ├── class-feed-manager.php
│   └── class-sync-logger.php
├── admin/
│   ├── class-admin-page.php
│   └── views/
│       ├── feeds.php
│       └── log.php
└── languages/
    └── ota-calendar-sync-hu_HU.po
```

---

## Database

### `wp_ota_sync_feeds`
```sql
id                INT AUTO_INCREMENT PRIMARY KEY
ota_name          VARCHAR(50)   -- 'getyourguide' | 'viator' | 'gowithguide'
label             VARCHAR(100)  -- human-readable name
ical_url          TEXT          -- iCal feed URL (HTTPS only, SSRF-protected)
bookly_service_id INT           -- FK to Bookly service
bookly_staff_id   INT           -- FK to Bookly staff (default: first staff of service)
last_sync         DATETIME
last_status       VARCHAR(20)   -- 'ok' | 'error' | 'pending'
last_message      TEXT          -- error detail or "Synced N blocks"
created_at        DATETIME
```

**Unique index:** `(ical_url)` — prevents duplicate feeds.

### `wp_ota_sync_blocks`
```sql
id                   INT AUTO_INCREMENT PRIMARY KEY
feed_id              INT           -- FK to wp_ota_sync_feeds
event_uid            VARCHAR(255)  -- iCal VEVENT UID (idempotency key)
start_datetime       DATETIME
end_datetime         DATETIME
bookly_block_id      INT           -- FK to Bookly blocked appointment
synced_at            DATETIME
marked_missing_at    DATETIME NULL -- set when UID disappears from feed; block deleted after 7 days
```

**Unique index:** `(feed_id, event_uid)` — prevents duplicate blocks per feed.

---

## Sync Flow

```
WP-Cron fires (every 30 min)
    │
    └── foreach active feed:
            ├── HTTP GET iCal URL (HTTPS only, timeout: 10s)
            ├── On error → log + skip
            ├── Parse VEVENT entries
            │       Filter: events where start_datetime >= today (by start date)
            │               AND start_datetime <= today+365 days
            │               Multi-day events included if start >= today
            │
            ├── foreach event:
            │       ├── UID exists in wp_ota_sync_blocks?
            │       │       ├── YES, same start+end → clear marked_missing_at if set, skip
            │       │       ├── YES, different start+end → delete old Bookly block,
            │       │       │                               create new block, update row,
            │       │       │                               clear marked_missing_at if set
            │       │       └── NO → create Bookly block, insert row
            │
            ├── UIDs in DB but NOT in current feed:
            │       ├── marked_missing_at IS NULL → set marked_missing_at = now
            │       └── marked_missing_at older than 7 days → delete Bookly block + row
            │
            └── Update feed last_sync + last_status
```

---

## Bookly Integration

Uses Bookly's "Blocked Time" mechanism. The exact internal API will be confirmed during implementation by inspecting Bookly source (`\Bookly\Lib\Entities\Block` or similar). The intent:

- Create a block for a specific staff member + time range
- Block is not visible to customers as a bookable slot
- Block stores an internal note for traceability: `"OTA sync: getyourguide / <event_uid>"`

**Staff mapping:**
Each feed has a `bookly_staff_id`. If the service has only one staff member (typical for solo operators), it defaults automatically. For services with multiple staff, the admin selects which staff to block.

**Bookly version requirements:**
- Bookly Free — sufficient for blocking functionality
- Bookly Pro — required for Stripe/PayPal online payment

---

## Admin UI

### Settings page: Feed list

```
[+ Add Feed]

OTA        | Label          | Service       | Last Sync    | Status
-----------|----------------|---------------|--------------|--------
GYG        | City Tour      | City Tour     | 5 min ago    | ✅ 3 blocks
Viator     | City Tour      | City Tour     | 5 min ago    | ✅ 1 block
GYG        | Airport Tfr    | Transfer      | 5 min ago    | ❌ Feed error
```

### Add/Edit Feed form

```
OTA:       [GetYourGuide ▼]
iCal URL:  [________________________]  (HTTPS only)
Service:   [Budapest City Tour ▼]
Staff:     [Dóra Gábor ▼]             (auto-filled if only one staff)
Label:     [GYG - City Tour]
[Save]
```

---

## Error Handling & Resilience

**Feed failure backoff:** After 5 consecutive fetch errors, a feed is automatically disabled and an admin notice is shown. The admin can re-enable manually after fixing the issue.

| Scenario | Behavior |
|---|---|
| iCal URL unreachable | Log error, skip feed, retry next cycle |
| Invalid iCal format | Log parse error, skip feed |
| Non-HTTPS URL | Reject on save, show validation error |
| Private/local IP in URL | Reject on save (SSRF protection) |
| Bookly not active | Admin notice on plugin page |
| Bookly service deleted | Log warning, skip affected feed |
| Past events | Only sync today → +365 days (by event start date) |
| Removed OTA booking | Set marked_missing_at; delete Bookly block after 7-day grace period |
| Bookly block manually deleted | On update/delete: if block not found, create fresh block and update bookly_block_id |

## Security

- **Admin capability:** Settings page requires `manage_options` capability (WordPress administrator)
- **SSRF protection:** iCal URL must be HTTPS; private IP ranges (10.x, 192.168.x, 127.x, ::1) are blocked both on save (URL validation) and at fetch time (DNS rebinding protection — resolved IP is checked before the request is made)
- **Nonce verification:** All admin form submissions use WordPress nonces

## Database Migrations

Plugin stores a `ota_sync_db_version` option. On `plugins_loaded`, if the stored version differs from the current version, `dbDelta()` runs to apply schema changes. This ensures safe upgrades.

---

## Requirements

- PHP 7.4+
- WordPress 5.8+
- Bookly plugin (free version minimum)
- Bookly Pro for online payment (Stripe/PayPal)

## WP-Cron Reliability Note

WP-Cron only fires when the WordPress site receives a request. On low-traffic sites, the 30-minute sync may be delayed. For production use, the recommended setup is to disable WP-Cron (`define('DISABLE_WP_CRON', true)` in wp-config.php) and add a real system cron job:

```
*/30 * * * * wget -q -O - https://dorabudapest.com/wp-cron.php?doing_wp_cron
```

The plugin settings page displays a persistent inline notice (non-dismissible) on the Settings tab if `DISABLE_WP_CRON` is not set, recommending the system cron setup with the exact command to copy.

---

## Out of Scope

- Two-way sync (WordPress → OTA) — requires OTA connectivity partner API
- Custom booking frontend (handled by Bookly)
- Payment processing (handled by Bookly Pro + Stripe/PayPal)
- GoWithGuide webhook support (not available on their platform)
