<?php

namespace Tests\Feature\Logistics;

use App\Enums\DeliveryFailureReason;
use App\Enums\OrderStatus;
use App\Enums\ParcelReturnStatus;
use App\Enums\PaymentStatus;
use App\Models\Admin\Admin;
use App\Models\Catalog\InventoryTransaction;
use App\Models\Catalog\Product;
use App\Models\Logistics\Area;
use App\Models\Logistics\CodRemittance;
use App\Models\Logistics\ParcelLossCase;
use App\Models\Logistics\ParcelReturn;
use App\Models\Rider\Courier;
use App\Models\Rider\Rider;
use App\Models\Seller\SellerDebt;
use App\Models\Seller\SellerSettlement;
use App\Models\Shared\Incidents;
use App\Models\Shared\OrderItem;
use App\Models\Shared\Payment;
use App\Models\Shared\StatusHistory;
use App\Notifications\Incident\ReturnRecoveryUpdate;
use App\Services\Buyer\BuyerShipmentConfirmation;
use App\Services\Logistics\CourierAssignmentService;
use App\Services\Logistics\ManifestService;
use App\Services\Logistics\ParcelIncidentService;
use App\Services\Logistics\ParcelReturnService;
use App\Services\Rider\RiderOperationsService;
use App\Services\Seller\SellerSettlementService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BootstrapsCrossProviderHandoffs;
use Tests\TestCase;

/** I-01: every approved return can finish, retry, be cancelled, disputed or closed as a loss. */
class ReturnRecoveryTest extends TestCase
{
    use BootstrapsCrossProviderHandoffs;
    use LazilyRefreshDatabase;

    private function fixture(bool $cross = false): array
    {
        $admin = Admin::factory()->create(['role' => 'admin']);
        $a = $this->operationalHandoffProvider('Laguna A', '043400000', 'Laguna', '043405000', 'Calamba', '0434050001', 'Bucal');
        if ($cross) {
            $b = $this->operationalHandoffProvider('Quezon B', '045600000', 'Quezon', '045624000', 'Lucena', '0456240001', 'Barangay I');
        } else {
            $b = $a;
            $station = $a['station']->replicate();
            $station->name = 'Destination Station';
            $station->barangay_code = '0434050002';
            $station->save();
            $area = Area::query()->create(['logistics_provider_id' => $a['provider']->id, 'station_hub_id' => $station->id,
                'name' => 'Destination coverage', 'municipality' => 'Calamba', 'barangay_code' => '0434050002', 'barangay_name' => 'Second', 'city_municipality_code' => '043405000', 'coverage_validated_at' => now(), 'is_active' => true]);
            $b['station'] = $station;
            $b['area'] = $area;
        }
        $seller = $this->sellerIn('043400000', 'Laguna', '043405000', 'Calamba', '0434050001', 'Bucal');
        $buyer = $this->buyerIn($cross ? '045600000' : '043400000', $cross ? 'Quezon' : 'Laguna', $cross ? '045624000' : '043405000', $cross ? 'Lucena' : 'Calamba', $b['area']->barangay_code, 'Destination');
        $parcel = $this->handoffParcel($seller, $buyer, $a['provider'], 'TRK-RECOVER-'.bin2hex(random_bytes(4)), OrderStatus::OutForDelivery);
        $rider = $this->riderFor($b);
        $courier = $rider->courier;
        $parcel->forceFill(['origin_station_hub_id' => $a['station']->id, 'custody_provider_id' => $b['provider']->id, 'custody_hub_id' => $b['station']->id, 'delivery_courier_id' => $courier->id, 'destination_area_id' => $b['area']->id,
            'shipping_fee_minor' => 5000, 'subtotal_minor' => 10000,
            'pickup_address_snapshot' => ['full' => 'Original Seller pickup street, Bucal, Laguna', 'barangay_code' => '0434050001', 'province_code' => '043400000']])->save();
        $product = Product::factory()->create(['seller_id' => $seller->id, 'stock_quantity' => 3, 'weight_grams' => 500]);
        $variant = $product->variants()->firstOrFail();
        OrderItem::factory()->create(['seller_order_id' => $parcel->id, 'product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 2]);
        $payment = Payment::factory()->create(['seller_order_id' => $parcel->id, 'amount_minor' => 15000]);

        return compact('a', 'b', 'admin', 'seller', 'buyer', 'parcel', 'rider', 'courier', 'product', 'variant', 'payment');
    }

    private function riderFor(array $network): Rider
    {
        $rider = Rider::factory()->create(['logistics_provider_id' => $network['provider']->id, 'station_hub_id' => $network['station']->id, 'status' => 'approved', 'email_verified_at' => now()]);
        Courier::factory()->create(['logistics_provider_id' => $network['provider']->id, 'rider_id' => $rider->id, 'area_id' => $network['area']->id, 'is_available' => false, 'max_weight_grams' => 100000]);

        return $rider->fresh();
    }

    /** Delivered and paid at the door, then the buyer asks to return it and the Seller approves. */
    private function approvedReturn(array $f): ParcelReturn
    {
        $parcel = $f['parcel'];
        $parcel->forceFill(['status' => OrderStatus::Delivered->value])->save();
        StatusHistory::query()->create(['seller_order_id' => $parcel->id, 'from_status' => OrderStatus::OutForDelivery,
            'to_status' => OrderStatus::Delivered, 'actor_type' => 'rider', 'source' => 'web']);
        $f['payment']->forceFill(['status' => PaymentStatus::Paid, 'paid_at' => now(), 'collected_at' => now(), 'collected_amount_minor' => 15000])->save();
        $returns = app(ParcelReturnService::class);
        $return = $returns->requestByBuyer($parcel->fresh(), $f['buyer'], 'Item arrived damaged');
        $returns->approveBySeller($return, $f['seller']);

        return $return->fresh();
    }

    private function collected(array $f): ParcelReturn
    {
        $return = $this->approvedReturn($f);
        $returns = app(ParcelReturnService::class);
        $returns->assignBuyerCollection($return, $f['b']['provider'], $f['courier']->id);
        $returns->confirmBuyerCollection($return, $f['rider'], $f['parcel']->tracking_number);

        return $return->fresh();
    }

    private function atHandover(array $f): ParcelReturn
    {
        $return = $this->collected($f);
        $service = app(ManifestService::class);
        $manifest = app(ParcelReturnService::class)->reverseManifest($return, $f['b']['provider'], $f['a']['station']->id);
        $item = $manifest->items()->sole();
        $scan = $service->verifyScan($manifest, $f['b']['provider'], $f['parcel']->tracking_number, 'dispatch');
        $service->dispatch($manifest, $f['b']['provider'], [$item->id => $scan['verification_token']]);
        $scan = $service->verifyScan($manifest->fresh(), $f['b']['provider'], $f['parcel']->tracking_number, 'receive');
        $service->receive($manifest->fresh(), $f['b']['provider'], $item, $scan['verification_token']);
        $returns = app(ParcelReturnService::class);
        $scan = $returns->scan($return->fresh(), $f['a']['provider'], $f['parcel']->tracking_number, 'handover');
        $returns->prepareHandover($return->fresh(), $f['a']['provider'], $scan['actions'][0]['verification_token']);
        $this->assertSame(ParcelReturnStatus::AwaitingSeller, $return->fresh()->status);

        return $return->fresh();
    }

    /** Cash remitted to Logistics, so a settlement exists while the order is still delivered. */
    private function remitted(array $f): SellerSettlement
    {
        $remittance = new CodRemittance;
        $remittance->forceFill([
            'seller_order_id' => $f['parcel']->id, 'payment_id' => $f['payment']->id, 'courier_id' => $f['courier']->id,
            'logistics_provider_id' => $f['b']['provider']->id, 'amount_minor' => 15000,
            'status' => CodRemittance::REMITTED_TO_LOGISTICS, 'received_amount_minor' => 15000,
            'confirmed_at' => now(), 'confirmed_by_id' => $f['b']['provider']->id,
        ])->save();

        return app(SellerSettlementService::class)->prepare($remittance);
    }

    private function missCollection(array $f, Rider $rider, string $reason = 'buyer_absent', ?string $notes = null): void
    {
        app(ParcelReturnService::class)->reportCollectionFailure(ParcelReturn::query()->where('seller_order_id', $f['parcel']->id)->sole(), $rider, $reason, $notes);
    }

    private function assertRefused(callable $action, ?string $message = null): void
    {
        try {
            $action();
            $this->fail('The action was not refused.');
        } catch (ValidationException|InvalidArgumentException $exception) {
            if ($message !== null) {
                $this->assertStringContainsString($message, $exception instanceof ValidationException ? implode(' ', $exception->errors()[array_key_first($exception->errors())]) : $exception->getMessage());
            }
        }
    }

    private function photo(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('damage.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jWZkAAAAASUVORK5CYII='));
    }

    // ── 1. Failed collection ───────────────────────────────────────────────

    public function test_buyer_no_show_is_recorded_retried_and_escalated_after_two_attempts(): void
    {
        $f = $this->fixture();
        $return = $this->approvedReturn($f);
        $returns = app(ParcelReturnService::class);
        $returns->assignBuyerCollection($return, $f['b']['provider'], $f['courier']->id);

        $this->actingAs($f['rider'], 'rider')
            ->post(route('rider.return-pickups.failed', $return), ['reason' => 'buyer_absent'])->assertSessionHasNoErrors();

        $return = $return->fresh();
        $this->assertSame(1, $return->collection_failed_attempts);
        $this->assertNull($return->collection_courier_id, 'the rider is released so Logistics must send someone again');
        $this->assertNull($return->collection_escalated_at);
        $this->assertSame(ParcelReturnStatus::Requested, $return->status);
        $event = $return->events()->where('event_key', 'collection_failed')->sole();
        $this->assertSame('rider', $event->actor_type);
        $this->assertSame($f['rider']->id, (int) $event->actor_id);
        $this->assertSame(['attempt' => 1, 'courier_id' => $f['courier']->id, 'reason' => 'buyer_absent', 'notes' => null], $event->metadata);
        $this->assertSame(1, $f['buyer']->notifications()->where('type', ReturnRecoveryUpdate::class)->count());
        $this->assertSame([], $this->get(route('rider.return-pickups.index'))->assertOk()->viewData('returns')->modelKeys());

        // The same rider cannot report the released assignment again.
        $this->post(route('rider.return-pickups.failed', $return), ['reason' => 'buyer_absent'])->assertForbidden();

        // Retry with a different rider, who also misses the buyer.
        $second = $this->riderFor($f['b']);
        $returns->assignBuyerCollection($return, $f['b']['provider'], $second->courier->id);
        $this->missCollection($f, $second, 'other', 'Gate locked and phone unanswered.');

        $return = $return->fresh();
        $this->assertSame(2, $return->collection_failed_attempts);
        $this->assertNotNull($return->collection_escalated_at);
        $this->assertSame(['collection_failed', 'collection_failed_2', 'collection_escalated'], $return->events()->where('event_key', 'like', 'collection_%')->where('event_key', '!=', 'collection_assigned')->pluck('event_key')->all());
        $this->assertSame(1, $f['admin']->notifications()->where('type', ReturnRecoveryUpdate::class)->count());

        // Nothing is refunded or cancelled automatically, and no third rider can be sent.
        $this->assertSame(ParcelReturnStatus::Requested, $return->status);
        $this->assertNull($return->refunded_at);
        $this->assertSame(OrderStatus::Delivered, $f['parcel']->fresh()->status);
        $this->assertRefused(fn () => $returns->assignBuyerCollection($return, $f['b']['provider'], $f['courier']->id), 'Admin must decide');
        $this->actingAs($f['b']['provider'], 'logistics')->get(route('logistics.returns.show', $return))->assertOk()
            ->assertSee('Admin decides this return')->assertDontSee('action="'.route('logistics.returns.assign-collection', $return).'"', false);
        $this->actingAs($f['admin'], 'admin')->get(route('admin.returns.index'))->assertOk()->assertSee('Needs your decision: collection failed 2 times.');
    }

    public function test_only_the_assigned_rider_can_report_and_other_needs_notes(): void
    {
        $f = $this->fixture();
        $return = $this->approvedReturn($f);
        app(ParcelReturnService::class)->assignBuyerCollection($return, $f['b']['provider'], $f['courier']->id);
        $other = $this->riderFor($f['b']);

        $this->actingAs($other, 'rider')->post(route('rider.return-pickups.failed', $return), ['reason' => 'buyer_absent'])->assertForbidden();
        $this->actingAs($f['rider'], 'rider')->post(route('rider.return-pickups.failed', $return), ['reason' => 'other'])->assertSessionHasErrors('notes');
        $this->post(route('rider.return-pickups.failed', $return), ['reason' => 'teleported'])->assertSessionHasErrors('reason');
        $this->assertSame(0, $return->fresh()->collection_failed_attempts);

        // A collected parcel cannot be reported as missed.
        app(ParcelReturnService::class)->confirmBuyerCollection($return, $f['rider'], $f['parcel']->tracking_number);
        $this->assertRefused(fn () => $this->missCollection($f, $f['rider']));
        $this->assertSame(0, $return->fresh()->collection_failed_attempts);
    }

    public function test_a_reassigned_rider_completes_the_return_after_a_missed_collection(): void
    {
        $f = $this->fixture();
        $return = $this->approvedReturn($f);
        $returns = app(ParcelReturnService::class);
        $returns->assignBuyerCollection($return, $f['b']['provider'], $f['courier']->id);
        $this->missCollection($f, $f['rider'], 'parcel_unavailable');

        $second = $this->riderFor($f['b']);
        $returns->assignBuyerCollection($return->fresh(), $f['b']['provider'], $second->courier->id);
        $returns->confirmBuyerCollection($return->fresh(), $second, $f['parcel']->tracking_number);

        $return = $return->fresh();
        $this->assertNotNull($return->collected_at);
        $this->assertSame(1, $return->collection_failed_attempts);
        $this->assertTrue($return->inReturnNetwork());
        $this->assertSame($f['b']['station']->id, (int) $f['parcel']->fresh()->custody_hub_id);
    }

    // ── 2. Cancel / close ──────────────────────────────────────────────────

    public function test_buyer_cancels_before_collection_and_held_earnings_come_back_once(): void
    {
        $f = $this->fixture();
        $return = $this->approvedReturn($f);
        $settlement = $this->remitted($f);
        app(SellerSettlementService::class)->releasePendingForReturn($f['parcel']->fresh());
        $this->assertSame(SellerSettlement::RELEASED, $settlement->fresh()->status);
        $this->assertSame(5000, SellerDebt::sole()->amount_minor, 'approval charged the return trip');
        $this->assertNotNull($f['parcel']->fresh()->commission_finalized_at);

        $this->actingAs($f['buyer'], 'buyer')
            ->post(route('buyer.orders.cancelReturn', [$f['parcel']->order_id, $f['parcel']]), ['reason' => 'Found the missing part.'])
            ->assertSessionHasNoErrors();

        $return = $return->fresh();
        $this->assertSame(ParcelReturnStatus::Cancelled, $return->status);
        $this->assertSame('buyer', $return->closed_by_type);
        $this->assertNotNull($return->earnings_restored_at);
        $this->assertFalse($return->releasesPendingEarnings());
        $this->assertFalse($f['parcel']->fresh()->hasActiveReturn());
        $this->assertNull($f['parcel']->fresh()->commission_finalized_at);
        $this->assertSame(SellerSettlement::PENDING_PAYOUT, $settlement->fresh()->status);
        $this->assertSame(SellerSettlement::COMMISSION_FINALIZATION_MISSING, $settlement->fresh()->pending_reason);
        $this->assertSame(0, SellerDebt::sole()->amount_minor, 'the unpaid return-trip charge is dropped');
        $this->assertSame(1, $f['seller']->notifications()->where('type', ReturnRecoveryUpdate::class)->count());

        // Repeating is refused and changes nothing.
        $this->post(route('buyer.orders.cancelReturn', [$f['parcel']->order_id, $f['parcel']]))->assertSessionHasErrors('return');
        $this->assertRefused(fn () => app(ParcelReturnService::class)->recordRefund($return, $f['admin'], 'REF-1', 15000, 'Buyer GCash', now()->toDateString()), 'nothing to refund');

        // The buyer kept the item: confirming receipt completes it and finalizes 10% commission.
        app(BuyerShipmentConfirmation::class)->confirm($f['parcel']->order, $f['buyer'], $f['parcel']->id);
        $parcel = $f['parcel']->fresh();
        $this->assertSame(OrderStatus::Completed, $parcel->status);
        $this->assertSame(1000, $parcel->commission_bps);
        $this->assertSame(1000, $parcel->commission_minor);
        $this->assertSame(9000, $settlement->fresh()->seller_payout_minor);
        $this->assertSame(SellerSettlement::PENDING_PAYOUT, $settlement->fresh()->status);
    }

    public function test_buyer_cannot_cancel_after_verified_collection(): void
    {
        $f = $this->fixture();
        $return = $this->collected($f);

        $this->actingAs($f['buyer'], 'buyer')
            ->post(route('buyer.orders.cancelReturn', [$f['parcel']->order_id, $f['parcel']]))->assertSessionHasErrors('return');
        $this->assertSame(ParcelReturnStatus::Requested, $return->fresh()->status);
        $this->assertNotNull($return->fresh()->collected_at);
        $this->assertSame($f['b']['provider']->id, (int) $f['parcel']->fresh()->custody_provider_id);
    }

    public function test_another_buyer_cannot_cancel(): void
    {
        $f = $this->fixture();
        $this->approvedReturn($f);
        $stranger = $this->buyerIn('043400000', 'Laguna', '043405000', 'Calamba', '0434050002', 'Second');

        $this->actingAs($stranger, 'buyer')
            ->post(route('buyer.orders.cancelReturn', [$f['parcel']->order_id, $f['parcel']]))->assertStatus(404);
        $this->assertSame(ParcelReturnStatus::Requested, $f['parcel']->fresh()->parcelReturn->status);
    }

    public function test_admin_closes_an_escalated_no_show_once_and_moderators_cannot(): void
    {
        $f = $this->fixture();
        $return = $this->approvedReturn($f);
        $returns = app(ParcelReturnService::class);
        foreach ([$f['rider'], $this->riderFor($f['b'])] as $rider) {
            $returns->assignBuyerCollection($return->fresh(), $f['b']['provider'], $rider->courier->id);
            $this->missCollection($f, $rider);
        }

        $moderator = Admin::factory()->create(['role' => 'moderator']);
        $this->actingAs($moderator, 'admin')->post(route('admin.returns.close', $return), ['close_reason' => 'Buyer never answered calls.'])->assertForbidden();
        $this->actingAs($f['admin'], 'admin')->post(route('admin.returns.close', $return), ['close_reason' => 'short'])->assertSessionHasErrors('close_reason');
        $this->assertSame(ParcelReturnStatus::Requested, $return->fresh()->status);

        $this->post(route('admin.returns.close', $return), ['close_reason' => 'Buyer never answered calls or messages.'])->assertSessionHasNoErrors();
        $return = $return->fresh();
        $this->assertSame(ParcelReturnStatus::Cancelled, $return->status);
        $this->assertSame('admin', $return->closed_by_type);
        $this->assertSame($f['admin']->id, (int) $return->closed_by_id);
        $this->assertDatabaseHas('operations_audit_logs', ['action' => 'return_closed']);

        // A repeated close is refused and writes nothing new.
        $this->post(route('admin.returns.close', $return), ['close_reason' => 'Buyer never answered calls or messages.'])->assertSessionHasErrors('return');
        $this->assertSame(1, $return->events()->where('event_key', 'admin_closed')->count());
        $this->assertSame(OrderStatus::Delivered, $f['parcel']->fresh()->status);
        $this->assertTrue($f['parcel']->fresh()->canBuyerConfirmReceipt());
    }

    public function test_a_recorded_refund_blocks_closing_an_uncollected_return(): void
    {
        $f = $this->fixture();
        $return = $this->approvedReturn($f);
        app(ParcelReturnService::class)->recordRefund($return, $f['admin'], 'REF-EARLY', 15000, 'Buyer GCash', now()->toDateString());

        $this->assertRefused(fn () => app(ParcelReturnService::class)->closeByAdmin($return->fresh(), $f['admin'], 'Buyer did not hand the item over.'), 'Void it');
        $this->assertFalse($return->fresh()->canBuyerCancel());
        $this->assertSame(ParcelReturnStatus::Requested, $return->fresh()->status);
    }

    public function test_moderators_cannot_decide_who_pays_record_or_void_refunds(): void
    {
        $f = $this->fixture();
        $return = $this->approvedReturn($f);
        $moderator = Admin::factory()->create(['role' => 'moderator']);

        $this->actingAs($moderator, 'admin')->post(route('admin.returns.fault', $return), [
            'fault_party' => ParcelReturn::FAULT_LOGISTICS,
            'fault_note' => 'Buyer reported transit damage at the door.',
        ])->assertForbidden();
        $this->assertNull($return->fresh()->fault_set_by_admin_id);

        $this->actingAs($moderator, 'admin')->post(route('admin.returns.refund', $return), [
            'reference' => 'REF-MOD', 'amount' => '150.00', 'recipient' => 'Buyer GCash',
            'paid_on' => now('Asia/Manila')->toDateString(),
        ])->assertForbidden();
        $this->assertNull($return->fresh()->refunded_at);

        $this->actingAs($f['admin'], 'admin')->post(route('admin.returns.refund', $return), [
            'reference' => 'REF-ADMIN', 'amount' => '150.00', 'recipient' => 'Buyer GCash',
            'paid_on' => now('Asia/Manila')->toDateString(),
        ])->assertSessionHasNoErrors();
        $this->assertNotNull($return->fresh()->refunded_at);

        $this->actingAs($moderator, 'admin')->post(route('admin.returns.refund.void', $return), [
            'void_reason' => 'The transfer bounced back to us.',
        ])->assertForbidden();
        $this->assertNotNull($return->fresh()->refunded_at);
        $this->assertSame(0, $return->events()->where('event_key', 'like', 'refund_voided%')->count());
    }

    // ── 3. Lost / damaged in the return network ────────────────────────────

    public function test_a_return_lost_in_reverse_transport_closes_the_return_not_the_delivered_order(): void
    {
        $f = $this->fixture(true);
        $return = $this->collected($f);
        $this->remitted($f);
        app(SellerSettlementService::class)->releasePendingForReturn($f['parcel']->fresh());
        $stock = $f['variant']->fresh()->stock_quantity;

        $this->actingAs($f['b']['provider'], 'logistics')
            ->post(route('logistics.returns.report-problem', $return), ['type' => 'missing', 'reason' => 'Not in the outgoing bin'])->assertSessionHasNoErrors();
        $incident = Incidents::query()->where('incident_seller_order_id', $f['parcel']->id)->sole();
        $this->assertSame('missing', $incident->type);
        $this->assertSame(1, $return->events()->where('event_key', 'transit_missing')->count());

        // A repeated report reuses the open incident.
        $this->post(route('logistics.returns.report-problem', $return), ['type' => 'missing', 'reason' => 'Not in the outgoing bin'])->assertSessionHasNoErrors();
        $this->assertSame(1, Incidents::query()->count());
        $this->assertSame(1, $return->events()->where('event_key', 'like', 'transit_%')->count());

        $incidents = app(ParcelIncidentService::class);
        $incidents->resolve($incident, $f['admin'], ['event' => 'CLOSE_LOST', 'reason' => 'Searched both hubs; not found.']);
        $incidents->resolve($incident->fresh(), $f['admin'], ['event' => 'CLOSE_LOST', 'reason' => 'Searched both hubs; not found.']);

        $return = $return->fresh();
        $this->assertSame(ParcelReturnStatus::Lost, $return->status);
        $this->assertFalse($return->isActive());
        $this->assertSame(OrderStatus::Delivered, $f['parcel']->fresh()->status, 'the delivered order is not cancelled');
        $case = ParcelLossCase::sole();
        $this->assertSame($return->id, (int) $case->parcel_return_id);
        $this->assertSame($f['b']['provider']->id, (int) $case->logistics_provider_id, 'liability follows verified custody');
        $this->assertSame(ParcelLossCase::REVIEW_REQUIRED, $case->compensation_status);
        $this->assertSame($stock, $f['variant']->fresh()->stock_quantity, 'nothing is restocked');
        $this->assertSame(0, InventoryTransaction::query()->where('reason', 'return')->count());
        $this->assertFalse($f['parcel']->fresh()->canBuyerConfirmReceipt());
        $this->assertRefused(fn () => app(BuyerShipmentConfirmation::class)->confirm($f['parcel']->order, $f['buyer'], $f['parcel']->id));

        // The buyer is still refunded through the existing return rules, once.
        $returns = app(ParcelReturnService::class);
        $returns->recordRefund($return, $f['admin'], 'REF-LOST', 15000, 'Buyer GCash', now()->toDateString());
        $this->assertNotNull($return->fresh()->refunded_at);
        $this->assertRefused(fn () => $returns->recordRefund($return->fresh(), $f['admin'], 'REF-LOST-2', 15000, 'Buyer GCash', now()->toDateString()), 'already has a recorded refund');
        $this->assertSame(1, ParcelLossCase::query()->count());
    }

    public function test_a_return_damaged_beyond_return_is_closed_without_restock(): void
    {
        $f = $this->fixture();
        $return = $this->collected($f);

        app(ParcelReturnService::class)->reportTransitProblem($return, $f['b']['provider'], ['type' => 'damaged', 'reason' => 'Crushed box', 'photo' => $this->photo()]);
        $incident = Incidents::query()->sole();
        app(ParcelIncidentService::class)->resolve($incident, $f['admin'], ['event' => 'CLOSE_DAMAGED', 'reason' => 'Contents destroyed in the hub.']);

        $this->assertSame(ParcelReturnStatus::Damaged, $return->fresh()->status);
        $this->assertSame(OrderStatus::Delivered, $f['parcel']->fresh()->status);
        $this->assertSame(3, $f['variant']->fresh()->stock_quantity);
        $this->assertSame(ParcelLossCase::REVIEW_REQUIRED, ParcelLossCase::sole()->compensation_status);
    }

    public function test_damaged_closure_labels_sibling_exceptions_as_damaged_not_lost(): void
    {
        $f = $this->fixture();
        $return = $this->collected($f);
        $returns = app(ParcelReturnService::class);
        $returns->reportTransitProblem($return, $f['b']['provider'], ['type' => 'damaged', 'reason' => 'Crushed box', 'photo' => $this->photo()]);
        $returns->reportTransitProblem($return, $f['b']['provider'], ['type' => 'missing', 'reason' => 'Not in the damaged bin']);

        $damaged = Incidents::query()->where('type', 'damaged')->sole();
        app(ParcelIncidentService::class)->resolve($damaged, $f['admin'], ['event' => 'CLOSE_DAMAGED', 'reason' => 'Contents destroyed in the hub.']);

        $sibling = Incidents::query()->where('type', 'missing')->sole();
        $this->assertSame('CLOSE_DAMAGED', $sibling->resolution);
        $this->assertSame('CLOSE_DAMAGED', $sibling->events()->where('event', 'resolved')->sole()->detail);
        $this->assertSame(ParcelReturnStatus::Damaged, $return->fresh()->status);
    }

    public function test_admin_can_authorize_exactly_one_extra_collection_attempt(): void
    {
        $f = $this->fixture();
        $return = $this->approvedReturn($f);
        $returns = app(ParcelReturnService::class);
        foreach ([$f['rider'], $this->riderFor($f['b'])] as $rider) {
            $returns->assignBuyerCollection($return->fresh(), $f['b']['provider'], $rider->courier->id);
            $this->missCollection($f, $rider);
        }
        $this->assertTrue($return->fresh()->collectionEscalated());
        $this->assertRefused(fn () => $returns->assignBuyerCollection($return->fresh(), $f['b']['provider'], $f['courier']->id), 'Admin must decide');

        $moderator = Admin::factory()->create(['role' => 'moderator']);
        $this->actingAs($moderator, 'admin')->post(route('admin.returns.collection-retry', $return), ['retry_note' => 'Buyer now confirms someone is home.'])->assertSessionHasNoErrors();
        $this->assertTrue($return->fresh()->collectionRetryAuthorized());

        $third = $this->riderFor($f['b']);
        $returns->assignBuyerCollection($return->fresh(), $f['b']['provider'], $third->courier->id);
        $this->missCollection($f, $third, 'buyer_absent');
        $return = $return->fresh();
        $this->assertSame(3, $return->collection_failed_attempts);
        $this->assertTrue($return->collectionEscalated());
        $this->assertSame(1, $return->events()->where('event_key', 'collection_escalated_2')->count());

        // The extra attempt is spent: no fourth rider, and no second authorization.
        $this->assertRefused(fn () => $returns->assignBuyerCollection($return->fresh(), $f['b']['provider'], $f['courier']->id), 'Admin must decide');
        $this->assertRefused(fn () => $returns->authorizeCollectionRetry($return->fresh(), $f['admin'], 'Please try once more.'), 'collection limit');
    }

    public function test_admin_records_physical_disposition_of_a_disputed_return(): void
    {
        $f = $this->fixture();
        $return = $this->atHandover($f);
        app(ParcelReturnService::class)->disputeReceiptBySeller($return, $f['seller'], 'refused', 'Parcel was opened and resealed.');
        $return = $return->fresh();
        $this->assertTrue($return->dispositionCanBeRecorded());
        $stock = $f['variant']->fresh()->stock_quantity;

        $moderator = Admin::factory()->create(['role' => 'moderator']);
        $this->actingAs($moderator, 'admin')->post(route('admin.returns.disposition', $return), [
            'disposition' => 'returned_to_seller', 'disposition_note' => 'Seller accepted it after inspection.',
        ])->assertForbidden();
        $this->assertNull($return->fresh()->disposition);

        $this->actingAs($f['admin'], 'admin')->post(route('admin.returns.disposition', $return), [
            'disposition' => 'teleported', 'disposition_note' => 'This is not a real disposition.',
        ])->assertSessionHasErrors('disposition');

        $this->actingAs($f['admin'], 'admin')->post(route('admin.returns.disposition', $return), [
            'disposition' => 'returned_to_seller', 'disposition_note' => 'Seller accepted the parcel after a joint inspection.',
        ])->assertSessionHasNoErrors();

        $return = $return->fresh();
        $this->assertSame('returned_to_seller', $return->disposition);
        $this->assertSame($f['admin']->id, (int) $return->disposition_set_by_admin_id);
        $this->assertSame(ParcelReturnStatus::ReceiptDisputed, $return->status, 'recording disposition does not move the return state');
        $this->assertSame($f['a']['provider']->id, (int) $f['parcel']->fresh()->custody_provider_id, 'custody is unchanged');
        $this->assertSame($stock, $f['variant']->fresh()->stock_quantity, 'stock is unchanged');
        $this->assertDatabaseHas('operations_audit_logs', ['action' => 'return_disposition_recorded']);
    }

    public function test_only_the_custody_provider_can_report_and_only_inside_the_network(): void
    {
        $f = $this->fixture(true);
        $return = $this->approvedReturn($f);
        $service = app(ParcelReturnService::class);

        // Still with the buyer: there is nothing in the network to lose.
        $this->assertRefused(fn () => $service->reportTransitProblem($return, $f['b']['provider'], ['type' => 'missing', 'reason' => 'x']), 'holds');

        $service->assignBuyerCollection($return, $f['b']['provider'], $f['courier']->id);
        $service->confirmBuyerCollection($return, $f['rider'], $f['parcel']->tracking_number);
        try {
            $service->reportTransitProblem($return->fresh(), $f['a']['provider'], ['type' => 'missing', 'reason' => 'x']);
            $this->fail('A provider without custody reported the parcel.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame(0, Incidents::query()->count());
    }

    public function test_a_failed_delivery_lost_on_its_way_back_closes_as_a_lost_parcel(): void
    {
        $f = $this->fixture();
        $operations = app(RiderOperationsService::class);
        $operations->failDelivery($f['rider'], $f['parcel']->fresh(), DeliveryFailureReason::RecipientUnavailable, 'Door unanswered.');
        app(CourierAssignmentService::class)->assignDelivery($f['parcel']->fresh(), $f['b']['provider'], rescheduled: true);
        $operations->startDelivery($f['rider'], $f['parcel']->fresh());
        $operations->failDelivery($f['rider'], $f['parcel']->fresh(), DeliveryFailureReason::RecipientUnavailable, 'Door unanswered.');
        $return = $f['parcel']->fresh()->parcelReturn;

        app(ParcelReturnService::class)->reportTransitProblem($return, $f['b']['provider'], ['type' => 'missing', 'reason' => 'Lost at the station']);
        app(ParcelIncidentService::class)->resolve(Incidents::query()->sole(), $f['admin'], ['event' => 'CLOSE_LOST', 'reason' => 'Not found after a full search.']);

        $this->assertSame(ParcelReturnStatus::Lost, $return->fresh()->status);
        $this->assertSame(OrderStatus::Lost, $f['parcel']->fresh()->status);
        $this->assertSame(PaymentStatus::Cancelled, $f['payment']->fresh()->status);
        $this->assertSame(ParcelLossCase::PENDING, ParcelLossCase::sole()->compensation_status);
    }

    // ── 4. Seller receipt dispute ──────────────────────────────────────────

    public function test_seller_reports_a_wrong_item_and_admin_sends_it_back_to_handover(): void
    {
        $f = $this->fixture();
        $return = $this->atHandover($f);

        $this->actingAs($f['seller'], 'seller')->post(route('seller.returns.dispute-receipt', $return), [
            'issue' => 'wrong_item', 'note' => 'This is a different product model.', 'photos' => [$this->photo()],
        ])->assertSessionHasNoErrors();

        $return = $return->fresh();
        $this->assertSame(ParcelReturnStatus::ReceiptDisputed, $return->status);
        $this->assertNull($return->handover_code_hash);
        $this->assertNull($return->seller_received_at);
        $this->assertCount(1, $return->receipt_dispute_photos);
        $this->assertSame($f['a']['provider']->id, (int) $f['parcel']->fresh()->custody_provider_id, 'custody stays with Logistics');
        $this->assertTrue($return->isActive());
        $this->assertSame(1, $f['admin']->notifications()->where('type', ReturnRecoveryUpdate::class)->count());
        $this->assertRefused(fn () => app(ParcelReturnService::class)->scan($return, $f['a']['provider'], $f['parcel']->tracking_number, 'handover'));

        $this->actingAs($f['admin'], 'admin')->get(route('admin.returns.receipt-dispute.photo', [$return, 0]))->assertOk();
        $this->post(route('admin.returns.receipt-dispute.resume', $return), ['resume_note' => 'Photo shows the ordered model.'])->assertSessionHasNoErrors();
        $this->assertSame(ParcelReturnStatus::AtOrigin, $return->fresh()->status);

        // The normal verified receipt now completes the return and restocks once.
        $returns = app(ParcelReturnService::class);
        $scan = $returns->scan($return->fresh(), $f['a']['provider'], $f['parcel']->tracking_number, 'handover');
        $code = $returns->prepareHandover($return->fresh(), $f['a']['provider'], $scan['actions'][0]['verification_token']);
        $token = $returns->verifySeller($return->fresh(), $f['seller'], $f['parcel']->tracking_number, $code, 'resellable');
        $returns->receiveBySeller($return->fresh(), $f['seller'], 'resellable', $token);
        $this->assertSame(ParcelReturnStatus::Completed, $return->fresh()->status);
        $this->assertSame(OrderStatus::Returned, $f['parcel']->fresh()->status);
        $this->assertSame(5, $f['variant']->fresh()->stock_quantity);
    }

    public function test_seller_refusal_closed_by_admin_keeps_custody_and_stock(): void
    {
        $f = $this->fixture();
        $return = $this->atHandover($f);
        app(ParcelReturnService::class)->disputeReceiptBySeller($return, $f['seller'], 'refused', 'Parcel was opened and resealed.');

        // Seller receipt is impossible while disputed.
        $this->assertRefused(fn () => app(ParcelReturnService::class)->disputeReceiptBySeller($return->fresh(), $f['seller'], 'refused', 'Parcel was opened and resealed.'));

        $this->actingAs($f['admin'], 'admin')->post(route('admin.returns.close', $return), ['close_reason' => 'Disposition handled offline with the Seller.'])->assertSessionHasNoErrors();

        $return = $return->fresh();
        $this->assertSame(ParcelReturnStatus::Closed, $return->status);
        $this->assertSame($f['a']['provider']->id, (int) $f['parcel']->fresh()->custody_provider_id);
        $this->assertSame(3, $f['variant']->fresh()->stock_quantity);
        $this->assertNull($return->seller_received_at);
        $this->assertNull($return->earnings_restored_at, 'collected goods never restore earnings automatically');
        $this->assertFalse($f['parcel']->fresh()->canBuyerConfirmReceipt());
    }

    public function test_a_stranger_seller_cannot_dispute_and_disputes_need_the_parcel_at_origin(): void
    {
        $f = $this->fixture();
        $return = $this->collected($f);
        $this->assertRefused(fn () => app(ParcelReturnService::class)->disputeReceiptBySeller($return, $f['seller'], 'damaged', 'Box is crushed on one side.'), 'origin hub');

        $other = $this->sellerIn('043400000', 'Laguna', '043405000', 'Calamba', '0434050001', 'Bucal');
        $this->actingAs($other, 'seller')->post(route('seller.returns.dispute-receipt', $return), ['issue' => 'damaged', 'note' => 'Box is crushed on one side.'])->assertForbidden();
        $this->assertSame(ParcelReturnStatus::Requested, $return->fresh()->status);
    }

    // ── 5. Unchanged happy path ────────────────────────────────────────────

    public function test_a_normal_return_is_unaffected(): void
    {
        $f = $this->fixture();
        $return = $this->atHandover($f);
        $returns = app(ParcelReturnService::class);
        $code = $returns->prepareHandover($return, $f['a']['provider'], $returns->scan($return, $f['a']['provider'], $f['parcel']->tracking_number, 'handover')['actions'][0]['verification_token']);
        $returns->receiveBySeller($return->fresh(), $f['seller'], 'resellable', $returns->verifySeller($return->fresh(), $f['seller'], $f['parcel']->tracking_number, $code, 'resellable'));

        $return = $return->fresh();
        $this->assertSame(ParcelReturnStatus::Completed, $return->status);
        $this->assertSame(0, $return->collection_failed_attempts);
        $this->assertNull($return->closed_at);
        $this->assertSame(0, ParcelLossCase::query()->count());
    }
}
