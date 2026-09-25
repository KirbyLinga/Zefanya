<?php

namespace Database\Factories;

use App\Models\Buyer\Buyer;
use App\Models\CartItem;
use App\Models\Product;
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
            'quantity' => 1,
        ];
    }
}
