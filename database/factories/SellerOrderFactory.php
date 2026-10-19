<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Seller\Seller;
use App\Models\SellerOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SellerOrder>
 */
class SellerOrderFactory extends Factory
{
    protected $model = SellerOrder::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'seller_id' => Seller::factory(),
            'subtotal_minor' => 10000,
        ];
    }

    /**
     * Seed a specific status via forceFill (status is deliberately not
     * mass-assignable on SellerOrder).
     */
    public function status(OrderStatus $status): static
    {
        return $this->afterCreating(function (SellerOrder $sellerOrder) use ($status): void {
            $sellerOrder->forceFill(['status' => $status->value])->save();
        });
    }
}
