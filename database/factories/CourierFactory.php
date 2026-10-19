<?php

namespace Database\Factories;

use App\Models\Courier;
use App\Models\Logistics\LogisticsProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Courier>
 */
class CourierFactory extends Factory
{
    protected $model = Courier::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'logistics_provider_id' => LogisticsProvider::factory(),
            'name' => fake()->name(),
            'phone' => '09'.fake()->numerify('#########'),
            'service_area' => ['Quezon City', 'Makati', 'Taguig'],
            'is_available' => true,
            'vehicle_type' => 'motorcycle',
            'max_weight_grams' => 5000,
        ];
    }

    public function unavailable(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_available' => false,
        ]);
    }

    public function bike(): static
    {
        return $this->state(fn (array $attributes): array => [
            'vehicle_type' => 'bike',
            'max_weight_grams' => 5000,
        ]);
    }

    public function motorcycle(): static
    {
        return $this->state(fn (array $attributes): array => [
            'vehicle_type' => 'motorcycle',
            'max_weight_grams' => 10000,
        ]);
    }

    public function van(): static
    {
        return $this->state(fn (array $attributes): array => [
            'vehicle_type' => 'van',
            'max_weight_grams' => 20000,
        ]);
    }
}
