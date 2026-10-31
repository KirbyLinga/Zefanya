<?php

namespace Tests\Feature\Seller;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\CodRemittance;
use App\Models\Rider\Courier;
use App\Models\Seller\Seller;
use App\Models\Shared\Order;
use App\Models\Shared\OrderItem;
use App\Models\Shared\Payment;
use App\Models\Shared\SellerOrder;
use App\Models\Shared\Voucher;
use App\Services\Seller\SellerPayoutRequestService;
use App\Services\Seller\SellerSettlementService;
use App\Services\Shared\CommissionFinalizationService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** SEL-EARN-001 to 003: Pending and Available, with net after seller sales, seller vouchers, and 5% commission. */
class SellerEarningsPageTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_sel_earn_001_to_003_page_splits_pending_and_available_without_deducting_platform_or_shipping(): void
    {
        $seller = Seller::factory()->create();
        $buyer = Buyer::factory()->create();
        $this->availableEarnings($seller);
        $this->pendingSale($seller, $buyer);
        $this->pendingPlatformVoucher($seller, $buyer);

        $other = Seller::factory()->create();
        $hidden = $this->pendingSale($other, $buyer);
        $hidden->order->forceFill(['reference' => 'OTHER-SELLER-EARN'])->save();

        $response = $this->actingAs($seller, 'seller')->get(route('seller.earnings.index'))
            ->assertOk()
            ->assertSee('Pending')
            ->assertSee('Available')
            ->assertSee('₱237.50')
            ->assertSee('₱616.55')
            ->assertSee('Seller sales')
            ->assertSee('Seller vouchers')
            ->assertSee('Commission (5%)')
            ->assertSee('Platform vouchers (₱10.00) are funded by the marketplace and are not deducted.')
            ->assertSee('Shipping (₱73.00) is not deducted.')
            ->assertSee('Earnings')
            ->assertDontSee('OTHER-SELLER-EARN');

        $this->assertSame(23750, $response->viewData('pendingMinor'));
        $this->assertSame(61655, $response->viewData('availableMinor'));
        $this->assertSame(99900, $response->viewData('grossMinor'));
        $this->assertSame(5000, $response->viewData('salesMinor'));
        $this->assertSame(5000, $response->viewData('sellerVoucherMinor'));
        $this->assertSame(4495, $response->viewData('commissionMinor'));
        $this->assertSame(85405, $response->viewData('netMinor'));
        $this->assertSame(1000, $response->viewData('platformVoucherMinor'));
        $this->assertSame(7300, $response->viewData('shippingMinor'));
        $this->assertSame(
            $response->viewData('grossMinor') - $response->viewData('salesMinor') - $response->viewData('sellerVoucherMinor') - $response->viewData('commissionMinor'),
            $response->viewData('netMinor'),
        );

        app(SellerPayoutRequestService::class)->request($seller, '100.00', (string) Str::uuid());

        $this->actingAs($seller, 'seller')->get(route('seller.earnings.index'))
            ->assertOk()
            ->assertViewHas('pendingMinor', 23750)
            ->assertViewHas('availableMinor', 51655)
            ->assertViewHas('reservedMinor', 10000)
            ->assertSee('₱100.00 is reserved by a payout request.');
    }

    public function test_a_guest_cannot_open_seller_earnings(): void
    {
        $this->get(route('seller.earnings.index'))->assertRedirect(route('home', ['login' => 1]));
    }

    private function availableEarnings(Seller $seller): void
    {
        $parent = Order::factory()->create(['discount_minor' => 5000]);
        $order = SellerOrder::factory()->status(OrderStatus::Completed)->create([
            'seller_id' => $seller->id, 'order_id' => $parent->id, 'subtotal_minor' => 69900,
        ]);
        $order->forceFill([
            'shipping_fee_minor' => 6500,
            'voucher_discount_minor' => 5000,
            'voucher_funding_source' => Voucher::FUNDING_SELLER,
        ])->save();
        $payment = Payment::factory()->create(['seller_order_id' => $order->id, 'amount_minor' => 71400]);
        $payment->forceFill([
            'status' => PaymentStatus::Paid, 'paid_at' => now(),
            'collected_at' => now(), 'collected_amount_minor' => 71400,
        ])->save();
        $courier = Courier::factory()->create();
        $remittance = new CodRemittance;
        $remittance->forceFill([
            'seller_order_id' => $order->id, 'payment_id' => $payment->id, 'courier_id' => $courier->id,
            'logistics_provider_id' => $courier->logistics_provider_id, 'amount_minor' => 71400,
            'status' => CodRemittance::REMITTED_TO_LOGISTICS, 'received_amount_minor' => 71400, 'zefanya_received_at' => now(),
            'confirmed_at' => now(), 'confirmed_by_id' => $courier->logistics_provider_id,
        ])->save();
        app(SellerSettlementService::class)->prepare($remittance);
        app(CommissionFinalizationService::class)->finalize($order);
    }

    private function pendingSale(Seller $seller, Buyer $buyer): SellerOrder
    {
        $order = SellerOrder::factory()->status(OrderStatus::Completed)->create([
            'seller_id' => $seller->id,
            'order_id' => Order::factory()->create(['buyer_id' => $buyer->id, 'discount_minor' => 0])->id,
            'subtotal_minor' => 15000,
        ]);
        $order->forceFill([
            'shipping_fee_minor' => 0,
            'commission_bps' => 500,
            'commission_minor' => 750,
            'seller_net_minor' => 14250,
            'commission_finalized_at' => now(),
            'seller_funded_discount_minor' => 0,
            'marketplace_funded_discount_minor' => 0,
            'voucher_discount_minor' => 0,
        ])->save();
        OrderItem::query()->create([
            'seller_order_id' => $order->id,
            'product_name' => 'Sale item',
            'quantity' => 1,
            'price_minor' => 15000,
            'regular_price_minor' => 20000,
        ]);

        return $order;
    }

    private function pendingPlatformVoucher(Seller $seller, Buyer $buyer): void
    {
        $order = SellerOrder::factory()->status(OrderStatus::Completed)->create([
            'seller_id' => $seller->id,
            'order_id' => Order::factory()->create(['buyer_id' => $buyer->id, 'discount_minor' => 1000])->id,
            'subtotal_minor' => 10000,
        ]);
        $order->forceFill([
            'shipping_fee_minor' => 800,
            'platform_voucher_discount_minor' => 1000,
            'marketplace_funded_discount_minor' => 1000,
            'seller_funded_discount_minor' => 0,
            'voucher_discount_minor' => 1000,
            'voucher_funding_source' => Voucher::FUNDING_MARKETPLACE,
            'commission_bps' => 500,
            'commission_minor' => 500,
            'seller_net_minor' => 9500,
            'commission_finalized_at' => now(),
        ])->save();
        OrderItem::query()->create([
            'seller_order_id' => $order->id,
            'product_name' => 'Platform voucher item',
            'quantity' => 1,
            'price_minor' => 10000,
            'regular_price_minor' => 10000,
        ]);
    }
}
