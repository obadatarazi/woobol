=== WooBolSync ===
Contributors: cupcoding
Tags: woocommerce, bol.com, sync, integration, marketplace
Requires at least: 6.2
Tested up to: 6.7
Requires PHP: 8.2
Stable tag: 3.0.24
License: Proprietary

Production-grade WooCommerce ↔ bol.com integration: product sync, order import, signed webhooks, configurable schedules, and DB-backed logging.

== Description ==

WooBolSync keeps WooCommerce products and bol.com offers in lockstep and pulls bol.com orders back into WooCommerce automatically.

**Highlights**

* Push WooCommerce products to bol.com as offers (create / update / stock / price).
* Pull bol.com orders into WooCommerce, with shipment, cancellation, and return reconciliation.
* Configurable batch schedules (daily / weekly / monthly) plus event-driven sync on WooCommerce updates.
* Signed inbound webhook endpoint with RSA signature verification, optional shared-secret fallback, and rate limiting.
* Smart Sync hash skips unchanged products, with a one-click "force update existing" path.
* Field mapping UI for EAN/GTIN, listing title, and description sources.
* Category mapping UI with attribute templates per category.
* DB-backed logger with level/context filters and automatic retention purging.
* HPOS-compatible (WooCommerce custom order tables).

== Installation ==

1. Upload the `woo-bol-sync` folder to `/wp-content/plugins/`, or install the zip via Plugins → Add New → Upload.
2. Activate **WooBolSync** through the *Plugins* menu.
3. Navigate to **Bol Sync → Settings**, enter your bol.com Client ID and Client Secret, then click *Test Connection*.
4. Go to **Bol Sync → Settings**, fetch or create the economic operator.
5. (Optional) Map WooCommerce categories to bol.com categories under **Bol Sync → Category mapping**.
6. (Optional) Review **Bol Sync → Field mapping** and the *Default brand* before the first sync.
7. Click *Sync Products* on the Dashboard for the first run.

== Webhook setup ==

The plugin exposes:

`POST {site_url}/wp-json/woobol/v1/webhook`

Subscriptions are auto-managed when *Webhook automation* is enabled in Settings. Inbound POSTs are authenticated using one of:

* RSA signature (`X-BOL-Signature` header) verified against bol.com signature keys cached after `Ensure Webhook`.
* Optional shared secret in `X-WBS-Webhook-Token` header or `?token=` query argument.
* `wbs_webhook_verify_request` filter for custom verification.

Unauthenticated POSTs are rejected with HTTP 401. While bringing up a fresh subscription, you can temporarily disable strict authentication in **Settings → Webhook authentication**.

== Cron behaviour ==

WooBolSync registers four cron events:

* `wbs_cron_sync_products` (single events chained from the configured schedule, or omitted in *WooCommerce updates only* mode).
* `wbs_cron_sync_orders` (single events chained from the configured schedule).
* `wbs_cron_ensure_subscription` (twice daily housekeeping).
* `wbs_cron_purge_logs` (daily log retention).

When WooCommerce is inactive at activation time, only the housekeeping events are installed; sync events are scheduled the next time WooCommerce loads.

== Uninstall behaviour ==

Deactivating the plugin clears its cron events but retains options and tables.

Uninstalling (Plugins → Delete) **removes** all WooBolSync-owned data:

* Tables: `wbs_logs`, `wbs_product_mapping`, `wbs_order_mapping`, `wbs_category_mapping`, plus all staging tables.
* All options prefixed with `wbs_*` registered by the plugin.
* All transients prefixed with `wbs_*` (single-site and multisite).
* All scheduled cron events.

== Changelog ==

= 3.0.24 =
* Changed: **Bol Sync → bol Orders** list fetch sends `fulfilment-method` (ALL / FBR / FBB), optional `latest-change-date` for historical rows, and shows per-line **fulfilment status** from `orderItems`. Connection test and scheduled order list use `fulfilment-method=ALL`. On upgrade, existing **transient cache** is cleared as before (`wbs_db_version` bump).

= 3.0.22 =
* Changed: **Bol Sync → bol Products** now lists seller offers in a table (image, title, EAN, stock, price, fulfilment, reference) with row delete, multi-select, and bulk delete. Parses offers from the API body or from a JSON `summary` string when bol returns that shape. Images use the mapped WooCommerce product thumbnail when the EAN exists in the product mapping table.

= 3.0.21 =
* Added: delete a bol.com offer from **Bol Sync → bol Products** (by offer ID). Clears the local product mapping when it matches. Uses the Retailer Offer API `DELETE /offers/{offerId}` with your configured v10/v11 media type.

= 3.0.20 =
* Fixed: 14-digit GTIN (GTIN-14) with indicator digit 0 is normalized to EAN-13 for bol sync, so WooCommerce “GTIN / UPC / EAN” values like 08721586790001 are no longer treated as missing EAN.

= 3.0.17 =
* Fixed: staging offer payloads now use the same fulfilment defaults and listing title rules as dashboard product sync (`get_default_fulfilment_method` / delivery code, `get_listing_title` when the WC product exists). Staging variation *updates* now push stock and price via `/offers/{id}/stock` and `/price` like simple products and product sync.

= 3.0.16 =
* Maintenance: removed temporary Offer API media-type NDJSON file logging from `get_offer_media_type()`.

= 3.0.15 =
* Changed: Offer API media type default is now **v10** (new installs, missing option, and invalid values). Settings UI labels updated. Sites that already saved **v11** in the database must switch to v10 under Bol Sync → Settings (or update `wbs_offer_media_type` in `wp_options`) to stop sending v11.

= 3.0.14 =
* Fixed: critical — `finalize_stale_running_items()` called `$wpdb->prepare()` with a single array argument (invalid for WordPress), which could fatal and return HTTP 500 on staging sync AJAX.

= 3.0.13 =
* Fixed: staging sync jobs no longer leave `wbs_sync_job_item` rows stuck in `running` when the request ends; orphaned items are marked failed with a clear message, and variation offer sync catches unexpected exceptions.
* Maintenance: removed temporary Offer API NDJSON debug logging.

= 3.0.12 =
* Fixed: Offer payload now sends `condition.category` for v11 (and keeps legacy `condition.name` only on v10), resolving bol validation errors like `condition.name` invalid.
* Maintenance: cache-busting release to force WordPress/browser to load the latest staging sync code after update.

= 3.0.11 =
* Fix: Offer API v10/v11 fallback no longer merges bol.com response headers into the retry request (which could block media-type fallback and surface persistent 415 Unsupported Media Type).

= 3.0.10 =
* Improved: staging sync job message now includes the first failed item error (same detail as job items), so Recent sync jobs and DB rows show why a run failed.

= 3.0.9 =
* Maintenance: cache-busting release to force loading latest staging/admin JavaScript and CSS assets after update.

= 3.0.8 =
* Fixed: staging selected sync now reads currently checked table rows only, preventing stale hidden selection state from syncing unintended drafts.
* Fixed: improved Offer API media-type fallback detection by normalizing v10/v11 media headers before alternate-version retry.

= 3.0.7 =
* New: added read-only admin pages for bol Products and bol Orders under Bol Sync, so admins can fetch and inspect live bol data without running sync.
* New: bol Orders page now supports order list fetch (status/page/size) plus single order detail fetch by order ID.

= 3.0.6 =
* Improved: admin accent color aligned with the modern WordPress admin palette (`#2271b1`, darker hover `#135e96`) for section headers, toggles, primary actions, and focus rings.

= 3.0.5 =
* Improved: redesigned admin UI in the Woosa style — every page (Dashboard, Settings, Field mapping, Category mapping, Logs, License) now uses stacked collapsible section bars with round toggle switches and clearer two-column rows. The original WordPress-blue accent palette is preserved.
* Improved: helper descriptions under sync, webhook, debug, and log-retention controls are now plain-language merchant-friendly explanations.
* Improved: Settings page replaces the WordPress nav-tabs with a single scrollable layout matching the bol.com plugin reference design; collapsed/expanded state is remembered per section in localStorage.

= 3.0.4 =
* Improved: Settings page is now split into clear top tabs (Connection, Sync Rules, Product Sync Schedule, Order Import Schedule, Webhooks, Advanced & Logs) while preserving all existing option keys and save behavior.
* Improved: tab switching keeps schedule visibility logic intact and persists active tab in URL/local state for easier admin navigation.

= 3.0.3 =
* New: Staging & Review now supports syncing only selected drafts directly from the table.
* New: global product sync enable/disable guard now blocks cron/manual/hook product sync when disabled.

= 3.0.2 =
* Security: signed inbound webhooks with rate limiting and minimal logging.
* New: configurable default brand and webhook shared secret.
* Fix: product batch sync now walks the full catalog with a persisted cursor.
* Fix: uninstall now drops all staging tables and clears every plugin option.
* Lifecycle: cron sync events deferred until WooCommerce is loaded.
* i18n: unified text domain (`woo-bol-sync`) and translation loader.

== Frequently Asked Questions ==

= Does this plugin work with HPOS / custom order tables? =

Yes — it declares compatibility with WooCommerce HPOS.

= What PHP version do I need? =

PHP 8.2 or later.
