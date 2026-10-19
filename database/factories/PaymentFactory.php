<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\SellerOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'seller_order_id' => SellerOrder::factory(),
            'amount_minor' => 10000,
            'method' => PaymentMethod::Cod->value,
            'status' => PaymentStatus::Pending->value,
            'paid_at' => null,
        ];
    }

    public function paid(): static
    {
        return $this->state([
            'status' => PaymentStatus::Paid->value,
            'paid_at' => now(),
        ]);
    }
}
