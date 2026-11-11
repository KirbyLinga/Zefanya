<?php

namespace Tests\Feature\Logistics;

use App\Enums\OrderStatus;
use App\Enums\ParcelHandoffStatus;
use App\Models\Admin\Admin;
use App\Models\Admin\PlatformSetting;
use App\Models\Buyer\Buyer;
use App\Models\Buyer\CartItem;
use App\Models\Buyer\CheckoutToken;
use App\Models\Catalog\Product;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\ParcelHandoff;
use App\Models\Rider\Courier;
use App\Models\Rider\Rider;
use App\Models\Seller\Seller;
use App\Models\Shared\Incidents;
use App\Models\Shared\SellerOrder;
use App\Notifications\Logistics\TransferOverdue;
use App\Services\Logistics\ParcelIncidentService;
use Database\Seeders\DeliveryScenarioSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Cross-provider handoffs on the seeded Bulacan → Quezon City route:
 * controlled cancellation, verified recovery, overdue alerts, and the
 * read-only Admin Transport Overview.
 */
class HandoffCancellationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private LogisticsProvider $bulacan;

    private LogisticsProvider $metro;

    private SellerOrder $parcel;

    protected function setUp(): void
    {
        parent::setUp();
        putenv(DeliveryScenarioSeeder::MAILBOX_ENV);
        Notification::fake();
        $this->withoutVite();
        Admin::factory()->create(['email' => 'admin@zefanya.shop']);
        $this->seed(DeliveryScenarioSeeder::class);

        $this->bulacan = $this->account(LogisticsProvider::class, 'logistics-bulacan');
        $this->metro = $this->account(LogisticsProvider::class, 'logistics-metro');
        $this->parcel = $this->parcelAtMalolos();
    }

    public function test_cancelling_before_dispatch_puts_the_parcel_back_in_the_sending_queue(): void
    {
        $handoff = $this->requestHandoff();
        $this->actingAs($this->metro, 'logistics')->post(route('logistics.handoffs.accept', $handoff))->assertSessionHasNoErrors();

        // Only the sending company can cancel, and a reason is required.
        $this->post(route('logistics.handoffs.cancel', $handoff), ['cancellation_reason' => 'Mine now'])->assertForbidden();
        $this->actingAs($this->bulacan, 'logistics')->post(route('logistics.handoffs.cancel', $handoff), ['cancellation_reason' => ''])
            ->assertSessionHasErrors('cancellation_reason');
        $this->post(route('logistics.handoffs.cancel', $handoff), ['cancellation_reason' => 'Truck unavailable today'])
            ->assertRedirect(route('logistics.handoffs.show', $handoff))->assertSessionHasNoErrors();

        $handoff->refresh();
        $this->assertSame(ParcelHandoffStatus::Cancelled, $handoff->status);
        $this->assertFalse($handoff->awaitsRecovery(), 'It never left the hub.');
        $parcel = $this->parcel->fresh();
        $this->assertSame((int) $this->bulacan->id, $parcel->currentCustodyProviderId());
        $this->assertFalse(app(ParcelIncidentService::class)->hasOpenIncident($parcel));

        // The parcel can be handed off again.
        $this->assertNotSame($handoff->id, $this->requestHandoff()->id);
    }

    public function test_cancelling_after_dispatch_holds_the_parcel_until_a_verified_recovery_scan(): void
    {
        $handoff = $this->dispatchedHandoff();
        $this->actingAs($this->bulacan, 'logistics')->post(route('logistics.handoffs.cancel', $handoff), ['cancellation_reason' => 'Ferry cancelled'])
            ->assertSessionHasNoErrors();
        $this->post(route('logistics.handoffs.cancel', $handoff), ['cancellation_reason' => 'Ferry cancelled'])->assertSessionHasNoErrors();

        // Nothing is assumed: still in transit, still the sender's custody, held as an exception.
        $handoff->refresh();
        $parcel = $this->parcel->fresh();
        $this->assertTrue($handoff->awaitsRecovery());
        $this->assertSame(OrderStatus::InTransit, $parcel->status);
        $this->assertSame((int) $this->bulacan->id, $parcel->currentCustodyProviderId());
        $incident = Incidents::query()->where('incident_seller_order_id', $parcel->id)->sole();
        $this->assertSame('failed_transfer', $incident->type);

        // Its route cannot resume before the location is verified.
        try {
            app(ParcelIncidentService::class)->resolveForProvider($incident, $this->bulacan, ['event' => 'RESUME_ORIGINAL_ROUTE', 'reason' => 'Try again']);
            $this->fail('A route decision must wait for the recovery scan.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('must be verified before its route can resume', $e->errors()['resolution'][0]);
        }

        // The receiving company cannot receive it, and cannot record a recovery.
        $this->actingAs($this->metro, 'logistics')->postJson(route('logistics.handoffs.scan.verify', $handoff), [
            'tracking_number' => $parcel->tracking_number, 'phase' => 'receive',
        ])->assertStatus(422);
        $this->postJson(route('logistics.handoffs.scan.verify', $handoff), [
            'tracking_number' => $parcel->tracking_number, 'phase' => 'recover',
        ])->assertStatus(422);
        $this->post(route('logistics.handoffs.recover', $handoff), ['verification_token' => 'forged'])->assertForbidden();
        $outsider = $this->account(LogisticsProvider::class, 'logistics-laguna');
        $this->actingAs($outsider, 'logistics')->post(route('logistics.handoffs.recover', $handoff), ['verification_token' => 'x'])->assertNotFound();

        // A forged token is refused; a real single-use scan at the sending hub records it.
        $this->actingAs($this->bulacan, 'logistics')->get(route('logistics.handoffs.show', $handoff))->assertOk()->assertSee('Recover the parcel');
        $this->post(route('logistics.handoffs.recover', $handoff), ['verification_token' => 'forged'])->assertSessionHasErrors();
        $token = $this->postJson(route('logistics.handoffs.scan.verify', $handoff), [
            'tracking_number' => $parcel->tracking_number, 'phase' => 'recover',
        ])->assertOk()->json('verification_token');
        $this->post(route('logistics.handoffs.recover', $handoff), ['verification_token' => $token])->assertSessionHasNoErrors();
        $this->post(route('logistics.handoffs.recover', $handoff), ['verification_token' => $token])->assertSessionHasNoErrors();

        $handoff->refresh();
        $parcel = $this->parcel->fresh();
        $this->assertNotNull($handoff->recovered_at);
        $this->assertFalse($handoff->awaitsRecovery());
        $this->assertSame(OrderStatus::ArrivedAtHub, $parcel->status);
        $this->assertSame((int) $handoff->sending_hub_id, (int) $parcel->custody_hub_id);
        $this->assertSame(1, $incident->events()->where('event', 'recovery_received')->count());
        $this->assertTrue(app(ParcelIncidentService::class)->hasOpenIncident($parcel), 'Its exception still decides what happens next.');
    }

    public function test_an_overdue_handoff_is_flagged_for_both_companies_and_the_sender_is_told_once(): void
    {
        config(['transfers.overdue_after_hours' => 6]);
        $handoff = $this->dispatchedHandoff();
        $this->travel(7)->hours();

        foreach ([$this->bulacan, $this->metro] as $company) {
            $this->actingAs($company, 'logistics')->get(route('logistics.dashboard'))->assertOk()
                ->assertViewHas('overdueTransferCount', 1)->assertSee('Handoff #'.$handoff->id);
        }
        $this->artisan('transfers:flag-overdue')->assertSuccessful();
        $this->artisan('transfers:flag-overdue')->assertSuccessful();
        Notification::assertSentToTimes($this->bulacan, TransferOverdue::class, 1);
        Notification::assertNotSentTo($this->metro, TransferOverdue::class);
        $this->assertNotNull($handoff->fresh()->overdue_notified_at);
        $this->assertSame(ParcelHandoffStatus::InTransit, $handoff->fresh()->status, 'Flagging moves nothing.');
    }

    public function test_admin_transport_overview_is_read_only_and_shows_transit_and_recovery(): void
    {
        $handoff = $this->dispatchedHandoff();
        $admin = Admin::query()->where('email', 'admin@zefanya.shop')->sole();

        $this->actingAs($admin, 'admin')->get(route('admin.transport.index'))->assertOk()
            ->assertSee('Transport Overview')->assertSee('Handoff #'.$handoff->id)->assertSee('On time')
            ->assertDontSee('/cancel', false)->assertDontSee('/recover', false)->assertDontSee('/dispatch', false);

        $this->actingAs($this->bulacan, 'logistics')->post(route('logistics.handoffs.cancel', $handoff), ['cancellation_reason' => 'Road closed']);
        $this->actingAs($admin, 'admin')->get(route('admin.transport.index'))->assertOk()
            ->assertSee('Cancelled after dispatch, awaiting a recovery scan')->assertSee('Road closed');

        // A Logistics company cannot open the Admin page.
        auth('admin')->logout();
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->bulacan, 'logistics')->get(route('admin.transport.index'))->assertRedirect();
    }

    public function test_admin_is_prompted_until_payment_instructions_are_added(): void
    {
        $admin = Admin::query()->where('email', 'admin@zefanya.shop')->sole();
        PlatformSetting::query()->where('key', 'remittance_payment_details')->update(['value' => '']);

        $this->actingAs($admin, 'admin')->get(route('admin.settings.index'))->assertOk()
            ->assertSee('Payment instructions for Logistics companies are empty.');
        $this->get(route('admin.logistics-remittances.index'))->assertOk()->assertSee('cannot see where to send payments yet');

        PlatformSetting::query()->where('key', 'remittance_payment_details')->update(['value' => 'GCash: Zefanya, 0917 000 0000. Reference: your company name.']);
        $this->get(route('admin.settings.index'))->assertDontSee('Payment instructions for Logistics companies are empty.');
        $this->get(route('admin.logistics-remittances.index'))->assertDontSee('cannot see where to send payments yet');
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function account(string $model, string $slug)
    {
        return $model::query()->where('email', DeliveryScenarioSeeder::email($slug))->firstOrFail();
    }

    private function courierFor(string $slug): Courier
    {
        return Courier::query()->where('rider_id', $this->account(Rider::class, $slug)->id)->sole();
    }

    /** A Bulacan → Quezon City order, picked up and received at Malolos Station Hub. */
    private function parcelAtMalolos(): SellerOrder
    {
        $seller = $this->account(Seller::class, 'seller-bulacan');
        $buyer = $this->account(Buyer::class, 'buyer-qc');
        $product = Product::query()->where('seller_id', $seller->id)->sole();
        $this->actingAs($buyer, 'buyer')->postJson(route('buyer.cart.add'), ['product_id' => $product->id, 'quantity' => 1])->assertOk();
        $cart = CartItem::query()->where('buyer_id', $buyer->id)->sole();
        $this->get(route('buyer.checkout.index', ['items' => [$cart->id]]))->assertOk();
        $this->post(route('buyer.checkout.store'), [
            'checkout_token' => CheckoutToken::issue($buyer->id)->token,
            'carriers' => [$seller->id => $this->bulacan->id],
        ])->assertSessionHasNoErrors();
        $parcel = SellerOrder::query()->sole();

        $this->actingAs($seller, 'seller')->post(route('seller.orders.accept', $parcel))->assertSessionHasNoErrors();
        $this->post(route('seller.orders.pack', $parcel))->assertSessionHasNoErrors();
        $this->post(route('seller.orders.markReady', $parcel))->assertSessionHasNoErrors();
        $pickup = $this->courierFor('rider-malolos');
        $this->actingAs($this->bulacan, 'logistics')
            ->post(route('logistics.incoming-parcels.assignPickupRider', $parcel), ['courier_id' => $pickup->id, 'reason' => 'Origin collection'])
            ->assertSessionHasNoErrors();
        $token = $this->actingAs($pickup->rider, 'rider')->postJson(route('rider.scan.verify'), ['tracking_number' => $parcel->tracking_number])
            ->assertOk()->json('actions.0.verification_token');
        $this->post(route('rider.pickups.confirm', $parcel), ['verification_token' => $token])->assertSessionHasNoErrors();
        $token = $this->actingAs($this->bulacan, 'logistics')->postJson(route('logistics.scan.verify'), ['tracking_number' => $parcel->tracking_number])
            ->assertOk()->json('actions.0.verification_token');
        $this->post(route('logistics.incoming-parcels.toSortingCenter', $parcel), ['verification_token' => $token])->assertSessionHasNoErrors();

        return $parcel->fresh();
    }

    private function requestHandoff(): ParcelHandoff
    {
        $this->actingAs($this->bulacan, 'logistics')->post(route('logistics.handoffs.store'), ['seller_order_id' => $this->parcel->id])
            ->assertSessionHasNoErrors();

        return ParcelHandoff::query()->latest('id')->firstOrFail();
    }

    private function dispatchedHandoff(): ParcelHandoff
    {
        $handoff = $this->requestHandoff();
        $this->actingAs($this->metro, 'logistics')->post(route('logistics.handoffs.accept', $handoff))->assertSessionHasNoErrors();
        $token = $this->actingAs($this->bulacan, 'logistics')->postJson(route('logistics.handoffs.scan.verify', $handoff), [
            'tracking_number' => $this->parcel->tracking_number, 'phase' => 'dispatch',
        ])->assertOk()->json('verification_token');
        $this->post(route('logistics.handoffs.dispatch', $handoff), ['verification_token' => $token])->assertSessionHasNoErrors();
        $this->assertSame(OrderStatus::InTransit, $this->parcel->fresh()->status);

        return $handoff->fresh();
    }
}
