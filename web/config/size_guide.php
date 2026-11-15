<?php

/*
|--------------------------------------------------------------------------
| Garment length guide
|--------------------------------------------------------------------------
| Reference lengths shown on the product page and the choices offered to
| Sellers. A product stores only the chosen key (products.garment_type).
| Changing a range here changes what Buyers see; it never edits stored data.
| Keys are stable identifiers: do not rename a key that products already use.
*/

return [
    // Categories (by label, as ids differ between databases) that offer the guide.
    'categories' => ["Women's Apparel"],

    'groups' => [
        'dresses' => [
            'label' => 'Dresses',
            'types' => [
                'dress_mini' => ['label' => 'Mini', 'cm' => '75-90', 'in' => '30-35'],
                'dress_knee' => ['label' => 'Knee-length', 'cm' => '90-105', 'in' => '35-41'],
                'dress_midi' => ['label' => 'Midi', 'cm' => '105-125', 'in' => '41-49'],
                'dress_maxi' => ['label' => 'Maxi', 'cm' => '130-155', 'in' => '51-61'],
            ],
        ],
        'tops' => [
            'label' => 'Tops',
            'types' => [
                'top_crop' => ['label' => 'Crop top', 'cm' => '35-48', 'in' => '14-19'],
                'top_blouse' => ['label' => 'Regular blouse', 'cm' => '58-68', 'in' => '23-27'],
                'top_tunic' => ['label' => 'Tunic / longline', 'cm' => '70-85', 'in' => '28-33'],
            ],
        ],
    ],
];
