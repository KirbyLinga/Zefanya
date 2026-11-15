<?php

/*
|--------------------------------------------------------------------------
| Policy documents
|--------------------------------------------------------------------------
| Single source of truth for Web (Blade) and Mobile (JSON API). The text lives
| in docs/Policy as Markdown; the version and effective date are read from each
| file's header. A new version is a new file: keep the old file so earlier
| published text stays available for audit.
|
| `public => false` documents are never served by the public routes or API.
*/

return [
    'directory' => base_path('docs/Policy'),

    'documents' => [
        'privacy-policy' => [
            'title' => 'Privacy Policy',
            'file' => '01_Privacy_Policy.md',
            'public' => true,
            'audience' => 'everyone',
        ],
        'terms-of-service' => [
            'title' => 'Terms of Service',
            'file' => '02_Platform_Terms_of_Service.md',
            'public' => true,
            'audience' => 'everyone',
        ],
        'buyer-protection' => [
            'title' => 'Buyer Protection, Returns & Refunds',
            'file' => '03_Buyer_Protection_Returns_Refunds.md',
            'public' => true,
            'audience' => 'buyer',
        ],
        'seller-agreement' => [
            'title' => 'Seller Agreement',
            'file' => '04_Seller_Agreement.md',
            'public' => true,
            'audience' => 'seller',
        ],
        'logistics-agreement' => [
            'title' => 'Logistics Provider Agreement',
            'file' => '05_Logistics_Provider_Agreement.md',
            'public' => true,
            'audience' => 'logistics',
        ],
        'rider-courier-rules' => [
            'title' => 'Rider and Transport Courier Rules',
            'file' => '06_Rider_Transport_Courier_Rules.md',
            'public' => true,
            'audience' => 'rider',
        ],
        // Internal: reachable only through the authenticated admin route.
        'admin-sop' => [
            'title' => 'Internal Administrator, Privacy and Finance SOP',
            'file' => '07_Internal_Admin_Privacy_Finance_SOP.md',
            'public' => false,
            'audience' => 'admin',
        ],
    ],
];
