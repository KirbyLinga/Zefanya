<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Buyer\Buyer;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'buyer_id' => Buyer::factory(),
            'reference' => Order::generateUniqueReference(),
            'shipping_address' => [
                'name' => 'Test Buyer',
                'phone' => '09170000000',
                'province' => 'Metro Manila',
                'municipality' => 'Quezon City',
                'barangay' => 'Diliman',
                'street' => 'Test Street',
                'house_number' => '1',
                'address_detail' => null,
                'full' => '1, Test Street, Diliman, Quezon City, Metro Manila',
            ],
            'subtotal_minor' => 10000,
            'discount_minor' => 0,
            'total_minor' => 10000,
            'status' => OrderStatus::Placed,
        ];
    }
}
