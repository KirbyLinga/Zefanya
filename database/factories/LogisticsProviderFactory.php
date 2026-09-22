<?php

namespace Database\Factories;

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
            'street' => '123 Rizal St.',
            'house_number' => '123',
            'address_detail' => null,
            'upload_id_path' => 'logistics-ids/test.jpg',
            'dti_permit_path' => 'logistics-permits/test.jpg',
            'status' => 'approved',
            'email_verified_at' => now(),
            'approved_at' => now(),
        ];
    }

    public function pendingVerification(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'pending_verification',
            'email_verified_at' => null,
            'approved_at' => null,
        ]);
    }

    public function pendingApproval(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'pending_approval',
            'email_verified_at' => now(),
            'approved_at' => null,
        ]);
    }
}
