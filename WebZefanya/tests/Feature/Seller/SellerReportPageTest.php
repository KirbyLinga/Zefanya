<?php

namespace Tests\Feature\Seller;

use App\Enums\OrderStatus;
use App\Models\Buyer\Buyer;
use App\Models\Seller\Seller;
use App\Models\Shared\Order;
use App\Models\Shared\OrderItem;
use App\Models\Shared\SellerOrder;
use App\Models\Shared\Voucher;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** SEL-EARN-004: reports replace the stub and filter an inclusive Philippine date range. */
class SellerReportPageTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_sel_earn_004_inclusive_range_reports_sales_commission_and_earnings(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-15 04:00:00', 'UTC'));
        $seller = Seller::factory()->create();
        $buyer = Buyer::factory()->create();

        $this->completed($seller, $buyer, '2026-11-01 00:00:00', 10000, 500, 9500, [
            'shipping_fee_minor' => 800,
            'platform_voucher_discount_minor' => 1000,
            'marketplace_funded_discount_minor' => 1000,
            'voucher_discount_minor' => 1000,
            'voucher_funding_source' => Voucher::FUNDING_MARKETPLACE,
        ], 10000, 10000, 'IN-RANGE-PLATFORM');
        $this->completed($seller, $buyer, '2026-11-01 23:59:59', 15000, 750, 14250, [
            'shipping_fee_minor' => 0,
            'seller_funded_discount_minor' => 0,
            'voucher_discount_minor' => 0,
        ], 15000, 20000, 'IN-RANGE-SALE');
        $this->completed($seller, $buyer, '2026-10-31 23:59:59', 40000, 2000, 38000, [], 40000, 40000, 'BEFORE-RANGE');
        $this->completed($seller, $buyer, '2026-11-02 00:00:00', 30000, 1500, 28500, [], 30000, 30000, 'AFTER-RANGE');

        $other = Seller::factory()->create();
        $this->completed($other, $buyer, '2026-11-01 12:00:00', 99900, 4995, 94905, [], 99900, 99900, 'OTHER-SELLER-REPORT');

        $response = $this->actingAs($seller, 'seller')->get(route('seller.reports.index', [
            'from' => '2026-11-01',
            'to' => '2026-11-01',
        ]))->assertOk()
            ->assertSee('Reports')
            ->assertSee('name="from"', false)
            ->assertSee('name="to"', false)
            ->assertSee('IN-RANGE-PLATFORM')
            ->assertSee('IN-RANGE-SALE')
            ->assertDontSee('BEFORE-RANGE')
            ->assertDontSee('AFTER-RANGE')
            ->assertDontSee('OTHER-SELLER-REPORT')
            ->assertSee('₱250.00')
            ->assertSee('₱12.50')
            ->assertSee('₱237.50')
            ->assertSee('Platform vouchers (₱10.00)')
            ->assertSee('shipping (₱8.00)')
            ->assertDontSee('View sales and performance reports.');

        $this->assertSame(25000, $response->viewData('salesMinor'));
        $this->assertSame(1250, $response->viewData('commissionMinor'));
        $this->assertSame(23750, $response->viewData('netMinor'));
        $this->assertSame(1000, $response->viewData('platformVoucherMinor'));
        $this->assertSame(800, $response->viewData('shippingMinor'));
        $this->assertSame(
            $response->viewData('grossMinor') - $response->viewData('storeSaleMinor') - $response->viewData('sellerVoucherMinor') - $response->viewData('commissionMinor'),
            $response->viewData('netMinor'),
        );
    }

    public function test_sel_earn_004_rejects_an_end_date_before_the_start_date(): void
    {
        $seller = Seller::factory()->create();

        $this->actingAs($seller, 'seller')->get(route('seller.reports.index', [
            'from' => '2026-11-10',
            'to' => '2026-11-01',
        ]))->assertOk()
            ->assertSee('The end date must be on or after the start date.')
            ->assertViewHas('salesMinor', 0)
            ->assertViewHas('rows', fn ($rows) => $rows->isEmpty());
    }

    public function test_a_guest_cannot_open_seller_reports(): void
    {
        $this->get(route('seller.reports.index'))->assertRedirect(route('home', ['login' => 1]));
    }

    /** @param  array<string, mixed>  $financials */
    private function completed(
        Seller $seller,
        Buyer $buyer,
        string $manila,
        int $subtotal,
        int $commission,
        int $net,
        array $financials,
        int $price,
        int $regular,
        string $reference,
    ): SellerOrder {
        $parent = Order::factory()->create(['buyer_id' => $buyer->id, 'discount_minor' => 0, 'reference' => $reference]);
        $order = SellerOrder::factory()->status(OrderStatus::Completed)->create([
            'seller_id' => $seller->id,
            'order_id' => $parent->id,
            'subtotal_minor' => $subtotal,
        ]);
        $order->forceFill($financials + [
            'commission_bps' => 500,
            'commission_minor' => $commission,
            'seller_net_minor' => $net,
            'commission_finalized_at' => Carbon::parse($manila, 'Asia/Manila')->utc(),
        ])->save();
        OrderItem::query()->create([
            'seller_order_id' => $order->id,
            'product_name' => $reference,
            'quantity' => 1,
            'price_minor' => $price,
            'regular_price_minor' => $regular,
        ]);

        return $order;
    }
}
