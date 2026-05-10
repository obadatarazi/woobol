# WooBolSync - Quick Start Checklist

## Pre-Flight Checklist

### ✅ Step 1: Get bol.com Credentials (5 minutes)
- [ ] Log into [bol.com Seller Portal](https://partner.bol.com/sdd/nl/login)
- [ ] Navigate to Settings → API
- [ ] Create new API credentials
- [ ] Save **Client ID** and **Client Secret** securely

### ✅ Step 2: Configure Plugin (10 minutes)
- [ ] Install and activate WooBolSync plugin
- [ ] Go to **WooCommerce → Bol.com Sync → Settings**
- [ ] Enter Client ID in Connection section
- [ ] Enter Client Secret in Connection section
- [ ] Click **Save Settings**
- [ ] Verify ✅ green checkmarks for:
  - Credentials configured
  - API connection successful

### ✅ Step 3: Setup Economic Operator (5 minutes)
- [ ] Click **Fetch economic operator** button (sidebar)
- [ ] If none exists, fill in the form:
  - [ ] Business name
  - [ ] Street address
  - [ ] House number
  - [ ] Postal code
  - [ ] City
  - [ ] Country code (NL, BE, DE, etc.)
  - [ ] Email address
  - [ ] Phone number
- [ ] Click **Create economic operator** (or **Update** if exists)
- [ ] Verify status shows **VALID**

### ✅ Step 4: Configure Product Mapping (5 minutes)
- [ ] Go to **Field Mapping** page
- [ ] Set **EAN Source**:
  - Recommended: `sku` or `meta:_global_unique_id`
- [ ] Set **Title Source**:
  - Recommended: `product_name`
- [ ] Set **Description Source**:
  - Recommended: `short_description`
- [ ] Click **Save Mapping**

### ✅ Step 5: Configure Sync Settings (5 minutes)
- [ ] Go back to **Settings** page
- [ ] Under **Sync Rules**:
  - [ ] Check "Sync only published products"
  - [ ] Set fulfilment method: **FBR** (you ship) or **FBB** (bol ships)
  - [ ] Set delivery code: **24uurs-21** (24-hour delivery)
- [ ] Under **Product Sync Schedule**:
  - [ ] Enable product sync
  - [ ] Set sync mode: **Daily** (recommended to start)
  - [ ] Set sync time: **02:00** (2 AM)
- [ ] Under **Webhooks**:
  - [ ] Enable webhook automation
- [ ] Click **Save Settings**

### ✅ Step 6: Prepare Test Product (5 minutes)
- [ ] Create or select a WooCommerce product
- [ ] Ensure it has:
  - [ ] Valid 13-digit EAN (e.g., `0000007740404` for testing)
  - [ ] Price > €0.00
  - [ ] Stock quantity > 0
  - [ ] Status: **Published**
  - [ ] Not in excluded categories

### ✅ Step 7: Run First Sync (5 minutes)
- [ ] Go to **Dashboard** page
- [ ] If staging mode is enabled:
  - [ ] Click **Review Staging Queue**
  - [ ] Approve test product
- [ ] Or click **Sync Now** if available
- [ ] Wait for sync to complete
- [ ] Check **Logs** for any errors

### ✅ Step 8: Verify in bol.com (5 minutes)
- [ ] Log into bol.com Seller Portal
- [ ] Navigate to Products/Offers section
- [ ] Verify your test product appears
- [ ] Check price, stock, and details are correct

---

## Quick Reference

### Important URLs
- **WordPress Admin**: `https://yoursite.com/wp-admin/`
- **Plugin Settings**: `WooCommerce → Bol.com Sync → Settings`
- **Field Mapping**: `WooCommerce → Bol.com Sync → Field Mapping`
- **Dashboard**: `WooCommerce → Bol.com Sync → Dashboard`
- **Logs**: `WooCommerce → Bol.com Sync → Logs`
- **bol.com Seller Portal**: https://partner.bol.com/sdd/nl/login
- **bol.com Developer Docs**: https://developers.bol.com/

### Default Settings
- **Fulfilment Method**: FBR (Fulfilled By Retailer)
- **Delivery Code**: 24uurs-21
- **Offer API Version**: v10
- **Sync Time**: 02:00 (2 AM)
- **Price Margin**: None (WooCommerce price as-is)

### Test Data
- **Test EAN**: `0000007740404` (13-digit)
- **Test EAN**: `000000000000` (12-digit)

---

## Health Check

### Connection Status
Go to **Settings** page and verify:
- ✅ **Credentials configured**: Shows "Client ID and secret are set"
- ✅ **API connection**: Shows "Connection to bol.com succeeded"
- ✅ **Webhook automation**: Shows "Webhook automation is enabled"

### Economic Operator Status
Check sidebar panel shows:
- ✅ **Status**: VALID
- ✅ **ID**: Shows operator ID code
- ✅ **Last fetched**: Recent timestamp

### Product Sync Status
Go to **Dashboard** and check:
- ✅ **Last sync**: Shows recent date/time
- ✅ **Synced products**: Shows count > 0
- ✅ **Errors**: Shows 0 or minimal errors

### Logs Status
Go to **Logs** and verify:
- ✅ No recent **error** level logs
- ✅ Recent **info** logs show successful syncs
- ⚠️ **warning** logs are acceptable if resolved

---

## Common First-Time Issues

### Issue: "Missing bol.com API credentials"
**Fix**: Enter Client ID and Client Secret in Settings → Connection

### Issue: "No economic operator found"
**Fix**: Click "Fetch economic operator" or fill in the form to create one

### Issue: "Invalid EAN"
**Fix**: Ensure products have valid 12 or 13-digit EAN codes (numbers only)

### Issue: "Product not synced"
**Fix**: Check product is Published, has price > 0, has stock, has EAN

### Issue: "Connection failed"
**Fix**: Verify credentials are correct, server can reach bol.com API

---

## Next Steps After Setup

### For Small Stores (< 100 products)
1. ✅ Enable daily sync
2. ✅ Enable webhook automation  
3. ✅ Sync all products at once
4. ✅ Monitor logs for errors
5. ✅ Enable automatic order import

### For Large Stores (100+ products)
1. ✅ Enable staging mode
2. ✅ Sync products in batches
3. ✅ Review staging queue regularly
4. ✅ Enable webhook automation
5. ✅ Schedule sync during off-peak hours

### For Development/Testing
1. ✅ Use test products with test EANs
2. ✅ Enable debug logging
3. ✅ Test with small quantities
4. ✅ Mark offers "on hold" initially
5. ✅ Test order import with real orders

---

## Documentation Files

### Complete Guides
- **SETUP_GUIDE.md**: Comprehensive setup instructions (10 pages)
- **API_REFERENCE.md**: Complete API endpoint reference (8 pages)
- **TROUBLESHOOTING.md**: Detailed troubleshooting guide (12 pages)
- **QUICK_START.md**: This checklist (2 pages)

### Recommended Reading Order
1. **QUICK_START.md** (this file) - 10 minutes
2. **SETUP_GUIDE.md** - sections relevant to your needs
3. **API_REFERENCE.md** - when you need specific endpoint details
4. **TROUBLESHOOTING.md** - when you encounter issues

---

## Support Resources

### Plugin Documentation
- Version: 3.0.19
- Author: Obada Al-Tarazi
- Website: https://cupcoding.com

### bol.com Resources
- Developer Portal: https://developers.bol.com/
- API Documentation: v10.0
- Seller Portal: https://partner.bol.com/sdd/nl/login

### WordPress/WooCommerce
- Minimum PHP: 8.2
- Minimum WordPress: 6.2
- Minimum WooCommerce: 8.0

---

## Success Indicators

You'll know setup is successful when:

- ✅ Plugin shows all green checkmarks in Settings
- ✅ Economic operator status is VALID
- ✅ Test product appears in bol.com Seller Portal
- ✅ Logs show successful API requests
- ✅ Webhooks are delivering events (check logs)
- ✅ Orders import automatically from bol.com
- ✅ Stock updates sync in both directions

---

## Maintenance Schedule

### Daily
- Check Dashboard for sync status
- Review error logs (if any)

### Weekly
- Verify webhook subscription active
- Check order import working
- Review product sync success rate

### Monthly
- Update plugin if new version available
- Review and archive old logs
- Verify API credentials still valid
- Check economic operator status

---

## Quick Command Reference

### WP-CLI Commands (if available)
```bash
# Check plugin status
wp plugin list | grep woo-bol

# View options
wp option get wbs_client_id
wp option get wbs_economic_operator_id

# Check cron
wp cron event list | grep wbs

# Clear transients
wp transient delete wbs_bol_access_token
```

### Database Queries (advanced)
```sql
-- Check product mappings
SELECT COUNT(*) FROM wp_wbs_product_mapping;

-- Check recent logs
SELECT * FROM wp_wbs_logs 
ORDER BY created_at DESC LIMIT 10;

-- Check order mappings
SELECT COUNT(*) FROM wp_wbs_order_mapping;
```

---

## Emergency Reset

If you need to start fresh:

1. **Backup first!**
   ```bash
   mysqldump wordpress_db > backup.sql
   ```

2. **Clear plugin data**:
   - Go to Settings → Advanced
   - Click "Clear all mappings" (if available)
   - Or run SQL:
   ```sql
   TRUNCATE TABLE wp_wbs_product_mapping;
   TRUNCATE TABLE wp_wbs_order_mapping;
   DELETE FROM wp_wbs_logs;
   ```

3. **Reset credentials**:
   - Delete Client ID and Secret
   - Save settings
   - Re-enter credentials

4. **Refetch operator**:
   - Click "Fetch economic operator"

5. **Resync products**:
   - Go to Dashboard
   - Run full sync

⚠️ **Warning**: This will break existing product/order links!

---

**Last Updated**: May 9, 2026  
**Plugin Version**: 3.0.19  
**Estimated Setup Time**: 45 minutes  
**Difficulty**: Easy to Moderate
