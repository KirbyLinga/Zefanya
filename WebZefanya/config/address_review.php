<?php

/*
 * Cross-region delivery address review.
 *
 * An order whose delivery region is not the Seller's own region or a
 * neighbouring one (for example Metro Manila to Abra) is held before pickup
 * until Admin confirms the Buyer's address by phone. Same-region and
 * neighbouring-region orders (Metro Manila to Laguna or Bulacan) are not held.
 *
 * Regions are the first two digits of the PSGC province code. Neighbours are
 * listed one way; the check treats them as symmetric.
 */
return [
    'enabled' => env('ADDRESS_REVIEW_ENABLED', true),

    // Older PSGC data numbers BARMM as 15; newer data uses 19.
    'region_aliases' => ['15' => '19'],

    'region_names' => [
        '01' => 'Ilocos Region', '02' => 'Cagayan Valley', '03' => 'Central Luzon', '04' => 'CALABARZON',
        '05' => 'Bicol Region', '06' => 'Western Visayas', '07' => 'Central Visayas', '08' => 'Eastern Visayas',
        '09' => 'Zamboanga Peninsula', '10' => 'Northern Mindanao', '11' => 'Davao Region', '12' => 'SOCCSKSARGEN',
        '13' => 'Metro Manila', '14' => 'Cordillera (CAR)', '16' => 'Caraga', '17' => 'MIMAROPA',
        '18' => 'Negros Island Region', '19' => 'BARMM',
    ],

    'neighbours' => [
        '13' => ['03', '04'],
        '14' => ['01', '02', '03'],
        '01' => ['02', '03'],
        '02' => ['03'],
        '03' => ['04'],
        '04' => ['05', '17'],
        '17' => ['05', '06'],
        '05' => ['08'],
        '06' => ['07', '18'],
        '18' => ['07'],
        '07' => ['08', '10'],
        '08' => ['16'],
        '16' => ['10', '11'],
        '10' => ['09', '11', '12', '19'],
        '09' => ['19'],
        '11' => ['12'],
        '12' => ['19'],
    ],
];
