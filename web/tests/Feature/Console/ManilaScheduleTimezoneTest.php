<?php

namespace Tests\Feature\Console;

use App\Enums\OrderStatus;
use App\Models\Shared\SellerOrder;
use App\Models\Shared\StatusHistory;
use App\Models\Shared\Voucher;
use App\Services\Buyer\AutomaticBuyerReceiptService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ManilaScheduleTimezoneTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_receipt_schedule_uses_manila_without_changing_its_frequency_or_storage_timezone(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command ?? '', 'orders:auto-complete-receipts'));

        $this->assertNotNull($event);
        $this->assertSame('Asia/Manila', $event->timezone);
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertSame('UTC', config('app.timezone'));
    }

    public static function manilaMidnight(): array
    {
        return [['2026-10-27 15:59:00', false], ['2026-10-27 16:00:00', true], ['2026-10-27 16:01:00', false]];
    }

    #[DataProvider('manilaMidnight')]
    public function test_business_schedule_midnight_is_evaluated_in_manila(string $utc, bool $expected): void
    {
        $this->travelTo(Carbon::parse($utc, 'UTC'));
        $event = app(Schedule::class)->call(static fn () => null)->dailyAt('00:00');

        $this->assertSame($expected, $event->isDue($this->app));
    }

    public function test_voucher_expiry_at_manila_day_end_uses_the_same_utc_instant(): void
    {
        $voucher = Voucher::factory()->create(['expires_at' => CarbonImmutable::parse('2026-10-27 23:59:59', 'Asia/Manila')->utc()]);
        $this->travelTo(Carbon::parse('2026-10-27 15:59:58', 'UTC'));
        $this->assertNull($voucher->fresh()->rejectionReason(100000));

        $this->travelTo(Carbon::parse('2026-10-27 16:00:00', 'UTC'));

        $this->assertSame('This voucher has expired.', $voucher->fresh()->rejectionReason(100000));
    }

    public static function unchangedReceiptBoundary(): array
    {
        return [['2026-10-27 15:59:59', false], ['2026-10-27 16:00:00', true], ['2026-10-27 16:00:01', true]];
    }

    #[DataProvider('unchangedReceiptBoundary')]
    public function test_manila_scheduling_does_not_shift_the_seven_day_receipt_deadline(string $utc, bool $expected): void
    {
        $order = SellerOrder::factory()->create(['status' => OrderStatus::Delivered]);
        $history = StatusHistory::query()->create(['seller_order_id' => $order->id, 'from_status' => OrderStatus::OutForDelivery, 'to_status' => OrderStatus::Delivered]);
        $history->forceFill(['created_at' => CarbonImmutable::parse('2026-10-20 16:00:00', 'UTC')])->saveQuietly();
        $this->travelTo(Carbon::parse($utc, 'UTC'));
        $cutoff = CarbonImmutable::now()->subHours(AutomaticBuyerReceiptService::WAIT_HOURS);

        $this->assertSame($expected, AutomaticBuyerReceiptService::eligibleOrders($cutoff)->whereKey($order->id)->exists());
        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);
    }
}
