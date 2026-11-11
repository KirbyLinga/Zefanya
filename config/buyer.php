<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Buyer-Specific Configuration
    |--------------------------------------------------------------------------
    */

    'announcements' => [
        'top_bar' => env('BUYER_ANNOUNCEMENT_TEXT', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Shipping Fee (applies to every order)
    |--------------------------------------------------------------------------
    |
    | One marketplace-wide rule, the same for every zone and every Logistics
    | company: base_minor covers the first base_kg kilos of a store's parcel,
    | and each kilo after that adds additional_kg_minor. Weight rounds up to
    | whole kilos. Centavos. The zone tables below and company rate cards no
    | longer change the price.
    |
    */
    'shipping_fee' => [
        'base_minor' => (int) env('BUYER_SHIPPING_BASE_MINOR', 5000),
        'base_kg' => (int) env('BUYER_SHIPPING_BASE_KG', 2),
        'additional_kg_minor' => (int) env('BUYER_SHIPPING_ADDITIONAL_KG_MINOR', 2000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Shipping Rate Table (no longer used for pricing)
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
    | Order value never zeroes this fee. Free shipping is only a shipping voucher.
    |
    */
    'cross_province' => [
        'fee_minor' => env('BUYER_CROSS_PROVINCE_FEE_MINOR'),
        'additional_kg_minor' => (int) env('BUYER_CROSS_PROVINCE_ADDITIONAL_KG_MINOR', 0),
    ],
];
