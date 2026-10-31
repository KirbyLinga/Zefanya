<?php

namespace Tests\Feature\Logistics;

use App\Enums\DeliveryFailureReason;
use App\Enums\OrderActor;
use App\Enums\OrderStatus;
use App\Enums\ParcelReturnStatus;
use App\Models\Admin\Admin;
use App\Models\Catalog\InventoryTransaction;
use App\Models\Catalog\Product;
use App\Models\Logistics\Area;
use App\Models\Logistics\ParcelReturn;
use App\Models\Rider\Courier;
use App\Models\Rider\Rider;
use App\Models\Seller\Seller;
use App\Models\Shared\OrderItem;
use App\Models\Shared\Payment;
use App\Models\Shared\SellerOrder;
use App\Models\Shared\StatusHistory;
use App\Services\Logistics\CourierAssignmentService;
use App\Services\Logistics\ManifestService;
use App\Services\Logistics\ParcelHandoffService;
use App\Services\Logistics\ParcelHandoffVerification;
use App\Services\Logistics\ParcelReturnService;
use App\Services\Rider\RiderOperationsService;
use App\Services\Seller\SellerOrderStatusService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BootstrapsCrossProviderHandoffs;
use Tests\TestCase;

class PhysicalReturnTest extends TestCase
{
    use BootstrapsCrossProviderHandoffs;
    use LazilyRefreshDatabase;

    private function fixture(bool $cross = false, bool $sameStation = false): array
    {
        $admin = Admin::factory()->create();
        $a = $this->operationalHandoffProvider('Laguna A', '043400000', 'Laguna', '043405000', 'Calamba', '0434050001', 'Bucal');
        if ($cross) {
            $b = $this->operationalHandoffProvider('Quezon B', '045600000', 'Quezon', '045624000', 'Lucena', '0456240001', 'Barangay I');
        } else {
            $b = $a;
            if (! $sameStation) {
                $station = $a['station']->replicate();
                $station->name = 'Destination Station';
                $station->barangay_code = '0434050002';
                $station->save();
                $area = Area::query()->create(['logistics_provider_id' => $a['provider']->id, 'station_hub_id' => $station->id,
                    'name' => 'Destination coverage', 'municipality' => 'Calamba', 'barangay_code' => '0434050002', 'barangay_name' => 'Second', 'city_municipality_code' => '043405000', 'coverage_validated_at' => now(), 'is_active' => true]);
                $b['station'] = $station;
                $b['area'] = $area;
            }
        }
        $seller = $this->sellerIn('043400000', 'Laguna', '043405000', 'Calamba', '0434050001', 'Bucal');
        $buyer = $this->buyerIn($cross ? '045600000' : '043400000', $cross ? 'Quezon' : 'Laguna', $cross ? '045624000' : '043405000', $cross ? 'Lucena' : 'Calamba', $b['area']->barangay_code, 'Destination');
        $parcel = $this->handoffParcel($seller, $buyer, $a['provider'], 'TRK-RETURN-'.bin2hex(random_bytes(4)), OrderStatus::OutForDelivery);
        $rider = Rider::factory()->create(['logistics_provider_id' => $b['provider']->id, 'station_hub_id' => $b['station']->id, 'status' => 'approved', 'email_verified_at' => now()]);
        $courier = Courier::factory()->create(['logistics_provider_id' => $b['provider']->id, 'rider_id' => $rider->id, 'area_id' => $b['area']->id, 'is_available' => false, 'max_weight_grams' => 100000]);
        $parcel->forceFill(['origin_station_hub_id' => $a['station']->id, 'custody_provider_id' => $b['provider']->id, 'custody_hub_id' => $b['station']->id, 'delivery_courier_id' => $courier->id, 'destination_area_id' => $b['area']->id,
            'pickup_address_snapshot' => ['full' => 'Original Seller pickup street, Bucal, Laguna', 'barangay_code' => '0434050001', 'province_code' => '043400000']])->save();
        $product = Product::factory()->create(['seller_id' => $seller->id, 'stock_quantity' => 3, 'weight_grams' => 500]);
        $variant = $product->variants()->firstOrFail();
        OrderItem::factory()->create(['seller_order_id' => $parcel->id, 'product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 2]);
        $payment = Payment::factory()->create(['seller_order_id' => $parcel->id, 'amount_minor' => 10000]);

        return compact('a', 'b', 'admin', 'seller', 'buyer', 'parcel', 'rider', 'courier', 'product', 'variant', 'payment');
    }

    private function recordFailure(array $f): void
    {
        app(RiderOperationsService::class)->failDelivery($f['rider'], $f['parcel']->fresh(), DeliveryFailureReason::RecipientUnavailable, 'Door unanswered.');
    }

    private function twice(array $f): ParcelReturn
    {
        $this->recordFailure($f);
        $this->assertTrue(SellerOrderStatusService::canRetryDelivery($f['parcel']->fresh()));
        app(CourierAssignmentService::class)->assignDelivery($f['parcel']->fresh(), $f['b']['provider'], rescheduled: true);
        app(RiderOperationsService::class)->startDelivery($f['rider'], $f['parcel']->fresh());
        $this->recordFailure($f);

        return $f['parcel']->fresh()->parcelReturn;
    }

    private function moveHome(array $f, ParcelReturn $return): void
    {
        $service = app(ManifestService::class);
        $manifest = app(ParcelReturnService::class)->reverseManifest($return, $f['b']['provider'], $f['a']['station']->id);
        $item = $manifest->items()->sole();
        $scan = $service->verifyScan($manifest, $f['b']['provider'], $f['parcel']->tracking_number, 'dispatch');
        $service->dispatch($manifest, $f['b']['provider'], [$item->id => $scan['verification_token']]);
        $this->assertSame(ParcelReturnStatus::InTransit, $return->fresh()->status);
        $scan = $service->verifyScan($manifest->fresh(), $f['b']['provider'], $f['parcel']->tracking_number, 'receive');
        $service->receive($manifest->fresh(), $f['b']['provider'], $item, $scan['verification_token']);
        $this->assertSame(ParcelReturnStatus::AtOrigin, $return->fresh()->status);
    }

    private function receiptToken(array $f, ParcelReturn $return, string $condition = 'pending_review'): string
    {
        $service = app(ParcelReturnService::class);
        $scan = $service->scan($return, $f['a']['provider'], $f['parcel']->tracking_number, 'handover');
        $code = $service->prepareHandover($return, $f['a']['provider'], $scan['actions'][0]['verification_token']);

        return $service->verifySeller($return, $f['seller'], $f['parcel']->tracking_number, $code, $condition);
    }

    public function test_second_failure_requests_one_return_without_stock_payment_or_status_finalization(): void
    {
        $f = $this->fixture();
        $return = $this->twice($f);
        $this->recordFailure($f);
        $this->assertSame(2, $f['parcel']->fresh()->delivery_attempts);
        $this->assertSame(OrderStatus::DeliveryFailed, $f['parcel']->fresh()->status);
        $this->assertSame(ParcelReturnStatus::Requested, $return->status);
        $this->assertSame(1, ParcelReturn::query()->count());
        $this->assertSame(3, $f['variant']->fresh()->stock_quantity);
        $this->assertSame('pending', $f['payment']->fresh()->status->value);
        $this->assertSame(2, $f['parcel']->statusHistories()->where('to_status', 'DELIVERY_FAILED')->count());
        $this->assertSame(0, InventoryTransaction::query()->where('reason', 'return')->count());
        $this->assertNull(app(CourierAssignmentService::class)->assignDelivery($f['parcel']->fresh(), $f['b']['provider'], rescheduled: true));
    }

    public function test_canonical_return_shortcut_is_rejected_without_seller_receipt(): void
    {
        $f = $this->fixture();
        $this->twice($f);
        $this->expectException(\InvalidArgumentException::class);
        app(SellerOrderStatusService::class)->transition($f['parcel']->fresh(), OrderStatus::Returned, actor: OrderActor::Logistics, actorId: $f['a']['provider']->id);
    }

    public function test_same_provider_reverse_manifest_and_seller_receipt_complete_and_restock_once(): void
    {
        $f = $this->fixture();
        $return = $this->twice($f);
        $this->moveHome($f, $return);
        $this->assertSame(OrderStatus::DeliveryFailed, $f['parcel']->fresh()->status);
        $this->assertSame(3, $f['variant']->fresh()->stock_quantity);
        $token = $this->receiptToken($f, $return, 'resellable');
        app(ParcelReturnService::class)->receiveBySeller($return, $f['seller'], 'resellable', $token);
        $this->assertSame(ParcelReturnStatus::Completed, $return->fresh()->status);
        $this->assertSame($f['seller']->id, (int) $return->fresh()->received_by_seller_id);
        $this->assertSame(OrderStatus::Returned, $f['parcel']->fresh()->status);
        // Stock is restored exactly once when Seller receipt is verified.
        $this->assertSame(5, $f['variant']->fresh()->stock_quantity);
        $this->assertNotNull($return->fresh()->stock_restored_at);
        $this->assertSame('pending', $f['payment']->fresh()->status->value);
        $this->assertSame(1, InventoryTransaction::query()->where('reason', 'return')->count());
    }

    public function test_approved_resellable_stock_is_ledger_backed_and_cannot_restore_twice(): void
    {
        config(['returns.restore_verified_resellable' => true]);
        $f = $this->fixture();
        $return = $this->twice($f);
        $this->moveHome($f, $return);
        $token = $this->receiptToken($f, $return, 'resellable');
        app(ParcelReturnService::class)->receiveBySeller($return, $f['seller'], 'resellable', $token);
        $this->assertSame(5, $f['variant']->fresh()->stock_quantity);
        $this->assertSame(1, InventoryTransaction::query()->where('reason', 'return')->count());
        app(SellerOrderStatusService::class)->releaseVerifiedReturnStock($f['parcel']->fresh(), $f['admin']);
        $this->assertSame(5, $f['variant']->fresh()->stock_quantity);
        $this->expectException(ValidationException::class);
        app(ParcelReturnService::class)->receiveBySeller($return, $f['seller'], 'resellable', $token);
    }

    public function test_stock_is_restored_on_seller_receipt_regardless_of_condition(): void
    {
        config(['returns.restore_verified_resellable' => true]);
        $f = $this->fixture();
        $return = $this->twice($f);
        $this->moveHome($f, $return);
        $token = $this->receiptToken($f, $return, 'damaged');
        app(ParcelReturnService::class)->receiveBySeller($return, $f['seller'], 'damaged', $token);
        $this->assertSame(ParcelReturnStatus::Completed, $return->fresh()->status);
        // No condition-based restocking: damaged goods are restored too.
        $this->assertSame(5, $f['variant']->fresh()->stock_quantity);
        $this->assertSame(1, InventoryTransaction::query()->where('reason', 'return')->count());
        $this->assertNotNull($return->fresh()->stock_restored_at);
    }

    public function test_reverse_cross_provider_route_requires_an_explicit_b_to_a_link_and_verified_receipt(): void
    {
        $f = $this->fixture(true);
        $return = $this->twice($f);
        $this->activeHandoffLink($f['a']['provider'], $f['b']['provider'], $f['admin']);
        try {
            app(ParcelReturnService::class)->reverseHandoff($return, $f['b']['provider']);
            $this->fail('Forward link authorized a reverse handoff.');
        } catch (ValidationException) {
            $this->assertNotNull($return->fresh()->exception_reason);
        }
        $this->activeHandoffLink($f['b']['provider'], $f['a']['provider'], $f['admin']);
        $handoff = app(ParcelReturnService::class)->reverseHandoff($return->fresh(), $f['b']['provider']);
        $this->assertSame('return', $handoff->direction);
        $this->assertSame($f['a']['provider']->id, (int) $handoff->destination_provider_id);
        $service = app(ParcelHandoffService::class);
        $handoff = $service->accept($handoff, $f['a']['provider']);
        $scan = $service->verifyScan($handoff, $f['b']['provider'], $f['parcel']->tracking_number, 'dispatch');
        $handoff = $service->dispatch($handoff, $f['b']['provider'], $scan['verification_token']);
        $this->assertSame($f['b']['provider']->id, $f['parcel']->fresh()->custody_provider_id);
        $scan = $service->verifyScan($handoff, $f['a']['provider'], $f['parcel']->tracking_number, 'receive');
        $service->receive($handoff, $f['a']['provider'], $scan['verification_token']);
        $this->assertSame($f['a']['provider']->id, $f['parcel']->fresh()->custody_provider_id);
        $this->assertSame($f['a']['provider']->id, $f['parcel']->fresh()->carrier_provider_id);
        $this->assertSame(ParcelReturnStatus::AtOrigin, $return->fresh()->status);
        $this->assertSame(OrderStatus::DeliveryFailed, $f['parcel']->fresh()->status);
        $this->assertNull(app(CourierAssignmentService::class)->dispatch($f['parcel']->fresh(), 'delivery')['courier']);
    }

    public function test_seller_cannot_confirm_before_origin_arrival_or_with_a_forged_code(): void
    {
        $f = $this->fixture();
        $return = $this->twice($f);
        $this->actingAs($f['seller'], 'seller')->post(route('seller.returns.verify', $return), ['tracking_number' => $f['parcel']->tracking_number, 'handover_code' => 'forged', 'condition' => 'resellable'])->assertSessionHasErrors();
        $this->assertSame(OrderStatus::DeliveryFailed, $f['parcel']->fresh()->status);
        $this->assertSame(3, $f['variant']->fresh()->stock_quantity);
    }

    public function test_logistics_scan_alone_never_counts_as_seller_receipt_and_expired_handover_fails(): void
    {
        $f = $this->fixture(false, true);
        $return = $this->twice($f);
        $service = app(ParcelReturnService::class);
        $scan = $service->scan($return, $f['a']['provider'], $f['parcel']->tracking_number, 'origin');
        $service->confirmOrigin($return, $f['a']['provider'], $scan['actions'][0]['verification_token']);
        $this->assertSame(ParcelReturnStatus::AtOrigin, $return->fresh()->status);
        $this->assertNull($return->fresh()->seller_received_at);
        $token = $this->receiptToken($f, $return);
        $this->travel(11)->minutes();
        $this->expectException(ValidationException::class);
        $service->receiveBySeller($return, $f['seller'], 'pending_review', $token);
    }

    public function test_forged_and_replayed_origin_scans_are_rejected(): void
    {
        $f = $this->fixture(false, true);
        $return = $this->twice($f);
        $service = app(ParcelReturnService::class);
        try {
            $service->confirmOrigin($return, $f['a']['provider'], 'forged');
            $this->fail('Forged token accepted.');
        } catch (ValidationException) {
        }
        $scan = $service->scan($return, $f['a']['provider'], $f['parcel']->tracking_number, 'origin');
        $service->confirmOrigin($return, $f['a']['provider'], $scan['actions'][0]['verification_token']);
        $this->expectException(ValidationException::class);
        $service->confirmOrigin($return, $f['a']['provider'], $scan['actions'][0]['verification_token']);
    }

    public function test_returns_cannot_be_added_to_a_normal_outgoing_manifest(): void
    {
        $f = $this->fixture();
        $this->twice($f);
        $service = app(ManifestService::class);
        $manifest = $service->create($f['a']['provider'], $f['b']['station']->id, $f['a']['station']->id);
        $this->expectException(ValidationException::class);
        $service->addParcel($manifest, $f['parcel']->fresh());
    }

    public function test_return_provider_and_seller_isolation_and_scoped_pages(): void
    {
        $f = $this->fixture();
        $return = $this->twice($f);
        $foreign = $this->handoffProvider('Unrelated');
        $this->actingAs($foreign, 'logistics')->get(route('logistics.returns.show', $return))->assertNotFound();
        $this->get(route('logistics.returns.index'))->assertOk()->assertDontSee($f['parcel']->tracking_number);
        $seller = Seller::factory()->create();
        $this->actingAs($seller, 'seller')->post(route('seller.returns.receive', $return), ['verification_token' => 'forged', 'condition' => 'resellable'])->assertForbidden();
        $this->actingAs($f['b']['provider'], 'logistics')->get(route('logistics.returns.show', $return))->assertOk()->assertSee('Reverse internal transport');
        $this->actingAs($f['admin'], 'admin')->get(route('admin.returns.index'))->assertOk()->assertSee($f['parcel']->tracking_number);
        $this->actingAs($f['seller'], 'seller')->get(route('seller.returns.index'))->assertOk()->assertSee('Incoming Returns');
    }

    public function test_buyer_and_seller_tracking_show_return_progress_without_rider_identity(): void
    {
        $f = $this->fixture();
        $return = $this->twice($f);
        $this->moveHome($f, $return);
        foreach ([['buyer', $f['buyer'], route('buyer.orders.show', $f['parcel']->order)], ['seller', $f['seller'], route('seller.orders.show', $f['parcel'])]] as [$guard,$actor,$url]) {
            $this->actingAs($actor, $guard)->get($url)->assertOk()->assertSeeInOrder(['Physical return requested', 'Return in transit to Seller', 'Return received at origin station'])->assertDontSee($f['courier']->name);
        }
    }

    public function test_cod_collection_is_blocked_for_return_parcels(): void
    {
        $f = $this->fixture();
        $this->twice($f);
        $this->expectException(\InvalidArgumentException::class);
        $f['payment']->recordCodCollection(10000, 'rider', $f['courier']->id);
    }

    public function test_a_third_delivery_attempt_cannot_bypass_the_return_workflow(): void
    {
        $f = $this->fixture();
        $this->twice($f);
        $this->expectException(\InvalidArgumentException::class);
        app(SellerOrderStatusService::class)->transition($f['parcel']->fresh(), OrderStatus::AssignedToRider, actor: OrderActor::Logistics, actorId: $f['b']['provider']->id);
    }

    public function test_a_third_provider_link_never_authorizes_a_multi_hop_reverse_route(): void
    {
        $f = $this->fixture(true);
        $return = $this->twice($f);
        $c = $this->operationalHandoffProvider('Third provider', '043400000', 'Laguna', '043405000', 'Calamba', '0434050001', 'Bucal');
        $this->activeHandoffLink($f['b']['provider'], $c['provider'], $f['admin']);
        $this->expectException(ValidationException::class);
        app(ParcelReturnService::class)->reverseHandoff($return, $f['b']['provider']);
    }

    public function test_expired_origin_scan_and_forged_seller_receipt_tokens_fail(): void
    {
        $f = $this->fixture(false, true);
        $return = $this->twice($f);
        $service = app(ParcelReturnService::class);
        $scan = $service->scan($return, $f['a']['provider'], $f['parcel']->tracking_number, 'origin');
        $this->travel(11)->minutes();
        try {
            $service->confirmOrigin($return, $f['a']['provider'], $scan['actions'][0]['verification_token']);
            $this->fail('Expired origin scan accepted.');
        } catch (ValidationException) {
        }
        $this->travelBack();
        $scan = $service->scan($return, $f['a']['provider'], $f['parcel']->tracking_number, 'origin');
        $service->confirmOrigin($return, $f['a']['provider'], $scan['actions'][0]['verification_token']);
        $this->receiptToken($f, $return, 'resellable');
        $this->expectException(ValidationException::class);
        $service->receiveBySeller($return, $f['seller'], 'resellable', 'forged');
    }

    public function test_f2_token_issuance_itself_requires_the_physical_handover_code(): void
    {
        $f = $this->fixture();
        $return = $this->twice($f);
        $this->moveHome($f, $return);
        $this->receiptToken($f, $return);
        $this->expectException(ValidationException::class);
        app(ParcelHandoffVerification::class)->issueForSellerReturn($f['seller'], $return->fresh(), 'resellable', 'forged');
    }

    public function test_a_seller_receipt_token_cannot_change_the_inspected_stock_condition(): void
    {
        $f = $this->fixture();
        $return = $this->twice($f);
        $this->moveHome($f, $return);
        $token = $this->receiptToken($f, $return, 'damaged');
        $this->expectException(ValidationException::class);
        app(ParcelReturnService::class)->receiveBySeller($return, $f['seller'], 'resellable', $token);
    }

    public function test_paid_payment_is_flagged_for_review_without_inventing_a_refund(): void
    {
        $f = $this->fixture();
        $f['payment']->forceFill(['method' => 'cod', 'status' => 'paid', 'paid_at' => now()])->save();
        $return = $this->twice($f);
        $this->moveHome($f, $return);
        $token = $this->receiptToken($f, $return);
        app(ParcelReturnService::class)->receiveBySeller($return, $f['seller'], 'pending_review', $token);
        $this->assertTrue($return->fresh()->financial_review_required);
        $this->assertSame('paid', $f['payment']->fresh()->status->value);
        $this->assertSame(10000, $f['payment']->fresh()->amount_minor);
    }

    public function test_legacy_origin_needs_explicit_admin_verification_before_reverse_transport(): void
    {
        $f = $this->fixture();
        $f['parcel']->forceFill(['pickup_address_snapshot' => null])->save();
        $return = $this->twice($f);
        $this->assertNotNull($return->exception_reason);
        $this->actingAs($f['admin'], 'admin')->post(route('admin.returns.origin', $return), [
            'origin_hub_id' => $f['a']['station']->id, 'barangay_code' => '0434050001', 'address' => 'Verified original pickup street',
            'evidence' => 'Reviewed historical original waybill and Seller pickup evidence.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Verified original pickup street', $return->fresh()->seller_address['full']);
        $this->assertNull($return->fresh()->exception_reason);
        $this->assertSame(1, $return->events()->where('event_key', 'origin_verified')->count());
        $this->assertNull($f['parcel']->fresh()->pickup_address_snapshot);
    }

    public function test_multi_seller_siblings_and_totals_are_unchanged_by_one_physical_return(): void
    {
        $f = $this->fixture();
        $sibling = SellerOrder::factory()->create(['order_id' => $f['parcel']->order_id, 'status' => 'DELIVERED']);
        $total = $f['parcel']->order->total_minor;
        $return = $this->twice($f);
        $this->moveHome($f, $return);
        $token = $this->receiptToken($f, $return);
        app(ParcelReturnService::class)->receiveBySeller($return, $f['seller'], 'pending_review', $token);
        $this->assertSame(OrderStatus::Delivered, $sibling->fresh()->status);
        $this->assertSame(OrderStatus::Delivered, $f['parcel']->order->fresh()->status);
        $this->assertSame($total, $f['parcel']->order->fresh()->total_minor);
        $this->assertSame(1, ParcelReturn::query()->count());
    }

    public function test_repeated_early_return_requests_are_idempotent_and_block_the_normal_retry(): void
    {
        $f = $this->fixture();
        $this->recordFailure($f);
        $service = app(ParcelReturnService::class);
        $first = $service->request($f['parcel']->fresh(), $f['b']['provider'], 'Delivery cannot continue.');
        $second = $service->request($f['parcel']->fresh(), $f['b']['provider'], 'Delivery cannot continue.');
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, $first->events()->where('event_key', 'requested')->count());
        $this->assertFalse(SellerOrderStatusService::canRetryDelivery($f['parcel']->fresh()));
        $this->assertSame(3, $f['variant']->fresh()->stock_quantity);
    }

    public function test_admin_can_release_held_stock_only_after_policy_approval_and_physical_receipt(): void
    {
        $f = $this->fixture();
        $return = $this->twice($f);
        $this->moveHome($f, $return);
        $token = $this->receiptToken($f, $return, 'resellable');
        app(ParcelReturnService::class)->receiveBySeller($return, $f['seller'], 'resellable', $token);
        // Stock already restored at Seller receipt; admin release is a no-op.
        $this->actingAs($f['admin'], 'admin')->post(route('admin.returns.stock', $return))->assertSessionHasErrors('stock');
        $this->assertSame(5, $f['variant']->fresh()->stock_quantity);
        $this->assertSame(1, InventoryTransaction::query()->where('reason', 'return')->count());
        config(['returns.restore_verified_resellable' => true]);
        $this->post(route('admin.returns.stock', $return))->assertSessionHas('success');
        $this->post(route('admin.returns.stock', $return))->assertSessionHas('success');
        $this->assertSame(5, $f['variant']->fresh()->stock_quantity);
        $this->assertSame(1, InventoryTransaction::query()->where('reason', 'return')->count());
    }

    public function test_missing_variant_keeps_inventory_held_without_blocking_the_truth_of_seller_receipt(): void
    {
        config(['returns.restore_verified_resellable' => true]);
        $f = $this->fixture();
        $return = $this->twice($f);
        $this->moveHome($f, $return);
        $f['parcel']->orderItems()->update(['variant_id' => null]);
        $token = $this->receiptToken($f, $return, 'resellable');
        app(ParcelReturnService::class)->receiveBySeller($return, $f['seller'], 'resellable', $token);
        $this->assertSame(ParcelReturnStatus::Completed, $return->fresh()->status);
        $this->assertNull($return->fresh()->stock_restored_at);
        $this->assertSame(0, InventoryTransaction::query()->where('reason', 'return')->count());
        $this->assertSame(3, $f['variant']->fresh()->stock_quantity);
    }

    public function test_return_cannot_be_settled_as_cod_paid_through_the_payment_model(): void
    {
        $f = $this->fixture();
        $this->twice($f);
        $this->expectException(\InvalidArgumentException::class);
        $f['payment']->markPaid();
    }

    public function test_equal_second_tracking_orders_request_transport_and_receipt_before_canonical_returned(): void
    {
        $this->freezeTime();
        $f = $this->fixture();
        $return = $this->twice($f);
        $this->moveHome($f, $return);
        $token = $this->receiptToken($f, $return);
        app(ParcelReturnService::class)->receiveBySeller($return, $f['seller'], 'pending_review', $token);
        $titles = array_column($f['parcel']->fresh()->trackingEvents(), 'title');
        $this->assertLessThan(array_search('Return in transit to Seller', $titles), array_search('Physical return requested', $titles));
        $this->assertLessThan(array_search('Physical return received by Seller', $titles), array_search('Return received at origin station', $titles));
        $this->assertSame(OrderStatus::Returned->label(), $titles[array_key_last($titles)]);
        $keys = array_column($f['parcel']->fresh()->trackingEvents(), 'key');
        $this->assertCount(count($keys), array_unique($keys));
    }

    public function test_a_cached_payment_model_cannot_bypass_return_collection_or_settlement_guards(): void
    {
        $f = $this->fixture();
        $f['payment']->load('sellerOrder.parcelReturn');
        $this->assertNull($f['payment']->sellerOrder->parcelReturn);
        $this->twice($f);
        try {
            $f['payment']->recordCodCollection(10000, 'rider', $f['courier']->id);
            $this->fail('A stale payment relationship bypassed return custody.');
        } catch (\InvalidArgumentException) {
            $this->assertSame('pending', $f['payment']->fresh()->status->value);
        }
        $this->expectException(\InvalidArgumentException::class);
        $f['payment']->markPaid();
    }

    public function test_rollback_of_second_failure_does_not_leave_a_return_or_stock_change(): void
    {
        $f = $this->fixture();
        $this->recordFailure($f);
        app(CourierAssignmentService::class)->assignDelivery($f['parcel']->fresh(), $f['b']['provider'], rescheduled: true);
        app(RiderOperationsService::class)->startDelivery($f['rider'], $f['parcel']->fresh());
        DB::beginTransaction();
        $this->recordFailure($f);
        DB::rollBack();
        $this->assertSame(0, ParcelReturn::query()->count());
        $this->assertSame(1, $f['parcel']->fresh()->delivery_attempts);
        $this->assertSame(3, $f['variant']->fresh()->stock_quantity);
    }

    public function test_duplicate_dispatch_and_receipt_are_prevented(): void
    {
        $f = $this->fixture();
        $return = $this->twice($f);

        $manifestService = app(ManifestService::class);
        $manifest = app(ParcelReturnService::class)->reverseManifest($return, $f['b']['provider'], $f['a']['station']->id);
        $item = $manifest->items()->sole();

        // First dispatch succeeds.
        $scan = $manifestService->verifyScan($manifest, $f['b']['provider'], $f['parcel']->tracking_number, 'dispatch');
        $manifestService->dispatch($manifest, $f['b']['provider'], [$item->id => $scan['verification_token']]);
        $this->assertSame(ParcelReturnStatus::InTransit, $return->fresh()->status);

        // Duplicate dispatch on the same manifest item is rejected.
        $manifest = $manifest->fresh();
        $item = $manifest->items()->sole();
        $this->expectException(ValidationException::class);
        $manifestService->dispatch($manifest, $f['b']['provider'], [$item->id => $scan['verification_token']]);

        // Receive succeeds once.
        $receiveScan = $manifestService->verifyScan($manifest->fresh(), $f['b']['provider'], $f['parcel']->tracking_number, 'receive');
        $manifestService->receive($manifest->fresh(), $f['b']['provider'], $item, $receiveScan['verification_token']);
        $this->assertSame(ParcelReturnStatus::AtOrigin, $return->fresh()->status);

        // Duplicate receipt on the same manifest item is rejected.
        $manifest = $manifest->fresh();
        $item = $manifest->items()->sole();
        $this->expectException(ValidationException::class);
        $manifestService->receive($manifest, $f['b']['provider'], $item, $receiveScan['verification_token']);
    }

    /** A delivered parcel whose buyer has asked to return it, not yet decided by the Seller. */
    private function buyerReturn(array $f): ParcelReturn
    {
        $parcel = $f['parcel'];
        $parcel->forceFill(['status' => OrderStatus::Delivered->value])->save();
        StatusHistory::query()->create([
            'seller_order_id' => $parcel->id, 'from_status' => OrderStatus::OutForDelivery,
            'to_status' => OrderStatus::Delivered, 'actor_type' => 'rider', 'source' => 'web',
        ]);

        return app(ParcelReturnService::class)->requestByBuyer($parcel->fresh(), $f['buyer'], 'Item arrived damaged');
    }

    private function assertManifestRefuses(array $f, string $message): void
    {
        $service = app(ManifestService::class);
        $manifest = $service->create($f['b']['provider'], $f['b']['station']->id, $f['a']['station']->id, 'return');

        $this->assertNotContains($f['parcel']->id, $service->eligibleParcels($manifest)->pluck('id')->all());

        try {
            $service->addParcel($manifest, $f['parcel']->fresh());
            $this->fail('The manifest accepted a return that cannot move yet.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }

        $this->assertSame(0, $manifest->items()->count());
    }

    private function assertHandoffRefuses(array $f, ParcelReturn $return, string $message): void
    {
        try {
            app(ParcelReturnService::class)->reverseHandoff($return->fresh(), $f['b']['provider']);
            $this->fail('A reverse handoff started for a return that cannot move yet.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }

    public function test_manifest_service_refuses_a_buyer_return_until_it_is_decided_and_collected(): void
    {
        $f = $this->fixture();
        $return = $this->buyerReturn($f);
        $returns = app(ParcelReturnService::class);

        $this->assertManifestRefuses($f, 'has not decided');

        $returns->approveBySeller($return, $f['seller']);
        $this->assertManifestRefuses($f, 'still with the buyer');

        $returns->assignBuyerCollection($return, $f['b']['provider'], $f['courier']->id);
        $this->assertManifestRefuses($f, 'still with the buyer');

        $returns->confirmBuyerCollection($return, $f['rider'], $f['parcel']->tracking_number);

        $service = app(ManifestService::class);
        $manifest = $service->create($f['b']['provider'], $f['b']['station']->id, $f['a']['station']->id, 'return');
        $this->assertContains($f['parcel']->id, $service->eligibleParcels($manifest)->pluck('id')->all());
        $this->assertSame($f['parcel']->id, $service->addParcel($manifest, $f['parcel']->fresh())->seller_order_id);
    }

    public function test_a_normal_delivered_parcel_stays_closed_to_manifests(): void
    {
        $f = $this->fixture();
        $f['parcel']->forceFill(['status' => OrderStatus::Delivered->value])->save();
        $service = app(ManifestService::class);
        $manifest = $service->create($f['b']['provider'], $f['b']['station']->id, $f['a']['station']->id);

        $this->assertNotContains($f['parcel']->id, $service->eligibleParcels($manifest)->pluck('id')->all());
        $this->expectException(ValidationException::class);
        $service->addParcel($manifest, $f['parcel']->fresh());
    }

    public function test_handoff_refuses_a_buyer_return_until_it_is_collected(): void
    {
        $f = $this->fixture(true);
        $return = $this->buyerReturn($f);
        $returns = app(ParcelReturnService::class);
        $this->activeHandoffLink($f['b']['provider'], $f['a']['provider'], $f['admin']);

        $this->assertHandoffRefuses($f, $return, 'has not decided');

        $returns->approveBySeller($return, $f['seller']);
        $this->assertHandoffRefuses($f, $return, 'still with the buyer');
    }

    public function test_only_the_custody_provider_can_assign_and_only_its_active_riders(): void
    {
        $f = $this->fixture(true);
        $return = $this->buyerReturn($f);
        $returns = app(ParcelReturnService::class);

        try {
            $returns->assignBuyerCollection($return, $f['b']['provider'], $f['courier']->id);
            $this->fail('Assigned before the Seller decided.');
        } catch (ValidationException) {
            $this->assertNull($return->fresh()->collection_courier_id);
        }

        $returns->approveBySeller($return, $f['seller']);

        $foreign = Courier::factory()->create(['logistics_provider_id' => $f['a']['provider']->id]);
        try {
            $returns->assignBuyerCollection($return, $f['b']['provider'], $foreign->id);
            $this->fail('Assigned another provider\'s rider.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('courier_id', $exception->errors());
        }

        try {
            $returns->assignBuyerCollection($return, $f['a']['provider'], $foreign->id);
            $this->fail('A provider without custody assigned a rider.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $returns->assignBuyerCollection($return, $f['b']['provider'], $f['courier']->id);
        $this->assertSame($f['courier']->id, (int) $return->fresh()->collection_courier_id);
        $this->assertSame(1, $f['rider']->notifications()->count());
        $this->assertStringContainsString('Collect', $f['rider']->notifications()->first()->data['message']);
    }

    public function test_only_the_assigned_rider_can_collect_with_the_right_waybill(): void
    {
        $f = $this->fixture();
        $return = $this->buyerReturn($f);
        $returns = app(ParcelReturnService::class);
        $returns->approveBySeller($return, $f['seller']);
        $returns->assignBuyerCollection($return, $f['b']['provider'], $f['courier']->id);

        $other = Rider::factory()->create(['logistics_provider_id' => $f['b']['provider']->id, 'station_hub_id' => $f['b']['station']->id, 'status' => 'approved', 'email_verified_at' => now()]);
        Courier::factory()->create(['logistics_provider_id' => $f['b']['provider']->id, 'rider_id' => $other->id, 'area_id' => $f['b']['area']->id]);

        $this->actingAs($other, 'rider')->post(route('rider.return-pickups.collect', $return), ['tracking_number' => $f['parcel']->tracking_number])->assertForbidden();
        $this->assertSame([], $this->get(route('rider.return-pickups.index'))->assertOk()->viewData('returns')->modelKeys());

        $this->actingAs($f['rider'], 'rider');
        $this->assertSame([$return->id], $this->get(route('rider.return-pickups.index'))->assertOk()->assertSee('action="'.route('rider.return-pickups.collect', $return).'"', false)->viewData('returns')->modelKeys());
        $this->post(route('rider.return-pickups.collect', $return), ['tracking_number' => 'WRONG'])->assertSessionHasErrors('tracking_number');
        $this->assertNull($return->fresh()->collected_at);

        $this->post(route('rider.return-pickups.collect', $return), ['tracking_number' => $f['parcel']->tracking_number])->assertSessionHasNoErrors();

        $this->assertNotNull($return->fresh()->collected_at);
        $this->assertSame($f['b']['station']->id, (int) $f['parcel']->fresh()->custody_hub_id);
        $this->assertSame($f['b']['provider']->id, (int) $f['parcel']->fresh()->custody_provider_id);
        $this->assertSame(OrderStatus::Delivered, $f['parcel']->fresh()->status);
        $this->assertSame([], $this->get(route('rider.return-pickups.index'))->assertOk()->viewData('returns')->modelKeys());

        $this->post(route('rider.return-pickups.collect', $return), ['tracking_number' => $f['parcel']->tracking_number])->assertSessionHasErrors('return');
    }

    public function test_logistics_page_offers_collection_only_while_it_is_needed(): void
    {
        $f = $this->fixture();
        $return = $this->buyerReturn($f);
        $returns = app(ParcelReturnService::class);
        $this->actingAs($f['b']['provider'], 'logistics');
        $assign = 'action="'.route('logistics.returns.assign-collection', $return).'"';
        $manifest = 'action="'.route('logistics.returns.manifest', $return).'"';

        $this->get(route('logistics.returns.show', $return))->assertOk()->assertSee('has not decided')->assertDontSee($assign, false)->assertDontSee($manifest, false);

        $returns->approveBySeller($return, $f['seller']);
        $this->get(route('logistics.returns.show', $return))->assertOk()->assertSee($assign, false)->assertDontSee($manifest, false);

        $this->post(route('logistics.returns.assign-collection', $return), ['courier_id' => $f['courier']->id])->assertSessionHasNoErrors();
        $this->get(route('logistics.returns.show', $return))->assertOk()->assertSee('You can reassign');

        $returns->confirmBuyerCollection($return, $f['rider'], $f['parcel']->tracking_number);
        $this->get(route('logistics.returns.show', $return))->assertOk()->assertDontSee($assign, false)->assertSee($manifest, false);
    }

    public function test_buyer_return_travels_from_the_buyer_all_the_way_to_seller_receipt(): void
    {
        $f = $this->fixture();
        $return = $this->buyerReturn($f);
        $returns = app(ParcelReturnService::class);

        $returns->approveBySeller($return, $f['seller']);
        $returns->assignBuyerCollection($return, $f['b']['provider'], $f['courier']->id);
        $returns->confirmBuyerCollection($return, $f['rider'], $f['parcel']->tracking_number);

        $this->moveHome($f, $return->fresh());
        $this->assertSame(OrderStatus::Delivered, $f['parcel']->fresh()->status);

        $token = $this->receiptToken($f, $return->fresh(), 'resellable');
        $returns->receiveBySeller($return->fresh(), $f['seller'], 'resellable', $token);

        $this->assertSame(ParcelReturnStatus::Completed, $return->fresh()->status);
        $this->assertSame(OrderStatus::Returned, $f['parcel']->fresh()->status);
        $this->assertSame(5, $f['variant']->fresh()->stock_quantity);
        $this->assertSame(1, InventoryTransaction::query()->where('reason', 'return')->count());
        $this->assertSame(['requested', 'seller_approved', 'collection_assigned', 'collected_from_buyer', 'in_transit', 'at_origin', 'awaiting_seller', 'completed'], $return->events()->pluck('event_key')->all());
    }

    public function test_cross_provider_buyer_return_reaches_the_origin_provider(): void
    {
        $f = $this->fixture(true);
        $return = $this->buyerReturn($f);
        $returns = app(ParcelReturnService::class);
        $returns->approveBySeller($return, $f['seller']);
        $returns->assignBuyerCollection($return, $f['b']['provider'], $f['courier']->id);
        $returns->confirmBuyerCollection($return, $f['rider'], $f['parcel']->tracking_number);
        $this->activeHandoffLink($f['b']['provider'], $f['a']['provider'], $f['admin']);

        $handoff = $returns->reverseHandoff($return->fresh(), $f['b']['provider']);
        $this->assertSame('return', $handoff->direction);

        $service = app(ParcelHandoffService::class);
        $handoff = $service->accept($handoff, $f['a']['provider']);
        $scan = $service->verifyScan($handoff, $f['b']['provider'], $f['parcel']->tracking_number, 'dispatch');
        $handoff = $service->dispatch($handoff, $f['b']['provider'], $scan['verification_token']);
        $scan = $service->verifyScan($handoff, $f['a']['provider'], $f['parcel']->tracking_number, 'receive');
        $service->receive($handoff, $f['a']['provider'], $scan['verification_token']);

        $this->assertSame($f['a']['provider']->id, $f['parcel']->fresh()->custody_provider_id);
        $this->assertSame(ParcelReturnStatus::AtOrigin, $return->fresh()->status);
        $this->assertSame(OrderStatus::Delivered, $f['parcel']->fresh()->status);
    }

    public function test_unauthorized_initiation_is_rejected(): void
    {
        $f = $this->fixture();
        $this->recordFailure($f);

        // A foreign logistics provider without custody cannot initiate a return.
        $foreign = $this->handoffProvider('Unrelated');
        $this->actingAs($foreign, 'logistics')
            ->post(route('logistics.delivery-assignments.return', $f['parcel']), ['reason' => 'Unauthorized return.'])
            ->assertForbidden();

        // No return was created; the order still has one failure and stays failed.
        $this->assertSame(0, ParcelReturn::query()->count());
        $this->assertSame(1, $f['parcel']->fresh()->delivery_attempts);
        $this->assertSame(OrderStatus::DeliveryFailed, $f['parcel']->fresh()->status);
        $this->assertSame(3, $f['variant']->fresh()->stock_quantity);
    }
}
