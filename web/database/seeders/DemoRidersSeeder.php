<?php

namespace Database\Seeders;

use App\Models\Courier;
use App\Models\Logistics\LogisticsProvider;
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

        foreach (self::plannedRiders() as $rider) {
            $provider = $providers[$rider['provider_email']];

            Courier::updateOrCreate(
                ['phone' => $rider['phone']],
                [
                    'logistics_provider_id' => $provider->getKey(),
                    'name' => $rider['name'],
                    'service_area' => $rider['service_area'],
                    'is_available' => $rider['is_available'],
                    'vehicle_type' => $rider['vehicle_type'],
                    'max_weight_grams' => $rider['max_weight_grams'],
                ]
            );
        }

        $this->command?->info('Demo riders seeded for Provider A and Provider B.');
        $this->command?->info('Provider login password: '.self::PROVIDER_PASSWORD);
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
