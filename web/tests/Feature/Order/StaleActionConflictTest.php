<?php

namespace Tests\Feature\Order;

use App\Enums\OrderActor;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\StaleStateException;
use App\Models\Admin\Admin;
use App\Models\Buyer\Buyer;
use App\Models\Catalog\Product;
use App\Models\Logistics\CodRemittance;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\ParcelReturn;
use App\Models\Rider\Courier;
use App\Models\Seller\Seller;
use App\Models\Seller\SellerPayoutRequest;
use App\Models\Seller\SellerSettlement;
use App\Models\Shared\Order;
use App\Models\Shared\OrderItem;
use App\Models\Shared\Payment;
use App\Models\Shared\SellerOrder;
use App\Models\Shared\StatusHistory;
use App\Models\Shared\Voucher;
use App\Notifications\Seller\BuyerOrderCancelled;
use App\Services\Logistics\ParcelReturnService;
use App\Services\Rider\RiderOperationsService;
use App\Services\Seller\SellerOrderStatusService;
use App\Services\Seller\SellerPayoutRequestService;
use App\Services\Seller\SellerSettlementService;
use App\Services\Shared\CommissionFinalizationService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\CreatesAssignableFleet;
use Tests\TestCase;

/**
 * Stale UI actions: the page offered an action, another actor changed the
 * record first, and the late click must be refused with 409 and no side effects.
 */
class StaleActionConflictTest extends TestCase
{
    use CreatesAssignableFleet, LazilyRefreshDatabase;

    private function placedParcel(): SellerOrder
    {
        $seller = Seller::factory()->create(['status' => 'approved']);
        $product = Product::factory()->create(['seller_id' => $seller->id, 'stock_quantity' => 18]);
        $parent = Order::factory()->create(['buyer_id' => Buyer::factory()->create()->id]);
        $parcel = SellerOrder::factory()->status(OrderStatus::Placed)->create(['seller_id' => $seller->id, 'order_id' => $parent->id]);
        OrderItem::factory()->create([
            'seller_order_id' => $parcel->id, 'product_id' => $product->id,
            'variant_id' => $product->variants()->sole()->id, 'quantity' => 2,
        ]);
        Payment::factory()->create(['seller_order_id' => $parcel->id, 'method' => PaymentMethod::Cod, 'status' => PaymentStatus::Pending]);

        return $parcel->fresh(['order.buyer', 'seller', 'orderItems.variant', 'payment']);
    }

    private function buyerCancel(SellerOrder $parcel, string $reason = 'Changed my mind')
    {
        return $this->actingAs($parcel->order->buyer, 'buyer')
            ->postJson(route('buyer.orders.cancelSellerOrder', [$parcel->order, $parcel]), ['reason' => $reason]);
    }

    public function test_buyer_cancel_after_seller_accept_is_a_409_with_the_current_status_and_no_side_effects(): void
    {
        Notification::fake();
        $parcel = $this->placedParcel();
        $cancelUrl = route('buyer.orders.cancelSellerOrder', [$parcel->order, $parcel]);
        $this->actingAs($parcel->order->buyer, 'buyer')->get(route('buyer.orders.show', $parcel->order))
            ->assertOk()->assertSee('data-cancel-url="'.$cancelUrl.'"', false);

        $this->actingAs($parcel->seller, 'seller')->post(route('seller.orders.accept', $parcel))->assertSessionHas('accepted');

        $this->buyerCancel($parcel)
            ->assertStatus(409)
            ->assertJsonPath('status', OrderStatus::Confirmed->value)
            ->assertJsonPath('status_label', OrderStatus::Confirmed->label())
            ->assertJsonPath('message', 'This order can no longer be cancelled because the seller has already accepted it.');

        $this->assertSame(OrderStatus::Confirmed, $parcel->fresh()->status);
        $this->assertSame(18, $parcel->orderItems->sole()->variant->fresh()->stock_quantity);
        $this->assertSame(PaymentStatus::Pending, $parcel->payment->fresh()->status);
        $this->assertSame(1, StatusHistory::where('seller_order_id', $parcel->id)->count());
        $this->assertDatabaseCount('inventory_transactions', 0);
        Notification::assertNotSentTo($parcel->seller, BuyerOrderCancelled::class);
        $this->actingAs($parcel->order->buyer, 'buyer')->get(route('buyer.orders.show', $parcel->order))
            ->assertOk()->assertDontSee('data-cancel-url="'.$cancelUrl.'"', false);
    }

    public function test_seller_accept_after_buyer_cancel_is_refused_with_a_stale_page_message(): void
    {
        Notification::fake();
        $parcel = $this->placedParcel();
        $this->buyerCancel($parcel)->assertOk();

        $this->actingAs($parcel->seller, 'seller')
            ->from(route('seller.orders.show', $parcel))
            ->post(route('seller.orders.accept', $parcel))
            ->assertRedirect(route('seller.orders.show', $parcel))
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'changed while you were working on it'));

        $this->assertSame(OrderStatus::Cancelled, $parcel->fresh()->status);
        $this->assertSame(1, StatusHistory::where('seller_order_id', $parcel->id)->count());
        $this->assertSame(20, $parcel->orderItems->sole()->variant->fresh()->stock_quantity);
    }

    public function test_seller_decline_after_buyer_cancel_says_the_order_is_already_cancelled(): void
    {
        Notification::fake();
        $parcel = $this->placedParcel();
        $this->buyerCancel($parcel)->assertOk();

        $this->actingAs($parcel->seller, 'seller')
            ->post(route('seller.orders.decline', $parcel), ['reason' => 'Out of stock on our side'])
            ->assertSessionHas('error', 'This order was already cancelled.');

        $this->assertSame(1, StatusHistory::where('seller_order_id', $parcel->id)->count());
        $this->assertDatabaseCount('inventory_transactions', 1);
    }

    public function test_a_repeated_buyer_cancel_is_a_409_and_restores_stock_and_vouchers_once(): void
    {
        Notification::fake();
        $parcel = $this->placedParcel();
        $voucher = Voucher::factory()->create(['times_used' => 1]);
        $parcel->forceFill(['voucher_id' => $voucher->id, 'voucher_discount_minor' => 100])->save();

        $this->buyerCancel($parcel)->assertOk();
        $usage = $voucher->fresh()->times_used;
        $this->buyerCancel($parcel, 'Second click')
            ->assertStatus(409)
            ->assertJsonPath('status', OrderStatus::Cancelled->value)
            ->assertJsonPath('message', 'This shipment is already cancelled.');

        $this->assertSame(20, $parcel->orderItems->sole()->variant->fresh()->stock_quantity);
        $this->assertDatabaseCount('inventory_transactions', 1);
        $this->assertSame(1, StatusHistory::where('seller_order_id', $parcel->id)->count());
        $this->assertSame($usage, $voucher->fresh()->times_used);
        Notification::assertSentToTimes($parcel->seller, BuyerOrderCancelled::class, 1);
    }

    public function test_other_buyers_still_get_404_or_403_rather_than_409(): void
    {
        $parcel = $this->placedParcel();
        $this->actingAs($parcel->seller, 'seller')->post(route('seller.orders.accept', $parcel));
        $stranger = Buyer::factory()->create();

        $status = $this->actingAs($stranger, 'buyer')
            ->postJson(route('buyer.orders.cancelSellerOrder', [$parcel->order, $parcel]), ['reason' => 'Not mine'])
            ->status();

        $this->assertContains($status, [403, 404]);
        $this->assertSame(OrderStatus::Confirmed, $parcel->fresh()->status);
    }

    public function test_transition_service_separates_stale_hops_from_hops_the_role_never_owns(): void
    {
        $parcel = $this->placedParcel();
        $stale = $parcel->fresh();
        app(SellerOrderStatusService::class)->transition($parcel, OrderStatus::Confirmed, actor: OrderActor::Seller, actorId: $parcel->seller_id);

        try {
            app(SellerOrderStatusService::class)->transition($stale, OrderStatus::Cancelled, actor: OrderActor::Buyer, actorId: $parcel->order->buyer_id);
            $this->fail('A stale buyer cancel went through.');
        } catch (StaleStateException $e) {
            $this->assertStringContainsString('your page showed [PLACED]', $e->getMessage());
        }

        try {
            app(SellerOrderStatusService::class)->transition($parcel->fresh(), OrderStatus::Delivered, actor: OrderActor::Buyer, actorId: $parcel->order->buyer_id);
            $this->fail('A buyer delivered a parcel.');
        } catch (\InvalidArgumentException $e) {
            $this->assertNotInstanceOf(StaleStateException::class, $e, 'A hop the role never owns is not a stale page.');
        }

        $this->expectException(StaleStateException::class);
        app(SellerOrderStatusService::class)->transition($parcel->fresh(), OrderStatus::PickedUp);
    }

    public function test_an_uncaught_stale_state_renders_409_json_or_redirects_a_form_back(): void
    {
        $exception = new StaleStateException('Order #1 changed while you were working on it.');

        $json = $exception->render(Request::create('/x', 'POST', server: ['HTTP_ACCEPT' => 'application/json']));
        $this->assertSame(409, $json->getStatusCode());
        $this->assertSame('Order #1 changed while you were working on it.', $json->getData(true)['message']);

        $form = $exception->render(Request::create('/x', 'POST'));
        $this->assertTrue($form->isRedirect());
        $this->assertSame('Order #1 changed while you were working on it.', session('error'));
    }

    private function requestedReturn(): ParcelReturn
    {
        Storage::fake('local');
        $buyer = Buyer::factory()->create();
        $parcel = SellerOrder::factory()->status(OrderStatus::Delivered)->create([
            'order_id' => Order::factory()->create(['buyer_id' => $buyer->id])->id,
            'seller_id' => Seller::factory()->create(['status' => 'approved'])->id,
        ]);
        StatusHistory::query()->create([
            'seller_order_id' => $parcel->id, 'from_status' => OrderStatus::OutForDelivery,
            'to_status' => OrderStatus::Delivered, 'actor_type' => 'rider', 'source' => 'web',
        ]);
        $payment = Payment::factory()->create(['seller_order_id' => $parcel->id, 'amount_minor' => 10000]);
        $payment->forceFill(['status' => PaymentStatus::Paid, 'paid_at' => now()])->save();

        return app(ParcelReturnService::class)->requestByBuyer(
            $parcel, $buyer, 'Item arrived damaged', 'The box was crushed in transit.', [UploadedFile::fake()->image('a.jpg')],
        );
    }

    public function test_a_second_seller_decision_on_a_return_is_a_conflict_and_the_first_one_stands(): void
    {
        Notification::fake();
        $record = $this->requestedReturn();
        $seller = $record->sellerOrder->seller;

        $this->actingAs($seller, 'seller')->post(route('seller.returns.approve', $record))->assertSessionHasNoErrors();

        $this->postJson(route('seller.returns.reject', $record), ['reason' => 'The item was fine when shipped.'])
            ->assertStatus(409)
            ->assertJsonValidationErrors(['return' => 'This return request has already been decided.']);
        $this->post(route('seller.returns.approve', $record))->assertSessionHasErrors('return');

        $record->refresh();
        $this->assertSame(ParcelReturn::APPROVED, $record->seller_decision);
        $this->assertNull($record->seller_decision_reason);
        $this->assertSame(1, $record->events()->where('event_key', 'seller_approved')->count());
    }

    public function test_a_seller_decision_after_automatic_approval_is_a_conflict(): void
    {
        Notification::fake();
        $record = $this->requestedReturn();
        $this->travel(ParcelReturn::SELLER_DECISION_DAYS + 1)->days();
        $this->assertTrue(app(ParcelReturnService::class)->autoApproveOverdue($record->id));

        $this->actingAs($record->sellerOrder->seller, 'seller')
            ->postJson(route('seller.returns.reject', $record), ['reason' => 'The item was fine when shipped.'])
            ->assertStatus(409);

        $this->assertSame(ParcelReturn::AUTO_APPROVED, $record->fresh()->seller_decision);
    }

    public function test_a_return_request_reasons_are_still_validation_errors_not_conflicts(): void
    {
        $record = $this->requestedReturn();

        $this->actingAs($record->sellerOrder->seller, 'seller')
            ->postJson(route('seller.returns.reject', $record), ['reason' => 'Too short'])
            ->assertUnprocessable();
    }

    public function test_a_stale_delivery_reassignment_says_someone_else_already_reassigned_it(): void
    {
        Notification::fake();
        $provider = LogisticsProvider::factory()->create(['status' => 'approved', 'email_verified_at' => now()]);
        $old = $this->assignableCourier($provider, ['is_available' => false], 'Quezon City', '137404000');
        $first = $this->assignableCourier($provider, [], 'Quezon City', '137404000');
        $second = $this->assignableCourier($provider, [], 'Quezon City', '137404000');
        $pickup = $this->assignableCourier($provider, [], 'Quezon City', '137404000');
        $parcel = SellerOrder::factory()->create([
            'seller_id' => Seller::factory()->create(['status' => 'approved', 'barangay_code' => '1374040001'])->id,
            'order_id' => Order::factory()->create(['shipping_address' => ['barangay_code' => '1374040001', 'municipality' => 'Quezon City']])->id,
            // Out-for-delivery parcels cannot be reassigned directly (custody-safe).
            'status' => OrderStatus::AssignedToRider->value,
            'courier_id' => $pickup->id,
            'delivery_courier_id' => $old->id,
            'origin_station_hub_id' => $pickup->rider->station_hub_id,
            'destination_area_id' => $first->area_id,
            'delivery_attempts' => 1,
        ]);
        $reassign = fn (Courier $to) => $this->actingAs($provider, 'logistics')->post(route('logistics.delivery-assignments.reassign', $parcel), [
            'courier_id' => $to->id, 'reason' => 'Rider unavailable; replace within this station.', 'expected_courier_id' => $old->id,
        ]);

        $reassign($first)->assertSessionHas('success');
        $reassign($second)->assertSessionHas('error', 'This delivery was already reassigned by someone else. The page now shows the current Rider.');

        $this->assertSame($first->id, (int) $parcel->fresh()->delivery_courier_id);
        $this->assertSame(1, $parcel->deliveryAssignments()->count());
    }

    public function test_a_rider_cannot_collect_a_parcel_the_seller_cancelled_after_assignment(): void
    {
        Notification::fake();
        $provider = LogisticsProvider::factory()->create(['status' => 'approved', 'email_verified_at' => now()]);
        $courier = $this->assignableCourier($provider, [], 'Quezon City', '137404000');
        $parcel = SellerOrder::factory()->create([
            'seller_id' => Seller::factory()->create(['status' => 'approved', 'barangay_code' => '1374040001'])->id,
            'status' => OrderStatus::Cancelled->value,
            'courier_id' => $courier->id,
        ]);

        try {
            app(RiderOperationsService::class)->confirmPickup($courier->rider, $parcel);
            $this->fail('A cancelled parcel was collected.');
        } catch (ValidationException $e) {
            $this->assertSame(409, $e->status);
        }

        $this->assertSame(OrderStatus::Cancelled, $parcel->fresh()->status);
        $this->assertNull($parcel->fresh()->handover_at);
        $this->assertSame(0, StatusHistory::where('seller_order_id', $parcel->id)->count());
    }

    private function earnings(Seller $seller): SellerSettlement
    {
        $parent = Order::factory()->create(['discount_minor' => 5000]);
        $order = SellerOrder::factory()->status(OrderStatus::Completed)->create([
            'seller_id' => $seller->id, 'order_id' => $parent->id, 'subtotal_minor' => 69900,
        ]);
        $order->forceFill(['shipping_fee_minor' => 6500, 'voucher_discount_minor' => 5000, 'voucher_funding_source' => Voucher::FUNDING_SELLER])->save();
        $payment = Payment::factory()->create(['seller_order_id' => $order->id, 'amount_minor' => 71400]);
        $payment->forceFill(['status' => PaymentStatus::Paid, 'paid_at' => now(), 'collected_at' => now(), 'collected_amount_minor' => 71400])->save();
        $courier = Courier::factory()->create();
        $remittance = new CodRemittance;
        $remittance->forceFill([
            'seller_order_id' => $order->id, 'payment_id' => $payment->id, 'courier_id' => $courier->id,
            'logistics_provider_id' => $courier->logistics_provider_id, 'amount_minor' => 71400,
            'status' => CodRemittance::REMITTED_TO_LOGISTICS, 'received_amount_minor' => 71400, 'zefanya_received_at' => now(),
            'confirmed_at' => now(), 'confirmed_by_id' => $courier->logistics_provider_id,
        ])->save();
        $settlement = app(SellerSettlementService::class)->prepare($remittance);
        app(CommissionFinalizationService::class)->finalize($order);

        return $settlement->fresh();
    }

    public function test_a_second_direct_settlement_payout_is_a_409_and_pays_once(): void
    {
        $seller = Seller::factory()->create();
        $settlement = $this->earnings($seller);
        $this->actingAs(Admin::factory()->create(['role' => 'super_admin']), 'admin');
        $input = ['amount_minor' => 58410, 'reference' => 'DIRECT-1', 'reason' => 'Seller asked for a direct transfer'];

        $this->postJson(route('admin.settlements.confirm', $settlement), $input)->assertOk();
        $this->postJson(route('admin.settlements.confirm', $settlement), ['reference' => 'DIRECT-2'] + $input)
            ->assertStatus(409)->assertJsonValidationErrors('settlement');

        $settlement->refresh();
        $this->assertSame(SellerSettlement::PAID, $settlement->status);
        $this->assertSame('DIRECT-1', $settlement->payout_reference);
        $this->assertSame(58410, (int) $settlement->paid_amount_minor);
    }

    public function test_paying_a_payout_request_another_admin_rejected_is_a_409(): void
    {
        $seller = Seller::factory()->create();
        $settlement = $this->earnings($seller);
        $payouts = app(SellerPayoutRequestService::class);
        $payout = $payouts->request($seller, '584.10', (string) Str::uuid(), 'GCash 09171234567');
        $admin = Admin::factory()->create(['role' => 'admin']);
        $payouts->reject($payout, $admin, 'GCash number not registered');

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.seller-payouts.pay', $payout), ['reference' => 'LATE'])
            ->assertStatus(409)->assertJsonValidationErrors('payout');

        $this->assertSame(SellerPayoutRequest::REJECTED, $payout->fresh()->status);
        $this->assertNull($settlement->fresh()->paid_amount_minor);
    }
}
