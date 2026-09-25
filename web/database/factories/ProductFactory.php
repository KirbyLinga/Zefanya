<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Seller\Seller;
use App\Models\Shared\Category;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return [
            'seller_id' => Seller::factory(),
            'category_id' => Category::query()->value('id'),
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numerify('###'),
            'description' => fake()->sentence(),
            'price' => fake()->randomFloat(2, 15, 180),
            'stock_quantity' => 24,
            'low_stock_threshold' => 5,
            'status' => 'active',
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'draft',
        ]);
    }

    public function outOfStock(): static
    {
        return $this->state(fn (array $attributes): array => [
            'stock_quantity' => 0,
        ]);
    }
}
