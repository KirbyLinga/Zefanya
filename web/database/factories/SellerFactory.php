<?php

namespace Database\Factories;

use App\Models\Seller\Seller;
use App\Models\Shared\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Seller>
 */
class SellerFactory extends Factory
{
    protected $model = Seller::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'last_name' => fake()->lastName(),
            'first_name' => fake()->firstName(),
            'sex' => 'female',
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
            'contact_no' => '09'.fake()->numerify('#########'),
            'birthday' => '1990-01-15',
            'age' => 36,
            'address_mode' => 'manual',
            'business_name' => fake()->unique()->company(),
            'line_of_business_id' => Category::query()->value('id'),
            'upload_id_path' => 'ids/test.jpg',
            'business_permit_path' => 'permits/test.jpg',
            'status' => 'approved',
            'email_verified_at' => now(),
            'approved_at' => now(),
        ];
    }

    public function pendingApproval(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'pending_approval',
            'approved_at' => null,
        ]);
    }
}
