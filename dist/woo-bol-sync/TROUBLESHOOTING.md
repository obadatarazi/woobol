# WooBolSync - Troubleshooting & Debugging Guide

## Quick Diagnostics

### Check Connection Status
1. Go to **WooCommerce → Bol.com Sync → Settings**
2. Look for connection status indicators:
   - ✅ **Credentials configured**: Client ID and Secret are set
   - ✅ **API connection**: Successfully connected to bol.com
   - ✅ **Webhook automation**: Webhooks are enabled and working

### View Logs
1. Go to **WooCommerce → Bol.com Sync → Logs**
2. Filter by:
   - **Level**: error, warning, info, debug
   - **Category**: api, sync, webhook, order
   - **Date range**: Last 24 hours, 7 days, 30 days

---

## Common Issues

### 1. Authentication Issues

#### Error: "Missing bol.com API credentials"
**Symptoms:**
- Cannot connect to API
- Settings show "Credentials not configured"

**Causes:**
- Client ID or Client Secret not entered
- Credentials cleared accidentally

**Solutions:**
1. Get credentials from [bol.com Seller Portal](https://partner.bol.com/sdd/nl/login)
2. Go to Settings → Connection
3. Enter Client ID and Client Secret
4. Click "Save Settings"
5. Verify green checkmark appears

#### Error: "HTTP 401 Unauthorized"
**Symptoms:**
- API requests fail with 401 status
- Token refresh errors in logs

**Causes:**
- Invalid Client ID or Secret
- Credentials revoked in bol.com
- Expired or malformed token

**Solutions:**
1. Verify credentials in bol.com Seller Portal
2. Regenerate API credentials if needed
3. Delete and re-enter credentials in WordPress
4. Clear WordPress transients:
   ```php
   delete_transient('wbs_bol_access_token');
   ```

#### Error: "Could not retrieve bol.com access token (HTTP 400)"
**Symptoms:**
- Cannot get OAuth2 token
- Authentication fails immediately

**Causes:**
- Incorrect Client ID format
- Incorrect Client Secret format
- Network/firewall blocking `login.bol.com`

**Solutions:**
1. Copy credentials carefully (no extra spaces)
2. Verify Base64 encoding of credentials
3. Check server can reach `login.bol.com`:
   ```bash
   curl -v https://login.bol.com/token
   ```
4. Check firewall/security rules

---

### 2. Economic Operator Issues

#### Error: "No economic operator found"
**Symptoms:**
- Cannot create offers
- "Economic operator: not found" message

**Causes:**
- No economic operator created in bol.com
- Economic operator deleted
- API connection issue

**Solutions:**
1. Click **Fetch economic operator** button
2. If none found, fill in the form:
   - Name, address, email, phone
   - All fields are required
3. Click **Create economic operator**
4. Verify status shows VALID

#### Error: "Economic operator status: INVALID"
**Symptoms:**
- Operator fetched but marked invalid
- Cannot use operator for offers

**Causes:**
- Incomplete information
- Validation failed in bol.com
- EU compliance issue

**Solutions:**
1. Update operator with complete information
2. Ensure valid EU address
3. Verify email and phone are reachable
4. Contact bol.com support if persists

---

### 3. Product Sync Issues

#### Error: "Invalid EAN"
**Symptoms:**
- Products fail validation
- "EAN must be 12 or 13 digits" error

**Causes:**
- Product missing EAN/GTIN
- EAN has incorrect format (contains letters/spaces)
- EAN source misconfigured

**Solutions:**
1. Check Field Mapping → EAN Source setting
2. Verify products have valid EAN:
   - 12 digits (UPC) or 13 digits (EAN)
   - Only numbers, no letters or spaces
3. Add EAN to products:
   - SKU field, or
   - Custom field `_global_unique_id`, or
   - Product attribute `pa_ean`
4. For testing, use: `0000007740404`

#### Error: "Product price is 0"
**Symptoms:**
- Products not synced
- Validation error about price

**Causes:**
- WooCommerce product has no price set
- Price is empty or 0

**Solutions:**
1. Set Regular Price in WooCommerce product
2. If on sale, ensure Sale Price is set
3. Check price margin settings don't result in 0

#### Error: "Product not published"
**Symptoms:**
- Products skipped during sync
- Not appearing in staging

**Causes:**
- Product status is Draft/Pending
- "Sync only published" enabled

**Solutions:**
1. Publish products in WooCommerce
2. Or disable "Sync only published products" in Settings

#### Error: "Product in excluded category"
**Symptoms:**
- Certain products never sync
- No validation errors

**Causes:**
- Product category is in exclusion list

**Solutions:**
1. Go to Settings → Sync Rules
2. Check "Exclude categories" setting
3. Remove category from exclusion list

---

### 4. Offer API Issues

#### Error: "Offer API call failed with media type error"
**Symptoms:**
- HTTP 406 or 415 errors
- "Unsupported media type" message

**Causes:**
- API version mismatch (v10 vs v11)
- Incorrect Accept/Content-Type headers

**Solutions:**
1. Plugin automatically retries with alternate version
2. If persists, manually set version:
   - Go to Settings → Advanced
   - Change "Offer API version"
   - Try both v10 and v11
3. Check logs for which version works

#### Error: "HTTP 429 Too Many Requests"
**Symptoms:**
- API requests failing
- Sync stops mid-process

**Causes:**
- Rate limit exceeded
- Too many requests in short time

**Solutions:**
1. Plugin automatically retries with backoff
2. Reduce sync frequency
3. Enable staging mode to batch requests
4. Wait 1-5 minutes before retrying

#### Error: "Process status timeout"
**Symptoms:**
- Offers stay in "pending" status
- Async operations never complete

**Causes:**
- bol.com processing delay
- Network timeout
- Process stuck

**Solutions:**
1. Check process status manually in bol.com
2. Increase timeout in plugin (if needed)
3. Wait and retry later
4. Contact bol.com if process truly stuck

---

### 5. Webhook Issues

#### Error: "Webhook signature verification failed"
**Symptoms:**
- Webhooks rejected
- "Invalid signature" in logs

**Causes:**
- Signature keys not fetched
- Clock skew between servers
- Payload modified in transit

**Solutions:**
1. Fetch signature keys:
   - Plugin does this automatically
   - Or manually trigger in Settings
2. Verify server time is accurate:
   ```bash
   date
   ```
3. Temporarily disable signature checking (testing only):
   - Settings → Webhooks → Uncheck "Require webhook signatures"

#### Error: "Webhook URL not accessible"
**Symptoms:**
- bol.com cannot reach webhook endpoint
- No webhook events received

**Causes:**
- Server behind firewall
- SSL certificate issue
- WordPress REST API disabled

**Solutions:**
1. Verify webhook URL is accessible:
   ```bash
   curl -X POST https://yoursite.com/wp-json/woobol/v1/webhook
   ```
2. Check SSL certificate is valid
3. Verify WordPress REST API enabled
4. Check firewall allows incoming POST requests
5. Verify webhook URL in subscription:
   ```
   https://yoursite.com/wp-json/woobol/v1/webhook
   ```

---

### 6. Order Import Issues

#### Error: "No orders found"
**Symptoms:**
- Order sync returns no orders
- Known orders not importing

**Causes:**
- Date filter too restrictive
- Fulfilment method filter
- Orders already imported

**Causes:**
1. Check order sync date range
2. Verify fulfilment method (FBR vs FBB)
3. Check order status filter (ALL, OPEN, etc.)
4. Look in WooCommerce orders for existing imports

#### Error: "Order already exists"
**Symptoms:**
- Duplicate order warnings
- Orders skipped

**Causes:**
- Order already imported previously
- Duplicate order ID in bol.com

**Solutions:**
1. Normal behavior - orders are only imported once
2. Check order mapping table for existing link
3. If need to re-import, delete WooCommerce order first

---

### 7. Database Issues

#### Error: "Table does not exist"
**Symptoms:**
- Plugin errors on activation
- Missing data

**Causes:**
- Plugin not properly activated
- Database creation failed
- Table prefix mismatch

**Solutions:**
1. Deactivate and reactivate plugin
2. Check database for tables:
   - `wp_wbs_logs`
   - `wp_wbs_product_mapping`
   - `wp_wbs_order_mapping`
   - `wp_wbs_category_mapping`
   - `wp_wbs_sync_batches`
3. Manually run activation:
   ```php
   do_action('wbs_activate');
   ```

---

## Debugging Tools

### Enable Debug Mode
1. Edit `wp-config.php`:
   ```php
   define('WP_DEBUG', true);
   define('WP_DEBUG_LOG', true);
   define('WP_DEBUG_DISPLAY', false);
   ```
2. Check `/wp-content/debug.log` for errors

### Enable Plugin Debug Logging
1. Go to Settings → Advanced & Logs
2. Enable "Debug logging"
3. View detailed logs in Logs page

### Test API Connection Manually
Use WP-CLI or PHP:
```php
// Test connection
$api = new \WooBolSync\Services\Bol_API_Service();
$result = $api->test_connection();
var_dump($result);

// Get last error
echo $api->get_last_error();
```

### Check WordPress Cron
Ensure WP-Cron is running:
```bash
wp cron event list
```

Look for `wbs_` prefixed events:
- `wbs_product_sync`
- `wbs_order_sync`
- `wbs_subscription_sync`

### Manual Sync Trigger
```php
do_action('wbs_manual_product_sync');
do_action('wbs_manual_order_sync');
```

---

## Network & Server Issues

### SSL/TLS Issues
**Error:** "cURL error 60: SSL certificate problem"

**Solutions:**
1. Update server CA certificates:
   ```bash
   sudo update-ca-certificates
   ```
2. Or disable SSL verification (NOT recommended for production):
   ```php
   add_filter('https_ssl_verify', '__return_false');
   ```

### Timeout Issues
**Error:** "cURL error 28: Operation timed out"

**Solutions:**
1. Increase PHP timeout:
   ```php
   set_time_limit(300);
   ```
2. Increase WP HTTP timeout:
   ```php
   add_filter('http_request_timeout', function() { return 60; });
   ```

### Firewall/Security
**Error:** "Connection refused" or "Connection timed out"

**Solutions:**
1. Check server can reach bol.com:
   ```bash
   curl -v https://api.bol.com/retailer/
   ```
2. Whitelist bol.com IPs if needed
3. Check security plugins (Wordfence, Sucuri, etc.)
4. Verify no rate limiting on server

---

## Data Integrity

### Verify Product Mapping
Check database for correct mappings:
```sql
SELECT * FROM wp_wbs_product_mapping 
WHERE wc_product_id = 123;
```

### Verify Order Mapping
Check order linkage:
```sql
SELECT * FROM wp_wbs_order_mapping 
WHERE bol_order_id = '123456';
```

### Clear Stale Data
If data seems corrupted:
```sql
-- Clear product mappings
TRUNCATE TABLE wp_wbs_product_mapping;

-- Clear order mappings  
TRUNCATE TABLE wp_wbs_order_mapping;

-- Clear logs
DELETE FROM wp_wbs_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY);
```

⚠️ **Warning:** This will require re-syncing all products!

---

## Performance Issues

### Slow Sync
**Symptoms:**
- Sync takes very long
- Server timeouts

**Solutions:**
1. Enable staging mode to sync in smaller batches
2. Reduce number of products synced at once
3. Increase server resources (PHP memory, max execution time)
4. Schedule sync during off-peak hours

### High Memory Usage
**Solutions:**
1. Increase PHP memory limit:
   ```php
   ini_set('memory_limit', '512M');
   ```
2. Process products in smaller batches
3. Disable debug logging if enabled

---

## Getting Help

### Check Plugin Logs
1. Go to **Logs** page
2. Filter by level: **error** or **warning**
3. Look for recent entries
4. Copy full error message

### Collect Debug Info
Provide this information when asking for help:
- Plugin version: 3.0.19
- WordPress version
- WooCommerce version
- PHP version
- Error message (full text)
- Steps to reproduce
- Recent log entries

### Contact Support
- Plugin Author: Obada Al-Tarazi
- Website: https://cupcoding.com
- Include debug info above

### bol.com Support
For API-specific issues:
- Developer Portal: https://developers.bol.com/
- Seller Support: https://partner.bol.com/

---

## Advanced Debugging

### Enable MySQL Query Log
```sql
SET GLOBAL general_log = 'ON';
SET GLOBAL general_log_file = '/var/log/mysql/query.log';
```

### Monitor API Requests
Add logging to track all API calls:
```php
add_action('wbs_api_request', function($url, $method, $response) {
    error_log("API: $method $url - HTTP " . $response['code']);
}, 10, 3);
```

### Test Individual Components
```php
// Test economic operator
$api = new \WooBolSync\Services\Bol_API_Service();
$result = $api->search_economic_operators();
print_r($result);

// Test offer creation
$result = $api->request_with_headers('/offers', 'POST', [
    'ean' => '0000007740404',
    'condition' => ['name' => 'NEW', 'category' => 'NEW'],
    // ... rest of offer data
]);
print_r($result);
```

---

## Preventive Maintenance

### Regular Checks
- [ ] Test API connection weekly
- [ ] Review error logs monthly
- [ ] Verify economic operator status
- [ ] Check webhook subscription active
- [ ] Monitor sync success rate

### Backup Before Changes
Before major config changes:
```bash
# Backup database
mysqldump wordpress_db > backup.sql

# Backup plugin settings
wp option get wbs_client_id
wp option get wbs_economic_operator_id
# ... etc
```

### Update Checklist
When updating plugin:
1. Backup database
2. Test on staging site first
3. Verify API connection after update
4. Check settings preserved
5. Run test sync

---

**Last Updated**: May 9, 2026  
**Plugin Version**: 3.0.19
