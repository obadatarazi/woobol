# WooBolSync - WordPress bol.com Integration

**Version**: 3.0.19  
**Author**: Obada Al-Tarazi  
**Website**: https://cupcoding.com

Production-grade WooCommerce ↔ bol.com integration with product sync, order sync, stock management, webhooks, and comprehensive error handling.

---

## 🚀 Quick Start

**New to the plugin?** Start here:

1. **Read**: [QUICK_START.md](QUICK_START.md) - 10-minute checklist (45 min total setup)
2. **Setup**: Follow the 8-step checklist
3. **Test**: Sync 1-5 test products first
4. **Deploy**: Gradually enable full sync

---

## 📚 Documentation

### Core Documentation

| Document | Description | Time | When to Read |
|----------|-------------|------|--------------|
| [QUICK_START.md](QUICK_START.md) | Fast setup checklist with step-by-step guide | 10 min | **Start here** |
| [SETUP_GUIDE.md](SETUP_GUIDE.md) | Complete setup instructions with detailed explanations | 45 min | After quick start |
| [API_REFERENCE.md](API_REFERENCE.md) | Full API endpoint reference and integration details | 30 min | When customizing |
| [TROUBLESHOOTING.md](TROUBLESHOOTING.md) | Comprehensive troubleshooting guide | 40 min | When issues occur |
| [ANALYSIS.md](ANALYSIS.md) | Technical analysis and code review | 20 min | For developers |

### Quick Links

- **Getting Started**: [QUICK_START.md](QUICK_START.md)
- **API Credentials**: [SETUP_GUIDE.md#step-1-get-bolcom-api-credentials](SETUP_GUIDE.md#step-1-get-bolcom-api-credentials)
- **Field Mapping**: [SETUP_GUIDE.md#step-4-configure-product-mapping](SETUP_GUIDE.md#step-4-configure-product-mapping)
- **Common Issues**: [TROUBLESHOOTING.md#common-issues](TROUBLESHOOTING.md#common-issues)
- **API Endpoints**: [API_REFERENCE.md#economic-operators](API_REFERENCE.md#economic-operators)

---

## ✅ Features

### Product Management
- ✅ Two-way product sync (WooCommerce ↔ bol.com)
- ✅ Create, update, delete offers
- ✅ Real-time stock updates
- ✅ Price management with configurable margins
- ✅ EAN/GTIN support with multiple sources
- ✅ Product content sync (title, description, images)
- ✅ Category mapping (WooCommerce → bol.com)
- ✅ Staging mode (review before sync)

### Order Management
- ✅ Automatic order import from bol.com
- ✅ Order status synchronization
- ✅ Shipment tracking
- ✅ Order mapping (prevents duplicates)
- ✅ Scheduled order sync (daily/weekly/monthly)

### Automation
- ✅ Webhook support for real-time updates
- ✅ Webhook signature verification (RSA-SHA256)
- ✅ Automatic subscription management
- ✅ WP-Cron scheduled syncs
- ✅ Smart sync (only changed products)

### Developer Features
- ✅ WordPress filter hooks for customization
- ✅ Comprehensive logging system
- ✅ REST API webhook endpoint
- ✅ Database mapping tables
- ✅ WooCommerce HPOS compatible

---

## 📋 Requirements

### System Requirements
- **PHP**: 8.2 or higher
- **WordPress**: 6.2 or higher
- **WooCommerce**: 8.0 or higher
- **Server**: cURL, OpenSSL support
- **Database**: MySQL 5.7+ or MariaDB 10.2+

### bol.com Requirements
- Active bol.com seller account
- API credentials (Client ID + Secret)
- Economic Operator (EU requirement)
- Products with valid EAN/GTIN codes

### Recommended
- SSL certificate (required for webhooks)
- Dedicated server or managed hosting
- Regular backups
- Staging environment for testing

---

## 🎯 Who Should Use This?

### Perfect For
✅ **Online retailers** selling on bol.com marketplace  
✅ **Multi-channel sellers** using WooCommerce as master catalog  
✅ **Drop shippers** needing automated order management  
✅ **Large catalogs** (100-10,000+ products)  
✅ **Stores requiring review workflow** (staging mode)

### Not Suitable For
❌ Stores without EAN/GTIN codes on products  
❌ Services/digital products (bol.com requires physical products)  
❌ Stores unable to meet bol.com's requirements  
❌ WordPress sites without WooCommerce

---

## 🔧 Installation

### Method 1: Manual Upload (Recommended)
1. Download or clone this plugin
2. Upload to `/wp-content/plugins/WooBol/`
3. Activate via WordPress admin → Plugins
4. Go to WooCommerce → Bol.com Sync → Settings
5. Follow [QUICK_START.md](QUICK_START.md) checklist

### Method 2: Direct Installation
```bash
cd /path/to/wordpress/wp-content/plugins/
git clone [repository-url] WooBol
cd WooBol
# Plugin is ready - activate in WordPress admin
```

### Post-Installation
1. Get bol.com API credentials: [Seller Portal](https://partner.bol.com/sdd/nl/login)
2. Configure plugin: WooCommerce → Bol.com Sync → Settings
3. Follow setup guide: [SETUP_GUIDE.md](SETUP_GUIDE.md)

---

## ⚙️ Configuration

### Basic Setup (Required)

#### 1. API Credentials
```
WooCommerce → Bol.com Sync → Settings → Connection
- Client ID: [from bol.com]
- Client Secret: [from bol.com]
```

#### 2. Economic Operator
```
Settings → Economic operator (sidebar)
- Fetch existing operator, or
- Create new operator with business details
```

#### 3. Field Mapping
```
WooCommerce → Bol.com Sync → Field Mapping
- EAN Source: sku | meta:_global_unique_id | attribute:pa_ean
- Title Source: product_name | custom
- Description Source: short_description | description
```

#### 4. Sync Settings
```
Settings → Sync Rules
- Fulfilment method: FBR (you ship) | FBB (bol ships)
- Delivery code: 24uurs-21 (24-hour delivery)
- Sync schedule: Daily at 02:00
```

### Advanced Configuration (Optional)

#### Price Margins
Apply markup to WooCommerce prices:
```php
Settings → Sync Rules → Price margin
- Type: none | percent | fixed
- Value: 0 | 10 (percent) | 5.00 (fixed amount)
```

#### Category Exclusions
Exclude specific categories from sync:
```
Settings → Sync Rules → Exclude categories
Select categories to exclude
```

#### Staging Mode
Review products before syncing:
```
Settings → Advanced → Staging mode
- Enable staging mode
- Enable auto-ingest (optional)
```

#### Webhooks
Real-time updates from bol.com:
```
Settings → Webhooks
- Enable webhook automation
- Require webhook signatures (recommended)
```

---

## 🔌 API Integration

### Authentication
The plugin uses OAuth2 Client Credentials flow:

```php
// Automatic authentication handling
$api = new \WooBolSync\Services\Bol_API_Service();
$response = $api->request_with_headers('/orders', 'GET');

// Token is cached in transients
// Auto-refreshed when expired
```

### API Endpoints Used

#### Economic Operators
```http
GET    /retailer/economic-operators
POST   /retailer/economic-operator
GET    /retailer/economic-operator/{id}
PUT    /retailer/economic-operator/{id}
DELETE /retailer/economic-operator/{id}
```

#### Offers
```http
POST   /retailer/offers
GET    /retailer/offers/{id}
PUT    /retailer/offers/{id}
DELETE /retailer/offers/{id}
PUT    /retailer/offers/{id}/stock
```

#### Orders
```http
GET    /retailer/orders
GET    /retailer/orders/{id}
```

#### Subscriptions (Webhooks)
```http
GET    /retailer/subscriptions
POST   /retailer/subscriptions
GET    /retailer/subscriptions/signature-keys
```

Full endpoint reference: [API_REFERENCE.md](API_REFERENCE.md)

---

## 🎨 Customization

### WordPress Filters

#### Customize EAN Source
```php
add_filter('wbs_product_ean', function($ean, $product) {
    // Return custom EAN
    return get_post_meta($product->get_id(), '_custom_ean', true);
}, 10, 2);
```

#### Customize Price
```php
add_filter('wbs_bol_listing_price', function($price, $base_price) {
    // Apply 15% markup
    return $price * 1.15;
}, 10, 2);
```

#### Customize Brand
```php
add_filter('wbs_default_brand', function($brand, $product) {
    // Set brand per product
    return $product->get_meta('_brand_name') ?: $brand;
}, 10, 2);
```

#### EAN Fallback Sources
```php
add_filter('wbs_ean_fallback_sources', function($chain, $primary, $product) {
    // Add custom EAN source to fallback chain
    $chain[] = 'meta:_my_custom_ean';
    return $chain;
}, 10, 3);
```

### Database Tables

The plugin creates these tables:

| Table | Purpose |
|-------|---------|
| `wp_wbs_logs` | API and sync logs |
| `wp_wbs_product_mapping` | WooCommerce ↔ bol.com product links |
| `wp_wbs_order_mapping` | WooCommerce ↔ bol.com order links |
| `wp_wbs_category_mapping` | Category mappings |
| `wp_wbs_sync_batches` | Staging sync batches |
| `wp_wbs_product_sync_draft` | Staging product drafts |
| `wp_wbs_variation_sync_draft` | Staging variation drafts |
| `wp_wbs_sync_job` | Sync job tracking |
| `wp_wbs_sync_job_item` | Individual sync items |
| `wp_wbs_sync_audit` | Sync audit trail |

---

## 🧪 Testing

### Test Credentials Setup
1. Get test credentials from bol.com (if available)
2. Or use production credentials with caution
3. Start with test products

### Test EAN Codes
Use these for testing (not real products):
- `0000007740404` - 13-digit test EAN
- `000000000000` - 12-digit test EAN

### Test Checklist
- [ ] API connection successful
- [ ] Economic operator created/fetched
- [ ] Test product has valid EAN
- [ ] Test product price > 0
- [ ] Test product stock > 0
- [ ] Test product is published
- [ ] Sync creates offer in bol.com
- [ ] Webhook receives events
- [ ] Order import works
- [ ] Logs show no errors

---

## 📊 Performance

### Benchmarks (Typical Store)

**Store Size**: 500 products, 50 orders/day

| Metric | Value |
|--------|-------|
| API requests/day | ~600 |
| Database queries/day | ~5,000 |
| Memory usage (per sync) | ~64MB |
| Sync time (full) | ~30 seconds |
| Sync time (incremental) | ~10 seconds |

### Optimization Tips
1. ✅ Enable staging mode for large catalogs (1000+ products)
2. ✅ Use smart sync (only changed products)
3. ✅ Schedule sync during off-peak hours
4. ✅ Increase PHP memory limit to 256MB+ for large syncs
5. ✅ Enable opcode caching (OPcache)

---

## 🐛 Troubleshooting

### Quick Diagnostics

#### Check Connection
```
WooCommerce → Bol.com Sync → Settings
Look for green checkmarks:
✅ Credentials configured
✅ API connection
✅ Webhook automation
```

#### View Logs
```
WooCommerce → Bol.com Sync → Logs
Filter by level: error, warning
Filter by category: api, sync, webhook
```

#### Common Issues

| Issue | Quick Fix |
|-------|-----------|
| Missing credentials | Enter Client ID and Secret in Settings |
| No economic operator | Click "Fetch economic operator" button |
| Invalid EAN | Ensure products have 12-13 digit EAN codes |
| Product not synced | Check: published, has price, has stock, has EAN |
| Connection failed | Verify credentials, check server can reach bol.com |

Full troubleshooting guide: [TROUBLESHOOTING.md](TROUBLESHOOTING.md)

---

## 📈 Monitoring

### Health Check Dashboard
```
WooCommerce → Bol.com Sync → Dashboard
```

Check these indicators:
- ✅ **Connection status**: Green = healthy
- ✅ **Last sync**: Recent timestamp
- ✅ **Synced products**: Count matches expectations
- ✅ **Error rate**: <5% is acceptable
- ✅ **Webhook status**: Active subscription

### Log Monitoring
```
WooCommerce → Bol.com Sync → Logs
```

Monitor for:
- 🔴 **Errors**: HTTP 401, 403, 500, timeout, invalid data
- 🟡 **Warnings**: HTTP 429, retry, fallback, validation
- 🟢 **Info**: Successful sync, order import, webhook received

---

## 🔒 Security

### Best Practices
✅ Store credentials in `wp-config.php` (optional, more secure)  
✅ Enable webhook signature verification  
✅ Use SSL certificate (required for webhooks)  
✅ Limit admin access to plugin settings  
✅ Regularly review logs for suspicious activity  
✅ Keep WordPress/WooCommerce updated  
✅ Use strong API credentials (regenerate if compromised)

### Data Protection
- ✅ API credentials stored in WordPress options (not in code)
- ✅ OAuth2 tokens cached in transients (auto-expire)
- ✅ Webhook signatures verified with RSA-SHA256
- ✅ All inputs sanitized with WordPress functions
- ✅ All outputs escaped in templates

---

## 🤝 Support

### Documentation
- **Quick Start**: [QUICK_START.md](QUICK_START.md)
- **Setup Guide**: [SETUP_GUIDE.md](SETUP_GUIDE.md)
- **API Reference**: [API_REFERENCE.md](API_REFERENCE.md)
- **Troubleshooting**: [TROUBLESHOOTING.md](TROUBLESHOOTING.md)
- **Analysis**: [ANALYSIS.md](ANALYSIS.md)

### Plugin Support
- **Author**: Obada Al-Tarazi
- **Website**: https://cupcoding.com
- **Version**: 3.0.19

### bol.com Support
- **Developer Portal**: https://developers.bol.com/
- **Seller Portal**: https://partner.bol.com/sdd/nl/login
- **API Version**: v10.0

### Before Contacting Support
1. Check [TROUBLESHOOTING.md](TROUBLESHOOTING.md)
2. Review logs: WooCommerce → Bol.com Sync → Logs
3. Collect debug info:
   - Plugin version
   - WordPress version
   - WooCommerce version
   - PHP version
   - Error message
   - Steps to reproduce

---

## 📝 License

**Proprietary License**

This plugin is proprietary software. Unauthorized copying, modification, distribution, or use is strictly prohibited.

Copyright © 2024 Obada Al-Tarazi / CupCoding

---

## 🎉 Getting Started

**Ready to connect your store to bol.com?**

1. **Start here**: [QUICK_START.md](QUICK_START.md) (10 min read)
2. **Get credentials**: [bol.com Seller Portal](https://partner.bol.com/sdd/nl/login)
3. **Configure plugin**: WooCommerce → Bol.com Sync → Settings
4. **Test with 1-5 products** before full sync
5. **Enable automation** once confident

**Estimated setup time**: 45 minutes  
**Difficulty**: Easy to Moderate

---

## 📅 Version History

### Version 3.0.19 (Current)
- Production-ready release
- Full bol.com Retailer API v10 support
- OAuth2 authentication
- Product sync with staging mode
- Order import
- Webhook automation
- Comprehensive logging
- WooCommerce HPOS compatible

---

## 🙏 Acknowledgments

- **bol.com** for comprehensive API documentation
- **WooCommerce** for excellent e-commerce platform
- **WordPress** for solid CMS foundation

---

**Last Updated**: May 9, 2026  
**Plugin Version**: 3.0.19  
**API Version**: v10.0  
**Status**: ✅ Production Ready
