<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductVariant>
 */
class ProductVariantFactory extends Factory
{
    protected $model = ProductVariant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'sku' => null,
            'price' => fake()->randomElement(ProductCatalogData::PRICE_POINTS),
            'stock_quantity' => fake()->numberBetween(0, 40),
            'is_available' => true,
            'weight_grams' => null,
            'dimensions' => null,
        ];
    }

    /** Out-of-stock variant. */
    public function outOfStock(): static
    {
        return $this->state(fn (array $attributes): array => [
            'stock_quantity' => 0,
        ]);
    }

    /** Disabled (not selling) variant. */
    public function disabled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_available' => false,
        ]);
    }
}
