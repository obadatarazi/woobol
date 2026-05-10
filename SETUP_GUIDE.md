# WooBolSync - Complete Setup & Connection Guide

## Overview
This guide provides complete instructions for connecting your WordPress/WooCommerce store to bol.com using the WooBolSync plugin, based on the bol.com Retailer API v10.

---

## Prerequisites

### System Requirements
- ✅ PHP 8.2 or higher
- ✅ WordPress 6.2 or higher
- ✅ WooCommerce 8.0 or higher
- ✅ Active bol.com seller account

### Required Information
You'll need the following from your bol.com seller account:
1. **Client ID** - OAuth2 client identifier
2. **Client Secret** - OAuth2 client secret
3. **Economic Operator ID** - Required for creating offers

---

## Step 1: Get bol.com API Credentials

### 1.1 Access bol.com Seller Portal
1. Go to [bol.com Seller Portal](https://partner.bol.com/sdd/nl/login)
2. Log in with your seller credentials
3. Navigate to **Settings → API**

### 1.2 Create API Credentials
1. Click "Create API credentials" or similar option
2. Give your API credentials a descriptive name (e.g., "WooCommerce Integration")
3. Save the **Client ID** and **Client Secret** securely
   - ⚠️ **Important**: The Client Secret is only shown once!

---

## Step 2: Configure WooBolSync Plugin

### 2.1 Install and Activate Plugin
1. Ensure the plugin is uploaded to `/wp-content/plugins/WooBol/`
2. Activate the plugin from WordPress admin → Plugins

### 2.2 Enter API Credentials
1. Go to **WooCommerce → Bol.com Sync → Settings**
2. Under the **Connection** section, enter:
   - **Client ID**: Your bol.com client ID
   - **Client Secret**: Your bol.com client secret
3. Click **Save Settings**

### 2.3 Test Connection
The plugin will automatically test the connection to bol.com API. You should see:
- ✅ "Credentials configured" - Client ID and secret are set
- ✅ "API connection" - Connection to bol.com succeeded
- ⚠️ If connection fails, verify your credentials are correct

---

## Step 3: Configure Economic Operator

### What is an Economic Operator?
An Economic Operator is required by EU regulations for selling products on bol.com. It contains your business/seller information.

### 3.1 Fetch Existing Economic Operator
1. In the **Settings** page, locate the **Economic operator** sidebar panel
2. Click **Fetch economic operator**
3. If you already have an operator in bol.com, it will be loaded automatically

### 3.2 Create New Economic Operator (if needed)
If no economic operator exists, fill in the form:
- **Name**: Your business name
- **Street**: Business street address
- **House number**: Building number
- **Postal code**: ZIP/postal code
- **City**: City name
- **Country**: 2-letter country code (e.g., NL, BE, DE)
- **Email**: Business contact email
- **Phone number**: Business phone number
- **Additional address info**: (optional) Floor, unit, etc.
- **External reference**: (optional) Your internal reference

Click **Create economic operator** to save.

---

## Step 4: Configure Product Mapping

### 4.1 Field Mapping
Go to **WooCommerce → Bol.com Sync → Field Mapping** and configure:

#### EAN/GTIN Configuration
- **EAN Source**: Where to get the product EAN/GTIN from
  - Options: SKU, Product attribute, Custom field
  - Recommended: Use `sku` or `meta:_global_unique_id`
  - ⚠️ **Important**: EAN/GTIN must be 12 or 13 digits

#### Title Configuration  
- **Title Source**: Product name or custom field
  - Options: `product_name`, `meta:custom_field`, `attribute:custom_attribute`
  - The plugin automatically builds structured titles with brand, type, and features

#### Description Configuration
- **Description Source**: What to use for bol.com listing description
  - Options: `short_description`, `description`, `short_long`, `long_short`
  - Recommended: `short_description` for concise listings

### 4.2 Category Mapping (Optional)
Go to **WooCommerce → Bol.com Sync → Category Mapping**:
- Map your WooCommerce categories to bol.com category IDs
- Only needed if you want specific category targeting
- Leave unmapped for general catalog sync

---

## Step 5: Configure Sync Settings

### 5.1 Sync Rules
Configure what products to sync:
- **Sync only published products**: Enable to exclude drafts
- **Exclude categories**: Select WooCommerce categories to exclude
- **Default fulfilment method**: 
  - `FBR` (Fulfilled By Retailer) - You handle shipping
  - `FBB` (Fulfilled By Bol) - bol.com handles shipping
- **Default delivery code**: Delivery time code (e.g., `24uurs-21`)

### 5.2 Pricing Configuration
- **Price margin type**: How to adjust WooCommerce prices for bol.com
  - `none`: Use WooCommerce price as-is
  - `percent`: Add percentage markup (e.g., 10% = 1.10x price)
  - `fixed`: Add fixed amount (e.g., €5.00)
- **Price margin value**: The percentage or fixed amount

### 5.3 Product Sync Schedule
Choose when to sync products:
- **Sync mode**: Daily, Weekly, Monthly, or On WooCommerce updates
- **Sync time**: Time of day to run sync (e.g., 02:00)
- **Allow new offers**: Enable to create new offers on bol.com
- **Sync product content**: Enable to sync product details (not just inventory)

### 5.4 Order Import Schedule  
Configure when to import orders from bol.com:
- **Sync mode**: Daily, Weekly, or Monthly
- **Sync time**: Time of day to import orders (e.g., 02:15)

---

## Step 6: Configure Webhooks (Recommended)

### 6.1 Enable Webhooks
1. Go to **Settings → Webhooks** section
2. Enable **Webhook automation**
3. Your webhook URL will be: `https://yoursite.com/wp-json/woobol/v1/webhook`

### 6.2 Configure in bol.com
The plugin automatically manages webhook subscriptions via the API. It will:
- Create a subscription for PROCESS_STATUS events
- Fetch signature keys for verification
- Handle webhook signature validation

### 6.3 Webhook Benefits
- ✅ Real-time updates when bol.com processes offers
- ✅ Automatic status updates for orders
- ✅ Faster sync without polling

---

## Step 7: Advanced Configuration

### 7.1 Staging Mode (Review Before Sync)
- Enable **Staging mode** to review changes before syncing
- Products go into a staging queue for manual approval
- Useful for high-volume stores or strict quality control

### 7.2 API Version
- **Offer API version**: v10 or v11
  - Default: `application/vnd.retailer.v10+json`
  - Alternative: `application/vnd.retailer.v11+json`
  - The plugin automatically falls back if a version fails

### 7.3 Logging
- Enable **Debug logging** for troubleshooting
- Logs are stored in the WordPress database
- View logs at **WooCommerce → Bol.com Sync → Logs**

---

## API Endpoints Reference

### Authentication
```
POST https://login.bol.com/token
Authorization: Basic base64(client_id:client_secret)
Content-Type: application/x-www-form-urlencoded

grant_type=client_credentials
```

Response:
```json
{
  "access_token": "eyJ...",
  "token_type": "Bearer",
  "expires_in": 299
}
```

### Core Endpoints
All API requests use base URL: `https://api.bol.com/retailer`

#### Economic Operators
- `GET /economic-operators` - List operators
- `POST /economic-operator` - Create operator
- `GET /economic-operator/{id}` - Get operator details
- `PUT /economic-operator/{id}` - Update operator
- `DELETE /economic-operator/{id}` - Delete operator

#### Offers
- `POST /offers` - Create single offer
- `GET /offers/{offer-id}` - Get offer details
- `PUT /offers/{offer-id}` - Update offer
- `DELETE /offers/{offer-id}` - Delete offer
- `PUT /offers/{offer-id}/stock` - Update stock

#### Orders
- `GET /orders` - List orders
- `GET /orders/{order-id}` - Get order details
- `PUT /orders/shipment` - Create shipment

#### Process Status (Async)
- `GET /process-status/{id}` - Check async operation status

#### Subscriptions (Webhooks)
- `GET /subscriptions` - List subscriptions
- `POST /subscriptions` - Create subscription
- `GET /subscriptions/{id}` - Get subscription
- `PUT /subscriptions/{id}` - Update subscription
- `DELETE /subscriptions/{id}` - Delete subscription

---

## Common Issues & Solutions

### Issue 1: "Missing bol.com API credentials"
**Cause**: Client ID or Client Secret not configured
**Solution**: 
1. Get credentials from bol.com seller portal
2. Enter them in Settings → Connection section
3. Save settings and test connection

### Issue 2: "No economic operator found"
**Cause**: Economic operator not created in bol.com account
**Solution**:
1. Click "Fetch economic operator" button
2. If none exists, fill in the form and click "Create economic operator"
3. Verify the operator status shows as VALID

### Issue 3: "Invalid EAN"
**Cause**: Product EAN/GTIN is missing or invalid format
**Solution**:
1. Ensure products have valid 12 or 13-digit EAN/GTIN codes
2. Check Field Mapping → EAN Source setting
3. For testing, you can use test EAN: `0000007740404`

### Issue 4: "Offer API call failed with media type error"
**Cause**: API version mismatch
**Solution**:
1. The plugin automatically retries with alternate version
2. Or manually set Offer API version in Settings → Advanced
3. Try both v10 and v11 to see which works

### Issue 5: "HTTP 401 Unauthorized"
**Cause**: Token expired or invalid credentials
**Solution**:
1. Verify Client ID and Client Secret are correct
2. Token is automatically refreshed - check logs for auth errors
3. Re-save credentials to force new token fetch

### Issue 6: "HTTP 429 Too Many Requests"  
**Cause**: API rate limit exceeded
**Solution**:
1. Plugin automatically retries with exponential backoff
2. Reduce sync frequency if hitting limits often
3. Enable staging mode to batch requests

---

## Testing Your Setup

### Test Checklist
- [ ] API credentials entered and connection successful
- [ ] Economic operator created and status is VALID
- [ ] Field mapping configured (EAN, title, description)
- [ ] Sync settings configured
- [ ] Webhook automation enabled
- [ ] Test product created with valid EAN
- [ ] Manual sync test from Dashboard

### Test Product Requirements
For testing, create a WooCommerce product with:
- Valid 13-digit EAN (e.g., `0000007740404`)
- Price > 0
- Stock quantity > 0
- Product status: Published
- Not in excluded categories

### Running First Sync
1. Go to **WooCommerce → Bol.com Sync → Dashboard**
2. Click **Sync Products Now** (if available)
3. Or use **Staging** to review products before syncing
4. Check **Logs** for any errors

---

## API Authentication Flow

The plugin handles authentication automatically:

1. **Initial Request**: Plugin needs to make API call
2. **Check Token Cache**: Look for cached access token in WordPress transients
3. **Token Valid**: Use cached token (valid for ~5 minutes)
4. **Token Expired**: Request new token from `https://login.bol.com/token`
5. **Store Token**: Cache token in transient with TTL
6. **Make Request**: Use Bearer token in Authorization header

```php
// Automatically handled by Bol_API_Service class
$response = $api->request_with_headers('/orders', 'GET');
```

---

## Webhook Signature Verification

The plugin verifies webhook signatures for security:

1. bol.com signs webhook payloads with RSA-SHA256
2. Plugin fetches public keys from `/subscriptions/signature-keys`
3. Verifies `X-Bol-Signature` header against payload
4. Rejects unsigned or invalid requests

Enable/disable in Settings → Webhooks → **Require webhook signatures**

---

## Support & Documentation

### Plugin Support
- Plugin Version: 3.0.19
- Author: Obada Al-Tarazi
- Website: https://cupcoding.com

### bol.com API Documentation
- API Docs: https://developers.bol.com/
- Seller Portal: https://partner.bol.com/sdd/nl/login
- API Version: v10.0

### WordPress/WooCommerce
- WordPress: 6.2+ required
- WooCommerce: 8.0+ required
- PHP: 8.2+ required

---

## Next Steps

After completing setup:

1. **Test with a few products first** - Don't sync your entire catalog immediately
2. **Monitor logs** - Check for any errors or warnings
3. **Review offers in bol.com** - Verify products appear correctly
4. **Test order import** - Place a test order on bol.com
5. **Enable automation** - Once confident, enable scheduled syncs
6. **Monitor webhooks** - Ensure real-time updates are working

---

## Postman Collection

For API testing and debugging, use the official bol.com Postman collection:
- Collection: bol.com retailer API v10.0 public
- Import the collection for manual API testing
- Useful for troubleshooting or custom integrations

---

**Last Updated**: May 9, 2026  
**Plugin Version**: 3.0.19  
**API Version**: v10.0
