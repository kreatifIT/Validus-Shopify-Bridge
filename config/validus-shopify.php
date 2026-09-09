<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Shopify connection
    |--------------------------------------------------------------------------
    |
    | If this package is installed alongside statamic-rad-pack/shopify (or
    | another Shopify package), the SHOPIFY_* env defaults are picked up
    | automatically without extra setup. For a standalone install, just set
    | the VALIDUS_SHOPIFY_* variables instead.
    |
    */
    'shopify' => [
        'admin_token' => env('VALIDUS_SHOPIFY_ADMIN_TOKEN', env('SHOPIFY_ADMIN_TOKEN')),
        // Bare domain, no scheme (e.g. "your-shop.myshopify.com").
        'store_url' => env('VALIDUS_SHOPIFY_STORE_URL', env('SHOPIFY_APP_URL')),
        'api_version' => env('VALIDUS_SHOPIFY_API_VERSION', env('SHOPIFY_API_VERSION', '2025-04')),
        'webhook_secret' => env('VALIDUS_SHOPIFY_WEBHOOK_SECRET', env('SHOPIFY_WEBHOOK_SECRET')),
        'location_id' => env('VALIDUS_SHOPIFY_LOCATION_ID'),

        // Product option names used for the vintage year and bottle format
        // options ProductSyncService creates on each Shopify product. Match
        // these to whatever the shop's existing product options are called,
        // if any already exist.
        'option_names' => [
            'vintage' => env('VALIDUS_SHOPIFY_VINTAGE_OPTION_NAME', 'Vintage'),
            'format' => env('VALIDUS_SHOPIFY_FORMAT_OPTION_NAME', 'Format'),
        ],

        // Validus delivers price.fullPrice net (excl. VAT). Whether that
        // needs to be grossed up before writing to Shopify depends on the
        // shop's own tax settings (taxesIncluded) - verify this in Shopify
        // Admin before going live. Default false = price passed through
        // net, unchanged.
        'prices_include_tax' => env('VALIDUS_SHOPIFY_PRICES_INCLUDE_TAX', false),

        // Only for local development/testing without a real webhook secret -
        // leave false in production, otherwise VerifyShopifyWebhookSignature
        // stops checking the HMAC signature.
        'ignore_webhook_integrity_check' => env('VALIDUS_SHOPIFY_IGNORE_WEBHOOK_CHECK', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Validus connection
    |--------------------------------------------------------------------------
    */
    'validus' => [
        'base_url' => env('VALIDUS_API_URL', 'https://registri.wine/ecommerce_bridge'),
        'api_key' => env('VALIDUS_API_KEY'),
        'timeout' => env('VALIDUS_API_TIMEOUT', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Product grouping
    |--------------------------------------------------------------------------
    |
    | Validus delivers every vintage/format combination as its own product.
    | These values control how code.code (e.g. "56070025") is split into a
    | grouping key + vintage year by the default ProductCodeGroupingStrategy:
    | the first product_code_length digits identify the product, the last
    | year_code_length digits are the vintage (prefixed with
    | year_century_prefix), and anything in between is ignored. Confirm the
    | exact digit layout with the customer per install - a customer whose
    | Validus code scheme doesn't fit this pattern can supply their own
    | VariantGroupingStrategy instead.
    |
    */
    'grouping' => [
        'product_code_length' => 2,
        'year_code_length' => 2,
        'year_century_prefix' => '20',
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment code mapping
    |--------------------------------------------------------------------------
    |
    | Maps Shopify payment gateways to the codes Validus expects. Extend as
    | needed without a code change once further payment methods are confirmed.
    |
    */
    'payment_code_map' => [
        // 'shopify_payments' => 'CC',
    ],

    /*
    |--------------------------------------------------------------------------
    | Inventory tracking
    |--------------------------------------------------------------------------
    |
    | Newly imported variants are NOT put on inventory tracking by default
    | (a manual, per-variant decision in Shopify Admin).
    |
    */
    'track_new_variants' => env('VALIDUS_SHOPIFY_TRACK_NEW_VARIANTS', false),

    /*
    |--------------------------------------------------------------------------
    | Auto-deactivation of products Validus no longer lists
    |--------------------------------------------------------------------------
    |
    | Every real sync-products run also deactivates any already-linked
    | variant whose Validus product has disappeared from the catalog
    | entirely (discontinued, or removed by mistake): inventory tracking is
    | turned on if it wasn't already, stock is zeroed, and the variant's
    | inventory policy is set to deny overselling - the product itself is
    | archived too, but only once *every* variant mapped to it is gone.
    | Nothing is deleted; re-adding the product to Validus and syncing again
    | is enough to bring it back (a variant's inventory policy/stock is left
    | as-is on reactivation, so restore that manually if it should be sold
    | immediately again). Requires shopify.location_id to be set - silently
    | does nothing without it, same as inventory quantity syncing.
    |
    */
    'deactivation' => [
        'enabled' => env('VALIDUS_SHOPIFY_AUTO_DEACTIVATE', true),

        // Safety net for a bad/partial Validus response (e.g. an API error
        // that doesn't throw, or a temporarily incomplete price list) being
        // mistaken for mass discontinuation: if the share of already-linked
        // products that would be deactivated in one run exceeds this ratio,
        // the whole deactivation step is skipped (the rest of the sync still
        // runs normally) rather than archiving half the catalog by accident.
        'max_removed_ratio' => (float) env('VALIDUS_SHOPIFY_MAX_REMOVED_RATIO', 0.5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Order export request/webhook logging
    |--------------------------------------------------------------------------
    |
    | Every order export writes two JSON files, both keyed by the Shopify
    | order id (a redelivery/retry overwrites its file, so it's always the
    | latest attempt) - useful to see exactly what Shopify sent and what
    | actually went to Validus when an order gets rejected, without
    | reproducing either by hand:
    | - webhook_log_*: the raw "orders/paid" payload, as Shopify sent it.
    | - request_log_*: the JSON payload sent to Validus' POST /orders.
    | Both contain customer name/address/email/phone - set the respective
    | disk to null to turn either off if that shouldn't sit on disk here.
    |
    */
    'order_export' => [
        'webhook_log_disk' => env('VALIDUS_ORDER_EXPORT_WEBHOOK_LOG_DISK', 'local'),
        'webhook_log_directory' => env('VALIDUS_ORDER_EXPORT_WEBHOOK_LOG_DIRECTORY', 'validus-order-webhooks'),

        'request_log_disk' => env('VALIDUS_ORDER_EXPORT_REQUEST_LOG_DISK', 'local'),
        'request_log_directory' => env('VALIDUS_ORDER_EXPORT_REQUEST_LOG_DIRECTORY', 'validus-order-requests'),
    ],

];
