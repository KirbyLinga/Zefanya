<?php

namespace Tests\Feature\Seller;

use App\Enums\OrderStatus;
use App\Models\Seller\Seller;
use App\Models\Shared\Order;
use App\Models\Shared\SellerOrder;
use App\Models\Shared\StatusHistory;
use App\Models\Shared\Voucher;
use App\Services\Seller\SellerSalesTrend;
use App\Services\Shared\CommissionFinalizationService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DashboardStatisticsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_empty_dashboard_shows_zero_completed_orders_and_zero_earnings(): void
    {
        $seller = Seller::factory()->create(['status' => 'approved']);

        $this->actingAs($seller, 'seller')->get(route('seller.dashboard'))
            ->assertOk()
            ->assertViewHas('completedOrders', 0)
            ->assertViewHas('netEarningsMinor', 0)
            ->assertViewHas('earningsPendingCount', 0)
            ->assertSee('Completed Orders')
            ->assertSee('Net Earnings')
            ->assertSee('₱0.00');
    }

    public function test_statistics_and_daily_sales_are_independent_and_scoped_to_the_seller(): void
    {
        $this->freezeTime();
        $seller = Seller::factory()->create(['status' => 'approved']);
        $pending = $this->sellerOrder($seller, OrderStatus::Placed, 1000);
        $this->sellerOrder($seller, OrderStatus::Preparing, 2000);
        $this->sellerOrder($seller, OrderStatus::Completed, 10000);
        $this->sellerOrder($seller, OrderStatus::Delivered, 20000);
        $yesterday = $this->sellerOrder($seller, OrderStatus::Completed, 5000);
        $yesterday->forceFill(['updated_at' => now()->subDay()])->save();
        $this->sellerOrder($seller, OrderStatus::Cancelled, 99900);
        $this->sellerOrder($seller, OrderStatus::Returned, 88800);
        $this->sellerOrder(Seller::factory()->create(), OrderStatus::Completed, 99900);

        $response = $this->actingAs($seller, 'seller')->get(route('seller.dashboard'))
            ->assertOk()
            ->assertViewHas('totalOrders', 7)
            ->assertViewHas('pendingOrders', 2)
            ->assertViewHas('completedOrders', 2)
            ->assertViewHas('totalSales', 350)
            ->assertViewHas('recentOrders', fn ($orders) => $orders->count() === 7 && $orders->contains('id', $pending->id));

        $this->assertSame([0, 0, 0, 0, 0, 5000, 30000], $response->viewData('salesTrend')->pluck('value')->all());
    }

    private function soldOn(Seller $seller, Carbon $at, int $subtotal, OrderStatus $status = OrderStatus::Completed): SellerOrder
    {
        $order = $this->sellerOrder($seller, $status, $subtotal);
        $order->forceFill(['updated_at' => $at])->saveQuietly();

        return $order;
    }

    public function test_daily_sales_use_philippine_days_not_utc_days(): void
    {
        // 00:30 on Oct 31 in Manila is still 16:30 on Oct 30 in UTC.
        Carbon::setTestNow(Carbon::parse('2026-10-30 16:30:00', 'UTC'));
        $seller = Seller::factory()->create(['status' => 'approved']);
        $this->soldOn($seller, Carbon::parse('2026-10-30 16:10:00', 'UTC'), 1000);
        $this->soldOn($seller, Carbon::parse('2026-10-30 15:50:00', 'UTC'), 2000);

        $response = $this->actingAs($seller, 'seller')->get(route('seller.dashboard'))->assertOk();
        $days = $response->viewData('salesTrend');

        $this->assertSame('Today', $days->last()['label']);
        $this->assertSame('Oct 31', $days->last()['date']);
        $this->assertSame(1000, $days->last()['value']);
        $this->assertSame(2000, $days[5]['value']);
        $response->assertSee('Saturday, October 31, 2026');
    }

    public function test_a_later_update_does_not_move_a_sale_off_its_delivery_day(): void
    {
        $this->freezeTime();
        $seller = Seller::factory()->create(['status' => 'approved']);
        $order = $this->sellerOrder($seller, OrderStatus::Completed, 5000);
        $history = StatusHistory::create(['seller_order_id' => $order->id, 'from_status' => OrderStatus::OutForDelivery, 'to_status' => OrderStatus::Delivered]);
        $history->forceFill(['created_at' => now()->subDays(3)])->save();

        $days = $this->actingAs($seller, 'seller')->get(route('seller.dashboard'))->viewData('salesTrend');

        $this->assertSame([0, 0, 0, 5000, 0, 0, 0], $days->pluck('value')->all());
        $this->assertSame(1, $days[3]['orders']);
    }

    public function test_the_summary_compares_with_the_seven_days_before(): void
    {
        $this->freezeTime();
        $seller = Seller::factory()->create(['status' => 'approved']);
        $this->soldOn($seller, now()->subDay(), 15000);
        $this->soldOn($seller, now()->subDays(2), 15000);
        $this->soldOn($seller, now()->subDays(8), 20000);
        $this->soldOn($seller, now()->subDays(20), 99900);

        $response = $this->actingAs($seller, 'seller')->get(route('seller.dashboard'))->assertOk();
        $summary = $response->viewData('salesSummary');

        $this->assertSame(30000, $summary['totalMinor']);
        $this->assertSame(2, $summary['orderCount']);
        $this->assertSame(20000, $summary['previousMinor']);
        $this->assertSame(50, $summary['changePercent']);
        $response->assertSee('Up 50% from the 7 days before')->assertSee('₱300.00')->assertSee('View as table');
    }

    public function test_a_fall_and_a_first_week_of_sales_read_plainly(): void
    {
        $this->freezeTime();
        $seller = Seller::factory()->create(['status' => 'approved']);
        $this->soldOn($seller, now()->subDay(), 10000);

        $this->actingAs($seller, 'seller')->get(route('seller.dashboard'))
            ->assertSee('No sales in the 7 days before')
            ->assertDontSee('Up 0%');

        $this->soldOn($seller, now()->subDays(8), 40000);

        $this->actingAs($seller, 'seller')->get(route('seller.dashboard'))
            ->assertSee('Down 75% from the 7 days before');
    }

    public function test_no_sales_this_week_shows_a_message_instead_of_an_empty_chart(): void
    {
        $seller = Seller::factory()->create(['status' => 'approved']);

        $this->actingAs($seller, 'seller')->get(route('seller.dashboard'))
            ->assertOk()
            ->assertSee('No sales in the last 7 days')
            ->assertDontSee('View as table');
    }

    /** @return array<string, array{0: int, 1: list<int>}> */
    public static function axes(): array
    {
        return [
            'small' => [30000, [0, 10000, 20000, 30000]],
            'needs a round top' => [31000, [0, 10000, 20000, 30000, 40000]],
            'large' => [25800000, [0, 10000000, 20000000, 30000000]],
            'tiny' => [50, [0, 100]],
        ];
    }

    /** @param list<int> $ticks */
    #[DataProvider('axes')]
    public function test_the_axis_uses_round_values_that_reach_the_tallest_bar(int $tallest, array $ticks): void
    {
        $this->freezeTime();
        $seller = Seller::factory()->create(['status' => 'approved']);
        $this->soldOn($seller, now()->subDay(), $tallest);

        $summary = app(SellerSalesTrend::class)->forSeller($seller->id);

        $this->assertSame($ticks, $summary['ticks']);
        $this->assertGreaterThanOrEqual($tallest, $summary['axisMaxMinor']);
    }

    public function test_compact_peso_labels(): void
    {
        $this->assertSame('₱0', SellerSalesTrend::compactPeso(0));
        $this->assertSame('₱500', SellerSalesTrend::compactPeso(50000));
        $this->assertSame('₱1K', SellerSalesTrend::compactPeso(100000));
        $this->assertSame('₱1.5K', SellerSalesTrend::compactPeso(150000));
        $this->assertSame('₱100K', SellerSalesTrend::compactPeso(10000000));
        $this->assertSame('₱2M', SellerSalesTrend::compactPeso(200000000));
    }

    public function test_recent_orders_show_the_same_status_colours_as_the_orders_page(): void
    {
        $seller = Seller::factory()->create(['status' => 'approved']);
        $this->sellerOrder($seller, OrderStatus::ReadyForPickup, 1000);
        $this->sellerOrder($seller, OrderStatus::Cancelled, 1000);

        $this->actingAs($seller, 'seller');

        foreach ([route('seller.dashboard'), route('seller.orders.index')] as $page) {
            $this->get($page)->assertOk()
                ->assertSee('bg-sky-100 text-sky-900 ring-sky-300', false)
                ->assertSee('bg-red-100 text-red-900 ring-red-300', false);
        }
    }

    public function test_stat_cards_link_to_the_pages_behind_them(): void
    {
        $seller = Seller::factory()->create(['status' => 'approved']);

        $response = $this->actingAs($seller, 'seller')->get(route('seller.dashboard'))->assertOk();

        foreach ([
            route('seller.reports.index'),
            route('seller.earnings.index'),
            route('seller.orders.index'),
            route('seller.orders.index', ['bucket' => 'delivered']),
            route('seller.orders.index', ['bucket' => 'to_ship']),
            route('seller.products.index'),
        ] as $url) {
            $response->assertSee('<a href="'.$url.'"', false);
        }
    }

    public function test_another_sellers_sales_stay_out_of_the_chart(): void
    {
        $this->freezeTime();
        $seller = Seller::factory()->create(['status' => 'approved']);
        $this->soldOn(Seller::factory()->create(), now(), 99900);

        $this->assertSame(0, app(SellerSalesTrend::class)->forSeller($seller->id)['totalMinor']);
    }

    public function test_net_earnings_use_finalized_amounts_after_commission_and_voucher_funding(): void
    {
        $seller = Seller::factory()->create(['status' => 'approved']);
        $this->finalizedOrder($seller, 10000, 2000, Voucher::FUNDING_SELLER);
        $this->finalizedOrder($seller, 20000, 2000, Voucher::FUNDING_MARKETPLACE);
        $this->finalizedOrder(Seller::factory()->create(), 90000);
        $delivered = $this->sellerOrder($seller, OrderStatus::Delivered, 50000);
        // A non-completed row must not contribute even if stale financial fields exist.
        $delivered->forceFill([
            'commission_bps' => 1000, 'commission_minor' => 5000,
            'seller_net_minor' => 45000, 'commission_finalized_at' => now(),
        ])->save();

        $this->actingAs($seller, 'seller')->get(route('seller.dashboard'))
            ->assertOk()
            ->assertViewHas('completedOrders', 2)
            ->assertViewHas('netEarningsMinor', 26600)
            ->assertViewHas('earningsPendingCount', 0)
            ->assertSee('₱266.00');
    }

    public function test_unknown_or_partial_financials_are_flagged_instead_of_estimated(): void
    {
        $seller = Seller::factory()->create(['status' => 'approved']);
        $this->finalizedOrder($seller, 10000);
        $unknown = $this->sellerOrder($seller, OrderStatus::Completed, 20000);
        $unknown->forceFill([
            'commission_bps' => 1000, 'commission_minor' => 2000,
            'commission_finalized_at' => now(), 'seller_net_minor' => null,
        ])->save();
        $partial = $this->sellerOrder($seller, OrderStatus::Completed, 30000);
        $partial->forceFill(['seller_net_minor' => 27000])->save();

        $this->actingAs($seller, 'seller')->get(route('seller.dashboard'))
            ->assertOk()
            ->assertViewHas('completedOrders', 3)
            ->assertViewHas('netEarningsMinor', 9500)
            ->assertViewHas('earningsPendingCount', 2)
            ->assertSee('2 completed orders awaiting finalized earnings.');

        $this->assertNull($unknown->fresh()->seller_net_minor);
        $this->assertNull($partial->fresh()->commission_finalized_at);
    }

    public function test_net_earnings_preserve_negative_finalized_amounts(): void
    {
        $seller = Seller::factory()->create(['status' => 'approved']);
        // Preserve an immutable historical snapshot, rather than generating it at today's rate.
        $historical = $this->sellerOrder($seller, OrderStatus::Completed, 10000);
        $historical->forceFill([
            'commission_bps' => 1000,
            'commission_minor' => 1000,
            'voucher_discount_minor' => 9500,
            'voucher_funding_source' => Voucher::FUNDING_SELLER,
            'seller_net_minor' => -500,
            'commission_finalized_at' => now(),
        ])->save();

        $this->actingAs($seller, 'seller')->get(route('seller.dashboard'))
            ->assertOk()
            ->assertViewHas('completedOrders', 1)
            ->assertViewHas('netEarningsMinor', -500)
            ->assertSee('₱-5.00');
    }

    private function sellerOrder(Seller $seller, OrderStatus $status, int $subtotal): SellerOrder
    {
        return SellerOrder::factory()->status($status)->create([
            'seller_id' => $seller->id,
            'subtotal_minor' => $subtotal,
        ]);
    }

    private function finalizedOrder(Seller $seller, int $subtotal, int $discount = 0, ?string $funding = null): SellerOrder
    {
        $parent = Order::factory()->create(['discount_minor' => $discount]);
        $order = SellerOrder::factory()->status(OrderStatus::Completed)->create([
            'seller_id' => $seller->id, 'order_id' => $parent->id, 'subtotal_minor' => $subtotal,
        ]);
        $order->forceFill([
            'shipping_fee_minor' => 1500,
            'voucher_discount_minor' => $discount,
            'voucher_funding_source' => $funding,
        ])->save();

        return app(CommissionFinalizationService::class)->finalize($order);
    }
}
