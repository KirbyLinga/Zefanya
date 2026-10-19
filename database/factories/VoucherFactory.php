<?php

namespace Database\Factories;

use App\Enums\VoucherType;
use App\Models\Voucher;
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
            'type' => VoucherType::Fixed,
            'value' => 20000,            // ₱200.00 in centavos
            'min_spend' => null,
            'is_active' => true,
            'expires_at' => null,
            'usage_limit' => null,
            'times_used' => 0,
        ];
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
