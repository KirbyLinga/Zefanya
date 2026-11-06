<?php

namespace Tests\Feature\Logistics;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Buyer\CheckoutController;
use App\Models\Admin\Admin;
use App\Models\Buyer\CartItem;
use App\Models\Buyer\CheckoutToken;
use App\Models\Catalog\Product;
use App\Models\Logistics\Manifest;
use App\Models\Logistics\ParcelHandoff;
use App\Models\Logistics\ParcelRoute;
use App\Models\Logistics\TransportConnection;
use App\Models\Rider\Courier;
use App\Models\Rider\Rider;
use App\Models\Seller\SellerShippingProvider;
use App\Models\Shared\Incidents;
use App\Models\Shared\SellerOrder;
use App\Notifications\Buyer\DeliveryOtpCode;
use App\Services\Logistics\ManifestService;
use App\Services\Logistics\ParcelHandoffService;
use App\Services\Logistics\RouteExecutionService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BootstrapsCrossProviderHandoffs;
use Tests\TestCase;

class EndToEndLogisticsStabilizationTest extends TestCase
{
    use BootstrapsCrossProviderHandoffs;
    use LazilyRefreshDatabase;

    public static function supportedRoutes(): array
    {
        return ['local Pila to Pila' => ['local'], 'Lumban to Pila' => ['intra'],
            'Bulacan to Quezon City' => ['cross'], 'Luzon to Mindanao' => ['island']];
    }

    #[DataProvider('supportedRoutes')]
    public function test_verified_delivery_lifecycle(string $scenario): void
    {
        Notification::fake();
        Storage::fake('local');
        $f = $this->network($scenario);
        $pickup = $this->courier($f['a']);
        $delivery = $scenario === 'local' ? $pickup : $this->courier($f['b']);
        $parcel = $this->checkout($f);
        $address = $parcel->order->shipping_address;
        $route = $parcel->parcelRoute;
        $this->assertSame(count($f['connections']), count($route->legs));
        $this->assertNull($parcel->custody_provider_id);
        $this->assertNull(app(RouteExecutionService::class)->currentLocation($route));

        $this->actingAs($f['seller'], 'seller')->post(route('seller.orders.accept', $parcel))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(OrderStatus::Confirmed, $parcel->fresh()->status);
        $this->post(route('seller.orders.pack', $parcel))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(OrderStatus::Preparing, $parcel->fresh()->status);
        $this->post(route('seller.orders.markReady', $parcel))->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($f['a']['provider'], 'logistics')
            ->post(route('logistics.incoming-parcels.assignPickupRider', $parcel), ['courier_id' => $pickup->id, 'reason' => 'Origin collection'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($pickup->id, (int) $parcel->fresh()->courier_id);
        $token = $this->riderScan($pickup, $parcel);
        $this->post(route('rider.pickups.confirm', $parcel), ['verification_token' => $token])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(OrderStatus::PickedUp, $parcel->fresh()->status);
        $this->assertNotNull($parcel->fresh()->handover_at);

        $token = $this->logisticsScan($f['a'], $parcel);
        $this->post(route('logistics.incoming-parcels.toSortingCenter', $parcel), ['verification_token' => $token])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($f['a']['station']->id, $parcel->fresh()->custody_hub_id);
        $this->assertSame($f['a']['station']->id, app(RouteExecutionService::class)->currentLocation($route)->id);
        $this->assertTracking($f, $parcel, $f['a']['station']->name, $f['a']['station']->name, $f['connections'][0]->destinationHub->name ?? null);

        if ($scenario === 'intra') {
            $this->post(route('logistics.exceptions.store', $parcel), ['type' => 'missing',
                'reason' => 'Private investigation note', 'description' => 'Staff-only custody investigation.'])
                ->assertRedirect()->assertSessionHasNoErrors();
            $incident = Incidents::query()->sole();
            $this->post(route('logistics.exceptions.review', $incident), ['review_note' => 'Parcel located at verified origin.'])
                ->assertRedirect()->assertSessionHasNoErrors();
            $this->post(route('logistics.exceptions.resolve', $incident), ['resolution' => 'KEEP_ON_HOLD', 'resolution_note' => 'Await review completion.'])
                ->assertRedirect()->assertSessionHasNoErrors();
            $this->actingAs($f['buyer'], 'buyer')->get(route('buyer.orders.show', $parcel->order))
                ->assertSee('On hold:')->assertSee($f['a']['station']->name)
                ->assertDontSee('Private investigation note')->assertDontSee('Staff-only custody investigation.');
            $this->actingAs($f['a']['provider'], 'logistics')->get(route('logistics.delivery-monitoring.show', $parcel))
                ->assertSee('On hold:')->assertViewHas('routeDetails', fn ($details): bool => $details['held']);
            $this->post(route('logistics.exceptions.resolve', $incident), ['resolution' => 'RESUME_ORIGINAL_ROUTE', 'resolution_note' => 'Located parcel verified at origin; original connections active.'])
                ->assertRedirect()->assertSessionHasNoErrors();
            $this->assertFalse(app(RouteExecutionService::class)->trackingSummary($parcel->fresh())['held']);
            $this->assertSame(0, $route->fresh()->current_leg_index);
        }

        foreach ($f['connections'] as $index => $connection) {
            $origin = $connection->originHub;
            $destination = $connection->destinationHub;
            $owner = $origin->provider;
            if ($origin->logistics_provider_id === $destination->logistics_provider_id) {
                $service = app(ManifestService::class);
                $this->actingAs($owner, 'logistics')->get(route('logistics.manifests.create'))->assertOk();
                $this->post(route('logistics.manifests.store'), ['origin_hub_id' => $origin->id, 'destination_hub_id' => $destination->id])
                    ->assertRedirect()->assertSessionHasNoErrors();
                $manifest = Manifest::query()->latest('id')->firstOrFail();
                $this->get(route('logistics.manifests.show', $manifest))->assertOk()->assertSee('Add');
                $this->post(route('logistics.manifests.items.store', $manifest), ['seller_order_id' => $parcel->id])
                    ->assertRedirect()->assertSessionHasNoErrors();
                $item = $manifest->items()->sole();
                $scan = $service->verifyScan($manifest, $owner, $parcel->tracking_number, 'dispatch');
                $this->post(route('logistics.manifests.dispatch', $manifest), ['tokens' => [$item->id => $scan['verification_token']]])
                    ->assertRedirect()->assertSessionHasNoErrors();
                $manifest->refresh();
                $this->assertSame($index, $route->fresh()->current_leg_index);
                $this->assertNull(app(RouteExecutionService::class)->currentLocation($route));
                $this->assertTracking($f, $parcel, 'In transit between hubs', $origin->name, $destination->name);
                $scan = $service->verifyScan($manifest, $owner, $parcel->tracking_number, 'receive');
                $this->actingAs($owner, 'logistics')->get(route('logistics.manifests.receive.index', $manifest))->assertOk();
                $this->post(route('logistics.manifests.receive.store', [$manifest, $item]), ['verification_token' => $scan['verification_token']])
                    ->assertRedirect()->assertSessionHasNoErrors();
                $this->assertNotNull($item->fresh()->received_at);
            } else {
                $service = app(ParcelHandoffService::class);
                $this->actingAs($owner, 'logistics')->post(route('logistics.handoffs.store'), ['seller_order_id' => $parcel->id])
                    ->assertRedirect()->assertSessionHasNoErrors();
                $handoff = ParcelHandoff::query()->latest('id')->firstOrFail();
                $this->actingAs($destination->provider, 'logistics')->get(route('logistics.handoffs.show', $handoff))
                    ->assertOk()->assertSee('Accept');
                $this->post(route('logistics.handoffs.accept', $handoff))->assertRedirect()->assertSessionHasNoErrors();
                $handoff->refresh();
                $this->assertSame($index, $route->fresh()->current_leg_index);
                $this->assertSame($owner->id, $parcel->fresh()->custody_provider_id);
                $scan = $service->verifyScan($handoff, $owner, $parcel->tracking_number, 'dispatch');
                $this->actingAs($owner, 'logistics')->post(route('logistics.handoffs.dispatch', $handoff), ['verification_token' => $scan['verification_token']])
                    ->assertRedirect()->assertSessionHasNoErrors();
                $handoff->refresh();
                $this->assertNull(app(RouteExecutionService::class)->currentLocation($route));
                $this->assertTracking($f, $parcel, 'In transit between hubs', $origin->name, $destination->name);
                $scan = $service->verifyScan($handoff, $destination->provider, $parcel->tracking_number, 'receive');
                $this->actingAs($destination->provider, 'logistics')->post(route('logistics.handoffs.receive', $handoff), ['verification_token' => $scan['verification_token']])
                    ->assertRedirect()->assertSessionHasNoErrors();
                $this->assertNotNull($handoff->fresh()->received_at);
            }
            $this->assertSame($destination->id, $parcel->fresh()->custody_hub_id);
            $this->assertSame($destination->logistics_provider_id, $parcel->fresh()->custody_provider_id);
            $this->assertSame($destination->id, app(RouteExecutionService::class)->currentLocation($route)->id);
            $this->assertTracking($f, $parcel, $destination->name, $destination->name, $f['connections'][$index + 1]->destinationHub->name ?? null);
            if ($index < count($f['connections']) - 1) {
                $this->assertNull($route->fresh()->route_completed_at);
                $this->assertSame($index + 1, $route->fresh()->current_leg_index);
                $this->assertSame($f['connections'][$index + 1]->destination_hub_id, app(RouteExecutionService::class)->nextDestination($route)->id);
                $this->assertNull($parcel->fresh()->delivery_courier_id);
            }
        }
        $this->assertNotNull($route->fresh()->route_completed_at);
        $this->assertSame($f['b']['station']->id, $parcel->fresh()->custody_hub_id);
        $this->assertNull(app(RouteExecutionService::class)->nextDestination($route));
        if ($scenario === 'local') {
            $this->assertDatabaseCount('manifests', 0);
            $this->assertDatabaseCount('parcel_handoffs', 0);
        }
        if ($scenario !== 'local') {
            $this->actingAs($f['buyer'], 'buyer')->get(route('buyer.orders.show', $parcel->order))
                ->assertSee('Verified receiving history')->assertSee('Received at '.$f['b']['station']->name);
        }
        if ($scenario === 'island') {
            $this->assertSame(['ROAD', 'SEA', 'AIR'], array_column($route->fresh()->legs, 'transport_mode'));
        }

        $token = $this->logisticsScan($f['b'], $parcel);
        $this->post(route('logistics.parcel-sorting.sort', $parcel), ['verification_token' => $token])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(OrderStatus::Sorted, $parcel->fresh()->status);
        $this->post(route('logistics.parcel-sorting.assignDelivery', $parcel), ['courier_id' => $delivery->id, 'reason' => 'Destination delivery'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $parcel = $parcel->fresh();
        $this->assertSame($delivery->id, (int) $parcel->delivery_courier_id);
        $this->assertSame(OrderStatus::AssignedToRider, $parcel->status, (string) session('error'));
        $this->assertSame($f['b']['provider']->id, $delivery->logistics_provider_id);
        $token = $this->riderScan($delivery, $parcel);
        $this->post(route('rider.deliveries.start', $parcel), ['verification_token' => $token])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTracking($f, $parcel, 'With delivery rider', $f['b']['station']->name, null);
        $token = $this->riderScan($delivery, $parcel);
        $this->post(route('rider.deliveries.otp', $parcel), ['verification_token' => $token])->assertRedirect()->assertSessionHasNoErrors();
        $code = Notification::sent($f['buyer'], DeliveryOtpCode::class)->last()->code;
        $payment = $parcel->payment;
        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $token = $this->riderScan($delivery, $parcel);
        $this->post(route('rider.deliveries.deliver', $parcel), [
            'verification_token' => $token, 'delivery_otp' => $code,
            'proof_photo' => UploadedFile::fake()->image('proof.jpg'),
            'received_by' => 'Buyer', 'cod_amount' => number_format($payment->amount_minor / 100, 2, '.', ''),
        ])->assertRedirect(route('rider.deliveries.index'))->assertSessionHasNoErrors();
        $this->assertSame(OrderStatus::Delivered, $parcel->fresh()->status);
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame($payment->amount_minor, $payment->fresh()->collected_amount_minor);
        $this->assertNotNull($parcel->fresh()->deliveryProof);
        $this->actingAs($f['buyer'], 'buyer')->post(route('buyer.orders.confirmReceived', $parcel->order))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(OrderStatus::Completed, $parcel->fresh()->status);
        $this->assertTracking($f, $parcel, 'Delivered to buyer', $f['b']['station']->name, null);
        $this->assertSame($address, $parcel->order->fresh()->shipping_address);
        $this->assertSame(4, $f['product']->fresh()->stock_quantity);
    }

    public static function unsupportedRoutes(): array
    {
        return ['missing connection' => ['missing'], 'inactive connection' => ['inactive'], 'missing coverage' => ['coverage']];
    }

    #[DataProvider('unsupportedRoutes')]
    public function test_unsupported_checkout_leaves_no_order_payment_route_or_stock_mutations(string $failure): void
    {
        $f = $this->network('cross');
        if ($failure === 'missing') {
            $f['connections'][0]->delete();
        } elseif ($failure === 'inactive') {
            $f['connections'][0]->update(['is_active' => false]);
        } else {
            $f['b']['area']->update(['is_active' => false]);
        }
        $cart = $this->cart($f);
        $this->actingAs($f['buyer'], 'buyer')->get(route('buyer.checkout.index'))
            ->assertOk()->assertSee('not available');
        $this->post(route('buyer.checkout.store'), ['checkout_token' => CheckoutToken::issue($f['buyer']->id)->token,
            'carriers' => [$f['seller']->id => $f['a']['provider']->id]])->assertSessionHasErrors();
        foreach (['orders', 'seller_orders', 'payments', 'parcel_routes', 'inventory_transactions'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertModelExists($cart);
        $this->assertSame(5, $f['product']->fresh()->stock_quantity);
    }

    public function test_route_persistence_failure_rolls_back_stock_order_payment_and_token_claim(): void
    {
        Notification::fake();
        $f = $this->network('cross');
        $cart = $this->cart($f);
        $token = CheckoutToken::issue($f['buyer']->id);
        $event = 'eloquent.creating: '.ParcelRoute::class;
        Event::listen($event, function (): void {
            throw ValidationException::withMessages(['route' => 'Route persistence unavailable.']);
        });

        try {
            $this->actingAs($f['buyer'], 'buyer')->post(route('buyer.checkout.store'), [
                'checkout_token' => $token->token, 'carriers' => [$f['seller']->id => $f['a']['provider']->id],
            ])->assertRedirect(route('buyer.cart.index'))->assertSessionHas('error', fn ($error): bool => str_contains($error, 'No shipping route available'));
        } finally {
            Event::forget($event);
        }

        foreach (['orders', 'seller_orders', 'payments', 'parcel_routes', 'inventory_transactions'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame(5, $f['product']->fresh()->stock_quantity);
        $this->assertSame(5, $f['product']->variants()->sole()->stock_quantity);
        $this->assertFalse((bool) $token->fresh()->used);
        $this->assertModelExists($cart);
        Notification::assertNothingSent();
    }

    private function network(string $scenario): array
    {
        $this->withoutVite();
        config(['buyer.cross_province.fee_minor' => 19900]);
        $local = in_array($scenario, ['local', 'intra'], true);
        $a = $local
            ? $this->operationalHandoffProvider('Laguna', '043400000', 'Laguna', $scenario === 'local' ? '043424000' : '043413000', $scenario === 'local' ? 'Pila' : 'Lumban', '0434240001', 'Origin', '040000000', 'CALABARZON')
            : $this->operationalHandoffProvider('Bulacan', '031400000', 'Bulacan', '031410000', 'Malolos', '0314100001', 'Origin');
        $seller = $this->sellerIn($a['station']->province_code, $a['station']->province_name, $a['station']->city_municipality_code, $a['station']->municipality, $a['area']->barangay_code, 'Origin');
        $b = $a;
        if ($scenario === 'intra') {
            $b['station'] = $a['station']->replicate();
            $b['station']->fill(['name' => 'Pila destination', 'city_municipality_code' => '043424000', 'municipality' => 'Pila', 'barangay_code' => '0434240002'])->save();
            $b['area'] = $a['area']->replicate();
            $b['area']->fill(['name' => 'Pila coverage', 'station_hub_id' => $b['station']->id, 'city_municipality_code' => '043424000', 'municipality' => 'Pila', 'barangay_code' => '0434240002'])->save();
        } elseif (! $local) {
            $b = $scenario === 'cross'
                ? $this->operationalHandoffProvider('Quezon City', '137404000', 'Metro Manila', '137404000', 'Quezon City', '1374040001', 'Destination', '130000000', 'NCR')
                : $this->operationalHandoffProvider('Mindanao', '112400000', 'Davao del Sur', '112402000', 'Davao City', '1124020001', 'Destination', '110000000', 'Davao Region');
            $this->activeHandoffLink($a['provider'], $b['provider'], Admin::factory()->create());
        }
        $buyer = $this->buyerIn($b['station']->province_code, $b['station']->province_name, $b['station']->city_municipality_code, $b['station']->municipality, $b['area']->barangay_code, 'Destination');
        SellerShippingProvider::query()->create(['seller_id' => $seller->id, 'logistics_provider_id' => $a['provider']->id, 'is_enabled' => true]);
        $product = Product::factory()->create(['seller_id' => $seller->id, 'price' => 250, 'stock_quantity' => 5, 'weight_grams' => 500]);
        $connections = [];
        $pairs = match ($scenario) {
            'local' => [],
            'island' => [[$a['station'], $a['province'], 'ROAD'], [$a['province'], $b['province'], 'SEA'], [$b['province'], $b['station'], 'AIR']],
            default => [[$a['station'], $b['station'], 'ROAD']],
        };
        foreach ($pairs as [$origin, $destination, $mode]) {
            $connections[] = TransportConnection::query()->create(['origin_hub_id' => $origin->id, 'destination_hub_id' => $destination->id,
                'transport_mode' => $mode, 'operating_provider_id' => $origin->logistics_provider_id, 'is_active' => true]);
        }

        return compact('a', 'b', 'seller', 'buyer', 'product', 'connections');
    }

    private function assertTracking(array $f, SellerOrder $parcel, string $location, ?string $lastHub, ?string $nextHub): void
    {
        $this->actingAs($f['buyer'], 'buyer')->get(route('buyer.orders.show', $parcel->order))
            ->assertOk()->assertSee('Verified route progress')->assertSee($location)
            ->assertViewHas('routeDetails', fn ($details): bool => $details[$parcel->id]['location'] === $location
                && $details[$parcel->id]['last_verified_hub'] === $lastHub && $details[$parcel->id]['next_destination'] === $nextHub);
        $this->actingAs($parcel->fresh()->custodyProvider, 'logistics')->get(route('logistics.delivery-monitoring.show', $parcel))
            ->assertOk()->assertSee('Verified route progress')->assertSee($location)
            ->assertViewHas('routeDetails', fn ($details): bool => $details['location'] === $location
                && $details['last_verified_hub'] === $lastHub && $details['next_destination'] === $nextHub);
    }

    private function courier(array $network): Courier
    {
        $rider = Rider::factory()->create(['logistics_provider_id' => $network['provider']->id,
            'station_hub_id' => $network['station']->id, 'status' => 'approved', 'email_verified_at' => now()]);

        return Courier::factory()->create(['logistics_provider_id' => $network['provider']->id,
            'rider_id' => $rider->id, 'area_id' => $network['area']->id, 'is_available' => true, 'max_weight_grams' => 10000]);
    }

    private function cart(array $f): CartItem
    {
        $cart = CartItem::factory()->create(['buyer_id' => $f['buyer']->id, 'product_id' => $f['product']->id, 'quantity' => 1]);
        session([CheckoutController::SESSION_KEY => [$cart->id]]);

        return $cart;
    }

    private function checkout(array $f): SellerOrder
    {
        $this->cart($f);
        $this->actingAs($f['buyer'], 'buyer')->post(route('buyer.checkout.store'), [
            'checkout_token' => CheckoutToken::issue($f['buyer']->id)->token,
            'carriers' => [$f['seller']->id => $f['a']['provider']->id],
        ])->assertRedirect()->assertSessionHasNoErrors();

        return SellerOrder::query()->sole();
    }

    private function riderScan(Courier $courier, SellerOrder $parcel): string
    {
        return $this->actingAs($courier->rider, 'rider')->postJson(route('rider.scan.verify'), ['tracking_number' => $parcel->tracking_number])
            ->assertOk()->json('actions.0.verification_token');
    }

    private function logisticsScan(array $network, SellerOrder $parcel): string
    {
        return $this->actingAs($network['provider'], 'logistics')->postJson(route('logistics.scan.verify'), ['tracking_number' => $parcel->tracking_number])
            ->assertOk()->json('actions.0.verification_token');
    }
}
