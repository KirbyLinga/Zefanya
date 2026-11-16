<?php

namespace Database\Factories\Seller;

use App\Enums\StoreSaleType;
use App\Models\Catalog\Product;
use App\Models\Seller\Seller;
use App\Models\Seller\StoreSale;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StoreSale>
 */
class StoreSaleFactory extends Factory
{
    protected $model = StoreSale::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'seller_id' => Seller::factory(),
            // The product belongs to the sale's Seller, as the model requires.
            'product_id' => fn (array $attributes) => Product::factory()->create(['seller_id' => $attributes['seller_id']])->id,
            'variant_id' => null,
            'type' => StoreSaleType::Percent,
            'value' => 20,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
        ];
    }

    /** A whole percentage off, 1-99. */
    public function percent(int $percent): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => StoreSaleType::Percent,
            'value' => $percent,
        ]);
    }

    /** A set sale price in centavos. */
    public function fixedPrice(int $centavos): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => StoreSaleType::FixedPrice,
            'value' => $centavos,
        ]);
    }

    public function upcoming(): static
    {
        return $this->state(fn (array $attributes): array => [
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDays(2),
        ]);
    }

    public function ended(): static
    {
        return $this->state(fn (array $attributes): array => [
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->subDay(),
        ]);
    }
}
