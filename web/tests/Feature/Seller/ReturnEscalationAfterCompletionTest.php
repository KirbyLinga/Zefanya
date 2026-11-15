<?php

namespace Tests\Feature\Seller;

use App\Enums\OrderStatus;
use App\Enums\ParcelReturnStatus;
use App\Enums\PaymentStatus;
use App\Models\Admin\Admin;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\ParcelReturn;
use App\Models\Seller\Seller;
use App\Models\Shared\Order;
use App\Models\Shared\Payment;
use App\Models\Shared\SellerOrder;
use App\Models\Shared\StatusHistory;
use App\Services\Logistics\ParcelReturnService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Audit 1.13: a Seller rejection may be escalated only while the shipment is
 * still delivered and uncompleted. Once the shipment completes, Completed has no
 * further transitions, so an overturned return could never be received and
 * would rewrite the completed order's finalized commission.
 */
class ReturnEscalationAfterCompletionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private SellerOrder $parcel;

    private Seller $seller;

    private Buyer $buyer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake('local');
        Notification::fake();
        Carbon::setTestNow(Carbon::parse('2026-11-01 00:00:00', 'UTC'));

        $this->buyer = Buyer::factory()->create();
        $this->seller = Seller::factory()->create(['status' => 'approved']);
        $this->parcel = SellerOrder::factory()->status(OrderStatus::Delivered)->create([
            'order_id' => Order::factory()->create(['buyer_id' => $this->buyer->id])->id,
            'seller_id' => $this->seller->id,
        ]);
        StatusHistory::query()->create([
            'seller_order_id' => $this->parcel->id, 'from_status' => OrderStatus::OutForDelivery,
            'to_status' => OrderStatus::Delivered, 'actor_type' => 'rider', 'source' => 'web',
        ]);
        $payment = Payment::factory()->create(['seller_order_id' => $this->parcel->id, 'amount_minor' => 10000]);
        $payment->recordCodCollection(10000, 'rider', 1);
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        $parcel = $this->parcel->fresh();

        return [$parcel->status->value, $parcel->commission_bps, $parcel->commission_minor, $parcel->seller_net_minor, $parcel->commission_finalized_at?->toIso8601String(), $parcel->payment->status->value];
    }

    private function rejectedReturn(): ParcelReturn
    {
        $this->actingAs($this->buyer, 'buyer')->post(
            route('buyer.orders.requestReturn', [$this->parcel->order, $this->parcel]),
            ['reason' => 'Item arrived damaged', 'description' => 'The delivered item arrived damaged.', 'photos' => [UploadedFile::fake()->image('damage.jpg')]],
            ['Accept' => 'application/json'],
        )->assertCreated();
        $record = ParcelReturn::sole();
        $this->actingAs($this->seller, 'seller')->post(route('seller.returns.reject', $record), ['reason' => 'The item was fine when shipped.'])
            ->assertSessionHasNoErrors();
        $this->assertSame(ParcelReturnStatus::Rejected, $record->fresh()->status);

        return $record->fresh();
    }

    private function completeShipment(): void
    {
        // A rejected return is terminal, so the buyer may confirm receipt.
        $this->actingAs($this->buyer, 'buyer')->post(route('buyer.orders.confirmReceived', $this->parcel->order), ['seller_order_id' => $this->parcel->id])
            ->assertSessionHasNoErrors();
        $this->assertSame(OrderStatus::Completed, $this->parcel->fresh()->status);
        $this->assertNotNull($this->parcel->fresh()->commission_finalized_at);
    }

    public function test_rejection_cannot_be_escalated_after_the_shipment_completes(): void
    {
        $record = $this->rejectedReturn();
        $this->completeShipment();
        $before = $this->snapshot();

        $this->actingAs($this->buyer, 'buyer')->get(route('buyer.orders.show', $this->parcel->order))
            ->assertOk()->assertDontSee(route('buyer.orders.escalateReturn', [$this->parcel->order, $this->parcel]), false);

        $this->actingAs($this->buyer, 'buyer')->post(
            route('buyer.orders.escalateReturn', [$this->parcel->order, $this->parcel]),
            ['reason' => 'The photos show the damage the seller denied.'],
        )->assertSessionHasErrors('return');

        $record->refresh();
        $this->assertSame(ParcelReturnStatus::Rejected, $record->status);
        $this->assertNull($record->escalated_at);
        $this->assertFalse($record->events()->where('event_key', 'buyer_escalated')->exists());
        $this->assertSame($before, $this->snapshot());
    }

    public function test_escalation_while_delivered_still_works_and_blocks_completion(): void
    {
        $record = $this->rejectedReturn();

        $this->actingAs($this->buyer, 'buyer')->post(
            route('buyer.orders.escalateReturn', [$this->parcel->order, $this->parcel]),
            ['reason' => 'The photos show the damage the seller denied.'],
        )->assertSessionHasNoErrors();

        $this->assertSame(ParcelReturnStatus::InDispute, $record->fresh()->status);
        $this->actingAs($this->buyer, 'buyer')->post(route('buyer.orders.confirmReceived', $this->parcel->order), ['seller_order_id' => $this->parcel->id]);
        $this->assertSame(OrderStatus::Delivered, $this->parcel->fresh()->status);
    }

    public function test_legacy_dispute_on_a_completed_shipment_cannot_be_overturned_but_can_be_upheld(): void
    {
        $record = $this->rejectedReturn();
        $this->completeShipment();
        // Legacy state: escalated before the guard existed.
        $record->forceFill(['escalated_at' => now(), 'status' => ParcelReturnStatus::InDispute])->save();
        $before = $this->snapshot();
        $admin = Admin::factory()->create(['role' => 'admin']);

        $this->actingAs($admin, 'admin')->post(route('admin.returns.dispute', $record), [
            'outcome' => 'overturned', 'note' => 'The damage photos match the buyer description.',
        ])->assertSessionHasErrors('return');
        $this->assertSame(ParcelReturnStatus::InDispute, $record->fresh()->status);
        $this->assertNull($record->fresh()->dispute_outcome);
        $this->assertSame($before, $this->snapshot());

        $this->actingAs($admin, 'admin')->post(route('admin.returns.dispute', $record), [
            'outcome' => 'upheld', 'note' => 'The shipment was already completed by the buyer.',
        ])->assertSessionHasNoErrors();
        $this->assertSame(ParcelReturnStatus::Rejected, $record->fresh()->status);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_service_rejects_escalation_after_completion_without_the_http_layer(): void
    {
        $record = $this->rejectedReturn();
        $this->completeShipment();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('This rejection can no longer be escalated.');
        app(ParcelReturnService::class)->escalateByBuyer($record, $this->buyer, 'The photos show the damage the seller denied.');
    }
}
