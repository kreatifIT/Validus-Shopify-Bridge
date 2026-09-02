<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Shopify connection
    |--------------------------------------------------------------------------
    |
    | Falls dieses Paket neben statamic-rad-pack/shopify (oder einem anderen
    | Shopify-Paket) installiert ist, greifen die SHOPIFY_*-env-Defaults ohne
    | Zusatzaufwand auf dieselben Werte zurück. Für einen eigenständigen
    | Einsatz einfach die VALIDUS_SHOPIFY_*-Variablen setzen.
    |
    */
    'shopify' => [
        'admin_token' => env('VALIDUS_SHOPIFY_ADMIN_TOKEN', env('SHOPIFY_ADMIN_TOKEN')),
        // Bare domain, no scheme (e.g. "kellerei-st-michael.myshopify.com") -
        // matches the existing SHOPIFY_APP_URL convention already used by
        // statamic-rad-pack/shopify.
        'store_url' => env('VALIDUS_SHOPIFY_STORE_URL', env('SHOPIFY_APP_URL')),
        'api_version' => env('VALIDUS_SHOPIFY_API_VERSION', env('SHOPIFY_API_VERSION', '2025-04')),
        'webhook_secret' => env('VALIDUS_SHOPIFY_WEBHOOK_SECRET', env('SHOPIFY_WEBHOOK_SECRET')),
        'location_id' => env('VALIDUS_SHOPIFY_LOCATION_ID'),

        // Validus liefert price.fullPrice netto (ohne MwSt., bestätigt). Ob
        // beim Schreiben nach Shopify auf Basis von tax.rate auf brutto
        // hochgerechnet werden muss, hängt von den Shopify-Steuereinstellungen
        // ab (taxesIncluded auf Shop-Ebene) - vor Produktivbetrieb im Shopify
        // Admin verifizieren. Default false = Preis wird 1:1 netto übernommen.
        'prices_include_tax' => env('VALIDUS_SHOPIFY_PRICES_INCLUDE_TAX', false),

        // Nur für lokale Entwicklung/Tests ohne echtes Webhook-Secret - im
        // Produktivbetrieb false lassen, sonst prüft VerifyShopifyWebhookSignature
        // die HMAC-Signatur nicht mehr.
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
    | Validus liefert jede Jahrgang/Format-Kombination als eigenständiges
    | Produkt. Diese Werte steuern, wie code.code (z. B. "56070025") in
    | Gruppierungsschlüssel + Jahrgang zerlegt wird - siehe
    | ProductCodeGroupingStrategy. Bestätigtes Format für Kellerei St. Michael:
    | erste 2 Ziffern = Produkt, letzte 2 Ziffern = Jahrgang (20XX), die
    | mittleren Ziffern werden ignoriert.
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
    | Ordnet Shopify-Zahlungs-Gateways den von Validus erwarteten Codes zu.
    | Erweiterbar ohne Code-Änderung, sobald weitere Zahlungsmittel feststehen.
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
    | Neu importierte Varianten werden standardmäßig NICHT auf Bestandsführung
    | gestellt (manuelle Entscheidung je Variante im Shopify Admin).
    |
    */
    'track_new_variants' => env('VALIDUS_SHOPIFY_TRACK_NEW_VARIANTS', false),

];
