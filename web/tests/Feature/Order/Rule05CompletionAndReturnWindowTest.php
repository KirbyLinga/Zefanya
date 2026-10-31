<?php

namespace Tests\Feature\Order;

use App\Enums\OrderActor;
use App\Enums\OrderStatus;
use App\Enums\ParcelIncidentStatus;
use App\Enums\ParcelReturnStatus;
use App\Enums\PaymentStatus;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\ParcelReturn;
use App\Models\Shared\Incidents;
use App\Models\Shared\Order;
use App\Models\Shared\Payment;
use App\Models\Shared\SellerOrder;
use App\Models\Shared\StatusHistory;
use App\Services\Buyer\AutomaticBuyerReceiptService;
use App\Services\Logistics\ParcelReturnService;
use App\Services\Seller\SellerOrderStatusService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class Rule05CompletionAndReturnWindowTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function deliveredAt(string $at, ?Order $parent = null): SellerOrder
    {
        $parent ??= Order::factory()->create(['buyer_id' => Buyer::factory()->create()->id, 'discount_minor' => 0]);
        $parcel = SellerOrder::factory()->status(OrderStatus::Delivered)->create([
            'order_id' => $parent->id, 'subtotal_minor' => 10010,
        ]);
        $parcel->forceFill(['shipping_fee_minor' => 650, 'voucher_discount_minor' => 0])->save();
        $history = StatusHistory::query()->create([
            'seller_order_id' => $parcel->id, 'from_status' => OrderStatus::OutForDelivery,
            'to_status' => OrderStatus::Delivered, 'actor_type' => 'rider', 'source' => 'web',
        ]);
        $history->forceFill(['created_at' => Carbon::parse($at, 'UTC')])->saveQuietly();
        $payment = Payment::factory()->create(['seller_order_id' => $parcel->id, 'amount_minor' => 10660]);
        $payment->forceFill(['status' => PaymentStatus::Paid, 'paid_at' => $at,
            'collected_at' => $at, 'collected_amount_minor' => 10660])->save();

        return $parcel->fresh(['order.buyer', 'payment']);
    }

    public static function completionBoundary(): array
    {
        return [
            'old 72 hour deadline does not complete' => ['2026-10-23 16:00:00', false],
            'one second before seven days' => ['2026-10-27 15:59:59', false],
            'exactly seven days' => ['2026-10-27 16:00:00', true],
            'one second after seven days' => ['2026-10-27 16:00:01', true],
        ];
    }

    #[DataProvider('completionBoundary')]
    public function test_buy_trk_005_seven_day_boundary_keeps_to_receive_until_due(string $now, bool $due): void
    {
        $parcel = $this->deliveredAt('2026-10-20 16:00:00');
        $this->travelTo(Carbon::parse($now, 'UTC'));
        $paymentBefore = $parcel->payment->fresh()->getRawOriginal();
        $this->actingAs($parcel->order->buyer, 'buyer');

        $this->artisan('orders:auto-complete-receipts')->assertSuccessful();

        $this->assertSame($due ? OrderStatus::Completed : OrderStatus::Delivered, $parcel->fresh()->status);
        $this->assertSame($paymentBefore, $parcel->payment->fresh()->getRawOriginal());
        $this->get(route('buyer.orders.index', ['tab' => $due ? 'completed' : 'to_receive']))
            ->assertOk()->assertSee($parcel->order->reference);
        $this->assertSame($due ? 1 : 0, StatusHistory::where('seller_order_id', $parcel->id)->where('to_status', OrderStatus::Completed)->count());
    }

    public function test_repeated_scheduler_execution_finalizes_five_percent_once_without_collecting_cod_again(): void
    {
        $parcel = $this->deliveredAt('2026-10-20 16:00:00');
        $this->travelTo(Carbon::parse('2026-10-27 16:00:00', 'UTC'));
        $paymentBefore = $parcel->payment->fresh()->getRawOriginal();
        $this->artisan('orders:auto-complete-receipts')->assertSuccessful();
        $snapshot = $parcel->fresh()->getRawOriginal();
        $completion = StatusHistory::where('seller_order_id', $parcel->id)->where('to_status', OrderStatus::Completed)->sole();

        $this->travelTo(Carbon::parse('2026-10-28 16:00:00', 'UTC'));
        $this->artisan('orders:auto-complete-receipts')->assertSuccessful();

        $this->assertSame(500, $parcel->fresh()->commission_bps);
        $this->assertSame(501, $parcel->fresh()->commission_minor);
        $this->assertSame(9509, $parcel->fresh()->seller_net_minor);
        $this->assertSame($snapshot, $parcel->fresh()->getRawOriginal());
        $this->assertSame($paymentBefore, $parcel->payment->fresh()->getRawOriginal());
        $this->assertSame('system', $completion->actor_type);
        $this->assertSame('system', $completion->source);
        $this->assertSame(1, StatusHistory::where('seller_order_id', $parcel->id)->where('to_status', OrderStatus::Completed)->count());
        $this->assertDatabaseCount('inventory_transactions', 0);
    }

    public function test_shipments_complete_independently_and_manual_receipt_remains_available_before_the_deadline(): void
    {
        $first = $this->deliveredAt('2026-10-20 16:00:00');
        $second = $this->deliveredAt('2026-10-26 16:00:00', $first->order);
        $this->travelTo(Carbon::parse('2026-10-27 16:00:00', 'UTC'));
        $this->artisan('orders:auto-complete-receipts')->assertSuccessful();
        $this->assertSame(OrderStatus::Completed, $first->fresh()->status);
        $this->assertSame(OrderStatus::Delivered, $second->fresh()->status);
        $this->assertSame(OrderStatus::Delivered, $first->order->fresh()->status);
        $this->assertNull($second->fresh()->commission_finalized_at);

        $this->actingAs($first->order->buyer, 'buyer')->post(route('buyer.orders.confirmReceived', $first->order), ['seller_order_id' => $second->id])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(OrderStatus::Completed, $second->fresh()->status);
        $this->assertSame(OrderStatus::Completed, $first->order->fresh()->status);
        $this->assertSame(501, $second->fresh()->commission_minor);
        $this->post(route('buyer.orders.confirmReceived', $first->order), ['seller_order_id' => $second->id])->assertRedirect();
        $this->assertSame(1, StatusHistory::where('seller_order_id', $second->id)->where('to_status', OrderStatus::Completed)->count());
    }

    private function returnUrl(SellerOrder $parcel): string
    {
        return route('buyer.orders.requestReturn', [$parcel->order, $parcel]);
    }

    private function returnPayload(string $reason = 'Item arrived damaged'): array
    {
        Storage::fake('local');

        return ['reason' => $reason, 'description' => 'The delivered item needs to be returned.', 'photos' => [UploadedFile::fake()->image('damage.jpg')]];
    }

    public function test_buy_ret_001_two_day_return_notifies_seller_once_and_blocks_completion(): void
    {
        $parcel = $this->deliveredAt('2026-10-20 16:00:00');
        $this->travelTo(Carbon::parse('2026-10-22 16:00:00', 'UTC'));
        $paymentBefore = $parcel->payment->fresh()->getRawOriginal();
        $this->actingAs($parcel->order->buyer, 'buyer');
        $this->get(route('buyer.orders.show', $parcel->order))->assertOk()
            ->assertSee('action="'.$this->returnUrl($parcel).'"', false);

        $first = $this->postJson($this->returnUrl($parcel), $this->returnPayload())
            ->assertCreated()->assertJsonPath('status', ParcelReturnStatus::Requested->value);
        $this->postJson($this->returnUrl($parcel), $this->returnPayload())
            ->assertOk()->assertJsonPath('id', $first->json('id'));

        $record = ParcelReturn::sole();
        $this->assertSame('Item arrived damaged', $record->reason);
        $this->assertTrue($record->financial_review_required);
        $this->assertNull($record->stock_restored_at);
        $event = $record->events()->sole();
        $this->assertSame('requested', $event->event_key);
        $this->assertSame('buyer', $event->actor_type);
        $this->assertSame($parcel->order->buyer_id, $event->actor_id);
        $notification = $parcel->seller->notifications()->sole();
        $this->assertSame('Item arrived damaged', $notification->data['reason']);
        $this->assertSame($record->id, $notification->data['parcel_return_id']);
        $this->get(route('buyer.orders.show', $parcel->order))->assertOk()->assertSee('Return request open')
            ->assertDontSee('action="'.$this->returnUrl($parcel).'"', false)
            ->assertDontSee('Order Received');

        $this->travelTo(Carbon::parse('2026-10-28 16:00:00', 'UTC'));
        $this->artisan('orders:auto-complete-receipts')->assertSuccessful();
        $this->artisan('orders:auto-complete-receipts')->assertSuccessful();
        $this->assertSame(OrderStatus::Delivered, $parcel->fresh()->status);
        $this->assertNull($parcel->fresh()->commission_finalized_at);
        $this->assertSame($paymentBefore, $parcel->payment->fresh()->getRawOriginal());
        $this->assertDatabaseCount('status_histories', 1);
        $this->assertDatabaseCount('parcel_returns', 1);
        $this->assertDatabaseCount('parcel_return_events', 1);
        $this->assertDatabaseCount('inventory_transactions', 0);
    }

    public static function returnBoundary(): array
    {
        return [
            'day seven still within the window' => ['2026-10-27 04:00:00', true],
            'one second before 168 hours' => ['2026-10-27 15:59:59', true],
            'exactly 168 hours' => ['2026-10-27 16:00:00', false],
            'seven days plus one hour' => ['2026-10-27 17:00:00', false],
        ];
    }

    #[DataProvider('returnBoundary')]
    public function test_buy_ret_003_return_window_matches_the_completion_cutoff(string $now, bool $allowed): void
    {
        Notification::fake();
        $parcel = $this->deliveredAt('2026-10-20 16:00:00');
        $this->travelTo(Carbon::parse($now, 'UTC'));
        $this->actingAs($parcel->order->buyer, 'buyer');
        $page = $this->get(route('buyer.orders.show', $parcel->order))->assertOk();
        $response = $this->postJson($this->returnUrl($parcel), $this->returnPayload('Wrong item received'));

        if ($allowed) {
            $page->assertSee('action="'.$this->returnUrl($parcel).'"', false);
            $response->assertCreated();
            $this->assertDatabaseCount('parcel_returns', 1);
        } else {
            $page->assertDontSee('action="'.$this->returnUrl($parcel).'"', false);
            $response->assertUnprocessable()->assertJsonValidationErrors('return');
            $this->assertDatabaseCount('parcel_returns', 0);
            Notification::assertNothingSent();
        }
        $this->assertSame(OrderStatus::Delivered, $parcel->fresh()->status);
        $this->assertNull($parcel->fresh()->commission_finalized_at);
    }

    public function test_completed_shipment_rejects_a_stale_return_request_even_before_seven_days(): void
    {
        $parcel = $this->deliveredAt('2026-10-20 16:00:00');
        $stale = $parcel->fresh();
        $this->travelTo(Carbon::parse('2026-10-22 16:00:00', 'UTC'));
        $this->actingAs($parcel->order->buyer, 'buyer')->post(route('buyer.orders.confirmReceived', $parcel->order), ['seller_order_id' => $parcel->id])->assertRedirect();
        $financials = $parcel->fresh()->getRawOriginal();
        $this->postJson($this->returnUrl($parcel), $this->returnPayload())->assertUnprocessable();
        try {
            app(ParcelReturnService::class)->requestByBuyer($stale, $parcel->order->buyer, 'Stale request');
            $this->fail('The service must recheck completion under the shipment lock.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('return', $exception->errors());
        }
        $this->assertSame($financials, $parcel->fresh()->getRawOriginal());
        $this->assertDatabaseCount('parcel_returns', 0);
    }

    public function test_return_first_wins_against_a_stale_completion_candidate_without_blocking_a_sibling(): void
    {
        $first = $this->deliveredAt('2026-10-20 16:00:00');
        $second = $this->deliveredAt('2026-10-20 16:00:00', $first->order);
        $stale = $first->fresh();
        $this->travelTo(Carbon::parse('2026-10-27 15:59:59', 'UTC'));
        $this->actingAs($first->order->buyer, 'buyer')->postJson($this->returnUrl($first), $this->returnPayload())->assertCreated();
        $this->travelTo(Carbon::parse('2026-10-27 16:00:00', 'UTC'));

        $this->assertFalse(app(AutomaticBuyerReceiptService::class)->complete($stale->id, now()->subHours(168)));
        $this->artisan('orders:auto-complete-receipts')->assertSuccessful();

        $this->assertSame(OrderStatus::Delivered, $first->fresh()->status);
        $this->assertSame(OrderStatus::Completed, $second->fresh()->status);
        $this->assertSame(OrderStatus::Delivered, $first->order->fresh()->status);
        $this->assertNull($first->fresh()->commission_finalized_at);
        $this->assertSame(501, $second->fresh()->commission_minor);
        $this->postJson(route('buyer.orders.confirmReceived', $first->order), ['seller_order_id' => $first->id])
            ->assertUnprocessable()->assertJsonValidationErrors('shipment');
        $this->assertSame(OrderStatus::Delivered, $first->fresh()->status);
    }

    public function test_automatic_completion_first_rejects_return_and_preserves_finalized_earnings(): void
    {
        $parcel = $this->deliveredAt('2026-10-20 16:00:00');
        $this->travelTo(Carbon::parse('2026-10-27 16:00:00', 'UTC'));
        $this->artisan('orders:auto-complete-receipts')->assertSuccessful();
        $financials = $parcel->fresh()->getRawOriginal();

        $this->actingAs($parcel->order->buyer, 'buyer')->postJson($this->returnUrl($parcel), $this->returnPayload())->assertUnprocessable();

        $this->assertSame($financials, $parcel->fresh()->getRawOriginal());
        $this->assertDatabaseCount('parcel_returns', 0);
        $this->assertSame(1, StatusHistory::where('to_status', OrderStatus::Completed)->count());
    }

    public function test_return_requests_enforce_buyer_ownership_parent_scope_and_reason(): void
    {
        $parcel = $this->deliveredAt('2026-10-20 16:00:00');
        $this->travelTo(Carbon::parse('2026-10-22 16:00:00', 'UTC'));
        $this->postJson($this->returnUrl($parcel), ['reason' => 'Guest'])->assertUnauthorized();
        $this->actingAs(Buyer::factory()->create(), 'buyer')->postJson($this->returnUrl($parcel), ['reason' => 'Not mine'])->assertNotFound();
        $this->actingAs($parcel->order->buyer, 'buyer');
        $wrongParent = Order::factory()->create(['buyer_id' => $parcel->order->buyer_id]);
        $this->postJson(route('buyer.orders.requestReturn', [$wrongParent, $parcel]), ['reason' => 'Wrong parent'])->assertNotFound();
        foreach ([null, '   ', str_repeat('a', 1001), ['invalid']] as $reason) {
            $this->postJson($this->returnUrl($parcel), ['reason' => $reason])->assertUnprocessable()->assertJsonValidationErrors('reason');
        }
        $this->assertDatabaseCount('parcel_returns', 0);
        $this->assertDatabaseCount('parcel_return_events', 0);
        $this->assertSame(OrderStatus::Delivered, $parcel->fresh()->status);
    }

    public function test_existing_incident_guard_blocks_buyer_return_requests(): void
    {
        $parcel = $this->deliveredAt('2026-10-20 16:00:00');
        $this->travelTo(Carbon::parse('2026-10-22 16:00:00', 'UTC'));
        Incidents::query()->create(['incident_seller_order_id' => $parcel->id, 'reporter_type' => 'buyer',
            'reporter_id' => $parcel->order->buyer_id, 'type' => 'damaged', 'description' => 'Pending damage investigation', 'status' => ParcelIncidentStatus::Open, 'happened_at' => now()]);

        $this->actingAs($parcel->order->buyer, 'buyer')->postJson($this->returnUrl($parcel), $this->returnPayload())
            ->assertUnprocessable()->assertJsonValidationErrors('parcel');

        $this->assertDatabaseCount('parcel_returns', 0);
    }

    public function test_missing_or_undated_delivery_history_cannot_open_a_return_window(): void
    {
        $parcel = $this->deliveredAt('2026-10-20 16:00:00');
        $this->travelTo(Carbon::parse('2026-10-22 16:00:00', 'UTC'));
        StatusHistory::where('seller_order_id', $parcel->id)->update(['created_at' => null]);
        $this->actingAs($parcel->order->buyer, 'buyer')->postJson($this->returnUrl($parcel), ['reason' => 'Unknown delivery time'])->assertUnprocessable();
        $this->assertDatabaseCount('parcel_returns', 0);
    }

    public function test_delivered_returns_require_verified_physical_seller_receipt_before_becoming_returned(): void
    {
        $parcel = $this->deliveredAt('2026-10-20 16:00:00');
        $this->travelTo(Carbon::parse('2026-10-22 16:00:00', 'UTC'));
        app(ParcelReturnService::class)->requestByBuyer($parcel, $parcel->order->buyer, 'Return item');
        try {
            app(SellerOrderStatusService::class)->transition($parcel->fresh(), OrderStatus::Returned,
                actor: OrderActor::Seller, actorId: $parcel->seller_id);
            $this->fail('Request intake is not proof of physical Seller receipt.');
        } catch (\InvalidArgumentException) {
            $this->assertSame(OrderStatus::Delivered, $parcel->fresh()->status);
        }
        $record = ParcelReturn::sole();
        $record->forceFill(['status' => ParcelReturnStatus::Completed, 'seller_received_at' => now(),
            'received_by_seller_id' => $parcel->seller_id])->save();
        $paymentBefore = $parcel->payment->fresh()->getRawOriginal();

        app(SellerOrderStatusService::class)->transition($parcel->fresh(), OrderStatus::Returned,
            actor: OrderActor::Seller, actorId: $parcel->seller_id);

        $this->assertSame(OrderStatus::Returned, $parcel->fresh()->status);
        $this->assertSame(OrderStatus::Returned, $parcel->order->fresh()->status);
        $this->assertSame($paymentBefore, $parcel->payment->fresh()->getRawOriginal());
        $this->assertNull($parcel->fresh()->commission_finalized_at);
    }

    public function test_buyer_return_form_submits_and_displays_the_open_request(): void
    {
        $parcel = $this->deliveredAt('2026-10-20 16:00:00');
        $this->travelTo(Carbon::parse('2026-10-22 16:00:00', 'UTC'));
        $detail = route('buyer.orders.show', $parcel->order);
        $this->actingAs($parcel->order->buyer, 'buyer')->from($detail)
            ->post($this->returnUrl($parcel), $this->returnPayload('Wrong item received'))
            ->assertRedirect($detail)->assertSessionHas('success')->assertSessionHasNoErrors();
        $this->get($detail)->assertOk()->assertSee('Return request open')->assertSee('Return request recorded.');
        $this->assertSame('Wrong item received', ParcelReturn::sole()->reason);
        $this->assertSame(1, $parcel->seller->notifications()->count());
    }
}
