<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class SpecShippingRateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach ([['ncr', 'ncr', 5000, 1500], ['visayas', 'ncr', 12000, 4000], ['ncr', 'visayas', 12000, 4000], ['visayas', 'visayas', 6000, 2000]] as [$origin, $destination, $first, $additional]) {
            \App\Models\Shared\ShippingRate::query()->firstOrCreate(
                ['origin_zone' => $origin, 'destination_zone' => $destination],
                ['first_kg_minor' => $first, 'additional_kg_minor' => $additional],
            );
        }
    }
}
