<?php

namespace Tests\Feature\Buyer;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Buyer\Buyer;
use App\Models\Shared\Payment;
use App\Models\Shared\PaymentCancellationRefund;
use App\Models\Shared\SellerOrder;
use App\Models\Shared\Voucher;
use App\Services\Buyer\BuyerAccountDeletionService;
use App\Services\Buyer\BuyerOrderCancellation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Rider\RiderTestCase;

class CancellationRulesTest extends RiderTestCase
{
    protected function parcel(OrderStatus $status, array $attributes = [], array $force = []): SellerOrder
    {
        $shipment = parent::parcel($status, $attributes, $force);
        foreach ($shipment->orderItems as $item) {
            $item->forceFill(['variant_id' => $item->product->variants()->sole()->id])->save();
        }

        return $shipment->fresh();
    }

    private function api(?Buyer $buyer = null): static
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return $this->withToken(($buyer ?? $this->buyer)->createToken('cancel-test', ['buyer'])->plainTextToken);
    }

    private function path(SellerOrder $shipment): string
    {
        return "/api/buyer/orders/{$shipment->order_id}/shipments/{$shipment->id}/cancel";
    }

    public function test_buy_can_001_api_allows_placed_but_blocks_once_the_seller_accepts(): void
    {
        foreach ([OrderStatus::Placed] as $status) {
            $shipment = $this->parcel($status);
            $this->api()->getJson("/api/buyer/shipments/{$shipment->id}")->assertOk()->assertJsonPath('data.can_cancel', true);
            $this->postJson($this->path($shipment), ['reason' => 'Changed my mind'])->assertOk()->assertJsonPath('data.can_cancel', false);
            $this->assertSame(OrderStatus::Cancelled, $shipment->fresh()->status);
        }
        foreach ([OrderStatus::Confirmed, OrderStatus::Preparing, OrderStatus::ReadyForPickup, OrderStatus::PickedUp, OrderStatus::Delivered] as $status) {
            $shipment = $this->parcel($status);
            $this->api()->getJson("/api/buyer/shipments/{$shipment->id}")->assertOk()->assertJsonPath('data.can_cancel', false);
            $this->postJson($this->path($shipment), ['reason' => 'Changed my mind'])->assertStatus(409);
            $this->assertSame($status, $shipment->fresh()->status);
        }
    }

    public function test_buy_can_002_unknown_reason_is_refused_by_web_api_and_service(): void
    {
        $shipment = $this->parcel(OrderStatus::Placed);
        $this->api()->postJson($this->path($shipment), ['reason' => 'Invented reason'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->actingAs($this->buyer, 'buyer')->postJson(route('buyer.orders.cancelSellerOrder', [$shipment->order, $shipment]), ['reason' => 'Invented reason'])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        try {
            app(BuyerOrderCancellation::class)->cancel($shipment->order, $shipment, $this->buyer, 'Invented reason');
            $this->fail('The service must enforce the preset list.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reason', $exception->errors());
        }
        $this->assertSame(OrderStatus::Placed, $shipment->fresh()->status);
        $this->assertDatabaseCount('inventory_transactions', 0);
        $this->assertDatabaseCount('status_histories', 0);
    }

    public function test_buy_can_003_paid_ewallet_records_exact_refund_liability_once(): void
    {
        $shipment = $this->parcel(OrderStatus::Placed);
        $voucher = Voucher::factory()->create(['code' => 'MCH50', 'times_used' => 1, 'expires_at' => now()->addDay()]);
        $shipment->forceFill(['platform_voucher_id' => $voucher->id])->save();
        $payment = Payment::factory()->paid()->create(['seller_order_id' => $shipment->id, 'method' => PaymentMethod::Ewallet, 'amount_minor' => 108300]);
        $before = $payment->fresh()->getRawOriginal();
        $this->api()->postJson($this->path($shipment), ['reason' => 'Changed my mind', 'amount_minor' => 1, 'buyer_id' => 999])->assertOk();
        $refund = PaymentCancellationRefund::query()->sole();
        $this->assertSame(108300, $refund->amount_minor);
        $this->assertSame($payment->id, $refund->payment_id);
        $this->assertSame($this->buyer->id, $refund->buyer_id);
        $this->assertSame('pending', $refund->status);
        $this->assertSame(0, $voucher->fresh()->times_used);
        $this->assertTrue($voucher->claims()->where('buyer_id', $this->buyer->id)->exists());
        $this->assertSame($before, $payment->fresh()->getRawOriginal());
        $this->api()->postJson($this->path($shipment), ['reason' => 'Changed my mind'])->assertStatus(409);
        $this->assertDatabaseCount('payment_cancellation_refunds', 1);
        $this->assertDatabaseCount('inventory_transactions', 1);
        $this->assertDatabaseCount('status_histories', 1);
        $this->assertSame('You have a refund being processed. You can delete your account after you receive it.',
            app(BuyerAccountDeletionService::class)->blockingReason($this->buyer));
    }

    public function test_buy_can_003_refund_write_failure_rolls_back_inventory_status_and_voucher(): void
    {
        $shipment = $this->parcel(OrderStatus::Placed);
        Payment::factory()->paid()->create(['seller_order_id' => $shipment->id, 'method' => PaymentMethod::Card]);
        $voucher = Voucher::factory()->create(['times_used' => 1]);
        $shipment->forceFill(['platform_voucher_id' => $voucher->id])->save();
        $item = $shipment->orderItems()->firstOrFail();
        $stock = $item->product->stock_quantity;
        Event::listen('eloquent.creating: '.PaymentCancellationRefund::class, fn () => throw new \RuntimeException('Refund write failed'));
        try {
            app(BuyerOrderCancellation::class)->cancel($shipment->order, $shipment, $this->buyer, 'Changed my mind');
            $this->fail('Refund failure must roll back cancellation.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Refund write failed', $exception->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.PaymentCancellationRefund::class);
        }
        $this->assertSame(OrderStatus::Placed, $shipment->fresh()->status);
        $this->assertSame($stock, $item->product->fresh()->stock_quantity);
        $this->assertSame(1, $voucher->fresh()->times_used);
        $this->assertDatabaseCount('payment_cancellation_refunds', 0);
        $this->assertDatabaseCount('inventory_transactions', 0);
        $this->assertDatabaseCount('status_histories', 0);
    }

    public function test_buy_can_003_and_006_only_unexpired_voucher_returns_and_usage_decrements_once(): void
    {
        foreach ([true, false] as $expired) {
            $shipment = $this->parcel(OrderStatus::Placed);
            $voucher = Voucher::factory()->create(['times_used' => 1, 'expires_at' => $expired ? now()->subDay() : now()->addDay()]);
            $shipment->forceFill(['platform_voucher_id' => $voucher->id])->save();
            $this->api()->postJson($this->path($shipment), ['reason' => 'Changed my mind'])->assertOk();
            $this->assertSame(0, $voucher->fresh()->times_used);
            $this->assertSame(! $expired, $voucher->claims()->where('buyer_id', $this->buyer->id)->exists());
            $this->postJson($this->path($shipment), ['reason' => 'Changed my mind'])->assertStatus(409);
            $this->assertSame(0, $voucher->fresh()->times_used);
        }
    }

    public function test_guest_rider_and_foreign_buyer_cannot_cancel_or_create_refunds(): void
    {
        $shipment = $this->parcel(OrderStatus::Placed);
        Payment::factory()->paid()->create(['seller_order_id' => $shipment->id]);
        $this->postJson($this->path($shipment), ['reason' => 'Changed my mind'])->assertUnauthorized();
        $this->withToken($this->rider->createToken('rider-test', ['rider'])->plainTextToken)
            ->postJson($this->path($shipment), ['reason' => 'Changed my mind'])->assertForbidden();
        $this->api(Buyer::factory()->create(['status' => 'approved', 'email_verified_at' => now()]))
            ->postJson($this->path($shipment), ['reason' => 'Changed my mind'])->assertNotFound();
        $this->assertSame(OrderStatus::Placed, $shipment->fresh()->status);
        $this->assertDatabaseCount('payment_cancellation_refunds', 0);
        $this->assertDatabaseCount('inventory_transactions', 0);
    }

    public function test_the_database_refuses_two_refund_liabilities_for_one_payment(): void
    {
        $shipment = $this->parcel(OrderStatus::Placed);
        Payment::factory()->paid()->create(['seller_order_id' => $shipment->id, 'method' => PaymentMethod::Ewallet]);
        app(BuyerOrderCancellation::class)->cancel($shipment->order, $shipment, $this->buyer, 'Changed my mind');
        $refund = PaymentCancellationRefund::query()->sole();
        $this->expectException(QueryException::class);
        $refund->replicate()->save();
    }

    public function test_pending_cod_does_not_create_a_refund_liability(): void
    {
        $shipment = $this->parcel(OrderStatus::Placed);
        Payment::factory()->create(['seller_order_id' => $shipment->id]);
        $this->api()->postJson($this->path($shipment), ['reason' => 'Changed my mind'])->assertOk();
        $this->assertDatabaseCount('payment_cancellation_refunds', 0);
        $this->assertSame(PaymentStatus::Cancelled, $shipment->payment->status);
    }
}
