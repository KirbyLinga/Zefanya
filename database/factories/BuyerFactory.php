<?php

namespace Database\Factories;

use App\Models\Buyer\Buyer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Buyer>
 */
class BuyerFactory extends Factory
{
    protected $model = Buyer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'last_name' => fake()->lastName(),
            'first_name' => fake()->firstName(),
            'sex' => fake()->randomElement(['male', 'female']),
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
            'contact_no' => '09'.fake()->numerify('#########'),
            'birthday' => '1995-06-20',
            'age' => 31,
            'address_mode' => 'manual',
            'upload_id_path' => 'ids/test.jpg',
            'status' => 'approved',
            'email_verified_at' => now(),
            'approved_at' => now(),
        ];
    }
}
