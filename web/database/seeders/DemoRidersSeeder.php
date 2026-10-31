<?php

namespace Database\Seeders;

use App\Models\Logistics\Area;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Rider\Courier;
use App\Models\Rider\Rider;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class DemoRidersSeeder extends Seeder
{
    public const PROVIDER_A_EMAIL = 'demo.provider.a@example.com';

    public const PROVIDER_B_EMAIL = 'demo.provider.b@example.com';

    public const PROVIDER_PASSWORD = 'password123';

    public const AREA_SANTA_CRUZ = 'Santa Cruz';

    public const AREA_PAGSANJAN = 'Pagsanjan';

    public const AREA_LOS_BANOS = 'Los Baños';

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function plannedRiders(): array
    {
        return [
            [
                'provider_email' => self::PROVIDER_A_EMAIL,
                'name' => 'Rider A1 - Provider A',
                'phone' => '09170000011',
                'service_area' => [self::AREA_SANTA_CRUZ],
                'is_available' => true,
                'vehicle_type' => 'motorcycle',
                'max_weight_grams' => 10000,
            ],
            [
                'provider_email' => self::PROVIDER_A_EMAIL,
                'name' => 'Rider A2 - Provider A',
                'phone' => '09170000012',
                'service_area' => [self::AREA_PAGSANJAN],
                'is_available' => true,
                'vehicle_type' => 'motorcycle',
                'max_weight_grams' => 10000,
            ],
            [
                'provider_email' => self::PROVIDER_A_EMAIL,
                'name' => 'Rider A3 - Provider A',
                'phone' => '09170000013',
                'service_area' => [self::AREA_LOS_BANOS],
                'is_available' => false,
                'vehicle_type' => 'motorcycle',
                'max_weight_grams' => 10000,
            ],
            [
                'provider_email' => self::PROVIDER_B_EMAIL,
                'name' => 'Rider B1 - Provider B',
                'phone' => '09170000021',
                'service_area' => [self::AREA_SANTA_CRUZ],
                'is_available' => true,
                'vehicle_type' => 'motorcycle',
                'max_weight_grams' => 10000,
            ],
            [
                'provider_email' => self::PROVIDER_B_EMAIL,
                'name' => 'Rider B2 - Provider B',
                'phone' => '09170000022',
                'service_area' => [self::AREA_LOS_BANOS],
                'is_available' => true,
                'vehicle_type' => 'van',
                'max_weight_grams' => 20000,
            ],
        ];
    }

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->error('DemoRidersSeeder refuses to run in production.');

            return;
        }

        $providerA = $this->demoProvider(self::PROVIDER_A_EMAIL, 'Provider A');
        $providerB = $this->demoProvider(self::PROVIDER_B_EMAIL, 'Provider B');

        $providers = [
            self::PROVIDER_A_EMAIL => $providerA,
            self::PROVIDER_B_EMAIL => $providerB,
        ];

        foreach (self::plannedRiders() as $index => $rider) {
            $provider = $providers[$rider['provider_email']];
            $station = $this->stationFor($provider);
            $coveredAreas = collect($rider['service_area'])
                ->map(fn (string $municipality) => $this->coverageFor($provider, $station, $municipality));

            $courier = Courier::firstOrNew(['phone' => $rider['phone']]);
            $riderModel = $courier->rider_id !== null ? Rider::find($courier->rider_id) : null;
            $riderModel ??= Rider::firstOrNew(['contact_no' => $rider['phone']]);

            $nameParts = explode(' - ', $rider['name'], 2);
            $name = explode(' ', $nameParts[0], 2);

            $riderModel->fill([
                'first_name' => $name[0],
                'last_name' => $name[1] ?? 'Rider',
                'birthday' => '1995-03-22',
                'email' => 'demo.rider.'.$rider['phone'].'@example.com',
                'contact_no' => $rider['phone'],
                'license_no' => 'DMO-00-'.str_pad((string) ($index + 1), 6, '0', STR_PAD_LEFT),
                'license_expiry' => '2030-03-22',
                'vehicle_type' => ucfirst($rider['vehicle_type']),
                'plate_number' => 'DEMO'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
                'drivers_license_path' => 'rider-licenses/test.jpg',
                'or_cr_path' => 'rider-or-crs/test.pdf',
            ]);
            $riderModel->forceFill([
                'logistics_provider_id' => $provider->getKey(),
                'station_hub_id' => $station->id,
                'status' => Rider::STATUS_APPROVED,
                'email_verified_at' => now(),
                'status_changed_at' => now(),
            ])->save();

            $courier->fill([
                'logistics_provider_id' => $provider->getKey(),
                'rider_id' => $riderModel->id,
                'area_id' => $coveredAreas->first()?->id,
                'name' => $rider['name'],
                'service_area' => $rider['service_area'],
                'is_available' => $rider['is_available'],
                'vehicle_type' => $rider['vehicle_type'],
                'max_weight_grams' => $rider['max_weight_grams'],
            ])->save();
        }

        $this->command?->info('Demo riders seeded for Provider A and Provider B.');
        $this->command?->info('Provider login password: '.self::PROVIDER_PASSWORD);
    }

    private function stationFor(LogisticsProvider $provider): LogisticsHub
    {
        $province = LogisticsHub::firstOrCreate([
            'logistics_provider_id' => $provider->getKey(),
            'hub_type' => LogisticsHub::PROVINCE,
            'name' => 'Demo Province Hub '.$provider->id,
        ], [
            'province_name' => 'Laguna',
            'region_name' => 'Region IV-A',
            'is_active' => true,
            'approval_status' => LogisticsHub::APPROVAL_APPROVED,
            'approved_at' => now(),
        ]);

        return LogisticsHub::firstOrCreate([
            'logistics_provider_id' => $provider->getKey(),
            'hub_type' => LogisticsHub::STATION,
            'name' => 'Demo Station Hub '.$provider->id,
        ], [
            'parent_hub_id' => $province->id,
            'province_name' => 'Laguna',
            'region_name' => 'Region IV-A',
            'municipality' => self::AREA_SANTA_CRUZ,
            'is_active' => true,
            'approval_status' => LogisticsHub::APPROVAL_APPROVED,
            'approved_at' => now(),
        ]);
    }

    private function coverageFor(LogisticsProvider $provider, LogisticsHub $station, string $municipality): Area
    {
        return Area::firstOrCreate([
            'logistics_provider_id' => $provider->getKey(),
            'municipality' => $municipality,
        ], [
            'station_hub_id' => $station->id,
            'name' => $municipality,
            'is_active' => true,
        ]);
    }

    private function demoProvider(string $email, string $businessName): LogisticsProvider
    {
        $slug = $email === self::PROVIDER_A_EMAIL ? 'demo-provider-a' : 'demo-provider-b';

        Storage::disk('local')->put("logistics-ids/{$slug}.jpg", 'demo placeholder');
        Storage::disk('local')->put("logistics-permits/{$slug}.jpg", 'demo placeholder');

        return LogisticsProvider::updateOrCreate(
            ['email' => $email],
            [
                'first_name' => 'Demo',
                'last_name' => 'Provider',
                'sex' => 'male',
                'password' => Hash::make(self::PROVIDER_PASSWORD),
                'contact_no' => $email === self::PROVIDER_A_EMAIL ? '09170000001' : '09170000002',
                'birthday' => '1990-01-15',
                'age' => 36,
                'business_name' => $businessName,
                'address_mode' => 'manual',
                'street' => '123 Rizal St.',
                'house_number' => '123',
                'upload_id_path' => "logistics-ids/{$slug}.jpg",
                'dti_permit_path' => "logistics-permits/{$slug}.jpg",
                'status' => 'approved',
                'email_verified_at' => now(),
                'approved_at' => now(),
                'rejection_reason' => null,
            ]
        );
    }
}
