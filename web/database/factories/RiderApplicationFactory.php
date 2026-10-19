<?php

namespace Database\Factories;

use App\Models\Logistics\LogisticsProvider;
use App\Models\RiderApplication;
use Illuminate\Database\Eloquent\Factories\Factory;

class RiderApplicationFactory extends Factory
{
    protected $model = RiderApplication::class;

    public function definition(): array
    {
        return [
            'logistics_provider_id' => LogisticsProvider::factory()->state(['status' => 'approved']),
            'first_name' => 'Ana', 'last_name' => 'Santos', 'sex' => 'female',
            'email' => fake()->unique()->safeEmail(), 'contact_no' => fake()->unique()->numerify('09#########'),
            'password' => 'Password1!', 'birthday' => '1995-06-20',
            'province_code' => '0400000000', 'province_name' => 'Laguna',
            'municipality_code' => '0434000000', 'municipality_name' => 'Calamba',
            'barangay_code' => '043401001', 'barangay_name' => 'Barangay One', 'street' => 'Rizal Street',
            'vehicle_type' => 'motorcycle', 'plate_number' => 'ABC 1234',
            'upload_id_path' => 'rider-ids/test.pdf', 'or_cr_path' => 'rider-or-cr/test.pdf',
            'status' => 'unverified', 'email_verified_at' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (): array => ['status' => 'pending', 'email_verified_at' => now()]);
    }

    public function approved(): static
    {
        return $this->state(fn (): array => ['status' => 'approved', 'email_verified_at' => now()]);
    }
}
