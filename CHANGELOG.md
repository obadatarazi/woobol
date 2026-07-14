# Changelog

All notable changes to WooBolSync are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [3.1.6] - 2026-07-09

### Fixed
- Release zip no longer bundles `bol-webshop-order-filter.php` inside `woo-bol-sync/`, which caused WordPress to treat uploads as a different plugin ("Bol vs Webshop Order Filter") instead of updating WooBolSync.

## [3.1.5] - 2026-07-08

### Added
- **Sendcloud → bol.com tracking bridge**: when a bol.com order gets a tracking number in WooCommerce (order note, shipment-tracking meta, or AST Pro), WooBolSync automatically confirms the shipment on bol.com with `transporterCode` + `trackAndTrace` so the bol customer receives tracking without manual portal work.
- Carrier name mapping to bol.com transporter codes (PostNL → `TNT`, DHL → `DHLFORYOU`, DPD, Bpost, UPS, etc.).
- One-time diagnostic log (`Bol Sync → Logs`, context `orders`, level debug) dumping all order meta for a bol-linked order when no tracking could be detected yet — helps pinpoint the exact meta key used by shipping integrations (e.g. Sendcloud's cloud-to-REST-API sync) that don't add order notes.

### Changed
- bol.com shipment push on order completed/shipped now waits for a tracking number when none is available yet (typical Sendcloud label flow).
- Order-note tracking parser now requires a shipping-related keyword before scanning a note, and no longer matches arbitrary long digit sequences, to avoid picking up unrelated numbers (payment references, phone numbers) from other notes.
- Order-note parser now reads the `carrier` and `code` query parameters straight from Sendcloud's own tracking URL (`*.sendcloud.sc/forward?carrier=...&code=...`) as the primary detection method, confirmed against a real Sendcloud "DHL eCommerce" order note; prose-based patterns (e.g. "shipment is: CODE") remain as fallback for notes without a link.

## [3.1.4] - 2026-05-23

### Fixed
- bol.com subscription test push: send `Content-Type` and `{}` body on `POST /subscriptions/test/{id}` (required by bol API; bodyless POST without media type returned HTTP 400).
- Wait for async subscription update/create (`202` + `PENDING`) to finish before scheduling the test notification.
- Surface bol API `detail` text when the test call fails; detect disabled subscriptions on bol.com.

## [3.1.3] - 2026-05-23

### Fixed
- Webhook subscription setup: resolve the real `subscriptionId` from async create/update process status (`entityId`) instead of storing `processStatusId`, which caused bol.com test push (`POST /subscriptions/test/{id}`) to return HTTP 400 Bad Request.
- Reconcile subscription ID from the bol API when the stored ID is stale; refresh signature keys before scheduling the test notification.

## [3.0.24] - 2026-05-09

### Changed
- **bol Orders** admin list: API requests include `fulfilment-method` (default ALL), optional `latest-change-date`, and the table derives **status** from each item’s `fulfilmentStatus` / `fulfilmentMethod`.
- **API / sync**: `test_connection()` and `Order_Sync_Service` order list use `fulfilment-method=ALL` with `status=ALL`.

### Notes
- Version upgrade continues to run `Activator::clear_plugin_transient_cache()` when `wbs_db_version` is older than `WBS_VERSION`.

## [3.0.18] - 2026-05-09

### Changed
- On plugin **activation** and on **version upgrade**, all WooBolSync **transients** are cleared (`wbs_*`: bol access token, license cache, webhook signature keys, product/order sync debounce keys, and any other `wbs_` transients). Forces fresh API auth and avoids stale cached state after an update.

## [3.0.17] - 2026-05-09

### Fixed
- Staging `build_offer_payload()` now mirrors dashboard product sync: store default fulfilment + delivery code (ignores staged JSON overrides that could differ from bol expectations), and `unknownProductTitle` uses `Mapping_Config::get_listing_title()` when the variation or product exists in WooCommerce.
- Staging **variation** offer **updates** now call `/offers/{id}/stock` and `/offers/{id}/price` after the main PUT when parent draft `sync_stock` / `sync_price` allow — matching simple-product staging and `Product_Sync_Service::sync_existing_offer()`.

## [3.0.16] - 2026-05-09

### Removed
- Temporary NDJSON file logging in `Mapping_Config::get_offer_media_type()` (debug session `e0fb2a`).

## [3.0.15] - 2026-05-09

### Changed
- Default Offer API media type is now **v10** (`get_option` default, settings registration default, sanitizer fallback, and `add_option` on first install). Settings screen lists v10 first as the default.

### Notes
- Existing sites that already have `wbs_offer_media_type` set to v11 in the database keep that value until an admin selects v10 and saves Settings.

## [3.0.14] - 2026-05-09

### Fixed
- `Sync_Job::finalize_stale_running_items()` now builds the `UPDATE ... IN (...)` query with `call_user_func_array( [ $wpdb, 'prepare' ], ... )`. Passing one array into `$wpdb->prepare()` is invalid and caused PHP fatals / HTTP 500 on `admin-ajax.php` when finishing a staging sync.

## [3.0.13] - 2026-05-09

### Fixed
- Staging sync finalization now marks any `wbs_sync_job_item` rows still in `running` as `failed` with an explanatory message, and bumps job failed counts / status when appropriate.
- Variation offer sync wraps the bol Offer call in `try/catch` so unexpected exceptions still record a failed job item and variation draft error.

### Removed
- Temporary NDJSON debug logging from the Offer API client.

## [3.0.12] - 2026-05-09

### Fixed
- Offer payload now sends `condition.category` for v11 (while keeping `condition.name` only for v10), resolving `condition.name` validation failures from bol (`400 Error validating request`).

### Changed
- Cache-busting release to ensure WordPress/browser loads the latest staging sync assets and PHP code after update.

## [3.0.11] - 2026-05-09

### Fixed
- Offer API v10/v11 fallback no longer reuses bol.com response headers as the next request’s header overrides (which could prevent the alternate media-type retry and leave staging sync stuck on 415 Unsupported Media Type).

## [3.0.10] - 2026-05-09

### Changed
- Staging sync job summary message (stored on `wbs_sync_job`) now appends the first failed job item `error_message`, so admins see the concrete failure without opening item rows.

## [3.0.9] - 2026-05-09

### Changed
- Cache-busting release to force browsers and WordPress script/style handles to load the latest staging/admin assets after plugin update.

## [3.0.8] - 2026-05-09

### Fixed
- Staging selected sync now derives IDs from currently checked visible rows and prunes stale selection keys after list refresh, preventing accidental parent-draft sync when selecting a single variation row.
- Offer API media type fallback now normalizes incoming media header values (v10/v11 detection), so automatic alternate-version retry still triggers when headers include extra parameters.

## [3.0.7] - 2026-05-09

### Added
- Read-only admin page for bol products (`Bol Sync -> bol Products`) with EAN-based fetch that displays catalog and offers API responses.
- Read-only admin page for bol orders (`Bol Sync -> bol Orders`) with orders list fetch (`status`, `page`, `size`) and per-order detail lookup.
- New admin AJAX endpoints:
  - `wbs_bol_products_fetch`
  - `wbs_bol_orders_fetch`
  - `wbs_bol_order_detail_fetch`
  to support the new monitoring pages without changing sync data.

## [3.0.6] - 2026-05-09

### Changed
- Admin CSS accent palette updated from legacy `#0073aa` to the current
  WordPress admin primary blue `#2271b1`, with `#135e96` for darker borders
  and hover states, and matching `rgba(34,113,177,…)` focus rings on toggles,
  inputs, and primary buttons (`assets/css/admin.css`).

## [3.0.5] - 2026-05-09

### Added
- Woosa-style admin UI primitive `.wbs-section`: stacked collapsible cards
  with green header bar, round toggle switches, and friendly two-column rows
  applied across every admin page (Dashboard, Settings, Field mapping,
  Category mapping, Logs, License).
- Pure-CSS round toggle switch that automatically replaces every checkbox
  inside `.wbs-section__body` (sync, webhook, debug, etc.) without touching
  the underlying Settings API field callbacks.
- Per-section open/closed state persisted to `localStorage`
  (`wbs:section-state:<key>`), restoring the merchant's preference on reload.
- New i18n strings `collapse` and `minimize` for the section toggle button.

### Changed
- Settings page replaces the WordPress `nav-tab-wrapper` and the `?tab=`
  URL-driven panel switcher with a single scrollable layout where every
  Settings API section renders as its own collapsible card.
- Helper descriptions for `field_smart_sync`, `field_offer_media_type`,
  `field_auto_recover_stale_offers`, `field_staging_mode`,
  `field_webhook_enabled`, `field_webhook_signing_required`,
  `field_webhook_shared_secret`, `field_allow_new_offers`,
  `field_sync_product_content`, `field_debug_mode`, `field_log_retention`,
  and the `sec_*_desc` callbacks were rewritten as plain-language,
  merchant-friendly explanations (labels themselves are unchanged).
- Page chrome refreshed: softer card shadows, larger 8px border radius,
  and tighter spacing across the plugin. The original WordPress-blue
  accent palette (`#0073aa`) is kept so the admin UI continues to match
  the rest of the merchant's WordPress dashboard.

### Notes
- All `register_setting`, `add_settings_section`, and `add_settings_field`
  registrations are unchanged. Form `name`s, IDs, and the
  `wbs_settings_group` post target stay identical, so saving settings
  works exactly as before.
- The legacy `.wbs-card` and `.wbs-settings-tabs` styles are kept for
  backward compatibility but new and updated views use `.wbs-section`.

## [3.0.4] - 2026-05-05

### Added
- Settings page now includes top-level tabs to separate Connection, Sync Rules,
  Product Sync Schedule, Order Import Schedule, Webhooks, and Advanced & Logs.

### Changed
- Settings rendering now groups existing Settings API sections into tab panels
  without changing option keys, storage format, or form submission flow.
- Admin tab switching preserves product/order schedule conditional row behavior
  and keeps the active tab state via URL and local storage.

## [3.0.3] - 2026-05-05

### Added
- Staging & Review table now supports selecting rows and syncing only the selected drafts.
- New "Sync selected drafts" bulk action in staging toolbar with backend filtering to syncable approved drafts only.

### Changed
- Product sync enable/disable setting now consistently blocks product sync across cron, manual admin actions, and WooCommerce-triggered hooks.

## [3.0.2] - 2026-05-05

### Added
- Authenticated webhook endpoint (`woobol/v1/webhook`) with three verification strategies:
  RSA signature against cached bol.com `signatureKeys`, optional shared-secret token
  (`X-WBS-Webhook-Token` header or `?token=` query argument), and the
  `wbs_webhook_verify_request` filter for custom verification.
- New settings: *Webhook authentication* (signing required), *Webhook shared secret*,
  and *Default brand*.
- `Hook_Loader::add_filter()` parity with `add_action()`.
- `wbs_default_brand` filter for per-product brand overrides.
- `readme.txt` and `CHANGELOG.md` for release packaging.

### Changed
- Product batch sync now walks the catalog deterministically using
  `Product_Sync_Service::OPTION_SYNC_CATALOG_OFFSET`, ordered by product ID,
  and wraps to the start when the catalog is fully cycled.
- Webhook controller no longer logs raw payload bodies; only a SHA-256 hash
  prefix and length are recorded.
- Webhook endpoint enforces a per-IP rate limit (60 req/min) and rejects
  unsigned traffic with HTTP 401 by default.
- Cron sync events (`wbs_cron_sync_products`, `wbs_cron_sync_orders`) are
  scheduled only when WooCommerce is loaded; housekeeping events install at
  activation regardless.
- `_wbs_brand` product meta becomes the editable per-product brand. The store
  default is configured in *Field mapping → Listing defaults*.
- Plugin name unified to **WooBolSync** with text domain `woo-bol-sync`.
- Bumped minimum PHP to 8.2.

### Removed
- Hardcoded `CaffeBello` brand defaults (replaced by configurable option +
  filter).
- Stale `Cron` namespace mapping in the autoloader.
- `rest_pre_dispatch` no-op filter previously registered in
  `Webhook_Controller::__construct()`.

### Fixed
- Uninstall now drops all staging tables (`wbs_sync_batches`,
  `wbs_product_sync_draft`, `wbs_variation_sync_draft`, `wbs_sync_job`,
  `wbs_sync_job_item`, `wbs_sync_audit`) and removes every option the plugin
  creates, including staging flags, schedules, webhook secret/keys, and
  catalog cursor.
- Cleared multisite site-meta transients on uninstall.
