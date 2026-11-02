<?php

namespace Tests\Feature\Logistics;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Buyer\CheckoutController;
use App\Models\Admin\Admin;
use App\Models\Buyer\Buyer;
use App\Models\Buyer\CartItem;
use App\Models\Buyer\CheckoutToken;
use App\Models\Catalog\Product;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\LogisticsProviderLink;
use App\Models\Logistics\Manifest;
use App\Models\Logistics\ParcelHandoff;
use App\Models\Logistics\TransportConnection;
use App\Models\Rider\Courier;
use App\Models\Rider\Rider;
use App\Models\Seller\Seller;
use App\Models\Shared\SellerOrder;
use App\Notifications\Buyer\DeliveryOtpCode;
use App\Services\Logistics\ManifestService;
use App\Services\Logistics\ParcelHandoffService;
use Database\Seeders\DeliveryScenarioSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DeliveryScenarioSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        putenv(DeliveryScenarioSeeder::MAILBOX_ENV);
    }

    private function seedNetwork(): void
    {
        $this->withoutVite();
        Admin::factory()->create();
        $this->seed(DeliveryScenarioSeeder::class);
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: list<string>, 6: list<string>, 7: int}> */
    public static function supportedScenarios(): array
    {
        return [
            '1 local Pila to Pila' => ['seller-pila', 'buyer-pila', 'logistics-laguna', 'rider-pila', 'rider-pila', [], [], 0],
            '2 intra-province Lumban to Pila' => ['seller-lumban', 'buyer-pila', 'logistics-laguna', 'rider-lumban', 'rider-pila', ['ROAD'], ['Pila Station Hub'], 0],
            '3 inter-province Bulacan to Quezon City' => ['seller-bulacan', 'buyer-qc', 'logistics-bulacan', 'rider-malolos', 'rider-qc', ['ROAD'], ['Quezon City Station Hub'], 1],
            '4 inter-island Batangas to Davao' => ['seller-batangas', 'buyer-davao', 'logistics-batangas', 'rider-batangas-city', 'rider-davao-city', ['ROAD', 'SEA', 'ROAD'], ['Batangas Port Gateway', 'Davao Port Gateway', 'Davao City Station Hub'], 1],
        ];
    }

    /**
     * @param  list<string>  $modes
     * @param  list<string>  $hubs
     */
    #[DataProvider('supportedScenarios')]
    public function test_seeded_network_carries_the_parcel_from_checkout_to_completion(
        string $sellerSlug, string $buyerSlug, string $originSlug, string $pickupSlug, string $deliverySlug,
        array $modes, array $hubs, int $expectedHandoffs,
    ): void {
        Notification::fake();
        Storage::fake('local');
        $this->seedNetwork();

        $seller = $this->account(Seller::class, $sellerSlug);
        $buyer = $this->account(Buyer::class, $buyerSlug);
        $origin = $this->account(LogisticsProvider::class, $originSlug);
        $pickup = $this->courierFor($pickupSlug);
        $delivery = $this->courierFor($deliverySlug);
        $product = Product::query()->where('seller_id', $seller->id)->sole();
        $stock = $product->stock_quantity;

        $parcel = $this->placeOrder($buyer, $seller, $origin);
        $route = $parcel->parcelRoute;
        $this->assertSame($modes, array_column($route->legs, 'transport_mode'));
        $this->assertSame($hubs, array_map(fn (array $leg): string => LogisticsHub::query()->findOrFail($leg['hub_id'])->name, $route->legs));

        // Seller -> Pickup Rider -> origin Station Hub.
        $this->actingAs($seller, 'seller')->post(route('seller.orders.accept', $parcel))->assertSessionHasNoErrors();
        $this->post(route('seller.orders.pack', $parcel))->assertSessionHasNoErrors();
        $this->post(route('seller.orders.markReady', $parcel))->assertSessionHasNoErrors();
        $this->actingAs($origin, 'logistics')
            ->post(route('logistics.incoming-parcels.assignPickupRider', $parcel), ['courier_id' => $pickup->id, 'reason' => 'Origin collection'])
            ->assertSessionHasNoErrors();
        $this->post(route('rider.pickups.confirm', $parcel), ['verification_token' => $this->riderScan($pickup, $parcel)])->assertSessionHasNoErrors();
        $this->assertSame(OrderStatus::PickedUp, $parcel->fresh()->status);
        $this->post(route('logistics.incoming-parcels.toSortingCenter', $parcel), ['verification_token' => $this->logisticsScan($origin, $parcel)])
            ->assertSessionHasNoErrors();

        // Every saved transport leg, each ending in a verified receipt.
        foreach ($route->fresh()->legs as $index => $leg) {
            $holder = $parcel->fresh()->custodyProvider;
            $from = $parcel->fresh()->custody_hub_id;
            $to = LogisticsHub::query()->findOrFail($leg['hub_id']);

            if ((int) $to->logistics_provider_id === (int) $holder->id) {
                $this->moveByManifest($holder, $parcel, (int) $from, $to);
            } else {
                $this->moveByHandoff($holder, $to->provider, $parcel);
            }

            $this->assertSame($to->id, $parcel->fresh()->custody_hub_id, 'Leg '.$index);
            $this->assertSame($to->logistics_provider_id, $parcel->fresh()->custody_provider_id, 'Leg '.$index);
        }

        $this->assertNotNull($route->fresh()->route_completed_at);
        $this->assertDatabaseCount('parcel_handoffs', $expectedHandoffs);
        if ($modes === []) {
            $this->assertDatabaseCount('manifests', 0);
        }

        // Destination Hub -> Delivery Rider -> Buyer -> completion and money.
        $destination = $delivery->provider;
        $this->actingAs($destination, 'logistics')
            ->post(route('logistics.parcel-sorting.sort', $parcel), ['verification_token' => $this->logisticsScan($destination, $parcel)])
            ->assertSessionHasNoErrors();
        $this->post(route('logistics.parcel-sorting.assignDelivery', $parcel), ['courier_id' => $delivery->id, 'reason' => 'Destination delivery'])
            ->assertSessionHasNoErrors();
        $this->assertSame(OrderStatus::AssignedToRider, $parcel->fresh()->status, (string) session('error'));
        $this->post(route('rider.deliveries.start', $parcel), ['verification_token' => $this->riderScan($delivery, $parcel)])->assertSessionHasNoErrors();
        $this->post(route('rider.deliveries.otp', $parcel), ['verification_token' => $this->riderScan($delivery, $parcel)])->assertSessionHasNoErrors();
        $code = Notification::sent($buyer, DeliveryOtpCode::class)->last()->code;
        $this->actingAs($buyer, 'buyer')->get(route('buyer.orders.show', $parcel->order))->assertOk()->assertSee($code);
        $payment = $parcel->fresh()->payment;
        $this->post(route('rider.deliveries.deliver', $parcel), [
            'verification_token' => $this->riderScan($delivery, $parcel),
            'delivery_otp' => $code,
            'proof_photo' => UploadedFile::fake()->create('proof.jpg', 20, 'image/jpeg'),
            'received_by' => 'Buyer',
            'cod_amount' => number_format($payment->amount_minor / 100, 2, '.', ''),
        ])->assertRedirect(route('rider.deliveries.index'))->assertSessionHasNoErrors();
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->actingAs($buyer, 'buyer')->post(route('buyer.orders.confirmReceived', $parcel->order))->assertSessionHasNoErrors();

        $this->assertSame(OrderStatus::Completed, $parcel->fresh()->status);
        $this->assertSame($stock - 1, $product->fresh()->stock_quantity);
    }

    public function test_unsupported_address_blocks_checkout_with_no_side_effects_until_a_supported_address_is_chosen(): void
    {
        Notification::fake();
        $this->seedNetwork();
        $buyer = $this->account(Buyer::class, 'buyer-unsupported');
        $seller = $this->account(Seller::class, 'seller-pila');
        $provider = $this->account(LogisticsProvider::class, 'logistics-laguna');
        $product = Product::query()->where('seller_id', $seller->id)->sole();
        $stock = $product->stock_quantity;
        $cart = $this->cart($buyer, $product);
        $this->assertSame('072217003', $buyer->barangay_code);

        $this->actingAs($buyer, 'buyer')->get(route('buyer.checkout.index'))->assertOk()->assertSee('not available');
        $this->post(route('buyer.checkout.store'), [
            'checkout_token' => CheckoutToken::issue($buyer->id)->token,
            'carriers' => [$seller->id => $provider->id],
        ])->assertSessionHasErrors();

        foreach (['orders', 'seller_orders', 'payments', 'parcel_routes', 'inventory_transactions'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertModelExists($cart);
        $this->assertSame($stock, $product->fresh()->stock_quantity);

        // The buyer's saved Pila address is the supported way forward.
        $supported = $buyer->addresses()->where('is_default', false)->sole();
        session([CheckoutController::SESSION_KEY => [$cart->id]]);
        $this->post(route('buyer.checkout.store'), [
            'checkout_token' => CheckoutToken::issue($buyer->id)->token,
            'address_id' => $supported->id,
            'carriers' => [$seller->id => $provider->id],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $parcel = SellerOrder::query()->sole();
        $this->assertSame([], $parcel->parcelRoute->legs);
        $this->assertSame('043422003', $parcel->order->shipping_address['barangay_code']);
        $this->assertSame($stock - 1, $product->fresh()->stock_quantity);
    }

    public function test_air_standby_takes_over_when_sea_is_deactivated(): void
    {
        Notification::fake();
        $this->seedNetwork();
        TransportConnection::query()->where('transport_mode', TransportConnection::SEA)->sole()->update(['is_active' => false]);

        $parcel = $this->placeOrder(
            $this->account(Buyer::class, 'buyer-davao'),
            $this->account(Seller::class, 'seller-batangas'),
            $this->account(LogisticsProvider::class, 'logistics-batangas'),
        );

        $this->assertSame(['ROAD', 'AIR', 'ROAD'], array_column($parcel->parcelRoute->legs, 'transport_mode'));
    }

    public function test_seeder_is_idempotent_and_self_check_matches_every_scenario(): void
    {
        $this->seedNetwork();
        $tables = ['buyers', 'sellers', 'logistics_providers', 'riders', 'couriers', 'logistics_hubs', 'areas', 'logistics_provider_links',
            'transport_connections', 'logistics_shipping_rates', 'seller_shipping_providers', 'products', 'product_variants', 'buyer_addresses'];
        $before = collect($tables)->mapWithKeys(fn (string $table): array => [$table => $this->getConnection()->table($table)->count()])->all();

        $this->seed(DeliveryScenarioSeeder::class);

        foreach ($before as $table => $count) {
            $this->assertDatabaseCount($table, $count);
        }
        $this->assertDatabaseCount('orders', 0);
        foreach ((new DeliveryScenarioSeeder)->scenarioCheck() as $row) {
            $this->assertSame($row['expected'], $row['available'], $row['scenario'].': '.$row['route']);
            $this->assertStringNotContainsString('NOT ROUTABLE', $row['route'], $row['scenario']);
        }
    }

    public function test_changing_the_mailbox_rehomes_accounts_instead_of_duplicating_them(): void
    {
        $this->seedNetwork();
        $buyers = Buyer::query()->count();

        putenv(DeliveryScenarioSeeder::MAILBOX_ENV.'=someone@example.com');
        try {
            $this->seed(DeliveryScenarioSeeder::class);

            $this->assertDatabaseCount('buyers', $buyers);
            $this->assertDatabaseHas('buyers', ['email' => 'someone+zef-demo-buyer-pila@example.com']);
        } finally {
            putenv(DeliveryScenarioSeeder::MAILBOX_ENV);
        }
    }

    public function test_seeder_leaves_existing_accounts_and_network_untouched(): void
    {
        $provider = LogisticsProvider::factory()->create(['province_code' => '043400000', 'province_name' => 'Laguna']);
        $seller = Seller::factory()->create(['province_code' => '043400000', 'barangay_code' => '043411006']);
        $providerSnapshot = $provider->fresh()->getAttributes();
        $sellerSnapshot = $seller->fresh()->getAttributes();

        $this->seedNetwork();

        $this->assertSame($providerSnapshot, $provider->fresh()->getAttributes());
        $this->assertSame($sellerSnapshot, $seller->fresh()->getAttributes());
        $this->assertDatabaseMissing('seller_shipping_providers', ['seller_id' => $seller->id]);
        $this->assertDatabaseMissing('logistics_hubs', ['logistics_provider_id' => $provider->id]);
    }

    public function test_seeder_refuses_to_run_without_an_admin_and_in_production(): void
    {
        $this->seed(DeliveryScenarioSeeder::class);
        $this->assertDatabaseCount('logistics_providers', 0);

        Admin::factory()->create();
        $this->app['env'] = 'production';
        try {
            (new DeliveryScenarioSeeder)->run();
        } finally {
            $this->app['env'] = 'testing';
        }
        $this->assertDatabaseCount('logistics_providers', 0);
        $this->assertSame(0, LogisticsProviderLink::query()->count());
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

    private function courierFor(string $slug): Courier
    {
        return Courier::query()->where('rider_id', $this->account(Rider::class, $slug)->id)->sole();
    }

    private function cart(Buyer $buyer, Product $product): CartItem
    {
        $cart = CartItem::factory()->create(['buyer_id' => $buyer->id, 'product_id' => $product->id, 'quantity' => 1]);
        session([CheckoutController::SESSION_KEY => [$cart->id]]);

        return $cart;
    }

    private function placeOrder(Buyer $buyer, Seller $seller, LogisticsProvider $origin): SellerOrder
    {
        $this->cart($buyer, Product::query()->where('seller_id', $seller->id)->sole());
        $this->actingAs($buyer, 'buyer')->post(route('buyer.checkout.store'), [
            'checkout_token' => CheckoutToken::issue($buyer->id)->token,
            'carriers' => [$seller->id => $origin->id],
        ])->assertRedirect()->assertSessionHasNoErrors();

        return SellerOrder::query()->sole();
    }

    private function moveByManifest(LogisticsProvider $owner, SellerOrder $parcel, int $from, LogisticsHub $to): void
    {
        $service = app(ManifestService::class);
        $this->actingAs($owner, 'logistics')->post(route('logistics.manifests.store'), ['origin_hub_id' => $from, 'destination_hub_id' => $to->id])
            ->assertSessionHasNoErrors();
        $manifest = Manifest::query()->latest('id')->firstOrFail();
        $this->post(route('logistics.manifests.items.store', $manifest), ['seller_order_id' => $parcel->id])->assertSessionHasNoErrors();
        $item = $manifest->items()->sole();
        $scan = $service->verifyScan($manifest, $owner, $parcel->tracking_number, 'dispatch');
        $this->post(route('logistics.manifests.dispatch', $manifest), ['tokens' => [$item->id => $scan['verification_token']]])->assertSessionHasNoErrors();
        $scan = $service->verifyScan($manifest->refresh(), $owner, $parcel->tracking_number, 'receive');
        $this->post(route('logistics.manifests.receive.store', [$manifest, $item]), ['verification_token' => $scan['verification_token']])->assertSessionHasNoErrors();
    }

    private function moveByHandoff(LogisticsProvider $owner, LogisticsProvider $destination, SellerOrder $parcel): void
    {
        $service = app(ParcelHandoffService::class);
        $this->actingAs($owner, 'logistics')->post(route('logistics.handoffs.store'), ['seller_order_id' => $parcel->id])->assertSessionHasNoErrors();
        $handoff = ParcelHandoff::query()->latest('id')->firstOrFail();
        $this->assertSame($destination->id, (int) $handoff->destination_provider_id);
        $this->actingAs($destination, 'logistics')->post(route('logistics.handoffs.accept', $handoff))->assertSessionHasNoErrors();
        $scan = $service->verifyScan($handoff->refresh(), $owner, $parcel->tracking_number, 'dispatch');
        $this->actingAs($owner, 'logistics')->post(route('logistics.handoffs.dispatch', $handoff), ['verification_token' => $scan['verification_token']])->assertSessionHasNoErrors();
        $scan = $service->verifyScan($handoff->refresh(), $destination, $parcel->tracking_number, 'receive');
        $this->actingAs($destination, 'logistics')->post(route('logistics.handoffs.receive', $handoff), ['verification_token' => $scan['verification_token']])->assertSessionHasNoErrors();
    }

    private function riderScan(Courier $courier, SellerOrder $parcel): string
    {
        return $this->actingAs($courier->rider, 'rider')->postJson(route('rider.scan.verify'), ['tracking_number' => $parcel->tracking_number])
            ->assertOk()->json('actions.0.verification_token');
    }

    private function logisticsScan(LogisticsProvider $provider, SellerOrder $parcel): string
    {
        return $this->actingAs($provider, 'logistics')->postJson(route('logistics.scan.verify'), ['tracking_number' => $parcel->tracking_number])
            ->assertOk()->json('actions.0.verification_token');
    }
}
