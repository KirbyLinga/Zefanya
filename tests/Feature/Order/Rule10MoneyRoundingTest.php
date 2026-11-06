<?php

namespace Tests\Feature\Order;

use App\Enums\OrderStatus;
use App\Enums\VoucherType;
use App\Models\Shared\Order;
use App\Models\Shared\SellerOrder;
use App\Models\Shared\Voucher;
use App\Services\Shared\CommissionFinalizationService;
use App\Support\Money;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class Rule10MoneyRoundingTest extends TestCase
{
    use LazilyRefreshDatabase;

    public static function percentageBoundaries(): array
    {
        return [
            'below half a centavo' => [104, 10, 10],
            'exactly half a centavo' => [105, 10, 11],
            'above half a centavo' => [106, 10, 11],
            'fraction rounds up' => [999, 15, 150],
            'half with a larger whole part' => [1250, 15, 188],
            'zero subtotal' => [0, 10, 0],
            'zero rate' => [105, 0, 0],
            'one centavo at half' => [1, 50, 1],
            'full discount' => [105, 100, 105],
            'large intermediate product' => [PHP_INT_MAX, 100, PHP_INT_MAX],
        ];
    }

    #[DataProvider('percentageBoundaries')]
    public function test_percentage_vouchers_round_half_up_in_integer_centavos(int $subtotal, int $percent, int $expected): void
    {
        $voucher = new Voucher(['type' => VoucherType::Percent, 'value' => $percent]);

        $this->assertSame($expected, $voucher->discountCentavos($subtotal));
    }

    public function test_fixed_and_percentage_vouchers_never_exceed_the_subtotal(): void
    {
        $fixed = new Voucher(['type' => VoucherType::Fixed, 'value' => 200]);
        $percent = new Voucher(['type' => VoucherType::Percent, 'value' => 125]);

        $this->assertSame(105, $fixed->discountCentavos(105));
        $this->assertSame(105, $percent->discountCentavos(105));
    }

    public static function commissionBoundaries(): array
    {
        return [[109, 5, 104], [110, 6, 104], [111, 6, 105], [1, 0, 1], [10, 1, 9], [69900, 3495, 66405]];
    }

    #[DataProvider('commissionBoundaries')]
    public function test_commission_uses_five_percent_and_half_up_centavo_rounding(int $subtotal, int $commission, int $net): void
    {
        $parent = Order::factory()->create(['discount_minor' => 0]);
        $order = SellerOrder::factory()->create([
            'order_id' => $parent->id, 'status' => OrderStatus::Completed,
            'subtotal_minor' => $subtotal, 'shipping_fee_minor' => 4900, 'voucher_discount_minor' => 0,
        ]);

        $finalized = app(CommissionFinalizationService::class)->finalize($order);

        $this->assertSame(500, $finalized->commission_bps);
        $this->assertSame($commission, $finalized->commission_minor);
        $this->assertSame($net, $finalized->seller_net_minor);
        $this->assertSame($subtotal, $finalized->commission_minor + $finalized->seller_net_minor);
    }

    public function test_percentage_helper_does_not_silently_overflow_integer_storage(): void
    {
        $this->expectException(\OverflowException::class);

        Money::percentage(PHP_INT_MAX, 101);
    }

    public static function invalidPercentageInputs(): array
    {
        return [[-1, 10, 100], [1, -1, 100], [1, 10, 0]];
    }

    #[DataProvider('invalidPercentageInputs')]
    public function test_percentage_helper_rejects_invalid_inputs(int $amount, int $rate, int $scale): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Money::percentage($amount, $rate, $scale);
    }
}
