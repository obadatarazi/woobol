# WooBolSync - API Quick Reference

## Authentication

### Get Access Token
```http
POST https://login.bol.com/token
Authorization: Basic {base64(client_id:client_secret)}
Content-Type: application/x-www-form-urlencoded

grant_type=client_credentials
```

**Response:**
```json
{
  "access_token": "eyJraWQ...",
  "token_type": "Bearer",
  "expires_in": 299
}
```

**Usage in Requests:**
```http
Authorization: Bearer {access_token}
Accept: application/vnd.retailer.v10+json
Content-Type: application/vnd.retailer.v10+json
```

---

## Economic Operators

### Search Economic Operators
```http
GET https://api.bol.com/retailer/economic-operators
Accept: application/vnd.economic-operator.v1+json
```

### Get Economic Operator
```http
GET https://api.bol.com/retailer/economic-operator/{operatorId}
Accept: application/vnd.economic-operator.v1+json
```

### Create Economic Operator
```http
POST https://api.bol.com/retailer/economic-operator
Content-Type: application/vnd.economic-operator.v1+json

{
  "name": "Your Business Name",
  "street": "Main Street",
  "houseNumber": "123",
  "postalCode": "1234AB",
  "city": "Amsterdam",
  "country": "NL",
  "emailAddress": "business@example.com",
  "phoneNumber": "+31201234567"
}
```

### Update Economic Operator
```http
PUT https://api.bol.com/retailer/economic-operator/{operatorId}
Content-Type: application/vnd.economic-operator.v1+json

{
  "name": "Updated Business Name",
  ...
}
```

### Delete Economic Operator
```http
DELETE https://api.bol.com/retailer/economic-operator/{operatorId}
Accept: application/vnd.economic-operator.v1+json
```

---

## Offers

### Create Single Offer
```http
POST https://api.bol.com/retailer/offers
Accept: application/vnd.retailer.v10+json
Content-Type: application/vnd.retailer.v10+json

{
  "ean": "9780997326215",
  "condition": {
    "name": "NEW",
    "category": "NEW"
  },
  "reference": "TEST001",
  "onHoldByRetailer": false,
  "unknownProductTitle": "Product Title",
  "pricing": {
    "bundlePrices": [
      {
        "quantity": 1,
        "unitPrice": 9.99
      }
    ]
  },
  "stock": {
    "amount": 10,
    "managedByRetailer": false
  },
  "fulfilment": {
    "method": "FBR",
    "deliveryCode": "24uurs-21"
  }
}
```

**Response:**
```json
{
  "processStatusId": "abc-123-def"
}
```

### Get Single Offer
```http
GET https://api.bol.com/retailer/offers/{offerId}
Accept: application/vnd.retailer.v10+json
```

### Update Offer
```http
PUT https://api.bol.com/retailer/offers/{offerId}
Accept: application/vnd.retailer.v10+json
Content-Type: application/vnd.retailer.v10+json

{
  "reference": "UPDATED-REF",
  "onHoldByRetailer": false,
  "pricing": {
    "bundlePrices": [
      {
        "quantity": 1,
        "unitPrice": 12.99
      }
    ]
  },
  "stock": {
    "amount": 5,
    "managedByRetailer": false
  },
  "fulfilment": {
    "method": "FBR",
    "deliveryCode": "24uurs-21"
  }
}
```

### Update Offer Stock
```http
PUT https://api.bol.com/retailer/offers/{offerId}/stock
Accept: application/vnd.retailer.v10+json
Content-Type: application/vnd.retailer.v10+json

{
  "amount": 25,
  "managedByRetailer": false
}
```

### Delete Offer
```http
DELETE https://api.bol.com/retailer/offers/{offerId}
Accept: application/vnd.retailer.v10+json
```

---

## Orders

### Get List of Orders
```http
GET https://api.bol.com/retailer/orders?status=ALL&page=1
Accept: application/vnd.retailer.v10+json
```

**Query Parameters:**
- `fulfilment-method`: FBR or FBB
- `status`: ALL, OPEN, SHIPPED, CANCELLED, etc.
- `page`: Page number (default: 1)
- `change-interval-minute`: Filter by last changed (minutes)
- `latest-change-date`: Filter by change date (YYYY-MM-DD)

**Response:**
```json
{
  "orders": [
    {
      "orderId": "123456",
      "orderPlacedDateTime": "2026-05-09T10:30:00+02:00",
      "orderItems": [
        {
          "orderItemId": "789012",
          "ean": "9780997326215",
          "quantity": 1,
          "offerPrice": 9.99,
          "offerReference": "TEST001"
        }
      ],
      "shipmentDetails": {
        "firstName": "John",
        "surname": "Doe",
        "streetName": "Main Street",
        "houseNumber": "123",
        "zipCode": "1234AB",
        "city": "Amsterdam",
        "countryCode": "NL"
      }
    }
  ]
}
```

### Get Single Order
```http
GET https://api.bol.com/retailer/orders/{orderId}
Accept: application/vnd.retailer.v10+json
```

---

## Process Status (Async Operations)

### Check Process Status
```http
GET https://api.bol.com/retailer/process-status/{processStatusId}
Accept: application/vnd.retailer.v10+json
```

**Response:**
```json
{
  "id": "abc-123-def",
  "entityId": "offer-id-456",
  "eventType": "CREATE_OFFER",
  "status": "SUCCESS",
  "createTimestamp": "2026-05-09T10:30:00.000Z"
}
```

**Status Values:**
- `PENDING`: Processing
- `SUCCESS`: Completed successfully
- `FAILURE`: Failed with error
- `TIMEOUT`: Timed out

---

## Subscriptions (Webhooks)

### List Subscriptions
```http
GET https://api.bol.com/retailer/subscriptions
Accept: application/vnd.retailer.v10+json
```

### Create Subscription
```http
POST https://api.bol.com/retailer/subscriptions
Accept: application/vnd.retailer.v10+json
Content-Type: application/vnd.retailer.v10+json

{
  "resources": ["PROCESS_STATUS"],
  "url": "https://yoursite.com/wp-json/woobol/v1/webhook"
}
```

### Get Subscription
```http
GET https://api.bol.com/retailer/subscriptions/{subscriptionId}
Accept: application/vnd.retailer.v10+json
```

### Update Subscription
```http
PUT https://api.bol.com/retailer/subscriptions/{subscriptionId}
Accept: application/vnd.retailer.v10+json
Content-Type: application/vnd.retailer.v10+json

{
  "resources": ["PROCESS_STATUS"],
  "url": "https://yoursite.com/wp-json/woobol/v1/webhook"
}
```

### Delete Subscription
```http
DELETE https://api.bol.com/retailer/subscriptions/{subscriptionId}
Accept: application/vnd.retailer.v10+json
```

### Get Signature Keys (for webhook verification)
```http
GET https://api.bol.com/retailer/subscriptions/signature-keys
Accept: application/vnd.retailer.v10+json
```

**Response:**
```json
{
  "signatureKeys": [
    {
      "id": "key-id-1",
      "type": "RSA",
      "publicKey": "-----BEGIN PUBLIC KEY-----\n...\n-----END PUBLIC KEY-----"
    }
  ]
}
```

### Send Test Notification
```http
POST https://api.bol.com/retailer/subscriptions/test/{subscriptionId}
Accept: application/vnd.retailer.v10+json
```

---

## Product Content

### Create Product Content
```http
POST https://api.bol.com/retailer/content/products
Accept: application/vnd.retailer.v10+json
Content-Type: application/vnd.retailer.v10+json

{
  "internalReference": "product-123",
  "bundlePricesPrice": 9.99,
  "attributes": [
    {
      "id": "Title",
      "value": "Product Title"
    },
    {
      "id": "Brand",
      "value": "Brand Name"
    }
  ]
}
```

### Get Catalog Product
```http
GET https://api.bol.com/retailer/content/catalog-products/{ean}?accept-language=nl
Accept: application/vnd.retailer.v10+json
Accept-Language: nl
```

### Get Content Upload Report
```http
GET https://api.bol.com/retailer/content/upload-report/{uploadId}
Accept: application/vnd.retailer.v10+json
```

---

## Commissions

### Get Commission by EAN
```http
GET https://api.bol.com/retailer/commission/{ean}?condition=NEW&unit-price=9.99
Accept: application/vnd.retailer.v10+json
```

### Get Commissions in Bulk
```http
POST https://api.bol.com/retailer/commission
Accept: application/vnd.retailer.v10+json
Content-Type: application/vnd.retailer.v10+json

{
  "commissionQueries": [
    {
      "ean": "9780997326215",
      "condition": "NEW",
      "unitPrice": 9.99
    }
  ]
}
```

---

## Error Responses

### Common Error Format
```json
{
  "type": "https://api.bol.com/problems/validation-error",
  "title": "Validation Error",
  "status": 400,
  "detail": "The request contains invalid data",
  "violations": [
    {
      "name": "ean",
      "reason": "EAN must be 12 or 13 digits"
    }
  ]
}
```

### HTTP Status Codes
- `200 OK`: Success
- `201 Created`: Resource created
- `202 Accepted`: Async operation started
- `204 No Content`: Success with no response body
- `400 Bad Request`: Invalid request data
- `401 Unauthorized`: Invalid or expired token
- `403 Forbidden`: Insufficient permissions
- `404 Not Found`: Resource not found
- `406 Not Acceptable`: Invalid Accept header / API version
- `415 Unsupported Media Type`: Invalid Content-Type
- `422 Unprocessable Entity`: Validation failed
- `429 Too Many Requests`: Rate limit exceeded
- `500 Internal Server Error`: Server error
- `502 Bad Gateway`: Gateway error
- `503 Service Unavailable`: Service temporarily unavailable

---

## Rate Limits

bol.com API enforces rate limits:
- Retry after rate limit: Check `Retry-After` header
- Typical limits: ~100 requests/minute per endpoint
- Exponential backoff recommended

**Plugin Handling:**
- Automatic retry with exponential backoff
- Respects `Retry-After` header
- Configurable retry count (default: 2 retries)

---

## Testing

### Test EAN Codes
Use these for testing (not real products):
- `0000007740404` - 13-digit test EAN
- `000000000000` - 12-digit test EAN

### Test Environment
bol.com does not provide a sandbox environment. Use:
- Low-value test products
- Small quantities
- Mark offers as "on hold" initially

### Postman Collection
Import the official collection for manual testing:
- Collection ID: `53632893-d541e6bf-4e05-4d27-9f66-d479e75566c5`
- Workspace: [Gold Equinox Workspace](https://gold-equinox-4582551.postman.co)

---

## WordPress/WooCommerce Integration

### Plugin Database Tables
- `wp_wbs_logs` - API and sync logs
- `wp_wbs_product_mapping` - WooCommerce ↔ bol.com product mapping
- `wp_wbs_order_mapping` - WooCommerce ↔ bol.com order mapping
- `wp_wbs_category_mapping` - Category mappings
- `wp_wbs_sync_batches` - Staging sync batches
- `wp_wbs_product_sync_draft` - Staging product drafts
- `wp_wbs_sync_job` - Sync job tracking

### WordPress REST Endpoints
- `POST /wp-json/woobol/v1/webhook` - bol.com webhook receiver

### WooCommerce Hooks
The plugin uses standard WooCommerce hooks:
- `woocommerce_update_product` - Product update detection
- `woocommerce_new_product` - New product detection
- `woocommerce_product_set_stock` - Stock change detection

---

## Plugin Architecture

### Key Classes
- `Bol_API_Service` - API client wrapper
- `Product_Sync_Service` - Product sync logic
- `Order_Sync_Service` - Order import logic
- `Staging_Sync_Service` - Staging/review mode
- `Subscription_Sync_Service` - Webhook management
- `Webhook_Controller` - Webhook receiver
- `Mapping_Config` - Configuration helper

### Filters & Actions
```php
// Customize EAN source
add_filter('wbs_product_ean', function($ean, $product) {
    return 'custom-ean';
}, 10, 2);

// Customize price sent to bol.com
add_filter('wbs_bol_listing_price', function($price, $base_price) {
    return $price * 1.15; // 15% markup
}, 10, 2);

// Customize brand value
add_filter('wbs_default_brand', function($brand, $product) {
    return 'My Brand';
}, 10, 2);
```

---

**Last Updated**: May 9, 2026  
**API Version**: v10.0  
**Plugin Version**: 3.0.19
