<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class PsgcSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * Idempotent: loads reference data from mobile/assets/data/ph_psgc.json.
     */
    public function run(): void
    {
        $path = base_path('../mobile/assets/data/ph_psgc.json');
        if (! File::exists($path)) {
            $path = base_path('mobile/assets/data/ph_psgc.json');
        }

        if (! File::exists($path)) {
            $this->command?->warn("PSGC data file not found at {$path}. Skipping.");
            return;
        }

        $json = json_decode(File::get($path), true);
        if (! isset($json['regions']) || ! is_array($json['regions'])) {
            $this->command?->error("Invalid PSGC JSON structure.");
            return;
        }

        $now = now();
        $regions = [];
        $provinces = [];
        $cities = [];
        $barangays = [];

        foreach ($json['regions'] as $reg) {
            $regionCode = (string) $reg['code'];
            $regions[] = [
                'code' => $regionCode,
                'name' => (string) $reg['name'],
                'short_name' => $reg['shortName'] ?? null,
                'official_name' => $reg['officialName'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            // Regular provinces
            if (! empty($reg['provinces'])) {
                foreach ($reg['provinces'] as $prov) {
                    $provCode = (string) $prov['code'];
                    $provinces[] = [
                        'code' => $provCode,
                        'region_code' => $regionCode,
                        'name' => (string) $prov['name'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    if (! empty($prov['cities'])) {
                        foreach ($prov['cities'] as $city) {
                            $cityCode = (string) $city['code'];
                            $cities[] = [
                                'code' => $cityCode,
                                'region_code' => $regionCode,
                                'province_code' => $provCode,
                                'name' => (string) $city['name'],
                                'postal_code' => isset($city['postalCode']) ? (string) $city['postalCode'] : null,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ];

                            if (! empty($city['barangays'])) {
                                foreach ($city['barangays'] as $brgy) {
                                    $bCode = is_array($brgy) ? (string) $brgy[0] : (string) $brgy;
                                    $bName = is_array($brgy) ? (string) $brgy[1] : (string) $brgy;
                                    $barangays[] = [
                                        'code' => $bCode,
                                        'city_code' => $cityCode,
                                        'name' => $bName,
                                        'created_at' => $now,
                                        'updated_at' => $now,
                                    ];
                                }
                            }
                        }
                    }
                }
            }

            // NCR or province-less cities directly under the region
            if (! empty($reg['cities'])) {
                foreach ($reg['cities'] as $city) {
                    $cityCode = (string) $city['code'];
                    $cities[] = [
                        'code' => $cityCode,
                        'region_code' => $regionCode,
                        'province_code' => null, // Province-less (e.g. NCR)
                        'name' => (string) $city['name'],
                        'postal_code' => isset($city['postalCode']) ? (string) $city['postalCode'] : null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    if (! empty($city['barangays'])) {
                        foreach ($city['barangays'] as $brgy) {
                            $bCode = is_array($brgy) ? (string) $brgy[0] : (string) $brgy;
                            $bName = is_array($brgy) ? (string) $brgy[1] : (string) $brgy;
                            $barangays[] = [
                                'code' => $bCode,
                                'city_code' => $cityCode,
                                'name' => $bName,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ];
                        }
                    }
                }
            }
        }

        // Upsert regions
        DB::table('psgc_regions')->upsert($regions, ['code'], ['name', 'short_name', 'official_name', 'updated_at']);

        // Upsert provinces
        foreach (array_chunk($provinces, 200) as $chunk) {
            DB::table('psgc_provinces')->upsert($chunk, ['code'], ['region_code', 'name', 'updated_at']);
        }

        // Upsert cities
        foreach (array_chunk($cities, 200) as $chunk) {
            DB::table('psgc_cities')->upsert($chunk, ['code'], ['region_code', 'province_code', 'name', 'postal_code', 'updated_at']);
        }

        // Upsert barangays
        foreach (array_chunk($barangays, 500) as $chunk) {
            DB::table('psgc_barangays')->upsert($chunk, ['code'], ['city_code', 'name', 'updated_at']);
        }

        $this->command?->info("PSGC data seeded: " . count($regions) . " regions, " . count($provinces) . " provinces, " . count($cities) . " cities, " . count($barangays) . " barangays.");
    }
}
