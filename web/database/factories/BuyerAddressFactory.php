<?php

namespace Database\Factories;

use App\Models\Buyer\Buyer;
use App\Models\Buyer\BuyerAddress;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BuyerAddress>
 */
class BuyerAddressFactory extends Factory
{
    protected $model = BuyerAddress::class;

    public function definition(): array
    {
        return [
            'buyer_id'     => Buyer::factory(),
            'recipient'    => $this->faker->name(),
            'phone'        => '09' . $this->faker->numerify('#########'),
            'label'        => $this->faker->randomElement(['Home', 'Work', 'Other']),
            'region'       => 'Region VII (Central Visayas)',
            'region_code'  => '07',
            'province'     => 'Cebu',
            'province_code' => '0722',
            'city'         => 'Cebu City',
            'city_code'    => '072217',
            'barangay'     => 'Lahug',
            'barangay_code' => '072217040',
            'street'       => $this->faker->streetAddress(),
            'postal_code'  => '6000',
            'latitude'     => null,
            'longitude'    => null,
            'is_default'   => false,
        ];
    }
}
