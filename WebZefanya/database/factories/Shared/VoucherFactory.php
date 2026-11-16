<?php

namespace Database\Factories\Shared;

use App\Enums\VoucherScope;
use App\Enums\VoucherType;
use App\Models\Seller\Seller;
use App\Models\Shared\Voucher;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Voucher>
 */
class VoucherFactory extends Factory
{
    protected $model = Voucher::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('SAVE##??')),
            'scope' => VoucherScope::Platform,
            'seller_id' => null,
            'type' => VoucherType::Fixed,
            'value' => 20000,            // ₱200.00 in centavos
            'min_spend' => null,
            'is_active' => true,
            'expires_at' => null,
            'usage_limit' => null,
            'times_used' => 0,
        ];
    }

    /** A shop-scoped voucher owned by one Seller. */
    public function shop(Seller|int $seller): static
    {
        return $this->state(fn (array $attributes): array => [
            'scope' => VoucherScope::Shop,
            'seller_id' => $seller instanceof Seller ? $seller->getKey() : $seller,
        ]);
    }

    /** An order-wide platform voucher. */
    public function platform(): static
    {
        return $this->state(fn (array $attributes): array => [
            'scope' => VoucherScope::Platform,
            'seller_id' => null,
        ]);
    }

    /** A voucher that discounts eligible shipping fees only. */
    public function shipping(): static
    {
        return $this->state(fn (array $attributes): array => [
            'scope' => VoucherScope::Shipping,
            'seller_id' => null,
        ]);
    }

    /** A voucher worth a flat amount in centavos. */
    public function fixed(int $centavos): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => VoucherType::Fixed,
            'value' => $centavos,
        ]);
    }

    /** A percentage-off voucher, 0-100. */
    public function percent(int $percent): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => VoucherType::Percent,
            'value' => $percent,
        ]);
    }

    /** A shop voucher limited to the given products. */
    public function forProducts(iterable $products): static
    {
        return $this->state(fn (array $attributes): array => [
            'applies_to_all_products' => false,
        ])->afterCreating(function (Voucher $voucher) use ($products): void {
            $voucher->products()->sync(collect($products)->map(fn ($product) => $product->id)->all());
        });
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'expires_at' => now()->subDay(),
        ]);
    }

    public function minSpend(int $centavos): static
    {
        return $this->state(fn (array $attributes): array => [
            'min_spend' => $centavos,
        ]);
    }
}
