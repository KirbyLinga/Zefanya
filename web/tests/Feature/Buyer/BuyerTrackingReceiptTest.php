<?php

namespace Tests\Feature\Buyer;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\CodRemittance;
use App\Models\Rider\Courier;
use App\Models\Seller\Seller;
use App\Models\Seller\SellerSettlement;
use App\Models\Shared\Order;
use App\Models\Shared\Payment;
use App\Models\Shared\SellerOrder;
use App\Services\Seller\SellerEarningsService;
use App\Services\Seller\SellerSettlementService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class BuyerTrackingReceiptTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_buy_trk_003_order_received_releases_remitted_seller_earnings(): void
    {
        $buyer = Buyer::factory()->create();
        $seller = Seller::factory()->create();
        $parent = Order::factory()->create(['buyer_id' => $buyer->id, 'discount_minor' => 0]);
        $parcel = SellerOrder::factory()->status(OrderStatus::Delivered)->create([
            'seller_id' => $seller->id,
            'order_id' => $parent->id,
            'subtotal_minor' => 10000,
        ]);
        $parcel->forceFill([
            'shipping_fee_minor' => 0,
            'voucher_discount_minor' => 0,
            'seller_funded_discount_minor' => 0,
            'marketplace_funded_discount_minor' => 0,
        ])->save();
        $payment = Payment::factory()->create([
            'seller_order_id' => $parcel->id,
            'amount_minor' => 10000,
        ]);
        $payment->forceFill([
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
            'collected_at' => now(),
            'collected_amount_minor' => 10000,
        ])->save();
        $courier = Courier::factory()->create();
        $remittance = new CodRemittance;
        $remittance->forceFill([
            'seller_order_id' => $parcel->id,
            'payment_id' => $payment->id,
            'courier_id' => $courier->id,
            'logistics_provider_id' => $courier->logistics_provider_id,
            'amount_minor' => 10000,
            'status' => CodRemittance::REMITTED_TO_LOGISTICS,
            'received_amount_minor' => 10000,
            'zefanya_received_at' => now(),
            'confirmed_at' => now(),
            'confirmed_by_id' => $courier->logistics_provider_id,
        ])->save();
        app(SellerSettlementService::class)->prepare($remittance);

        $settlement = SellerSettlement::query()->where('seller_order_id', $parcel->id)->firstOrFail();
        $this->assertSame(SellerSettlement::COMMISSION_FINALIZATION_MISSING, $settlement->pending_reason);
        $this->assertNull($settlement->seller_payout_minor);
        $this->assertSame(0, app(SellerEarningsService::class)->summary($seller)['availableMinor']);

        $this->actingAs($buyer, 'buyer')
            ->get(route('buyer.orders.show', $parent))
            ->assertOk()
            ->assertSee('Order Received');

        $this->post(route('buyer.orders.confirmReceived', $parent), ['seller_order_id' => $parcel->id])
            ->assertRedirect()
            ->assertSessionHas('success', 'Order received.');

        $this->assertSame(OrderStatus::Completed, $parcel->fresh()->status);
        $settlement->refresh();
        $this->assertNull($settlement->pending_reason);
        $this->assertSame(9500, $settlement->seller_payout_minor);
        $this->assertSame(500, $settlement->commission_minor);

        $summary = app(SellerEarningsService::class)->summary($seller);
        $this->assertSame(9500, $summary['availableMinor']);
        $this->assertSame(0, $summary['pendingMinor']);
    }
}
