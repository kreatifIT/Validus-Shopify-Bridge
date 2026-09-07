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

`config('validus-shopify.grouping')` controls how `code.code` (e.g. `"56070025"`) is split into a grouping key and a vintage year. The defaults assume a common layout: first 2 digits = product, last 2 digits = vintage (with a `20` century prefix). The digits in between are intentionally ignored - bottle size/format comes from the separate `code.bottleCapacity` + `code.measureUnit` API fields instead. Confirm the exact digit layout with your customer; if their Validus code scheme doesn't fit this pattern at all, supply your own `Kreatif\ValidusShopifyBridge\Grouping\VariantGroupingStrategy` implementation and bind it in your own service provider instead of `ProductCodeGroupingStrategy`.

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
# Preview what would be created/updated, without writing anything to Shopify:
php artisan validus-shopify:sync-products --dry-run

# Actually sync:
php artisan validus-shopify:sync-products
```

`--dry-run` prints a table of every product/variant it would touch - whether it's a new Shopify product or an update to an already-mapped one, the SKU, vintage, format and price it would write - and a summary count. Nothing is sent to Shopify in this mode; run it before the first real sync (and after any config change) to check the output looks right rather than finding out by looking at the live catalog.

Schedule it in `routes/console.php` or `bootstrap/app.php` if it should run automatically, e.g.:

```php
Schedule::command('validus-shopify:sync-products')->hourly();
```

### Deactivating products Validus stops listing

Every real (non-dry-run) sync also checks already-linked variants against the current Validus catalog. A variant whose Validus product has disappeared entirely (discontinued, or removed by mistake) is made unavailable to buy:

- inventory tracking is turned on if it wasn't already (an untracked variant is always purchasable no matter what, so this is required for the next two steps to actually do anything),
- stock is set to 0,
- `inventoryPolicy` is set to `DENY` so it can't be oversold in the meantime.

If that was the *only* Shopify variant still mapped to a given product, the whole product is also set to `ARCHIVED`. A product with other, still-current variants (e.g. a different vintage) is left alone - only the specific removed variant is touched.

Nothing is deleted, and `sync-products` never un-links a `validus_shopify_product_map` row on its own: if Validus starts listing the product again and it's synced, the existing mapping is reused (updated, not duplicated). The variant's inventory policy/stock is **not** automatically restored on reactivation though - flip that back manually in Shopify Admin once confirmed.

This is controlled by the `deactivation` config block:

```php
'deactivation' => [
    'enabled' => env('VALIDUS_SHOPIFY_AUTO_DEACTIVATE', true),
    'max_removed_ratio' => (float) env('VALIDUS_SHOPIFY_MAX_REMOVED_RATIO', 0.5),
],
```

`max_removed_ratio` is a safety net: if the share of already-linked products that would be deactivated in one run exceeds it, the whole deactivation step is skipped for that run (the rest of the sync still happens normally) and a warning is logged instead. This is meant to catch a bad or partial Validus response - an API hiccup that doesn't throw, or a temporarily incomplete price list - being mistaken for a mass discontinuation; a real, large batch of intentional discontinuations would need `max_removed_ratio` raised (or the deactivation step run manually after reviewing `validus-shopify:diff`). Requires `shopify.location_id` to be set - silently does nothing without it, same as inventory quantity syncing.

`validus-shopify:diff` reports the same "in Shopify but missing from Validus" set read-only, without writing anything - useful to check what a sync *would* deactivate ahead of time, or to audit it independent of the schedule.

New variants are imported **without** inventory tracking enabled (a manual, per-variant decision in Shopify Admin). Once a variant is flipped to tracked in Shopify, subsequent syncs push `qtyInStock` for it automatically.

### A failing product doesn't stop the rest of the sync

Each Validus product group (one Shopify product, e.g. all vintages/formats of one wine) is synced independently. If a group fails - most commonly Shopify rejecting a `productSet` call because two distinct Validus products collide on the same option values, see [Troubleshooting](#troubleshooting) below - that one group is skipped, `sync-products` prints it and exits non-zero, but every other group and the deactivation step still run. A `Kreatif\ValidusShopifyBridge\Events\ProductSyncGroupFailed` event fires for each failure (carrying the group key, product title and the exception) - the package doesn't send any notification itself, so bind a listener in the consuming app if you want one (email, Slack, etc.).

## Adopting a Shopify catalog that already has products in it

`sync-products` only ever consults its own `validus_shopify_product_map` table to decide whether a Validus product is new (create) or already known (update) - it never checks Shopify itself. If the store already has products in it from before this package was introduced (imported manually, or by a previous process) and they happen to share a SKU with a Validus product, the first real sync would create a **duplicate** product for every one of them instead of updating the existing one, since nothing is mapped yet.

Before running the first real sync on such a store, check for this and link any matches:

```bash
# See what a real sync would do beyond sync-products --dry-run: which SKUs already
# exist in Shopify but aren't linked yet (would become duplicates), which are
# genuinely new, and which already-linked variants have a price different from Validus:
php artisan validus-shopify:diff

# Also list variants that are linked and unchanged:
php artisan validus-shopify:diff --all

# Link every Validus product to its already-existing Shopify variant by matching SKU
# (writes to validus_shopify_product_map only, never touches Shopify itself):
php artisan validus-shopify:link-existing --dry-run
php artisan validus-shopify:link-existing
```

Run `link-existing` once per store as part of onboarding it onto this package (a store with no pre-existing catalog can skip it - `sync-products` alone is enough). `diff` is safe to run at any time afterwards too, e.g. to spot-check for price drift or for a `diff`-listed product that Validus stopped returning entirely (`sync-products` only ever adds/updates a mapping, never removes one, so a discontinued product stays untouched in Shopify until someone acts on it manually).

## Troubleshooting

### `Access denied for productSet field. Required access: 'write_products' access scope`

The Shopify Admin API token doesn't have the scopes this package needs. Fix it in Shopify Admin, not in code:

1. *Settings → Apps and sales channels → Develop apps* → open the app the `SHOPIFY_ADMIN_TOKEN` belongs to.
2. Tab **"Configuration"** → **"Admin API integration"** → *Edit* the API scopes.
3. Enable at least **`write_products`** (covers `productSet`, `productVariantsBulkUpdate`, `productUpdate`) and **`write_inventory`** (covers `inventorySetQuantities`, `inventoryItemUpdate`, both used by the deactivation step). Add **`read_products`** / **`read_inventory`** too, for the read-only lookups (`variantsBySku`, `variantInventoryState`).
4. Save, then **reinstall the app** - Shopify only issues a token with the new scopes after a reinstall, changing the scope list alone isn't enough. The existing `SHOPIFY_ADMIN_TOKEN` value usually stays valid (same token, now with more scopes); double check it on the same screen right after.

If the error persists after reinstalling with the right scopes, the second half of the message ("the user must have a permission to create products") points at the *Shopify staff account* that installed/owns the app rather than the token itself - on Shopify Plus stores with locked staff permissions, that account also needs the "Products" permission.

### `The variant 'X / Y' already exists. Please change at least one option value.`

Two **distinct** Validus products (different `id`) resolve to the same Shopify option values (vintage + format) under the configured `VariantGroupingStrategy` - `productSet` then tries to create two variants with an identical option combination on the same product, which Shopify rejects. This is a data problem, not something to silently work around: find out from Validus/the customer what actually distinguishes the two products (a code digit the grouping strategy currently ignores, a packaging/lot difference, or simply a duplicate/erroneous entry that Validus should remove) before deciding how to disambiguate them - guessing risks silently merging or mispricing a real product.

To find every colliding pair for a given `VariantGroupingStrategy` ahead of time:

```php
$grouping = app(\Kreatif\ValidusShopifyBridge\Grouping\VariantGroupingStrategy::class);
$byKey = collect(app(\Kreatif\ValidusShopifyBridge\Clients\ValidusClient::class)->getProducts())
    ->groupBy(fn ($p) => $grouping->groupKey($p));

foreach ($byKey as $key => $group) {
    foreach ($group->groupBy(fn ($p) => $grouping->vintageYear($p).'/'.($p['code']['bottleCapacity'] ?? '?')) as $combo => $items) {
        if ($items->count() > 1) {
            echo "{$key} {$items->first()['name']} - {$combo}: ".$items->pluck('code.code')->implode(', ').PHP_EOL;
        }
    }
}
```

Until it's resolved, `sync-products` skips the affected group (see [above](#a-failing-product-doesnt-stop-the-rest-of-the-sync)) rather than failing the whole run or guessing which of the two to keep.

## Known open items

These are deliberately left unhandled rather than guessed at - the corresponding code path throws instead of sending incomplete data:

- Discount/voucher line items and Italian customers' `fiscalId` (codice fiscale, not collected by Shopify's default checkout).
- Payment gateways not yet listed in `payment_code_map`.
- A Shopify order line item whose variant was never imported from Validus (no `ProductMap` entry).

## Using with Claude Code

`skills/validus-shopify-bridge/SKILL.md` covers the failure modes in this README that are easy to misdiagnose as a code bug (missing Shopify API scopes, an option-name mismatch, a Validus-side config error, colliding product codes, ...). If you use Claude Code on a project that installs this package, copy that file to `.claude/skills/validus-shopify-bridge/SKILL.md` in the project so Claude picks it up automatically - composer packages aren't scanned for skills on their own.

## Testing

```bash
composer test
```

Uses Orchestra Testbench + `Http::fake()` against both the Validus API and the Shopify Admin GraphQL API (no third-party Shopify SDK involved, so both go through Laravel's own HTTP client and are equally fakeable).
