<?php

// Trimmed-down "orders/paid" webhook payload, standard Shopify REST order
// object shape - only the fields OrderExportService actually reads.
return [
    'id' => 5551234,
    'name' => '#A2',
    'email' => 'h.mueller@muster.de',
    'phone' => '+49 30 12345678',
    'created_at' => '2025-06-29T10:15:00+02:00',
    'processed_at' => '2025-06-29T10:16:00+02:00',
    'currency' => 'EUR',
    'note' => 'Bitte klingeln.',
    'customer' => [
        'id' => 998877,
        'first_name' => 'Hans',
        'last_name' => 'Müller',
        'email' => 'h.mueller@muster.de',
        'phone' => '+49 30 12345678',
    ],
    'billing_address' => [
        'address1' => 'Tölzer Straße 15',
        'address2' => null,
        'city' => 'Grünwald',
        'province' => 'Bayern',
        'zip' => '82031',
        'country_code' => 'DE',
        'first_name' => 'Hans',
        'last_name' => 'Müller',
    ],
    'shipping_address' => [
        'address1' => 'Industriestraße 8',
        'address2' => null,
        'city' => 'Grünwald',
        'province' => 'Bayern',
        'zip' => '82031',
        'country_code' => 'DE',
        'first_name' => 'Hans',
        'last_name' => 'Müller',
    ],
    'line_items' => [
        [
            'id' => 111,
            'variant_id' => 424242,
            'sku' => '99070121',
            'name' => 'Demo Reserve 2021 - 0,75l',
            'quantity' => 2,
            'price' => '12.50',
            'tax_lines' => [
                ['title' => 'IVA', 'price' => '5.50', 'rate' => 0.22],
            ],
        ],
    ],
    'shipping_lines' => [
        [
            'price' => '20.00',
            'tax_lines' => [],
        ],
    ],
    'total_line_items_price' => '25.00',
    'total_tax' => '5.50',
    'total_discounts' => '0.00',
    'total_price' => '45.00',
    'tax_lines' => [
        ['title' => 'IVA', 'price' => '5.50', 'rate' => 0.22],
    ],
    'payment_gateway_names' => ['shopify_payments'],
    'checkout_id' => 'TRX123456',
];
