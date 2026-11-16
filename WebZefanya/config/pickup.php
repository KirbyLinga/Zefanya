<?php

return [
    // Pickup windows and rider pay are edited by Admin in Platform Settings. These
    // are the values used until a setting row exists (for example before the
    // migration that adds the rows has run).
    'windows' => [
        'morning' => '08:00-12:00',
        'afternoon' => '13:00-17:00',
    ],

    // A parcel marked ready this close to a window's end waits for the next
    // window instead, so it is not rolled over as "missed" a few minutes later.
    'min_lead_minutes' => 30,

    // A second pickup session for the same rider at the same seller starting
    // sooner than this after the first one closed is one continued visit, so its
    // first parcel is paid the additional rate instead of a second first-parcel fee.
    'revisit_gap_minutes' => 30,

    // Centavos. Used until Admin saves the matching Platform Settings.
    'rates' => [
        'first_minor' => 1000,
        'additional_minor' => 1000,
        'delivery_minor' => 3000,
    ],
];
