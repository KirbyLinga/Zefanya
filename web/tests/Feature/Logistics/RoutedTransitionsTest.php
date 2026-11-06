<?php

namespace Tests\Feature\Logistics;

use App\Enums\OrderStatus;
use App\Enums\ParcelReturnStatus;
use App\Http\Controllers\Buyer\CheckoutController;
use App\Models\Admin\Admin;
use App\Models\Buyer\Buyer;
use App\Models\Buyer\CartItem;
use App\Models\Buyer\CheckoutToken;
use App\Models\Catalog\Product;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\LogisticsProviderLink;
use App\Models\Logistics\TransportConnection;
use App\Models\Rider\Courier;
use App\Models\Rider\Rider;
use App\Models\Seller\Seller;
use App\Models\Shared\SellerOrder;
use App\Services\Buyer\CheckoutCarrierService;
use App\Services\Logistics\ManifestService;
use App\Services\Logistics\ParcelHandoffService;
use App\Services\Logistics\ParcelReturnService;
use App\Services\Logistics\ProviderHandoffSchemaService;
use App\Services\Logistics\RoutePlannerService;
use Database\Seeders\DeliveryScenarioSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\Support\BootstrapsCrossProviderHandoffs;
use Tests\TestCase;

class RoutedTransitionsTest extends TestCase
{
    use BootstrapsCrossProviderHandoffs;
    use LazilyRefreshDatabase;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        putenv(DeliveryScenarioSeeder::MAILBOX_ENV);
        Notification::fake();
        $this->withoutVite();
        $this->admin = Admin::factory()->create();
        $this->seed(DeliveryScenarioSeeder::class);
    }

    public function test_sorting_waits_for_the_final_destination_hub(): void
    {
        $origin = $this->provider('logistics-bulacan');
        $destination = $this->provider('logistics-metro');
        $parcel = $this->parcelAtOriginStation('seller-bulacan', 'buyer-qc', $origin, 'rider-malolos');

        $scan = $this->scan($origin, $parcel);
        $this->assertSame('transfer', $scan['context']);
        $this->assertSame(['open_handoffs', 'open_details'], array_column($scan['actions'], 'key'));
        $this->assertStringContainsString('Quezon City Station Hub', $scan['message']);

        $this->actingAs($origin, 'logistics')->post(route('logistics.parcel-sorting.sort', $parcel), ['verification_token' => 'unused'])
            ->assertSessionHas('error', fn (string $error): bool => str_contains($error, 'Transfer this parcel to Quezon City Station Hub'));
        $this->assertSame(OrderStatus::AtSortingCenter, $parcel->fresh()->status);
        $this->actingAs($origin, 'logistics')->get(route('logistics.parcel-sorting.index'))
            ->assertOk()->assertSee('Transfer this parcel to Quezon City Station Hub')->assertDontSee('Scan to confirm');

        $this->handoff($origin, $destination, $parcel);

        $scan = $this->scan($destination, $parcel);
        $this->assertSame(['sort_parcel', 'open_details'], array_column($scan['actions'], 'key'));
        $this->post(route('logistics.parcel-sorting.sort', $parcel), ['verification_token' => $scan['actions'][0]['verification_token']])
            ->assertSessionDoesntHaveErrors();
        $this->assertSame(OrderStatus::Sorted, $parcel->fresh()->status);
    }

    public function test_local_parcel_can_still_be_sorted_at_its_only_hub(): void
    {
        $provider = $this->provider('logistics-laguna');
        $parcel = $this->parcelAtOriginStation('seller-pila', 'buyer-pila', $provider, 'rider-pila');

        $this->assertSame(['sort_parcel', 'open_details'], array_column($this->scan($provider, $parcel)['actions'], 'key'));
    }

    public function test_parcel_travels_through_an_intermediate_provider_with_no_direct_partnership(): void
    {
        [$batangas, $cebu, $davao] = $this->chainNetwork();
        $seller = $this->account(Seller::class, 'seller-batangas');
        $buyer = $this->account(Buyer::class, 'buyer-davao');

        $quote = app(CheckoutCarrierService::class)->optionsForSellerGroup($buyer, $seller, 50000, 800);
        $this->assertTrue($quote['available']);
        $this->assertSame($davao->id, $quote['carriers'][0]['destination_provider_id']);

        $parcel = $this->parcelAtOriginStation('seller-batangas', 'buyer-davao', $batangas, 'rider-batangas-city');
        $this->assertCount(4, $parcel->parcelRoute->legs);
        $this->assertSame($davao->id, (int) $parcel->handoff_destination_provider_id);

        $this->manifest($batangas, $parcel, $this->gateway($batangas));
        $first = $this->handoff($batangas, $cebu['provider'], $parcel);
        $this->assertSame($cebu['province']->id, (int) $first->receiving_hub_id);
        $this->assertSame($cebu['provider']->id, $parcel->fresh()->custody_provider_id);
        $this->assertSame(2, $parcel->fresh()->parcelRoute->current_leg_index);

        $second = $this->handoff($cebu['provider'], $davao, $parcel);
        $this->assertSame($this->gateway($davao)->id, (int) $second->receiving_hub_id);
        $this->manifest($davao, $parcel, $this->station($davao));

        $this->assertNotNull($parcel->fresh()->parcelRoute->route_completed_at);
        $this->assertSame($davao->id, $parcel->fresh()->custody_provider_id);
    }

    public function test_handoff_refuses_a_saved_leg_that_stays_inside_the_provider(): void
    {
        $batangas = $this->provider('logistics-batangas');
        $parcel = $this->parcelAtOriginStation('seller-batangas', 'buyer-davao', $batangas, 'rider-batangas-city');

        try {
            app(ParcelHandoffService::class)->request($parcel->fresh(), $batangas);
            $this->fail('An internal leg must use a manifest.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('route', $e->errors());
        }
        $this->assertDatabaseCount('parcel_handoffs', 0);
        $this->assertDatabaseCount('parcel_handoff_exceptions', 0);
    }

    public function test_checkout_refuses_a_chain_with_an_unusable_provider_change(): void
    {
        [$batangas, $cebu, $davao] = $this->chainNetwork();
        $seller = $this->account(Seller::class, 'seller-batangas');
        $buyer = $this->account(Buyer::class, 'buyer-davao');

        app(ProviderHandoffSchemaService::class)->revoke($this->link($cebu['provider'], $davao), $this->admin, 'Partnership ended.');

        $this->assertFalse(app(CheckoutCarrierService::class)->optionsForSellerGroup($buyer, $seller, 50000, 800)['available']);
        $this->expectException(ValidationException::class);
        app(RoutePlannerService::class)->planRoute($seller, $batangas, $buyer->barangay_code, $buyer->province_code);
    }

    public function test_return_without_a_reverse_partnership_needs_admin_authorization_for_that_parcel(): void
    {
        $origin = $this->provider('logistics-bulacan');
        $destination = $this->provider('logistics-metro');
        app(ProviderHandoffSchemaService::class)->revoke($this->link($destination, $origin), $this->admin, 'One-way partnership.');
        $parcel = $this->parcelAtOriginStation('seller-bulacan', 'buyer-qc', $origin, 'rider-malolos');
        $this->handoff($origin, $destination, $parcel);
        $return = $this->failTwice($destination, $parcel, 'rider-qc');
        $service = app(ParcelReturnService::class);

        try {
            $service->reverseHandoff($return, $destination);
            $this->fail('A forward partnership alone must not authorize a return.');
        } catch (ValidationException) {
            $this->assertNotNull($return->fresh()->exception_reason);
        }

        $this->actingAs($this->admin, 'admin')->get(route('admin.returns.index'))->assertOk()->assertSee('Authorize reverse route');
        $this->post(route('admin.returns.reverse-route', $return), ['note' => 'short'])->assertSessionHasErrors('note');
        $this->post(route('admin.returns.reverse-route', $return), ['note' => 'Seller confirmed the parcel must come home.'])
            ->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertTrue($service->reverseRouteAuthorized($return->fresh()));
        $this->assertNull($return->fresh()->exception_reason);

        // The forward partnership ending must not trap the authorized parcel, and none is created.
        app(ProviderHandoffSchemaService::class)->revoke($this->link($origin, $destination), $this->admin, 'Partnership ended.');
        $links = LogisticsProviderLink::query()->where('status', LogisticsProviderLink::ACTIVE)->count();
        $handoff = $service->reverseHandoff($return->fresh(), $destination);
        $this->assertSame('return', $handoff->direction);
        $this->assertNull($handoff->logistics_provider_link_id);
        $this->assertSame($origin->id, (int) $handoff->destination_provider_id);
        $this->assertSame($links, LogisticsProviderLink::query()->where('status', LogisticsProviderLink::ACTIVE)->count());
        $this->completeHandoff($destination, $origin, $handoff, $parcel);

        $this->assertSame($origin->id, $parcel->fresh()->custody_provider_id);
        $this->assertSame((int) $return->fresh()->origin_hub_id, (int) $parcel->fresh()->custody_hub_id);
        $this->assertSame(ParcelReturnStatus::AtOrigin, $return->fresh()->status);
    }

    public function test_return_steps_back_through_an_intermediate_provider(): void
    {
        [$batangas, $cebu, $davao] = $this->chainNetwork();
        $parcel = $this->parcelAtOriginStation('seller-batangas', 'buyer-davao', $batangas, 'rider-batangas-city');
        $this->manifest($batangas, $parcel, $this->gateway($batangas));
        $this->handoff($batangas, $cebu['provider'], $parcel);
        $this->handoff($cebu['provider'], $davao, $parcel);
        $this->manifest($davao, $parcel, $this->station($davao));
        $return = $this->failTwice($davao, $parcel, 'rider-davao-city');
        app(ProviderHandoffSchemaService::class)->revoke($this->link($davao, $batangas), $this->admin, 'Returns now go through Cebu.');
        $this->activeHandoffLink($davao, $cebu['provider'], $this->admin);
        $this->activeHandoffLink($cebu['provider'], $batangas, $this->admin);
        $service = app(ParcelReturnService::class);

        $step = $service->reverseHandoff($return, $davao);
        $this->assertSame($cebu['provider']->id, (int) $step->destination_provider_id);
        $this->assertSame($cebu['province']->id, (int) $step->receiving_hub_id);
        $this->completeHandoff($davao, $cebu['provider'], $step, $parcel);
        $this->assertSame(ParcelReturnStatus::InTransit, $return->fresh()->status);
        $this->assertSame($cebu['provider']->id, $parcel->fresh()->custody_provider_id);

        $step = $service->reverseHandoff($return->fresh(), $cebu['provider']);
        $this->assertSame($batangas->id, (int) $step->destination_provider_id);
        $this->assertSame((int) $return->fresh()->origin_hub_id, (int) $step->receiving_hub_id);
        $this->completeHandoff($cebu['provider'], $batangas, $step, $parcel);

        $this->assertSame(ParcelReturnStatus::AtOrigin, $return->fresh()->status);
        $this->assertSame($batangas->id, $parcel->fresh()->custody_provider_id);
    }

    public function test_admin_cannot_authorize_a_reverse_route_for_a_parcel_that_never_changed_provider(): void
    {
        $provider = $this->provider('logistics-laguna');
        $parcel = $this->parcelAtOriginStation('seller-pila', 'buyer-pila', $provider, 'rider-pila');
        $scan = $this->scan($provider, $parcel);
        $this->post(route('logistics.parcel-sorting.sort', $parcel), ['verification_token' => $scan['actions'][0]['verification_token']]);
        $return = $this->failTwice($provider, $parcel, 'rider-pila');

        $this->expectException(ValidationException::class);
        app(ParcelReturnService::class)->authorizeReverseRoute($this->admin, $return, 'Trying to authorize with no handoff.');
    }

    public function test_rider_bell_polls_a_real_url(): void
    {
        $rider = $this->account(Rider::class, 'rider-pila');

        $this->actingAs($rider, 'rider')->get(route('rider.dashboard'))->assertOk()
            ->assertSee('data-recent-url="'.route('rider.notifications.recent').'"', false);
    }

    public function test_manifest_page_builds_destination_options_once_for_many_hubs(): void
    {
        $html = $this->actingAs($this->provider('logistics-laguna'), 'logistics')->get(route('logistics.manifests.create'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'const allOption'));
        $this->assertStringContainsString('Pila Station Hub', $html);
    }

    public function test_buyer_order_page_shows_subtotal_shipping_and_total(): void
    {
        $provider = $this->provider('logistics-laguna');
        $parcel = $this->placeOrder('seller-pila', 'buyer-pila', $provider);

        $this->actingAs($this->account(Buyer::class, 'buyer-pila'), 'buyer')->get(route('buyer.orders.show', $parcel->order))->assertOk()
            ->assertSeeInOrder(['Subtotal', '₱500.00', 'Shipping', '₱49.00', 'Total', '₱549.00']);
    }

    public function test_checkout_has_no_developer_wording(): void
    {
        $buyer = $this->account(Buyer::class, 'buyer-unsupported');
        $this->cart($buyer, $this->account(Seller::class, 'seller-pila'));

        $this->actingAs($buyer, 'buyer')->get(route('buyer.checkout.index'))->assertOk()
            ->assertDontSee('next release')
            ->assertDontSee('Destination-provider links')
            ->assertSee('Delivery from this shop to your address is not available yet');
    }

    public function test_seller_layout_has_a_mobile_menu_for_the_drawer(): void
    {
        $this->actingAs($this->account(Seller::class, 'seller-pila'), 'seller')->get(route('seller.dashboard'))->assertOk()
            ->assertSee('id="sellerSidebarOpen"', false)
            ->assertSee('id="sellerSidebarBackdrop"', false);
    }

    /**
     * Batangas -> Cebu -> Davao, with the seeded direct Batangas -> Davao partnership revoked.
     *
     * @return array{0: LogisticsProvider, 1: array<string, mixed>, 2: LogisticsProvider}
     */
    private function chainNetwork(): array
    {
        $batangas = $this->provider('logistics-batangas');
        $davao = $this->provider('logistics-davao');
        app(ProviderHandoffSchemaService::class)->revoke($this->link($batangas, $davao), $this->admin, 'Route now goes through Cebu.');
        $cebu = $this->operationalHandoffProvider('Cebu Relay', '072200000', 'Cebu', '072217000', 'City of Cebu', '072217004', 'Babag', '070000000', 'Central Visayas');
        $this->activeHandoffLink($batangas, $cebu['provider'], $this->admin);
        $this->activeHandoffLink($cebu['provider'], $davao, $this->admin);

        foreach ([[$this->gateway($batangas), $cebu['province']], [$cebu['province'], $this->gateway($davao)]] as [$from, $to]) {
            TransportConnection::query()->create(['origin_hub_id' => $from->id, 'destination_hub_id' => $to->id,
                'transport_mode' => TransportConnection::SEA, 'operating_provider_id' => $from->logistics_provider_id]);
        }

        return [$batangas, $cebu, $davao];
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  class-string<TModel>  $model
     * @return TModel
     */
    private function account(string $model, string $slug)
    {
        return $model::query()->where('email', DeliveryScenarioSeeder::email($slug))->firstOrFail();
    }

    private function provider(string $slug): LogisticsProvider
    {
        return $this->account(LogisticsProvider::class, $slug);
    }

    private function courier(string $slug): Courier
    {
        return Courier::query()->where('rider_id', $this->account(Rider::class, $slug)->id)->sole();
    }

    private function gateway(LogisticsProvider $provider): LogisticsHub
    {
        return LogisticsHub::query()->where('logistics_provider_id', $provider->id)->liveProvince()->sole();
    }

    private function station(LogisticsProvider $provider): LogisticsHub
    {
        return LogisticsHub::query()->where('logistics_provider_id', $provider->id)->where('hub_type', LogisticsHub::STATION)->sole();
    }

    private function link(LogisticsProvider $origin, LogisticsProvider $destination): LogisticsProviderLink
    {
        return LogisticsProviderLink::query()->where('origin_provider_id', $origin->id)
            ->where('destination_provider_id', $destination->id)->where('status', LogisticsProviderLink::ACTIVE)->sole();
    }

    private function cart(Buyer $buyer, Seller $seller): CartItem
    {
        $cart = CartItem::factory()->create(['buyer_id' => $buyer->id, 'quantity' => 1,
            'product_id' => Product::query()->where('seller_id', $seller->id)->sole()->id]);
        session([CheckoutController::SESSION_KEY => [$cart->id]]);

        return $cart;
    }

    private function placeOrder(string $sellerSlug, string $buyerSlug, LogisticsProvider $origin): SellerOrder
    {
        $seller = $this->account(Seller::class, $sellerSlug);
        $buyer = $this->account(Buyer::class, $buyerSlug);
        $this->cart($buyer, $seller);
        $this->actingAs($buyer, 'buyer')->post(route('buyer.checkout.store'), [
            'checkout_token' => CheckoutToken::issue($buyer->id)->token,
            'carriers' => [$seller->id => $origin->id],
        ])->assertRedirect()->assertSessionHasNoErrors();

        return SellerOrder::query()->sole();
    }

    private function parcelAtOriginStation(string $sellerSlug, string $buyerSlug, LogisticsProvider $origin, string $riderSlug): SellerOrder
    {
        $parcel = $this->placeOrder($sellerSlug, $buyerSlug, $origin);
        $pickup = $this->courier($riderSlug);
        $this->actingAs($this->account(Seller::class, $sellerSlug), 'seller')->post(route('seller.orders.accept', $parcel));
        $this->post(route('seller.orders.pack', $parcel));
        $this->post(route('seller.orders.markReady', $parcel));
        $this->post(route('rider.pickups.confirm', $parcel), ['verification_token' => $this->riderToken($pickup, $parcel)])->assertSessionHasNoErrors();
        $this->post(route('logistics.incoming-parcels.toSortingCenter', $parcel), ['verification_token' => $this->scan($origin, $parcel)['actions'][0]['verification_token']])
            ->assertSessionHasNoErrors();
        $this->assertSame(OrderStatus::AtSortingCenter, $parcel->fresh()->status);

        return $parcel->fresh();
    }

    /** @return array<string, mixed> */
    private function scan(LogisticsProvider $provider, SellerOrder $parcel): array
    {
        return $this->actingAs($provider, 'logistics')->postJson(route('logistics.scan.verify'), ['tracking_number' => $parcel->tracking_number])->assertOk()->json();
    }

    private function riderToken(Courier $courier, SellerOrder $parcel): string
    {
        return $this->actingAs($courier->rider, 'rider')->postJson(route('rider.scan.verify'), ['tracking_number' => $parcel->tracking_number])
            ->assertOk()->json('actions.0.verification_token');
    }

    private function manifest(LogisticsProvider $provider, SellerOrder $parcel, LogisticsHub $to): void
    {
        $service = app(ManifestService::class);
        $manifest = $service->create($provider, (int) $parcel->fresh()->custody_hub_id, $to->id);
        $item = $service->addParcel($manifest, $parcel->fresh());
        $scan = $service->verifyScan($manifest, $provider, $parcel->tracking_number, 'dispatch');
        $manifest = $service->dispatch($manifest, $provider, [$item->id => $scan['verification_token']]);
        $scan = $service->verifyScan($manifest, $provider, $parcel->tracking_number, 'receive');
        $service->receive($manifest, $provider, $item, $scan['verification_token']);
    }

    private function handoff(LogisticsProvider $from, LogisticsProvider $to, SellerOrder $parcel)
    {
        $handoff = app(ParcelHandoffService::class)->request($parcel->fresh(), $from);
        $this->assertSame($to->id, (int) $handoff->destination_provider_id);

        return $this->completeHandoff($from, $to, $handoff, $parcel);
    }

    private function completeHandoff(LogisticsProvider $from, LogisticsProvider $to, $handoff, SellerOrder $parcel)
    {
        $service = app(ParcelHandoffService::class);
        $handoff = $service->accept($handoff, $to);
        $scan = $service->verifyScan($handoff, $from, $parcel->tracking_number, 'dispatch');
        $handoff = $service->dispatch($handoff, $from, $scan['verification_token']);
        $scan = $service->verifyScan($handoff, $to, $parcel->tracking_number, 'receive');

        return $service->receive($handoff, $to, $scan['verification_token']);
    }

    private function failTwice(LogisticsProvider $provider, SellerOrder $parcel, string $riderSlug)
    {
        $courier = $this->courier($riderSlug);

        if ($parcel->fresh()->status === OrderStatus::AtSortingCenter) {
            $this->post(route('logistics.parcel-sorting.sort', $parcel), ['verification_token' => $this->scan($provider, $parcel)['actions'][0]['verification_token']])
                ->assertSessionDoesntHaveErrors();
        }
        $this->actingAs($provider, 'logistics')->post(route('logistics.parcel-sorting.assignDelivery', $parcel), ['courier_id' => $courier->id, 'reason' => 'Delivery run']);
        $this->assertSame(OrderStatus::AssignedToRider, $parcel->fresh()->status, (string) session('error'));

        foreach ([1, 2] as $attempt) {
            $this->post(route('rider.deliveries.start', $parcel), ['verification_token' => $this->riderToken($courier, $parcel)])->assertSessionHasNoErrors();
            $scan = $this->actingAs($courier->rider, 'rider')->postJson(route('rider.scan.verify'), ['tracking_number' => $parcel->tracking_number])->json();
            $token = collect($scan['actions'])->first(fn (array $action): bool => str_contains($action['label'], 'Failed'))['verification_token'];
            $this->post(route('rider.deliveries.failed', $parcel), ['verification_token' => $token, 'reason' => 'other', 'failure_reason' => 'other', 'notes' => 'Nobody home.', 'failure_notes' => 'Nobody home.'])
                ->assertSessionHasNoErrors();
            $this->assertSame($attempt, (int) $parcel->fresh()->delivery_attempts);
            if ($attempt === 1) {
                $this->actingAs($provider, 'logistics')->post(route('logistics.delivery-assignments.reschedule', $parcel), ['courier_id' => $courier->id, 'reason' => 'Final attempt']);
            }
        }

        $this->actingAs($provider, 'logistics')->post(route('logistics.delivery-assignments.return', $parcel), ['reason' => 'Two failed attempts']);

        return $parcel->fresh()->parcelReturn;
    }
}
