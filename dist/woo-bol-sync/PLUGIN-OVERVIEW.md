# WooBolSync Pro — Plugin overview

**WooCommerce ↔ bol.com Retailer API integration** for merchants who sell on bol.com and fulfil or manage catalog from WordPress.

This document summarizes what the plugin does, how it is structured, and how the main flows work. It is intended for developers, technical partners, and advanced store operators.

---

## At a glance

| Area | What it does |
|------|----------------|
| **Products** | Creates/updates bol.com offers, stock, and price; optional catalog/content upload; smart sync by hash |
| **Orders** | Imports bol.com orders into WooCommerce; pushes shipment and cancellation data back |
| **Returns** | Imports handled returns and annotates matching WC orders |
| **Economic operator** | Reads/creates/updates bol.com economic operator records (required for offers) |
| **Webhooks** | REST callback for bol.com; optional `PROCESS_STATUS` subscription automation |
| **Logging** | DB-backed logs with levels, contexts, retention, optional debug payload capture |
| **Licensing** | Proprietary license check workflow |
| **Scheduling** | Configurable batch product sync and order import (daily/weekly/monthly or product hooks only) |

**Requirements (from bootstrap):** WordPress ≥ 6.2, PHP ≥ 8.0, WooCommerce ≥ 8.0. Declares compatibility with WooCommerce **custom order tables** (HPOS).

**Version:** See `WBS_VERSION` in `woo-bol-sync.php` (plugin header and constant stay in sync).

---

## Directory layout

```
woo-bol-sync.php          # Bootstrap, constants, autoloader, activation hooks
uninstall.php             # Full data removal on plugin delete

admin/
  class-admin-menu.php    # Menus, Settings API, AJAX actions

assets/
  css/admin.css
  js/admin.js             # Dashboard/settings AJAX, log toggles, schedule UI

includes/
  class-activator.php     # DB tables, default options, cron registration
  class-deactivator.php   # Clears crons on deactivate (keeps data)
  class-hook-loader.php   # Thin action/filter registrar
  class-license-manager.php
  class-logger.php
  class-mapping-config.php # Options accessors, field maps, EO, webhooks, sync schedules
  class-plugin.php        # Singleton bootstrap: WC gate, crons, REST, admin
  class-sync-hooks.php    # WC hooks → per-product sync, order status → bol
  class-sync-scheduler.php # Product/order batch cron scheduling (single events)
  class-webhook-controller.php # REST route woobol/v1/webhook

models/
  class-category-map.php
  class-order-mapping.php
  class-product-mapping.php

services/
  class-bol-api-service.php      # OAuth, HTTP client, EO + retailer endpoints
  class-order-sync-service.php
  class-product-sync-service.php
  class-subscription-sync-service.php

views/admin/
  dashboard.php, settings.php, logs.php, license.php
  category-mapping.php, field-mapping.php
```

**Namespace:** `WooBolSync\…` with PSR-4-style autoloading to the folders above.

---

## Admin experience

| Page | Slug (approx.) | Purpose |
|------|----------------|---------|
| **Dashboard** | `wbs-dashboard` | Stats, connection test, manual product/order sync, health check, webhook ensure, cron “next run” hints |
| **Settings** | `wbs-settings` | API credentials, sync behaviour, **product/order schedule**, Offer API media type, webhook toggle, margin, debug, log retention; economic operator panel (AJAX) |
| **Category mapping** | `wbs-categories` | WC `product_cat` → bol category id + template; EAN catalog lookup + chunk recommendations (AJAX) |
| **Field mapping** | `wbs-field-map` | EAN/title/description sources, listing defaults (fulfilment, delivery, publish scope, excluded categories) |
| **Product edit (WooCommerce)** | Product edit page → **bol.com Required Product Data** metabox | Per-product core fields (`dutch_description`, `ingredients`, `origin_country`) plus structured net content (`value`, `unit`, optional `pieces`) normalized into `net_content` for content payloads; brand is fixed as `CaffeBello` |
| **Logs** | `wbs-logs` | Filterable DB log viewer |
| **License** | `wbs-license` | License key and validation |

Capability used for menu access: `manage_woocommerce`.

---

## Database tables (custom)

All use the WordPress table prefix.

| Suffix | Purpose |
|--------|---------|
| `wbs_logs` | Log rows: level, context, message, JSON `data`, `created_at` |
| `wbs_product_mapping` | WC product ↔ bol offer id, EAN, sync hash, JSON `meta` (status, errors, content hints, etc.) |
| `wbs_order_mapping` | WC order ↔ bol order id, push status |
| `wbs_category_mapping` | WC term ↔ bol category id + optional template JSON |

Created/updated via `dbDelta` on activation and versioned upgrades (`Activator::maybe_upgrade`).

---

## bol.com API usage (high level)

**Authentication:** OAuth2 client credentials → `https://login.bol.com/token`; Bearer token on Retailer API (with caching in a transient).

**Base URLs (in `Bol_API_Service`):**

- Retailer API: `https://api.bol.com/retailer`
- Shared API (e.g. process status): `https://api.bol.com/shared`
- Economic operator endpoints use media type `application/vnd.economic-operator.v1+json`.

**Retailer API versioning:**

- Default retailer JSON media type is **v10** for most resources.
- **Offer** routes (`/offers`, `/offers/{id}`, `/stock`, `/price`) use a configurable Offer media type (**v10 or v11**) from settings (`Mapping_Config::get_offer_media_type()`), so offers can be migrated independently.

**Representative endpoints used:**

- Offers: create/update, stock, price
- Orders: list/import; cancellations
- Shipments: create
- Returns: list (FBR/FBB)
- Content: product content upload, upload report, catalog product, chunk recommendations, assets
- Subscriptions: list/get/create/update for webhook subscription
- Economic operator: CRUD + list

**Resilience:** Configurable retry count, exponential backoff on transient HTTP statuses, optional `Retry-After` handling; process-status polling for async operations where applicable.

---

## Product sync

### Real-time (WooCommerce hooks)

`Sync_Hooks` triggers `Product_Sync_Service::sync_product_by_id()` on relevant changes:

- Stock hooks (`woocommerce_product_set_stock`, variation equivalent, stock status)
- Product updates (`save_post_product` on update, `woocommerce_update_product`)

Short transients deduplicate rapid repeated calls per product.

### Batch (WP-Cron)

Hook: `wbs_cron_sync_products` → `Product_Sync_Service::sync_all()`.

- Processes up to **`wbs_sync_batch_size`** products per run (default 25).
- **Smart sync:** optional hash compare to skip unchanged products (`Mapping_Config::compute_sync_hash`).
- Creates new offers or updates existing (using stored bol offer id); updates stock and price in separate calls.
- Price updates use bol’s expected JSON shape (`pricing.bundlePrices` wrapped for the `/price` endpoint as required by the API).
- After catalog constraints, may push product content (images, attributes) via content API.

### Category mapping and content attributes

- `Category_Map::resolve_mapped_wc_term_id_for_product()` picks the **same** WooCommerce category row as `Mapping_Config::get_bol_category_id_for_product()` (assigned `product_cat` term, then **ancestors**). **Template** attributes for content upload come from that row, so a product in a **child** category inherits a **parent’s** bol id + template when the child has no mapping.
- `POST /retailer/content/products` uses `language`, `attributes` (EAN, Name, Description, plus template lines as bol attribute ids), and optional `assets`. Per bol’s Product Content API, extra classification is expressed through **attributes** unless their schema documents a separate top-level field.
- **Category mapping** admin includes **Catalog lookup by EAN** (`GET /retailer/content/catalog-products/{ean}`) and highlights **`gpc.chunkId`** when bol returns it; **Chunk recommendations** (`POST /retailer/content/chunk-recommendations`) suggests `chunkId` values from a product name and optional description per bol’s Product Content API.

### Batch schedule (configurable)

`Sync_Scheduler` + settings options:

- **Modes:** daily, weekly, monthly, or **“On WooCommerce product updates only”** (no batch cron; hooks only).
- **Time** of day uses the **site timezone** (`wp_timezone()`).
- **Weekly:** weekday (PHP convention: 0 = Sunday … 6 = Saturday).
- **Monthly:** day 1–28 (documented cap to avoid invalid calendar dates).
- Implementation uses **chained `wp_schedule_single_event`** (not fixed-interval “monthly” pseudo-schedules), recomputed after each run.

---

## Order sync

### Scheduled import

Hook: `wbs_cron_sync_orders` → `Order_Sync_Service::sync_orders()`.

- Fetches bol orders, creates WC orders when new, stores bol payload and item ids in order meta for later pushes.
- Schedule modes: **daily / weekly / monthly** with same time/weekday/monthday primitives as above (no “WC updates only” for orders—orders come from bol).

### Webhook-triggered import

`POST` (and `GET`/`HEAD` for validation) to:

`{site}/wp-json/woobol/v1/webhook`

When webhook automation is enabled, a POST may call `sync_orders()` as a near-real-time complement to cron.

### Outbound from WooCommerce

On WC order status changes (e.g. completed/shipped → shipment push; cancelled/refunded → cancellation push), using stored bol `orderItemId` values.

### Returns

`sync_returns()` pulls handled returns per fulfilment method and adds order notes / meta markers to avoid duplicates.

---

## Webhook subscription automation

`Subscription_Sync_Service::ensure_process_status_subscription()` maintains a bol subscription for resource **`PROCESS_STATUS`** pointing at the REST callback URL.

- Cron: `wbs_cron_ensure_subscription` (twicedaily by default on activate).
- Stored subscription id: `wbs_webhook_subscription_id`.

**Note:** PROCESS_STATUS webhooks complement async/process flows; they are not a replacement for full catalog sync. Product coverage remains **scheduled batch + WC hooks** (and manual sync).

---

## Logging

`Logger` writes to `wbs_logs` with level (`info`, `warning`, `error`, `debug`, …) and context (`api`, `products`, `orders`, `automation`, etc.).

- **Debug mode** can log richer request/response detail (use cautiously in production).
- **Retention:** daily cron `wbs_cron_purge_logs` removes entries older than configured days.

---

## Licensing

`License_Manager` integrates with the plugin’s license workflow (options such as `wbs_license_key` / `wbs_license_status`—see code and License admin page). Exact remote validation rules live in `class-license-manager.php`.

---

## WordPress options (non-exhaustive but practical)

Credentials and behaviour: `wbs_client_id`, `wbs_client_secret`, `wbs_smart_sync`, `wbs_sync_batch_size`, `wbs_rate_limit_delay`, `wbs_api_retry_count`, `wbs_debug_mode`, `wbs_log_retention_days`.

Offer API: `wbs_offer_media_type` (`v10` or `v11` JSON media type for offer routes).

Webhook: `wbs_webhook_enabled`, `wbs_webhook_subscription_id`, `wbs_last_order_sync_at`.

Economic operator: `wbs_economic_operator_*` (id, name, status, last sync).

Listing/field map: `wbs_field_map`, `wbs_default_delivery_code`, `wbs_default_fulfilment_method`, `wbs_sync_only_published`, `wbs_exclude_category_ids`, margin options.

Connection test cache: `wbs_connection_status`, `wbs_connection_message`, `wbs_connection_last_tested_at`.

**Sync schedules:** `wbs_product_sync_mode`, `wbs_product_sync_time`, `wbs_product_sync_weekday`, `wbs_product_sync_monthday`, `wbs_order_sync_mode`, `wbs_order_sync_time`, `wbs_order_sync_weekday`, `wbs_order_sync_monthday`, `wbs_sync_scheduler_v2` (migration flag).

DB schema marker: `wbs_db_version`.

**Uninstall:** `uninstall.php` removes options, tables, transients, and clears scheduled hooks when the plugin is **deleted** from WordPress.

---

## Cron hooks (summary)

| Hook | Typical schedule | Role |
|------|------------------|------|
| `wbs_cron_sync_products` | User-defined (single-event chain) or **off** | Batch product sync |
| `wbs_cron_sync_orders` | User-defined (single-event chain) | Bulk order import |
| `wbs_cron_ensure_subscription` | Twicedaily (on activate) | Maintain bol PROCESS_STATUS subscription |
| `wbs_cron_purge_logs` | Daily | Log retention |

**Deactivation** clears all four hooks but **keeps** data. **Delete plugin** runs `uninstall.php` for full cleanup.

---

## Security and permissions

- Admin UI and AJAX actions expect **`manage_woocommerce`** (and nonces for AJAX).
- REST webhook route uses `permission_callback` **public** so bol.com can reach it; POST handling should stay minimal and idempotent where possible.
- Secrets (client secret) stored as WordPress options—standard WP hardening and server access control apply.

---

## Developer entry points

- **Bootstrap:** `WooBolSync\Includes\Plugin::bootstrap()`
- **Global accessor:** `woo_bol_sync()` → plugin instance (after `plugins_loaded`).
- **Filters (examples):** `wbs_product_ean`, `wbs_ean_fallback_sources`, `wbs_bol_listing_price` (see `Mapping_Config` and product sync).

---

## Related files in this repo

- `bol-support-email-webhook-technical.md` — technical narrative for bol.com support (webhook validation, flows).
- Optional YAML specs (e.g. economic operator) may be present for reference; runtime behaviour is defined by PHP and bol’s live API.

---

## Changelog discipline

When shipping:

1. Bump **`Version`** header and **`WBS_VERSION`** in `woo-bol-sync.php`.
2. Ensure `Activator::maybe_upgrade()` and any migration flags (e.g. `wbs_sync_scheduler_v2`) remain coherent.
3. Update this overview if user-facing behaviour or options change materially.

---

*Generated as an internal reference for the WooBolSync Pro codebase. For bol.com API contract details, always refer to the official bol.com Retailer API documentation for the versions you have enabled.*
