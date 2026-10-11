<?php

namespace Database\Factories;

use App\Models\OrderItem;
use App\Models\Product;
use App\Models\SellerOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    protected $model = OrderItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'seller_order_id' => SellerOrder::factory(),
            'product_id' => Product::factory(),
            'product_name' => 'Test Product',
            'quantity' => 1,
            'price_minor' => 10000,
        ];
    }
}
