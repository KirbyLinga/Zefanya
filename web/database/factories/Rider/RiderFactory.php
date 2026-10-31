<?php

namespace Database\Factories\Rider;

use App\Models\Logistics\LogisticsProvider;
use App\Models\Rider\Rider;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Rider> */
class RiderFactory extends Factory
{
    protected $model = Rider::class;

    public function definition(): array
    {
        return [
            'logistics_provider_id' => LogisticsProvider::factory(),
            'first_name' => 'Pedro Jose',
            'last_name' => 'Garcia',
            'birthday' => '1995-03-22',
            'email' => fake()->unique()->safeEmail(),
            'contact_no' => '09'.fake()->numerify('#########'),
            'license_no' => strtoupper(fake()->bothify('?-##-######')),
            'license_expiry' => '2030-03-22',
            'vehicle_type' => 'Motorcycle',
            'plate_number' => strtoupper(fake()->bothify('???####')),
            'drivers_license_path' => 'rider-licenses/test.jpg',
            'or_cr_path' => 'rider-or-crs/test.pdf',
            'status' => 'pending_activation',
        ];
    }
}
