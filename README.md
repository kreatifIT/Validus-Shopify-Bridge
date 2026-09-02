# kreatif/validus-shopify-bridge

Synchronizes products, prices and stock from the [Validus](https://registri.wine) ERP into Shopify, and reports paid Shopify orders back to Validus.

Framework-agnostic within the Laravel ecosystem: no dependency on Statamic or any particular Shopify integration package, so it works the same way for any client running Validus + Shopify.

## What it does

- **Product import** (`artisan validus-shopify:sync-products`): fetches the Validus catalog, groups the flat per-vintage/per-format rows into Shopify products with variants, and creates/updates them in Shopify (price, SKU, stock).
- **Order export**: listens for a Shopify `orders/paid` webhook, converts the order into the JSON shape Validus expects, and reports it - once, even if Shopify redelivers the webhook.

## Installation

```bash
composer require kreatif/validus-shopify-bridge
php artisan vendor:publish --tag=validus-shopify-config
php artisan migrate
```

## Configuration

All settings live in `config/validus-shopify.php` / your `.env`. If a Shopify package (like `statamic-rad-pack/shopify`) is already installed, the `SHOPIFY_*` variables it already uses are picked up automatically - only the `VALIDUS_*` variables are required beyond that:

```env
VALIDUS_API_URL=https://registri.wine/ecommerce_bridge
VALIDUS_API_KEY=

# Only needed if no other Shopify package already sets these:
# SHOPIFY_APP_URL=your-shop.myshopify.com
# SHOPIFY_ADMIN_TOKEN=
# SHOPIFY_WEBHOOK_SECRET=

VALIDUS_SHOPIFY_LOCATION_ID=
```

`VALIDUS_SHOPIFY_LOCATION_ID` is the Shopify location inventory gets written to. Find it once via the Shopify Admin GraphQL explorer:

```graphql
query { locations(first: 5) { nodes { id name } } }
```

### Product code format

`config('validus-shopify.grouping')` controls how `code.code` (e.g. `"56070025"`) is split into a grouping key and a vintage year. The defaults match Kellerei St. Michael's confirmed format: first 2 digits = product, last 2 digits = vintage (with a `20` century prefix). The digits in between are intentionally ignored - bottle size/format comes from the separate `code.bottleCapacity` + `code.measureUnit` API fields instead. A future customer with a different Validus code scheme can supply their own `Kreatif\ValidusShopifyBridge\Grouping\VariantGroupingStrategy` implementation and bind it in their own service provider instead of `ProductCodeGroupingStrategy`.

### Payment codes

`config('validus-shopify.payment_code_map')` maps a Shopify payment gateway name (`payment_gateway_names` on the order) to the payment code Validus expects, e.g.:

```php
'payment_code_map' => [
    'shopify_payments' => 'CC',
],
```

An order paid through a gateway that isn't in this map fails the export job rather than guessing - add the missing gateway here.

### Prices

Validus delivers `price.fullPrice` **net** (excl. VAT). Whether that needs to be grossed up before writing to Shopify depends on the shop's own tax settings (`taxesIncluded`) - **verify this in Shopify Admin before going live**, then set:

```env
VALIDUS_SHOPIFY_PRICES_INCLUDE_TAX=true
```

to gross up using each product's own `tax.rate` from Validus. Defaults to `false` (price passed through unchanged).

## Webhook setup

In Shopify Admin, register a webhook for the **`orders/paid`** topic (not `orders/create` - Validus only wants orders reported once they're paid) pointing at:

```
POST https://your-app.example/webhook/order
```

The route is protected by an HMAC signature check against `SHOPIFY_WEBHOOK_SECRET` (see `Kreatif\ValidusShopifyBridge\Http\Middleware\VerifyShopifyWebhookSignature`).

## Running the product sync

```bash
# Preview what would be grouped, without writing to Shopify:
php artisan validus-shopify:sync-products --dry-run

# Actually sync:
php artisan validus-shopify:sync-products
```

Schedule it in `routes/console.php` or `bootstrap/app.php` if it should run automatically, e.g.:

```php
Schedule::command('validus-shopify:sync-products')->hourly();
```

New variants are imported **without** inventory tracking enabled (manual decision per variant in Shopify Admin, matching the existing workflow at Kellerei St. Michael). Once a variant is flipped to tracked in Shopify, subsequent syncs push `qtyInStock` for it automatically.

## Known open items

These are deliberately left unhandled rather than guessed at - the corresponding code path throws instead of sending incomplete data:

- Discount/voucher line items and Italian customers' `fiscalId` (codice fiscale, not collected by Shopify's default checkout).
- Payment gateways not yet listed in `payment_code_map`.
- A Shopify order line item whose variant was never imported from Validus (no `ProductMap` entry).

## Testing

```bash
composer test
```

Uses Orchestra Testbench + `Http::fake()` against both the Validus API and the Shopify Admin GraphQL API (no third-party Shopify SDK involved, so both go through Laravel's own HTTP client and are equally fakeable).
