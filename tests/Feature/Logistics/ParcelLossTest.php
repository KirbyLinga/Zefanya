<?php

namespace Tests\Feature\Logistics;

use App\Enums\ManifestStatus;
use App\Enums\OrderActor;
use App\Enums\OrderStatus;
use App\Enums\ParcelHandoffStatus;
use App\Models\Admin\Admin;
use App\Models\Admin\OperationsAuditLog;
use App\Models\Buyer\Buyer;
use App\Models\Buyer\CartItem;
use App\Models\Buyer\CheckoutToken;
use App\Models\Catalog\Product;
use App\Models\Logistics\CodRemittance;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\Manifest;
use App\Models\Logistics\ParcelHandoff;
use App\Models\Logistics\ParcelLossCase;
use App\Models\Logistics\TransferCourier;
use App\Models\Logistics\TransportConnection;
use App\Models\Rider\Courier;
use App\Models\Rider\Rider;
use App\Models\Seller\Seller;
use App\Models\Seller\SellerDebt;
use App\Models\Seller\SellerSettlement;
use App\Models\Shared\Incidents;
use App\Models\Shared\Order;
use App\Models\Shared\SellerOrder;
use App\Notifications\Admin\LostParcelFound;
use App\Notifications\Admin\TransferEscalated;
use App\Notifications\Incident\ParcelLostConfirmed;
use App\Notifications\Logistics\TransferOverdue;
use App\Services\Admin\PlatformFinanceService;
use App\Services\Logistics\LogisticsFinanceService;
use App\Services\Logistics\ParcelIncidentService;
use App\Services\Logistics\ParcelLossService;
use App\Services\Logistics\StuckTransferService;
use App\Services\Seller\SellerOrderStatusService;
use App\Services\Seller\SellerPayoutRequestService;
use Database\Seeders\DeliveryScenarioSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Lost-parcel resolution on the seeded Bulacan → Quezon City route (two
 * companies, so liability before and after the receiving scan is testable).
 */
class ParcelLossTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected LogisticsProvider $bulacan;

    protected LogisticsProvider $metro;

    protected Admin $admin;

    protected SellerOrder $parcel;

    protected function setUp(): void
    {
        parent::setUp();
        putenv(DeliveryScenarioSeeder::MAILBOX_ENV);
        Notification::fake();
        $this->withoutVite();
        $this->admin = Admin::factory()->create(['email' => 'admin@zefanya.shop']);
        $this->seed(DeliveryScenarioSeeder::class);

        $this->bulacan = $this->account(LogisticsProvider::class, 'logistics-bulacan');
        $this->metro = $this->account(LogisticsProvider::class, 'logistics-metro');
        $this->parcel = $this->orderToMalolos();
    }

    // ── 1. Finalization ────────────────────────────────────────────────────

    public function test_admin_confirms_a_parcel_lost_and_it_is_closed_without_touching_stock_cash_or_earnings(): void
    {
        $handoff = $this->dispatchedHandoff();
        $stock = Product::query()->where('seller_id', $this->parcel->seller_id)->value('stock_quantity');
        $incident = $this->reportMissing($this->parcel);

        $this->actingAs($this->admin, 'admin')->post(route('admin.incidents.resolve', $incident), [
            'resolution' => 'CLOSE_LOST', 'resolution_note' => 'Truck never arrived; driver unreachable.',
        ])->assertSessionHasNoErrors();

        $parcel = $this->parcel->fresh();
        $this->assertSame(OrderStatus::Lost, $parcel->status);
        $this->assertSame(OrderStatus::Lost, $parcel->order->fresh()->status, 'A one-parcel order closes as lost.');
        $this->assertSame($stock, Product::query()->where('seller_id', $parcel->seller_id)->value('stock_quantity'), 'No stock is restored.');
        $this->assertSame('cancelled', $parcel->payment->fresh()->status->value, 'No COD will ever be collected.');
        $this->assertNull($parcel->commission_minor);
        $this->assertSame(0, SellerSettlement::query()->count());
        $this->assertDatabaseCount('cod_remittances', 0);
        $this->assertDatabaseCount('rider_earnings', 1); // Only the pickup earning from before the loss.
        $this->assertSame(ParcelHandoffStatus::Cancelled, $handoff->fresh()->status);
        $this->assertFalse($handoff->fresh()->awaitsRecovery());

        // Audited, with who, why and the old and new state.
        $log = OperationsAuditLog::query()->where('action', 'parcel_lost_confirmed')->sole();
        $this->assertSame('admin', $log->actor_type);
        $this->assertSame('Truck never arrived; driver unreachable.', $log->reason);
        $this->assertSame('LOST', $log->new_values['status']);
        $this->assertSame('admin_lost', $parcel->statusHistories()->where('to_status', 'LOST')->sole()->source);

        // Buyer, Seller and the responsible Logistics company are each told.
        $buyer = $parcel->order->buyer;
        Notification::assertSentTo($buyer, ParcelLostConfirmed::class, fn ($n) => $n->audience === 'buyer');
        Notification::assertSentTo($parcel->seller, ParcelLostConfirmed::class, fn ($n) => $n->audience === 'seller');
        Notification::assertSentTo($this->bulacan, ParcelLostConfirmed::class, fn ($n) => $n->audience === 'logistics');

        // Nothing can move it again.
        $this->expectException(ValidationException::class);
        ParcelIncidentService::assertOperationsAllowed($parcel);
    }

    public function test_confirming_twice_is_harmless_and_creates_one_case_and_one_obligation(): void
    {
        $this->dispatchedHandoff();
        $incident = $this->reportMissing($this->parcel);
        $url = route('admin.incidents.resolve', $incident);

        $this->actingAs($this->admin, 'admin')->post($url, ['resolution' => 'CLOSE_LOST', 'resolution_note' => 'Lost'])->assertSessionHasNoErrors();
        $this->post($url, ['resolution' => 'CLOSE_LOST', 'resolution_note' => 'Lost'])->assertSessionHasNoErrors();

        $this->assertSame(1, ParcelLossCase::query()->count());
        $this->assertSame(1, OperationsAuditLog::query()->where('action', 'parcel_lost_confirmed')->count());
        Notification::assertSentToTimes($this->parcel->order->buyer, ParcelLostConfirmed::class, 1);
        $this->assertSame(1, $this->parcel->statusHistories()->where('to_status', 'LOST')->count());
    }

    public function test_only_admin_can_confirm_lost_and_a_delivered_parcel_cannot_be(): void
    {
        $this->dispatchedHandoff();
        $incident = $this->reportMissing($this->parcel);
        // The company's own screen does not even offer it, and the service refuses it.
        $this->actingAs($this->bulacan, 'logistics')
            ->post(route('logistics.exceptions.resolve', $incident), ['resolution' => 'CLOSE_LOST', 'resolution_note' => 'x'])->assertSessionHasErrors('resolution');
        try {
            app(ParcelIncidentService::class)->resolveForProvider($incident, $this->bulacan, ['event' => 'CLOSE_LOST', 'reason' => 'x']);
            $this->fail('Only Admin may confirm a parcel lost.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertSame(OrderStatus::InTransit, $this->parcel->fresh()->status);

        // A parcel already delivered is not "lost".
        $this->parcel->forceFill(['status' => OrderStatus::Delivered->value])->save();
        $this->actingAs($this->admin, 'admin')->post(route('admin.incidents.resolve', $incident), [
            'resolution' => 'CLOSE_LOST', 'resolution_note' => 'Mistake',
        ])->assertSessionHasErrors('parcel');
        $this->assertSame(0, ParcelLossCase::query()->count());
        $this->assertSame(OrderStatus::Delivered, $this->parcel->fresh()->status);
    }

    // ── 2. Liability ───────────────────────────────────────────────────────

    public function test_before_the_receiving_scan_the_sender_is_liable_for_the_saved_merchandise_value_only(): void
    {
        $this->dispatchedHandoff();
        $saved = (int) $this->parcel->subtotal_minor;
        Product::query()->where('seller_id', $this->parcel->seller_id)->update(['price' => 999]);
        $this->parcel->forceFill([
            'voucher_discount_minor' => 8000,
            'shop_voucher_discount_minor' => 5000,
            'platform_voucher_discount_minor' => 3000,
        ])->save();
        $case = $this->confirmLost($this->parcel);

        $this->assertSame((int) $this->bulacan->id, (int) $case->logistics_provider_id);
        $this->assertSame(ParcelLossCase::BASIS_BEFORE_RECEIPT, $case->liability_basis);
        $this->assertSame(ParcelLossCase::PENDING, $case->compensation_status);
        // Saved checkout merchandise only: not the new catalog price, not the voucher, not shipping.
        $this->assertSame($saved, $case->merchandise_minor);
        $this->assertSame(50000, $case->merchandise_minor);
        $this->assertSame(5000, (int) $this->parcel->fresh()->shipping_fee_minor);
    }

    public function test_after_the_receiving_scan_the_receiver_is_liable(): void
    {
        $handoff = $this->dispatchedHandoff();
        $token = $this->actingAs($this->metro, 'logistics')->postJson(route('logistics.handoffs.scan.verify', $handoff), [
            'tracking_number' => $this->parcel->tracking_number, 'phase' => 'receive',
        ])->assertOk()->json('verification_token');
        $this->post(route('logistics.handoffs.receive', $handoff), ['verification_token' => $token])->assertSessionHasNoErrors();
        $this->assertSame((int) $this->metro->id, $this->parcel->fresh()->currentCustodyProviderId());

        $case = $this->confirmLost($this->parcel, reporter: $this->metro);

        $this->assertSame((int) $this->metro->id, (int) $case->logistics_provider_id);
        $this->assertSame(ParcelLossCase::BASIS_AFTER_RECEIPT, $case->liability_basis);
        Notification::assertSentTo($this->metro, ParcelLostConfirmed::class);
    }

    public function test_unclear_liability_needs_an_admin_decision_before_anyone_can_pay(): void
    {
        // Collected by the pickup Rider but never scanned into a hub: no verified custody.
        $this->parcel->forceFill(['status' => OrderStatus::PickedUp->value, 'custody_provider_id' => null, 'custody_hub_id' => null])->save();
        $case = $this->confirmLost($this->parcel);

        $this->assertNull($case->logistics_provider_id);
        $this->assertSame(ParcelLossCase::REVIEW_REQUIRED, $case->compensation_status);
        $this->assertSame(ParcelLossCase::BASIS_REVIEW, $case->liability_basis);

        $service = app(ParcelLossService::class);
        try {
            $service->recordCompensationPayment($case, $this->admin, 'REF-1', now()->toDateString());
            $this->fail('Payment must wait for a liability decision.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Decide which Logistics company is liable', $e->errors()['reference'][0]);
        }

        $case = $service->assignLiability($case, $this->admin, $this->bulacan->id, 'Its Rider collected it; no hub scanned it in.');
        $this->assertSame([ParcelLossCase::PENDING, ParcelLossCase::BASIS_ADMIN], [$case->compensation_status, $case->liability_basis]);
        $this->assertSame(1, OperationsAuditLog::query()->where('action', 'lost_compensation_assigned')->count());
        Notification::assertSentTo($this->bulacan, ParcelLostConfirmed::class);
    }

    public function test_the_compensation_is_confirmed_once_by_admin_and_corrected_only_by_audited_adjustments(): void
    {
        $this->dispatchedHandoff();
        $case = $this->confirmLost($this->parcel);
        $service = app(ParcelLossService::class);

        $paid = $service->recordCompensationPayment($case, $this->admin, 'BANK-7788', now()->toDateString());
        $this->assertTrue($paid->isPaid());
        $this->assertSame('BANK-7788', $paid->compensation_reference);
        Notification::assertSentTo($this->parcel->seller, ParcelLostConfirmed::class, fn ($n) => $n->audience === 'seller');

        // A repeat with the same reference changes nothing; a different one is refused.
        $service->recordCompensationPayment($case, $this->admin, 'BANK-7788', now()->toDateString());
        $this->assertSame(1, OperationsAuditLog::query()->where('action', 'lost_compensation_paid')->count());
        try {
            $service->recordCompensationPayment($case, $this->admin, 'OTHER', now()->toDateString());
            $this->fail('A second payment must be refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('already recorded as paid', $e->errors()['reference'][0]);
        }
        $this->assertSame(1, ParcelLossCase::query()->count());

        // The paid amount is never edited: a correction is a signed adjustment.
        $service->recordAdjustment($case, $this->admin, -10000, 'Seller returned part of the payment.');
        $this->assertSame(40000, $case->fresh('adjustments')->netCompensationMinor());
        $this->assertSame(50000, $case->fresh()->merchandise_minor);
        $this->assertSame(1, OperationsAuditLog::query()->where('action', 'lost_compensation_adjusted')->count());
        try {
            $service->recordAdjustment($case, $this->admin, -90000, 'Too much');
            $this->fail('The net cannot go below zero.');
        } catch (ValidationException) {
            $this->assertSame(40000, $case->fresh('adjustments')->netCompensationMinor());
        }
    }

    // ── 3. Bulk processing and partial receipts ───────────────────────────

    public function test_partial_loss_of_a_batch_closes_only_the_missing_parcels_and_keeps_the_receipts(): void
    {
        $laguna = $this->account(LogisticsProvider::class, 'logistics-laguna');
        $received = $this->orderTo('seller-lumban', 'buyer-pila', $laguna, 'rider-lumban');
        $lostA = $this->orderTo('seller-lumban', 'buyer-pila', $laguna, 'rider-lumban');
        $lostB = $this->orderTo('seller-lumban', 'buyer-pila', $laguna, 'rider-lumban');
        $manifest = $this->dispatchBatch($laguna, [$received, $lostA, $lostB]);

        // Pila scans in one parcel only: a partial receipt.
        $item = $manifest->items()->where('seller_order_id', $received->id)->sole();
        $token = $this->actingAs($laguna, 'logistics')->postJson(route('logistics.manifests.scan.verify', $manifest), [
            'tracking_number' => $received->tracking_number, 'phase' => 'receive',
        ])->assertOk()->json('verification_token');
        $this->post(route('logistics.manifests.receive.store', [$manifest, $item]), ['verification_token' => $token])->assertSessionHasNoErrors();
        $custody = $received->fresh()->custody_hub_id;

        // The two that never arrived are reported together: two exceptions, nothing else.
        $this->post(route('logistics.manifests.report-missing', $manifest), [
            'seller_order_ids' => [$lostA->id, $lostB->id], 'reason' => 'The van never reached Pila',
        ])->assertSessionHasNoErrors();
        $this->assertSame(2, Incidents::query()->where('type', 'missing')->count());
        $this->assertSame(OrderStatus::InTransit, $lostA->fresh()->status, 'Reporting alone assumes nothing.');

        // Admin confirms them lost together.
        $ids = Incidents::query()->pluck('id')->all();
        $this->actingAs($this->admin, 'admin')->post(route('admin.incidents.confirm-lost'), ['incident_ids' => $ids, 'reason' => 'Van lost at sea'])
            ->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertSame([OrderStatus::Lost, OrderStatus::Lost], [$lostA->fresh()->status, $lostB->fresh()->status]);
        $this->assertSame(2, ParcelLossCase::query()->count());
        // The received parcel is untouched and its custody is preserved.
        $this->assertSame(OrderStatus::ArrivedAtHub, $received->fresh()->status);
        $this->assertSame($custody, $received->fresh()->custody_hub_id);
        $this->assertNull(ParcelLossCase::query()->where('seller_order_id', $received->id)->first());
        // The batch can close: every parcel is now received or accounted for.
        $this->assertSame(ManifestStatus::ReceivedWithExceptions, $manifest->fresh()->status);
        $issues = $manifest->items()->get()->mapWithKeys(fn ($i) => [(int) $i->seller_order_id => $i->issue_type])->all();
        $this->assertEquals([(int) $received->id => null, (int) $lostA->id => 'missing', (int) $lostB->id => 'missing'], $issues);
    }

    public function test_reporting_missing_together_is_all_or_nothing_and_only_for_parcels_still_waiting(): void
    {
        $laguna = $this->account(LogisticsProvider::class, 'logistics-laguna');
        $a = $this->orderTo('seller-lumban', 'buyer-pila', $laguna, 'rider-lumban');
        $b = $this->orderTo('seller-lumban', 'buyer-pila', $laguna, 'rider-lumban');
        $manifest = $this->dispatchBatch($laguna, [$a, $b]);
        $bItem = $manifest->items()->where('seller_order_id', $b->id)->sole();
        $token = $this->actingAs($laguna, 'logistics')->postJson(route('logistics.manifests.scan.verify', $manifest), [
            'tracking_number' => $b->tracking_number, 'phase' => 'receive',
        ])->assertOk()->json('verification_token');
        $this->post(route('logistics.manifests.receive.store', [$manifest, $bItem]), ['verification_token' => $token])->assertSessionHasNoErrors();

        // $b was received: including it refuses the whole report, so $a gets no exception either.
        $this->post(route('logistics.manifests.report-missing', $manifest), ['seller_order_ids' => [$a->id, $b->id], 'reason' => 'x'])
            ->assertSessionHasErrors('seller_order_ids');
        $this->assertSame(0, Incidents::query()->count());

        // A parcel that is not on this batch, an empty list, and another company are all refused.
        $this->post(route('logistics.manifests.report-missing', $manifest), ['seller_order_ids' => [$this->parcel->id], 'reason' => 'x'])->assertSessionHasErrors('seller_order_ids');
        $this->post(route('logistics.manifests.report-missing', $manifest), ['seller_order_ids' => [], 'reason' => 'x'])->assertSessionHasErrors('seller_order_ids');
        $this->actingAs($this->bulacan, 'logistics')->post(route('logistics.manifests.report-missing', $manifest), ['seller_order_ids' => [$a->id], 'reason' => 'x'])->assertNotFound();
        $this->assertSame(0, Incidents::query()->count());

        // The valid report works, and repeating it creates no second exception.
        $this->actingAs($laguna, 'logistics')->post(route('logistics.manifests.report-missing', $manifest), ['seller_order_ids' => [$a->id], 'reason' => 'Never arrived'])->assertSessionHasNoErrors();
        $this->post(route('logistics.manifests.report-missing', $manifest), ['seller_order_ids' => [$a->id], 'reason' => 'Never arrived'])->assertSessionHasNoErrors();
        $this->assertSame(1, Incidents::query()->count());
    }

    public function test_one_parcel_that_cannot_be_closed_does_not_block_the_others_in_a_bulk_confirmation(): void
    {
        $this->dispatchedHandoff();
        $good = $this->reportMissing($this->parcel);
        // A second parcel that was delivered: it cannot be "lost".
        $laguna = $this->account(LogisticsProvider::class, 'logistics-laguna');
        $other = $this->orderTo('seller-pila', 'buyer-pila', $laguna, 'rider-pila');
        $bad = app(ParcelIncidentService::class)->reportOperational($other->fresh(), $laguna, ['type' => 'missing', 'reason' => 'Gone']);
        $other->forceFill(['status' => OrderStatus::Delivered->value])->save();

        $result = app(ParcelLossService::class)->confirmMany([$good->id, $bad->id], $this->admin, 'Confirmed lost');

        $this->assertSame([(int) $this->parcel->id], $result['confirmed']);
        $this->assertArrayHasKey((int) $other->id, $result['failed']);
        $this->assertSame(OrderStatus::Lost, $this->parcel->fresh()->status);
        $this->assertSame(OrderStatus::Delivered, $other->fresh()->status);
        $this->assertSame(1, ParcelLossCase::query()->count());

        // A repeat confirms nothing twice.
        $again = app(ParcelLossService::class)->confirmMany([$good->id], $this->admin, 'Confirmed lost');
        $this->assertSame(1, ParcelLossCase::query()->count());
        $this->assertSame([(int) $this->parcel->id], $again['confirmed']);
    }

    // ── multi-seller orders ────────────────────────────────────────────────

    public function test_in_a_multi_seller_order_only_the_lost_parcels_slice_closes(): void
    {
        $laguna = $this->account(LogisticsProvider::class, 'logistics-laguna');
        $buyer = $this->account(Buyer::class, 'buyer-pila');
        $sellers = [$this->account(Seller::class, 'seller-pila'), $this->account(Seller::class, 'seller-lumban')];
        $cartIds = [];
        $this->actingAs($buyer, 'buyer');
        foreach ($sellers as $seller) {
            $product = Product::query()->where('seller_id', $seller->id)->sole();
            $this->postJson(route('buyer.cart.add'), ['product_id' => $product->id, 'quantity' => 1])->assertOk();
            $cartIds[] = CartItem::query()->where('buyer_id', $buyer->id)->where('product_id', $product->id)->sole()->id;
        }
        $this->get(route('buyer.checkout.index', ['items' => $cartIds]))->assertOk();
        $this->post(route('buyer.checkout.store'), [
            'checkout_token' => CheckoutToken::issue($buyer->id)->token,
            'carriers' => [$sellers[0]->id => $laguna->id, $sellers[1]->id => $laguna->id],
        ])->assertSessionHasNoErrors();

        $order = Order::query()->latest('id')->firstOrFail();
        $this->assertSame(2, $order->sellerOrders()->count());
        $lost = $order->sellerOrders()->where('seller_id', $sellers[1]->id)->sole();
        $kept = $order->sellerOrders()->where('seller_id', $sellers[0]->id)->sole();
        // Collect only the Lumban parcel; the Pila one is still being packed.
        $this->actingAs($sellers[1], 'seller');
        $this->post(route('seller.orders.accept', $lost))->assertSessionHasNoErrors();
        $this->post(route('seller.orders.pack', $lost))->assertSessionHasNoErrors();
        $this->post(route('seller.orders.markReady', $lost))->assertSessionHasNoErrors();
        $this->pickUpAndReceive($lost->fresh(), $laguna, 'rider-lumban');
        $keptStatus = $kept->fresh()->status;
        $keptStock = Product::query()->where('seller_id', $sellers[0]->id)->value('stock_quantity');

        $case = $this->confirmLost($lost->fresh(), reporter: $laguna);

        $this->assertSame(OrderStatus::Lost, $lost->fresh()->status);
        $this->assertSame($keptStatus, $kept->fresh()->status, "The other seller's parcel is untouched.");
        $this->assertSame($keptStock, Product::query()->where('seller_id', $sellers[0]->id)->value('stock_quantity'));
        $this->assertSame((int) $lost->fresh()->subtotal_minor, $case->merchandise_minor, "Only the lost parcel's merchandise is owed.");
        $this->assertSame((int) $sellers[1]->id, (int) $case->seller_id);
        // The order keeps following its open parcel; it is not closed by the loss.
        $this->assertSame($keptStatus, $order->fresh()->status);
        Notification::assertNotSentTo($sellers[0], ParcelLostConfirmed::class);
        Notification::assertSentTo($sellers[1], ParcelLostConfirmed::class);

        // Once the other parcel completes, the order is no longer "lost" or "open".
        $kept->forceFill(['status' => OrderStatus::Completed->value])->save();
        $this->assertSame(OrderStatus::Completed, $order->fresh()->load('sellerOrders')->computeRollupStatus());
    }

    // ── 5. Recovery ────────────────────────────────────────────────────────

    public function test_a_found_parcel_is_recorded_by_a_verified_scan_and_the_order_stays_closed(): void
    {
        $this->dispatchedHandoff();
        $stock = Product::query()->where('seller_id', $this->parcel->seller_id)->value('stock_quantity');
        $case = $this->confirmLost($this->parcel);
        app(ParcelLossService::class)->recordCompensationPayment($case, $this->admin, 'BANK-1', now()->toDateString());
        $hubId = (int) LogisticsHub::query()->where('logistics_provider_id', $this->bulacan->id)->operational()->value('id');
        $scan = fn (array $extra = []) => $this->actingAs($this->bulacan, 'logistics')->postJson(
            route('logistics.lost-parcels.scan.verify', $case), $extra + ['tracking_number' => $this->parcel->tracking_number, 'hub' => $hubId]);

        // It appears on the company's page; a wrong tracking number or another company's hub is refused.
        $this->actingAs($this->bulacan, 'logistics')->get(route('logistics.lost-parcels.index'))->assertOk()->assertSee('Record as found')->assertSee('BANK-1');
        $scan(['tracking_number' => 'TRK-WRONG'])->assertStatus(422);
        // A company that never touched this parcel cannot even see the case. The receiving company may find it, but only at its own hub.
        $laguna = $this->account(LogisticsProvider::class, 'logistics-laguna');
        $this->actingAs($laguna, 'logistics')->postJson(route('logistics.lost-parcels.scan.verify', $case), ['tracking_number' => $this->parcel->tracking_number, 'hub' => $hubId])->assertNotFound();
        $this->actingAs($this->metro, 'logistics')->postJson(route('logistics.lost-parcels.scan.verify', $case), ['tracking_number' => $this->parcel->tracking_number, 'hub' => $hubId])->assertStatus(422);

        $token = $scan()->assertOk()->json('verification_token');
        $this->actingAs($this->bulacan, 'logistics')->post(route('logistics.lost-parcels.recover', $case), ['verification_token' => 'forged', 'hub' => $hubId])->assertSessionHasErrors();
        $this->assertNull($case->fresh()->recovered_at);
        $this->post(route('logistics.lost-parcels.recover', $case), ['verification_token' => $token, 'hub' => $hubId])->assertSessionHasNoErrors();
        // A repeat of the same find records nothing more, and the single-use token cannot start a second one.
        $firstFound = $case->fresh()->recovered_at;
        $this->post(route('logistics.lost-parcels.recover', $case), ['verification_token' => $token, 'hub' => $hubId])->assertSessionHasNoErrors();
        $this->assertEquals($firstFound, $case->fresh()->recovered_at);
        $this->assertSame(1, OperationsAuditLog::query()->where('action', 'lost_parcel_recovered')->count());
        Notification::assertSentToTimes($this->admin, LostParcelFound::class, 1);

        $case = $case->fresh();
        $parcel = $this->parcel->fresh();
        $this->assertNotNull($case->recovered_at);
        $this->assertSame($hubId, (int) $case->recovered_hub_id);
        $this->assertSame($hubId, (int) $parcel->custody_hub_id, 'The verified scan records where it is.');
        // Never reopened, never restocked, never delivered.
        $this->assertSame(OrderStatus::Lost, $parcel->status);
        $this->assertSame($stock, Product::query()->where('seller_id', $parcel->seller_id)->value('stock_quantity'));
        $this->assertSame(0, CodRemittance::query()->count());
        try {
            ParcelIncidentService::assertOperationsAllowed($parcel);
            $this->fail('A found parcel stays blocked.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
        Notification::assertSentTo($this->admin, LostParcelFound::class);

        // Admin decides; the decision is recorded once and changes nothing else.
        $this->actingAs($this->admin, 'admin')->post(route('admin.lost-parcels.disposition', $case), ['disposition' => 'return_to_seller', 'note' => 'Seller will collect it.'])->assertSessionHasNoErrors();
        $this->post(route('admin.lost-parcels.disposition', $case), ['disposition' => 'written_off', 'note' => 'Changed my mind'])->assertSessionHasErrors('disposition');
        $this->assertSame('return_to_seller', $case->fresh()->disposition);
        $this->assertSame(OrderStatus::Lost, $parcel->fresh()->status);
        $this->assertSame(1, OperationsAuditLog::query()->where('action', 'lost_parcel_disposition')->count());

        // The compensation was already paid, so any correction is an audited adjustment.
        $this->post(route('admin.lost-parcels.adjust', $case), ['amount' => '-200.00', 'reason' => 'Seller returned part of it'])->assertSessionHasNoErrors();
        $this->assertSame(30000, $case->fresh('adjustments')->netCompensationMinor());
        $this->assertSame(50000, $case->fresh()->merchandise_minor);
    }

    // ── tracking ───────────────────────────────────────────────────────────

    public function test_buyer_and_seller_tracking_show_delayed_investigating_lost_and_recovered(): void
    {
        config(['transfers.overdue_after_hours' => 6]);
        $this->dispatchedHandoff();
        $buyer = $this->parcel->order->buyer;
        $seller = $this->parcel->seller;
        $order = $this->parcel->order;
        $see = function (string $state, bool $buyerSide) use ($order, $buyer, $seller): void {
            $page = $buyerSide
                ? $this->actingAs($buyer, 'buyer')->get(route('buyer.orders.show', $order))
                : $this->actingAs($seller, 'seller')->get(route('seller.orders.show', $this->parcel));
            $page->assertOk()->assertSee('data-transit-alert="'.$state.'"', false);
        };

        // On time: nothing unusual is shown.
        $this->actingAs($buyer, 'buyer')->get(route('buyer.orders.show', $order))->assertDontSee('data-transit-alert', false);
        $this->travel(7)->hours();
        $see('delayed', true);
        $see('delayed', false);
        $this->actingAs($buyer, 'buyer')->get(route('buyer.orders.show', $order))->assertSee('This is not a loss.');

        $incident = $this->reportMissing($this->parcel);
        $see('investigating', true);
        $see('investigating', false);

        $this->actingAs($this->admin, 'admin')->post(route('admin.incidents.resolve', $incident), ['resolution' => 'CLOSE_LOST', 'resolution_note' => 'Lost']);
        $see('lost', true);
        $see('lost', false);
        $this->actingAs($buyer, 'buyer')->get(route('buyer.orders.show', $order))->assertSee('You paid nothing');

        $case = ParcelLossCase::query()->sole();
        $hubId = (int) LogisticsHub::query()->where('logistics_provider_id', $this->bulacan->id)->operational()->value('id');
        $token = $this->actingAs($this->bulacan, 'logistics')->postJson(route('logistics.lost-parcels.scan.verify', $case), ['tracking_number' => $this->parcel->tracking_number, 'hub' => $hubId])->json('verification_token');
        $this->post(route('logistics.lost-parcels.recover', $case), ['verification_token' => $token, 'hub' => $hubId])->assertSessionHasNoErrors();
        $see('recovered', true);
        $see('recovered', false);
        $this->actingAs($buyer, 'buyer')->get(route('buyer.orders.show', $order))->assertSee('The parcel was found at a hub');
    }

    // ── 4. Escalation ──────────────────────────────────────────────────────

    public function test_overdue_transfers_use_their_mode_threshold_then_escalate_to_admin_once_and_never_mark_lost(): void
    {
        config(['transfers.overdue_after_hours' => 6, 'transfers.overdue_hours.SEA' => 30, 'transfers.escalate_after_hours' => 10]);
        $handoff = $this->dispatchedHandoff();
        $stuck = app(StuckTransferService::class);
        $this->assertSame('ROAD', $stuck->modeFor($handoff), 'No saved connection between these hubs: ROAD.');

        $this->travel(7)->hours(); // Past the road limit (6 h), not yet escalated (6 + 10 = 16 h).
        $this->artisan('transfers:flag-overdue')->assertSuccessful();
        Notification::assertSentToTimes($this->bulacan, TransferOverdue::class, 1);
        Notification::assertNotSentTo($this->admin, TransferEscalated::class);

        $this->travel(10)->hours(); // 17 h: escalate.
        $this->artisan('transfers:flag-overdue')->assertSuccessful();
        $this->artisan('transfers:flag-overdue')->assertSuccessful();
        Notification::assertSentToTimes($this->admin, TransferEscalated::class, 1);
        Notification::assertSentToTimes($this->bulacan, TransferOverdue::class, 1);

        // Nothing was decided: still in transit, still the sender's custody, no exception, no loss.
        $this->assertSame(OrderStatus::InTransit, $this->parcel->fresh()->status);
        $this->assertSame((int) $this->bulacan->id, $this->parcel->fresh()->currentCustodyProviderId());
        $this->assertSame(0, Incidents::query()->count());
        $this->assertSame(0, ParcelLossCase::query()->count());
        $this->assertSame(ParcelHandoffStatus::InTransit, $handoff->fresh()->status);

        // A SEA connection gets its own, longer limit. (The seeded road link between these hubs is replaced.)
        TransportConnection::query()
            ->where('origin_hub_id', $handoff->sending_hub_id)->where('destination_hub_id', $handoff->receiving_hub_id)->update(['transport_mode' => 'SEA']);
        $this->assertSame('SEA', $stuck->modeFor($handoff));
        $this->assertSame(30, $stuck->overdueAfterFor($handoff));
        $this->assertFalse($stuck->isOverdue($handoff->fresh()), '17 hours is inside the 30-hour sea limit.');
    }

    // ── financial correctness ──────────────────────────────────────────────

    public function test_confirming_a_loss_leaves_every_existing_money_figure_unchanged(): void
    {
        $this->dispatchedHandoff();
        $seller = $this->parcel->seller;
        $platform = app(PlatformFinanceService::class);
        $payouts = app(SellerPayoutRequestService::class);
        $finance = app(LogisticsFinanceService::class);
        $before = [
            'position' => $platform->position(), 'earnings' => $platform->earnings(false), 'sellerBalance' => $payouts->availableBalance($seller),
            'bulacan' => $finance->statement($this->bulacan), 'metro' => $finance->statement($this->metro),
        ];

        $case = $this->confirmLost($this->parcel);
        app(ParcelLossService::class)->recordCompensationPayment($case, $this->admin, 'BANK-9', now()->toDateString());

        $this->assertEquals($before['position'], $platform->position());
        $this->assertEquals($before['earnings'], $platform->earnings(false));
        $this->assertSame($before['sellerBalance'], $payouts->availableBalance($seller));
        $this->assertEquals($before['bulacan'], $finance->statement($this->bulacan));
        $this->assertEquals($before['metro'], $finance->statement($this->metro));
        $this->assertSame(0, SellerDebt::query()->count());
        $this->assertSame(0, SellerSettlement::query()->count());
        $this->assertDatabaseCount('logistics_return_charges', 0);

        // The Seller sees the obligation in its own place, separate from the Zefanya balance.
        $this->actingAs($seller, 'seller')->get(route('seller.earnings.index'))->assertOk()
            ->assertSee('Lost parcels')->assertSee('₱500.00')->assertSee('Paid · ref BANK-9');
        // The company sees what it owes.
        $this->actingAs($this->bulacan, 'logistics')->get(route('logistics.lost-parcels.index'))->assertOk()->assertSee('₱500.00')->assertSee('Paid');
    }

    public function test_a_lost_parcel_cannot_be_handed_off_assigned_delivered_collected_or_confirmed(): void
    {
        $this->dispatchedHandoff();
        $this->confirmLost($this->parcel);
        $parcel = $this->parcel->fresh();

        // No dispatch: not as a new handoff, not as a transfer batch, not into the receiving hub.
        $this->actingAs($this->bulacan, 'logistics')->post(route('logistics.handoffs.store'), ['seller_order_id' => $parcel->id])->assertSessionHasErrors();
        $this->assertSame(1, ParcelHandoff::query()->count());
        $handoff = ParcelHandoff::query()->sole();
        $this->actingAs($this->metro, 'logistics')->postJson(route('logistics.handoffs.scan.verify', $handoff), [
            'tracking_number' => $parcel->tracking_number, 'phase' => 'receive',
        ])->assertStatus(422);

        // No assignment of a delivery Rider, and no delivery start.
        $rider = $this->courierFor('rider-malolos');
        $this->actingAs($this->bulacan, 'logistics')->post(route('logistics.parcel-sorting.assignDelivery', $parcel), ['courier_id' => $rider->id, 'reason' => 'x'])
            ->assertSessionHas('error');
        $this->assertNull($parcel->fresh()->delivery_courier_id);
        $this->actingAs($rider->rider, 'rider')->postJson(route('rider.scan.verify'), ['tracking_number' => $parcel->tracking_number])
            ->assertJsonMissingPath('actions.0.verification_token');

        // No COD: the payment is closed and nothing can be remitted for it.
        $this->assertSame('cancelled', $parcel->payment->fresh()->status->value);
        $this->assertDatabaseCount('cod_remittances', 0);

        // The Buyer cannot confirm receipt of a parcel they never got, and cannot cancel or return it.
        $buyer = $parcel->order->buyer;
        $this->actingAs($buyer, 'buyer')->post(route('buyer.orders.confirmReceived', $parcel->order))->assertSessionHasErrors();
        $this->assertSame(OrderStatus::Lost, $parcel->fresh()->status);
        $this->assertDatabaseCount('parcel_returns', 0);

        // And no one can move it by hand: every status change from LOST is refused.
        foreach ([OrderStatus::Delivered, OrderStatus::Completed, OrderStatus::Sorted, OrderStatus::Cancelled, OrderStatus::Returned, OrderStatus::InTransit] as $to) {
            try {
                app(SellerOrderStatusService::class)->transition($parcel->fresh(), $to, actor: OrderActor::Logistics, actorId: $this->bulacan->id);
                $this->fail('LOST must not move to '.$to->value);
            } catch (\Throwable $e) {
                $this->assertNotInstanceOf(AssertionFailedError::class, $e);
            }
        }
        $this->assertSame(OrderStatus::Lost, $parcel->fresh()->status);
        $this->assertSame(0, $parcel->fresh()->statusHistories()->whereIn('to_status', ['DELIVERED', 'COMPLETED'])->count());
    }

    // ── helpers ────────────────────────────────────────────────────────────

    protected function account(string $model, string $slug)
    {
        return $model::query()->where('email', DeliveryScenarioSeeder::email($slug))->firstOrFail();
    }

    protected function courierFor(string $slug): Courier
    {
        return Courier::query()->where('rider_id', $this->account(Rider::class, $slug)->id)->sole();
    }

    /** Places an order for $sellerSlug and returns it picked up and scanned into its origin hub. */
    protected function orderTo(string $sellerSlug, string $buyerSlug, LogisticsProvider $carrier, string $riderSlug): SellerOrder
    {
        $seller = $this->account(Seller::class, $sellerSlug);
        $buyer = $this->account(Buyer::class, $buyerSlug);
        $product = Product::query()->where('seller_id', $seller->id)->sole();
        $this->actingAs($buyer, 'buyer')->postJson(route('buyer.cart.add'), ['product_id' => $product->id, 'quantity' => 1])->assertOk();
        $cart = CartItem::query()->where('buyer_id', $buyer->id)->where('product_id', $product->id)->sole();
        $this->get(route('buyer.checkout.index', ['items' => [$cart->id]]))->assertOk();
        $this->post(route('buyer.checkout.store'), [
            'checkout_token' => CheckoutToken::issue($buyer->id)->token,
            'carriers' => [$seller->id => $carrier->id],
        ])->assertSessionHasNoErrors();
        $parcel = SellerOrder::query()->where('seller_id', $seller->id)->latest('id')->firstOrFail();

        $this->actingAs($seller, 'seller')->post(route('seller.orders.accept', $parcel))->assertSessionHasNoErrors();
        $this->post(route('seller.orders.pack', $parcel))->assertSessionHasNoErrors();
        $this->post(route('seller.orders.markReady', $parcel))->assertSessionHasNoErrors();

        return $this->pickUpAndReceive($parcel, $carrier, $riderSlug);
    }

    /** The pickup Rider collects the parcel and its origin hub scans it in. */
    protected function pickUpAndReceive(SellerOrder $parcel, LogisticsProvider $carrier, string $riderSlug): SellerOrder
    {
        $pickup = $this->courierFor($riderSlug);
        $this->actingAs($carrier, 'logistics')
            ->post(route('logistics.incoming-parcels.assignPickupRider', $parcel), ['courier_id' => $pickup->id, 'reason' => 'Origin collection'])
            ->assertSessionHasNoErrors();
        $token = $this->actingAs($pickup->rider, 'rider')->postJson(route('rider.scan.verify'), ['tracking_number' => $parcel->tracking_number])
            ->assertOk()->json('actions.0.verification_token');
        $this->post(route('rider.pickups.confirm', $parcel), ['verification_token' => $token])->assertSessionHasNoErrors();
        $token = $this->actingAs($carrier, 'logistics')->postJson(route('logistics.scan.verify'), ['tracking_number' => $parcel->tracking_number])
            ->assertOk()->json('actions.0.verification_token');
        $this->post(route('logistics.incoming-parcels.toSortingCenter', $parcel), ['verification_token' => $token])->assertSessionHasNoErrors();

        return $parcel->fresh();
    }

    /**
     * One internal batch, Lumban to Pila, scanned out and dispatched with its Courier.
     *
     * @param  list<SellerOrder>  $parcels
     */
    protected function dispatchBatch(LogisticsProvider $company, array $parcels): Manifest
    {
        $hub = fn (string $name) => LogisticsHub::query()->where('logistics_provider_id', $company->id)->where('name', $name)->firstOrFail();
        $this->actingAs($company, 'logistics')->post(route('logistics.manifests.store'), [
            'origin_hub_id' => $hub('Lumban Station Hub')->id, 'destination_hub_id' => $hub('Pila Station Hub')->id,
        ])->assertSessionHasNoErrors();
        $manifest = Manifest::query()->latest('id')->firstOrFail();
        foreach ($parcels as $parcel) {
            $this->post(route('logistics.manifests.items.store', $manifest), ['seller_order_id' => $parcel->id])->assertSessionHasNoErrors();
        }
        if ($manifest->fresh()->transport_required) {
            $courier = TransferCourier::query()->where('logistics_provider_id', $company->id)->active()->firstOrFail();
            $this->post(route('logistics.manifests.transport', $manifest), ['transfer_courier_id' => $courier->id])->assertSessionHasNoErrors();
        }
        $tokens = [];
        foreach ($parcels as $parcel) {
            $item = $manifest->items()->where('seller_order_id', $parcel->id)->sole();
            $tokens[$item->id] = $this->postJson(route('logistics.manifests.scan.verify', $manifest), [
                'tracking_number' => $parcel->tracking_number, 'phase' => 'dispatch',
            ])->assertOk()->json('verification_token');
        }
        $this->post(route('logistics.manifests.dispatch', $manifest), ['tokens' => $tokens])->assertSessionHasNoErrors();

        return $manifest->fresh();
    }

    protected function orderToMalolos(): SellerOrder
    {
        return $this->orderTo('seller-bulacan', 'buyer-qc', $this->bulacan, 'rider-malolos');
    }

    protected function requestHandoff(?SellerOrder $parcel = null): ParcelHandoff
    {
        $parcel ??= $this->parcel;
        $this->actingAs($this->bulacan, 'logistics')->post(route('logistics.handoffs.store'), ['seller_order_id' => $parcel->id])
            ->assertSessionHasNoErrors();

        return ParcelHandoff::query()->where('seller_order_id', $parcel->id)->latest('id')->firstOrFail();
    }

    protected function dispatchedHandoff(?SellerOrder $parcel = null): ParcelHandoff
    {
        $parcel ??= $this->parcel;
        $handoff = $this->requestHandoff($parcel);
        $this->actingAs($this->metro, 'logistics')->post(route('logistics.handoffs.accept', $handoff))->assertSessionHasNoErrors();
        if ($handoff->fresh()->transport_required) {
            $courier = TransferCourier::query()->where('logistics_provider_id', $this->bulacan->id)->active()->firstOrFail();
            $this->actingAs($this->bulacan, 'logistics')->post(route('logistics.handoffs.courier', $handoff), ['transfer_courier_id' => $courier->id])->assertSessionHasNoErrors();
        }
        $token = $this->actingAs($this->bulacan, 'logistics')->postJson(route('logistics.handoffs.scan.verify', $handoff), [
            'tracking_number' => $parcel->tracking_number, 'phase' => 'dispatch',
        ])->assertOk()->json('verification_token');
        $this->post(route('logistics.handoffs.dispatch', $handoff), ['verification_token' => $token])->assertSessionHasNoErrors();
        $this->assertSame(OrderStatus::InTransit, $parcel->fresh()->status);

        return $handoff->fresh();
    }

    /** The custodian's "missing" report that Admin then confirms lost. */
    protected function reportMissing(SellerOrder $parcel, ?LogisticsProvider $by = null): Incidents
    {
        return app(ParcelIncidentService::class)->reportOperational($parcel->fresh(), $by ?? $this->bulacan, [
            'type' => 'missing', 'reason' => 'Never arrived',
        ]);
    }

    protected function confirmLost(SellerOrder $parcel, ?int $liable = null, ?LogisticsProvider $reporter = null): ParcelLossCase
    {
        $incident = $this->reportMissing($parcel, $reporter ?? $this->bulacan);
        app(ParcelIncidentService::class)->resolve($incident, $this->admin, [
            'event' => 'CLOSE_LOST', 'reason' => 'Confirmed lost',
            'context' => $liable !== null ? ['liable_provider_id' => $liable] : [],
        ]);

        return ParcelLossCase::query()->where('seller_order_id', $parcel->id)->sole();
    }

    protected function sellerOrderCount(): int
    {
        return Order::query()->count();
    }
}
