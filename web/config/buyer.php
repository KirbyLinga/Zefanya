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
    | Cross-Province Shipping
    |--------------------------------------------------------------------------
    |
    | Explicit marketplace pricing rule for routes that require an approved
    | origin → destination provider handoff. There is deliberately no invented
    | default: until fee_minor is configured, cross-province checkout remains
    | unavailable. Amounts are in centavos and are never read from the client.
    |
    */
    'cross_province' => [
        'fee_minor' => env('BUYER_CROSS_PROVINCE_FEE_MINOR'),
        'free_shipping_threshold_minor' => env('BUYER_CROSS_PROVINCE_FREE_SHIPPING_THRESHOLD_MINOR'),
    ],
];
