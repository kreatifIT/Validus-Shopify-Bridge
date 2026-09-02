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

];
