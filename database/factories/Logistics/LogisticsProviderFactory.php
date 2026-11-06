<?php

namespace Database\Factories\Logistics;

use App\Models\Logistics\LogisticsProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LogisticsProvider>
 */
class LogisticsProviderFactory extends Factory
{
    protected $model = LogisticsProvider::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'last_name' => fake()->lastName(),
            'first_name' => fake()->firstName(),
            'middle_initial' => null,
            'sex' => 'female',
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
            'contact_no' => '09'.fake()->numerify('#########'),
            'birthday' => '1990-01-15',
            'age' => 36,
            'business_name' => fake()->unique()->company(),
            'address_mode' => 'manual',
            'province_code' => '030540000',
            'province_name' => 'Pampanga',
            'municipality_code' => '030540100',
            'municipality_name' => 'San Fernando',
            'barangay_code' => '0305401001',
            'barangay_name' => 'Santo Rosario',
            'street' => '123 Rizal St.',
            'house_number' => '123',
            'address_detail' => null,
            'upload_id_path' => 'logistics-ids/test.jpg',
            'dti_permit_path' => 'logistics-permits/test.jpg',
            'status' => 'approved',
            'email_verified_at' => now(),
            'approved_at' => now(),
            'province_verified_at' => now(),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'unverified',
            'email_verified_at' => null,
            'approved_at' => null,
            'province_verified_at' => null,
            'province_verified_by' => null,
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'pending',
            'email_verified_at' => now(),
            'approved_at' => null,
            'province_verified_at' => null,
            'province_verified_by' => null,
        ]);
    }
}
