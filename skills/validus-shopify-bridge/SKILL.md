---
name: validus-shopify-bridge
description: "Working with kreatif/validus-shopify-bridge - syncs products/prices/stock from the Validus (registri.wine) ERP into Shopify and reports paid orders back. Activate when debugging validus-shopify:sync-products, validus-shopify:diff, or validus-shopify:link-existing; investigating a ShopifyApiException or ValidusApiException from this package; configuring config/validus-shopify.php or VALIDUS_*/VALIDUS_SHOPIFY_* env vars; setting up the orders/paid webhook; onboarding a new client store onto this package; or when the user mentions Validus, registri.wine, or this package by name."
license: proprietary
metadata:
  author: kreatif
---

# kreatif/validus-shopify-bridge

Full docs live in the package's own `README.md` (`vendor/kreatif/validus-shopify-bridge/README.md` in a consuming app) - read it before making changes. This skill is a quick-reference for the failure modes that are easy to misdiagnose as a code bug when they're actually a config or Shopify-side permissions issue.

## What it does, in one paragraph

`validus-shopify:sync-products` fetches the flat Validus catalog (one row per vintage/format), groups it into Shopify products via a `VariantGroupingStrategy` (default: `ProductCodeGroupingStrategy`, first N digits of `code.code` = product, last N digits = vintage year), and upserts each group into Shopify via the `productSet` GraphQL mutation - matching an existing Shopify product through its own `validus_shopify_product_map` table, never by asking Shopify directly. A separate webhook (`POST /webhook/order`, HMAC-verified) reports paid orders back to Validus via `ExportOrderToValidusJob`.

## Before ever running a real (non-dry-run) sync on a store

1. **Check for a pre-existing catalog.** If the Shopify store already had products in it before this package was installed, `sync-products` doesn't know that - it only ever consults its own map table. Run `validus-shopify:diff` first; anything in the "FOUND IN SHOPIFY BUT NOT LINKED" section would otherwise become a **duplicate** product. Fix with `validus-shopify:link-existing` (matches by SKU, writes only to the map table, never touches Shopify).
2. **Check the product option names.** `config('validus-shopify.shopify.option_names')` (defaults `Vintage`/`Format`) must match whatever option names the store's *existing* products actually use, exactly (case-sensitive) - query one via GraphQL (`product(id: ...) { options { name } }`) and compare before the first real sync, otherwise `productSet` creates a second, differently-cased option instead of matching the existing one.
3. **Check the API scopes** (see below) and run `--dry-run` first regardless - it shows the exact create/update table and, since the auto-deactivation feature, would also report what it's about to deactivate.

## Troubleshooting

### `Access denied for productSet field. Required access: 'write_products' access scope`

Not a code bug - the Shopify Admin API token is missing scopes. In Shopify Admin: *Settings → Apps and sales channels → Develop apps* → the app → *Configuration → Admin API integration → Edit scopes* → enable `write_products`, `write_inventory`, `read_products`, `read_inventory` → save → **reinstall the app** (a scope change alone doesn't reissue the token; the existing `SHOPIFY_ADMIN_TOKEN` value usually stays valid after reinstall, but verify). If the error persists after that, the staff account that owns/installed the app may itself need the "Products" permission (Shopify Plus locked-staff-permissions stores).

### `The variant 'X / Y' already exists. Please change at least one option value.`

Two distinct Validus products (different `id`) collide on the same computed vintage+format under the grouping strategy. This is a **data** problem - don't guess a fix in code. Since `sync-products` handles a failing group gracefully (catches per group, fires `ProductSyncGroupFailed`, keeps going), the immediate impact is limited to that one product; find the actual colliding pairs (see README "Troubleshooting" section for a ready-made snippet) and take it back to Validus/the customer to find out what really distinguishes them before changing the grouping logic.

### `Validus API request to [/products] failed with status 404` (or similar Validus-side error)

Check the response body first - Validus returns descriptive JSON (e.g. `"Listino ecommerce attivo non trovato"` = no active e-commerce price list configured for this API key on Validus' side). This class of error is almost always Validus-side account/config, not this package - confirm the price list is active for the given `VALIDUS_API_KEY` before assuming a code issue. Also double check `validus-shopify.validus.base_url` - it's `https://registri.wine/ebridge`, easy to typo as `/ecommerce_bridge` (which looks more "obviously right" but isn't the actual endpoint).

### Webhook signature always fails (403 on `/webhook/order`)

`VALIDUS_SHOPIFY_WEBHOOK_SECRET` must be the Shopify app's **Client secret** (API credentials page), not the `SHOPIFY_ADMIN_TOKEN` (`shpat_...`) - easy to paste the wrong one since both live on/near the same Shopify Admin screen. A webhook registered via Shopify's legacy *Settings → Notifications* page is signed with the same Client secret as an app-scoped webhook subscription, so either registration method works - just don't set up both for the same topic, or the endpoint gets each order delivered twice.

## "What did the last import actually do?"

Check the `validus-shopify` log channel (`storage/logs/validus-shopify-*.log`, daily-rotated) before assuming nothing was logged - it's registered automatically by the package's service provider, not something a consuming app has to set up. One line per product group synced/skipped plus a per-run summary; console output from a scheduled run is otherwise gone the moment the command exits.

## Reading a `sync-products` run

- `N new Shopify product(s) would be created, M existing product(s) would be updated` (dry-run) / `Done. N Shopify product(s), M variant(s) processed.` (real) only count **successful** groups.
- A products count of 0-new can still be correct even when `validus-shopify:diff` shows "truly new" SKUs: a new vintage/format of an *already-existing* wine is a variant added to an existing product, not a new product.
- Deactivation output only appears when something was actually deactivated - silence means nothing in the current Validus response disappeared compared to the map table.
- `X product group(s) failed and were skipped` at the end means the run still did everything else; check the listed group keys/messages (and the `ProductSyncGroupFailed` event, if the consuming app has a listener on it) rather than treating a non-zero exit code as "nothing happened".
