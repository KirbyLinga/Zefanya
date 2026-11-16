<?php

namespace Database\Factories\Buyer;

use App\Models\Buyer\Buyer;
use App\Models\Buyer\CartItem;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CartItem>
 */
class CartItemFactory extends Factory
{
    protected $model = CartItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'buyer_id' => Buyer::factory(),
            'product_id' => Product::factory(),
            'variant_id' => null,
            'quantity' => 1,
        ];
    }

    /**
     * Target the product's auto Default variant (what CartController creates
     * for every add since variants).
     */
    public function forDefaultVariant(): static
    {
        return $this->state(function (array $attributes, CartItem $item): array {
            $variant = ProductVariant::query()
                ->where('product_id', $item->product_id ?? $attributes['product_id'])
                ->orderBy('id')
                ->firstOr(function () use ($attributes) {
                    return ProductVariant::query()->create([
                        'product_id' => $attributes['product_id'],
                        'sku' => null,
                        'price' => 100,
                        'stock_quantity' => 10,
                        'is_available' => true,
                    ]);
                });

            return ['variant_id' => $variant->id];
        });
    }
}
