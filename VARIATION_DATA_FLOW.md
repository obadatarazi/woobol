# Variation Product Handling - Data Source Guide

## Overview

Each variation is treated as a **separate individual product** on bol.com. The plugin intelligently combines data from the parent product and the specific variation.

---

## Data Source Matrix

| Data Field | Source | Fallback | Example |
|------------|--------|----------|---------|
| **Title** | Parent + Attributes | None | "T-Shirt - Red / Large" |
| **Short Description** | Parent | None | Parent product's short description |
| **Long Description** | Parent | None | Parent product's long description |
| **EAN** | Variation | Parent | Variation's own barcode |
| **Price** | Variation | Parent | Variation's own price |
| **Stock** | Variation | None | Variation's own stock level |
| **SKU** | Variation | None | Variation's own SKU |
| **Main Image** | Variation | Parent | Variation-specific image or parent |
| **Gallery** | Parent | None | Parent product gallery |
| **Categories** | Parent | None | Parent product categories |
| **Tags** | Parent | None | Parent product tags |

---

## How It Works

### 1. Title Generation

**Formula**: `Parent Name + " - " + Attribute Values`

**Examples**:
- Parent: "Nike Air Max"
- Variation (Size: 42, Color: Black) → **"Nike Air Max - 42 / Black"**
- Variation (Size: 43, Color: White) → **"Nike Air Max - 43 / White"**

### 2. Descriptions

**Always from parent** - Variations share the same content/SEO text.

```php
Short Description: Parent product short description
Long Description: Parent product long description
```

This makes sense because:
- ✅ One content to maintain for SEO
- ✅ Same product story for all variations
- ✅ Easier content management

### 3. EAN (Barcode)

**Priority**: Variation → Parent

**Scenario A: Each variation has unique barcode**
```
Parent EAN: (empty)
├─ Variation 1 EAN: 1234567890123 → Uses: 1234567890123
├─ Variation 2 EAN: 1234567890124 → Uses: 1234567890124
└─ Variation 3 EAN: 1234567890125 → Uses: 1234567890125
```

**Scenario B: All variations share one barcode**
```
Parent EAN: 1234567890123
├─ Variation 1 EAN: (empty) → Uses: 1234567890123
├─ Variation 2 EAN: (empty) → Uses: 1234567890123
└─ Variation 3 EAN: (empty) → Uses: 1234567890123
```

**Scenario C: Mix (some have unique, some shared)**
```
Parent EAN: 1234567890123
├─ Variation 1 EAN: 1111111111111 → Uses: 1111111111111
├─ Variation 2 EAN: (empty) → Uses: 1234567890123
└─ Variation 3 EAN: 2222222222222 → Uses: 2222222222222
```

### 4. Price

**Priority**: Variation → Parent

**Use Case**: T-shirts in different sizes

```
Parent Price: €19.99
├─ Size S: €19.99 (uses parent)
├─ Size M: €19.99 (uses parent)
├─ Size L: €21.99 (override) → Uses: €21.99
└─ Size XL: €23.99 (override) → Uses: €23.99
```

### 5. Stock

**Always from variation** - Each variation has independent stock.

```
Parent: Variable product (no stock)
├─ Red / Small: Stock = 10
├─ Red / Medium: Stock = 5
├─ Red / Large: Stock = 0 (out of stock)
├─ Blue / Small: Stock = 15
└─ Blue / Medium: Stock = 3
```

Each becomes a separate offer on bol.com with its own stock level.

### 6. Images

**Priority**: Variation → Parent

**Example: Phone case colors**
```
Parent Image: Generic product photo
├─ Black case: black-case.jpg → Uses: black-case.jpg
├─ Red case: red-case.jpg → Uses: red-case.jpg
├─ Blue case: (no image) → Uses: Parent generic photo
└─ Green case: green-case.jpg → Uses: green-case.jpg
```

---

## Real-World Examples

### Example 1: Clothing Store (T-Shirt)

**Parent Product**:
- Name: "Premium Cotton T-Shirt"
- Short Description: "Comfortable, breathable cotton tee"
- Long Description: "Made from 100% organic cotton..."
- EAN: 5012345678900
- Price: €19.99
- Main Image: white-tshirt-front.jpg
- Gallery: [front.jpg, back.jpg, detail.jpg]

**Variations**:

| Size | Color | Variation EAN | Price | Image | Stock |
|------|-------|--------------|-------|-------|-------|
| S | Red | (empty) | €19.99 | red-s.jpg | 10 |
| M | Red | (empty) | €19.99 | red-m.jpg | 5 |
| L | Red | (empty) | €19.99 | red-l.jpg | 0 |
| S | Blue | (empty) | €19.99 | blue-s.jpg | 8 |

**Result on bol.com** (4 separate offers):

**Offer 1**:
- Title: "Premium Cotton T-Shirt - S / Red"
- Description: "Comfortable, breathable cotton tee"
- EAN: 5012345678900 (inherited from parent)
- Price: €19.99
- Image: red-s.jpg
- Stock: 10

**Offer 2**:
- Title: "Premium Cotton T-Shirt - M / Red"
- Description: "Comfortable, breathable cotton tee"
- EAN: 5012345678900 (inherited from parent)
- Price: €19.99
- Image: red-m.jpg
- Stock: 5

... and so on for each variation.

---

### Example 2: Electronics Store (Phone Case)

**Parent Product**:
- Name: "iPhone 14 Pro Case"
- Short Description: "Slim protective case"
- Long Description: "Military-grade protection..."
- EAN: (empty)
- Price: €29.99
- Main Image: case-generic.jpg

**Variations**:

| Color | Variation EAN | Price | Image | Stock |
|-------|---------------|-------|-------|-------|
| Black | 1111111111111 | €29.99 | black.jpg | 50 |
| Red | 2222222222222 | €29.99 | red.jpg | 30 |
| Blue | 3333333333333 | €34.99 | blue.jpg | 15 |

**Result on bol.com** (3 separate offers):

**Offer 1**:
- Title: "iPhone 14 Pro Case - Black"
- Description: "Slim protective case"
- EAN: 1111111111111 (variation's own)
- Price: €29.99
- Stock: 50

**Offer 2**:
- Title: "iPhone 14 Pro Case - Red"
- Description: "Slim protective case"
- EAN: 2222222222222 (variation's own)
- Price: €29.99
- Stock: 30

**Offer 3**:
- Title: "iPhone 14 Pro Case - Blue"
- Description: "Slim protective case"
- EAN: 3333333333333 (variation's own)
- Price: €34.99 (premium color)
- Stock: 15

---

## Best Practices

### ✅ DO: Recommended Setup

**For Parent Product**:
1. Write detailed title, short description, long description
2. Set EAN if all variations share one barcode
3. Set base price if all variations have same price
4. Upload main product image and gallery
5. Assign categories and tags

**For Each Variation**:
1. Set attributes (Size, Color, etc.) - **REQUIRED**
2. Set EAN if variation has unique barcode
3. Set price if different from parent
4. Upload variation-specific image (if applicable)
5. Manage stock independently

### ❌ DON'T: Common Mistakes

1. ❌ **Don't** duplicate descriptions on each variation
2. ❌ **Don't** leave both parent and variation without EAN
3. ❌ **Don't** expect variation descriptions to override parent
4. ❌ **Don't** forget to set attributes on variations
5. ❌ **Don't** use same SKU for multiple variations

---

## Configuration Examples

### Scenario: Same Product, Multiple Sizes

```
✅ Best Setup:
Parent:
  - Name: "Running Shoes Pro"
  - Description: "Professional running shoes with..."
  - EAN: 9876543210123
  - Price: €89.99
  - Image: shoes-main.jpg

Variations:
  - Size 40: Attributes only, stock=10
  - Size 41: Attributes only, stock=15
  - Size 42: Attributes only, stock=8
  - Size 43: Attributes only, stock=5

Result: 4 offers, all share EAN/price/descriptions, differ only in size and stock
```

### Scenario: Different Products (Variations Have Unique Barcodes)

```
✅ Best Setup:
Parent:
  - Name: "Laptop Bag Professional"
  - Description: "Durable, waterproof laptop bag..."
  - EAN: (empty - not needed)
  - Price: €59.99
  - Image: bag-generic.jpg

Variations:
  - 13" model: EAN=1111, Price=€59.99, Image=13inch.jpg, Stock=20
  - 15" model: EAN=2222, Price=€69.99, Image=15inch.jpg, Stock=15
  - 17" model: EAN=3333, Price=€79.99, Image=17inch.jpg, Stock=10

Result: 3 offers, each with unique EAN/price/image, share descriptions
```

---

## Technical Details

### Data Flow

1. **Ingestion Phase** (`ingest_variation`):
   ```
   Get from variation → If empty → Get from parent → If empty → Error/Warning
   ```

2. **Payload Building** (`build_variation_mapped_payload`):
   ```
   Title: Parent name + " - " + Variation attributes
   Description: Parent description (always)
   EAN: Variation EAN (if set) OR Parent EAN
   Price: Variation price (if > 0) OR Parent price
   Stock: Variation stock (always)
   Image: Variation image (if set) OR Parent image
   ```

3. **Sync Phase** (`sync_variation_offer`):
   ```
   Each variation → Separate API call → Individual bol.com offer
   ```

### Database Storage

Each variation is stored in `wp_wbs_variation_sync_draft` with:
- Link to parent draft (`product_draft_id`)
- Variation-specific data (EAN, price, stock, SKU)
- Inherited data stored in `mapped_payload_json`
- Final combined data in `final_payload_json`

---

## Validation Rules

### Must Have (from variation OR parent):
- ✅ EAN (13 digits)
- ✅ Price (> €0)
- ✅ Stock quantity
- ✅ At least one attribute

### Should Have (warnings if missing):
- ⚠️ SKU
- ⚠️ Variation-specific image

### Always From Parent:
- ✅ Title base
- ✅ Short description
- ✅ Long description
- ✅ Gallery images
- ✅ Categories
- ✅ Tags

---

## Summary

**Your variation handling works like this**:

📝 **Content** (Title, Descriptions) → FROM PARENT  
🔢 **Identifiers** (EAN, SKU) → FROM VARIATION (with parent fallback)  
💰 **Pricing** → FROM VARIATION (with parent fallback)  
📦 **Stock** → FROM VARIATION (always)  
🖼️ **Images** → FROM VARIATION (with parent fallback)  
🏷️ **Categories/Tags** → FROM PARENT

**Result**: Each variation becomes a **complete, individual product** on bol.com!

---

**Last Updated**: May 9, 2026  
**Version**: 3.0.19  
**Status**: ✅ Working as designed
