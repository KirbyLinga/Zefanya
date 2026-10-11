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
];
