<?php

namespace Tests\Feature\Seller;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Seller\Seller;
use App\Models\SellerOrder;
use App\Models\Voucher;
use App\Services\CommissionFinalizationService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
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
            ->assertViewHas('netEarningsMinor', 25000)
            ->assertViewHas('earningsPendingCount', 0)
            ->assertSee('₱250.00');
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
            ->assertViewHas('netEarningsMinor', 9000)
            ->assertViewHas('earningsPendingCount', 2)
            ->assertSee('2 completed orders awaiting finalized earnings.');

        $this->assertNull($unknown->fresh()->seller_net_minor);
        $this->assertNull($partial->fresh()->commission_finalized_at);
    }

    public function test_net_earnings_preserve_negative_finalized_amounts(): void
    {
        $seller = Seller::factory()->create(['status' => 'approved']);
        $this->finalizedOrder($seller, 10000, 9500, Voucher::FUNDING_SELLER);

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
