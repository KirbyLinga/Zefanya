<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Buyer-Specific Configuration
    |--------------------------------------------------------------------------
    */

    'announcements' => [
        'top_bar' => env('BUYER_ANNOUNCEMENT_TEXT', ''),
        'free_shipping_threshold_minor' => env('BUYER_FREE_SHIPPING_THRESHOLD_MINOR', 150000), // in centavos (₱1,500)
    ],

    /*
    |--------------------------------------------------------------------------
    | Shipping Rate Table
    |--------------------------------------------------------------------------
    |
    | Per-store fee by the shop's total parcel weight, rounded up to whole
    | kilos: the first kilo, plus a rate for each kilo after it. Centavos.
    |
    */
    'shipping_rates' => [
        'same_municipality' => [
            'first_kg_minor' => (int) env('BUYER_RATE_SAME_MUNICIPALITY_FIRST_KG_MINOR', 4900),
            'additional_kg_minor' => (int) env('BUYER_RATE_SAME_MUNICIPALITY_ADDITIONAL_KG_MINOR', 1500),
        ],
        'same_province' => [
            'first_kg_minor' => (int) env('BUYER_RATE_SAME_PROVINCE_FIRST_KG_MINOR', 6900),
            'additional_kg_minor' => (int) env('BUYER_RATE_SAME_PROVINCE_ADDITIONAL_KG_MINOR', 2000),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cross-Province Shipping
    |--------------------------------------------------------------------------
    |
    | Explicit marketplace pricing rule for routes that require an approved
    | origin → destination provider handoff. There is deliberately no invented
    | default: until fee_minor is configured, cross-province checkout remains
    | unavailable. Amounts are in centavos and are never read from the client.
    | fee_minor is the first kilo; additional_kg_minor applies to each one after.
    |
    */
    'cross_province' => [
        'fee_minor' => env('BUYER_CROSS_PROVINCE_FEE_MINOR'),
        'additional_kg_minor' => (int) env('BUYER_CROSS_PROVINCE_ADDITIONAL_KG_MINOR', 0),
        'free_shipping_threshold_minor' => env('BUYER_CROSS_PROVINCE_FREE_SHIPPING_THRESHOLD_MINOR'),
    ],
];
