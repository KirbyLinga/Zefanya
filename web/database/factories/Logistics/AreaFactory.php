<?php

namespace Database\Factories\Logistics;

use App\Models\Logistics\Area;
use App\Models\Logistics\LogisticsProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Area>
 */
class AreaFactory extends Factory
{
    protected $model = Area::class;

    public function definition(): array
    {
        return [
            'logistics_provider_id' => LogisticsProvider::factory(),
            'name' => fake()->city().' Area',
            'municipality' => fake()->city(),
            'is_active' => true,
        ];
    }
}
