<?php

namespace Tests\Feature\Logistics;

use App\Enums\ManifestStatus;
use App\Enums\OrderStatus;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\Manifest;
use App\Models\Logistics\ManifestItem;
use App\Models\Order;
use App\Models\Seller\Seller;
use App\Models\SellerOrder;
use App\Services\ManifestService;
use App\Services\ParcelHandoffVerification;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Phase 4.3–4.5: internal manifests, verified dispatch/receiving, and the
 * hub-movement timeline exposed to Buyers and Sellers.
 */
class InternalManifestFlowTest extends TestCase
{
    use LazilyRefreshDatabase;

    private LogisticsProvider $provider;

    private LogisticsHub $provinceHub;

    private LogisticsHub $calamba;

    private LogisticsHub $losBanos;

    private Seller $seller;

    private Buyer $buyer;

    private Order $order;

    private SellerOrder $parcel;

    private ManifestService $manifests;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->provider = LogisticsProvider::factory()->create([
            'status' => 'approved',
            'email_verified_at' => now(),
            'province_code' => '043400000',
            'province_name' => 'Laguna',
            'municipality_code' => '043405000',
            'municipality_name' => 'Calamba',
        ]);

        $this->provinceHub = $this->createProvinceHub($this->provider, 'Laguna Province Hub');
        $this->calamba = $this->createStationHub($this->provider, $this->provinceHub, 'Calamba Station', 'Calamba', '043405000');
        $this->losBanos = $this->createStationHub($this->provider, $this->provinceHub, 'Los Baños Station', 'Los Baños', '043405100');

        $this->seller = Seller::factory()->create();
        $this->buyer = Buyer::factory()->create();

        $this->order = Order::factory()->create([
            'buyer_id' => $this->buyer->id,
            'shipping_address' => [
                'full' => '123 Sample St., Los Baños, Laguna',
                'municipality' => 'Los Baños',
                'municipality_name' => 'Los Baños',
            ],
        ]);

        $this->parcel = SellerOrder::factory()->create([
            'order_id' => $this->order->id,
            'seller_id' => $this->seller->id,
            'tracking_number' => 'TRK-CALAMBA-0001',
        ]);

        $this->parcel->forceFill([
            'status' => OrderStatus::AtSortingCenter->value,
            'origin_station_hub_id' => $this->calamba->id,
        ])->save();

        $this->parcel->refresh();

        $this->manifests = app(ManifestService::class);
    }

    public function test_logistics_manifest_pages_are_provider_scoped(): void
    {
        $this->actingAs($this->provider, 'logistics');

        $this->get(route('logistics.manifests.index'))
            ->assertOk()
            ->assertSee('Manifests');

        $this->get(route('logistics.manifests.create'))
            ->assertOk()
            ->assertSee('Create Manifest');

        $this->post(route('logistics.manifests.store'), [
            'origin_hub_id' => $this->calamba->id,
            'destination_hub_id' => $this->provinceHub->id,
        ])->assertRedirect();

        $manifest = Manifest::query()->latest('id')->firstOrFail();

        $this->assertSame($this->provider->id, (int) $manifest->logistics_provider_id);
        $this->assertSame(ManifestStatus::Draft, $manifest->status);

        $otherProvider = LogisticsProvider::factory()->create();
        $this->actingAs($otherProvider, 'logistics')
            ->get(route('logistics.manifests.show', $manifest))
            ->assertNotFound();
    }

    public function test_dispatch_requires_a_valid_scan_for_every_parcel_and_never_changes_parcel_status(): void
    {
        $secondParcel = $this->createParcel('TRK-CALAMBA-0002', $this->calamba);
        $manifest = $this->manifests->create($this->provider, $this->calamba->id, $this->provinceHub->id);
        $first = $this->manifests->addParcel($manifest, $this->parcel);
        $second = $this->manifests->addParcel($manifest, $secondParcel);

        $this->actingAs($this->provider, 'logistics');

        // No verification tokens: the draft must stay untouched.
        $this->post(route('logistics.manifests.dispatch', $manifest), ['tokens' => []])
            ->assertSessionHasErrors();

        $this->assertSame(ManifestStatus::Draft, $manifest->fresh()->status);

        // A token for only one parcel cannot dispatch the manifest.
        $firstToken = $this->scanForToken($manifest, $this->parcel, 'dispatch');
        $this->post(route('logistics.manifests.dispatch', $manifest), [
            'tokens' => [$first->id => $firstToken],
        ])->assertSessionHasErrors();

        $this->assertSame(ManifestStatus::Draft, $manifest->fresh()->status);

        // Every parcel verified: dispatch succeeds.
        $secondToken = $this->scanForToken($manifest, $secondParcel, 'dispatch');
        $this->post(route('logistics.manifests.dispatch', $manifest), [
            'tokens' => [
                $first->id => $firstToken,
                $second->id => $secondToken,
            ],
        ])->assertRedirect(route('logistics.manifests.show', $manifest));

        $manifest->refresh();
        $this->assertSame(ManifestStatus::Dispatched, $manifest->status);
        $this->assertNotNull($manifest->dispatched_at);
        $this->assertSame($this->provider->id, (int) $manifest->dispatched_by_id);
        $this->assertSame(2, $manifest->items()->count());

        // Replaying the same dispatch request cannot create a second movement.
        $this->post(route('logistics.manifests.dispatch', $manifest), [
            'tokens' => [
                $first->id => $firstToken,
                $second->id => $secondToken,
            ],
        ])->assertSessionHasErrors();

        $this->assertSame(ManifestStatus::Dispatched, $manifest->fresh()->status);
        $this->assertSame(2, ManifestItem::query()->where('manifest_id', $manifest->id)->count());

        // Canonical parcel lifecycle is untouched by manifest handling.
        $this->assertSame(OrderStatus::AtSortingCenter, $this->parcel->fresh()->status);
        $this->assertSame(OrderStatus::AtSortingCenter, $secondParcel->fresh()->status);
        $this->assertSame(0, $this->parcel->fresh()->statusHistories()->count());
    }

    public function test_receiving_is_verified_partial_and_idempotent(): void
    {
        $secondParcel = $this->createParcel('TRK-CALAMBA-0003', $this->calamba);
        $manifest = $this->manifests->create($this->provider, $this->calamba->id, $this->provinceHub->id);
        $first = $this->manifests->addParcel($manifest, $this->parcel);
        $second = $this->manifests->addParcel($manifest, $secondParcel);

        $this->actingAs($this->provider, 'logistics');

        $this->dispatchManifest($manifest, [
            $first->id => $this->scanForToken($manifest, $this->parcel, 'dispatch'),
            $second->id => $this->scanForToken($manifest, $secondParcel, 'dispatch'),
        ]);

        // The shared receiving scanner does not send a phase; the manifest state
        // determines that this scan is a destination receipt.
        $inferred = $this->postJson(route('logistics.manifests.scan.verify', $manifest), [
            'tracking_number' => $this->parcel->tracking_number,
            'method' => 'manual',
        ]);
        $inferred->assertOk()->assertJsonPath('actions.0.key', ParcelHandoffVerification::ACTION_MANIFEST_RECEIVE);
        $receiveToken = (string) $inferred->json('verification_token');

        $this->post(route('logistics.manifests.receive.store', [$manifest, $first]), [
            'verification_token' => $receiveToken,
        ])->assertRedirect(route('logistics.manifests.receive.index', $manifest));

        $manifest->refresh();
        $first->refresh();
        $second->refresh();

        $this->assertSame(ManifestStatus::Dispatched, $manifest->status);
        $this->assertNotNull($first->received_at);
        $this->assertSame($this->provider->id, (int) $first->received_by_id);
        $this->assertNull($second->received_at);
        $this->assertNull($manifest->received_at);

        // Reusing the consumed receiving token is rejected without a second receipt.
        $this->post(route('logistics.manifests.receive.store', [$manifest, $first]), [
            'verification_token' => $receiveToken,
        ])->assertSessionHasErrors('verification_token');

        $this->assertSame(1, $manifest->items()->whereNotNull('received_at')->count());

        // A fresh scan of an already received parcel is rejected too.
        $this->postJson(route('logistics.manifests.scan.verify', $manifest), [
            'tracking_number' => $this->parcel->tracking_number,
            'phase' => 'receive',
            'method' => 'manual',
        ])->assertStatus(422)->assertJsonPath('message', 'This parcel has already been received at the destination hub.');

        // The final parcel closes the manifest.
        $this->post(route('logistics.manifests.receive.store', [$manifest, $second]), [
            'verification_token' => $this->scanForToken($manifest, $secondParcel, 'receive'),
        ])->assertRedirect(route('logistics.manifests.receive.index', $manifest));

        $manifest->refresh();
        $this->assertSame(ManifestStatus::Received, $manifest->status);
        $this->assertNotNull($manifest->received_at);
        $this->assertSame($this->provider->id, (int) $manifest->received_by_id);
        $this->assertSame(0, $manifest->items()->whereNull('received_at')->count());

        // Deliveries are never marked DELIVERED/RETURNED by manifest scanning.
        $this->assertSame(OrderStatus::AtSortingCenter, $this->parcel->fresh()->status);
        $this->assertSame(OrderStatus::AtSortingCenter, $secondParcel->fresh()->status);
    }

    public function test_forged_expired_wrong_hub_and_wrong_action_tokens_are_rejected(): void
    {
        $manifest = $this->manifests->create($this->provider, $this->calamba->id, $this->provinceHub->id);
        $item = $this->manifests->addParcel($manifest, $this->parcel);

        $this->actingAs($this->provider, 'logistics');

        // Forged token.
        $this->post(route('logistics.manifests.dispatch', $manifest), [
            'tokens' => [$item->id => 'not-a-real-token'],
        ])->assertSessionHasErrors('verification_token');

        $this->assertSame(ManifestStatus::Draft, $manifest->fresh()->status);

        // Token bound to the wrong hub (destination instead of origin).
        $wrongHubToken = app(ParcelHandoffVerification::class)->issueForManifestItem(
            $this->provider,
            $item,
            ParcelHandoffVerification::ACTION_MANIFEST_DISPATCH,
            $this->provinceHub->id,
        );

        $this->post(route('logistics.manifests.dispatch', $manifest), [
            'tokens' => [$item->id => $wrongHubToken],
        ])->assertSessionHasErrors('verification_token');

        $this->assertSame(ManifestStatus::Draft, $manifest->fresh()->status);

        // Expired token (cache entry removed).
        $expiredToken = $this->scanForToken($manifest, $this->parcel, 'dispatch');
        Cache::forget('parcel_handoff_verify:'.$expiredToken);

        $this->post(route('logistics.manifests.dispatch', $manifest), [
            'tokens' => [$item->id => $expiredToken],
        ])->assertSessionHasErrors('verification_token');

        $this->assertSame(ManifestStatus::Draft, $manifest->fresh()->status);

        // A dispatch token cannot be replayed as a receiving token.
        $dispatchToken = $this->scanForToken($manifest, $this->parcel, 'dispatch');
        $unusedDispatchToken = $this->scanForToken($manifest, $this->parcel, 'dispatch');
        $this->dispatchManifest($manifest, [$item->id => $dispatchToken]);

        try {
            $this->manifests->receive($manifest->fresh(), $this->provider, $item->fresh(), $unusedDispatchToken);
            $this->fail('A dispatch token was accepted for receiving.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('verification_token', $e->errors());
        }

        $this->assertNull($item->fresh()->received_at);
    }

    public function test_multi_leg_movements_are_separate_from_delivery_status_and_visible_to_buyer_and_seller(): void
    {
        $legOne = $this->manifests->create($this->provider, $this->calamba->id, $this->provinceHub->id);
        $this->manifests->addParcel($legOne, $this->parcel);
        $this->dispatchAndReceive($legOne, $this->parcel);

        $this->assertSame($this->provinceHub->id, $this->manifests->currentHubId($this->parcel->fresh()));

        $legTwo = $this->manifests->create($this->provider, $this->provinceHub->id, $this->losBanos->id);
        $this->manifests->addParcel($legTwo, $this->parcel->fresh());
        $this->dispatchAndReceive($legTwo, $this->parcel->fresh());

        $this->assertSame($this->losBanos->id, $this->manifests->currentHubId($this->parcel->fresh()));

        $events = $this->parcel->fresh()->manifestMovementEvents();

        $this->assertCount(4, $events);
        $this->assertSame('dispatch', $events[0]['type']);
        $this->assertStringContainsString('Dispatched from Calamba Station', $events[0]['title']);
        $this->assertSame('receipt', $events[1]['type']);
        $this->assertStringContainsString('Laguna Province Hub', $events[1]['title']);
        $this->assertSame('dispatch', $events[2]['type']);
        $this->assertStringContainsString('Dispatched from Laguna Province Hub', $events[2]['title']);
        $this->assertSame('receipt', $events[3]['type']);
        $this->assertStringContainsString('Los Baños Station', $events[3]['title']);

        // Canonical delivery status did not move through either hub transfer.
        $this->assertSame(OrderStatus::AtSortingCenter, $this->parcel->fresh()->status);
        $this->assertSame(0, $this->parcel->fresh()->statusHistories()->count());

        $this->actingAs($this->buyer, 'buyer')
            ->get(route('buyer.orders.show', $this->order))
            ->assertOk()
            ->assertSee('Hub movements')
            ->assertSee('Calamba Station')
            ->assertSee('Los Baños Station');

        $this->actingAs($this->seller, 'seller')
            ->get(route('seller.orders.show', $this->parcel))
            ->assertOk()
            ->assertSee('Hub movements')
            ->assertSee('Calamba Station')
            ->assertSee('Los Baños Station');
    }

    public function test_manifest_ui_moves_from_draft_to_dispatch_to_receiving(): void
    {
        $manifest = $this->manifests->create($this->provider, $this->calamba->id, $this->provinceHub->id);
        $item = $this->manifests->addParcel($manifest, $this->parcel);

        $this->actingAs($this->provider, 'logistics');

        $this->get(route('logistics.manifests.show', $manifest))
            ->assertOk()
            ->assertSee('Add parcels')
            ->assertSee('Dispatch this manifest')
            ->assertSee($this->parcel->tracking_number);

        $this->dispatchManifest($manifest, [
            $item->id => $this->scanForToken($manifest, $this->parcel, 'dispatch'),
        ]);

        $this->get(route('logistics.manifests.show', $manifest))
            ->assertOk()
            ->assertSee('Receive parcels');

        $this->get(route('logistics.manifests.receive.index', $manifest))
            ->assertOk()
            ->assertSee('Scan each incoming parcel')
            ->assertSee('Expected parcels')
            ->assertSee($this->parcel->tracking_number);

        $this->post(route('logistics.manifests.receive.store', [$manifest, $item]), [
            'verification_token' => $this->scanForToken($manifest, $this->parcel, 'receive'),
        ])->assertRedirect(route('logistics.manifests.receive.index', $manifest));

        $this->get(route('logistics.manifests.receive.index', $manifest))
            ->assertOk()
            ->assertSee('Manifest fully received');
    }

    public function test_cross_provider_carrier_parcels_are_rejected(): void
    {
        $otherProvider = LogisticsProvider::factory()->create();
        $this->parcel->forceFill(['carrier_provider_id' => $otherProvider->id])->save();

        $manifest = $this->manifests->create($this->provider, $this->calamba->id, $this->provinceHub->id);

        try {
            $this->manifests->addParcel($manifest, $this->parcel->fresh());
            $this->fail('A parcel assigned to another carrier was added to the manifest.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('seller_order_id', $e->errors());
        }

        $this->assertSame(0, $manifest->items()->count());
        $this->assertTrue(
            $this->manifests->eligibleParcels($manifest)->every(
                fn (SellerOrder $parcel): bool => (int) $parcel->carrier_provider_id !== (int) $otherProvider->id
            )
        );
    }

    public function test_removed_draft_parcels_do_not_count_or_block_dispatch(): void
    {
        $secondParcel = $this->createParcel('TRK-CALAMBA-0004', $this->calamba);
        $manifest = $this->manifests->create($this->provider, $this->calamba->id, $this->provinceHub->id);
        $first = $this->manifests->addParcel($manifest, $this->parcel);
        $second = $this->manifests->addParcel($manifest, $secondParcel);

        $this->actingAs($this->provider, 'logistics');

        $this->delete(route('logistics.manifests.items.destroy', [$manifest, $second]))
            ->assertRedirect(route('logistics.manifests.show', $manifest));

        $this->assertSame(['total' => 1, 'received' => 0, 'pending' => 1], $this->manifests->counts($manifest));

        $this->dispatchManifest($manifest, [
            $first->id => $this->scanForToken($manifest, $this->parcel, 'dispatch'),
        ]);
    }

    public function test_same_station_deliveries_do_not_require_a_manifest(): void
    {
        $this->assertFalse($this->manifests->requiresManifest($this->calamba->id, $this->calamba->id));

        try {
            $this->manifests->create($this->provider, $this->calamba->id, $this->calamba->id);
            $this->fail('A same-station manifest was created.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('destination_hub_id', $e->errors());
        }

        $this->assertSame(0, Manifest::query()->count());
        $this->assertSame(0, ManifestItem::query()->count());
        $this->assertSame([], $this->parcel->fresh()->manifestMovementEvents());
    }

    private function dispatchManifest(Manifest $manifest, array $tokens): void
    {
        $this->post(route('logistics.manifests.dispatch', $manifest), ['tokens' => $tokens])
            ->assertRedirect(route('logistics.manifests.show', $manifest));

        $this->assertSame(ManifestStatus::Dispatched, $manifest->fresh()->status);
    }

    private function dispatchAndReceive(Manifest $manifest, SellerOrder $parcel): void
    {
        if (! $this->isActingAsLogistics()) {
            $this->actingAs($this->provider, 'logistics');
        }

        $item = $manifest->items()->where('seller_order_id', $parcel->id)->firstOrFail();

        $this->dispatchManifest($manifest, [
            $item->id => $this->scanForToken($manifest, $parcel, 'dispatch'),
        ]);

        $this->post(route('logistics.manifests.receive.store', [$manifest, $item]), [
            'verification_token' => $this->scanForToken($manifest, $parcel, 'receive'),
        ])->assertRedirect(route('logistics.manifests.receive.index', $manifest));

        $this->assertNotNull($item->fresh()->received_at);
    }

    private function scanForToken(Manifest $manifest, SellerOrder $parcel, string $phase): string
    {
        $response = $this->postJson(route('logistics.manifests.scan.verify', $manifest), [
            'tracking_number' => $parcel->tracking_number,
            'phase' => $phase,
            'method' => 'manual',
        ]);

        $response->assertOk();

        return (string) $response->json('verification_token');
    }

    private function isActingAsLogistics(): bool
    {
        return auth('logistics')->check();
    }

    private function createParcel(string $tracking, LogisticsHub $origin): SellerOrder
    {
        $order = Order::factory()->create([
            'buyer_id' => $this->buyer->id,
            'shipping_address' => [
                'full' => '123 Sample St., Los Baños, Laguna',
                'municipality' => 'Los Baños',
                'municipality_name' => 'Los Baños',
            ],
        ]);

        $parcel = SellerOrder::factory()->create([
            'order_id' => $order->id,
            'seller_id' => $this->seller->id,
            'tracking_number' => $tracking,
        ]);

        $parcel->forceFill([
            'status' => OrderStatus::AtSortingCenter->value,
            'origin_station_hub_id' => $origin->id,
        ])->save();

        return $parcel->fresh();
    }

    private function createProvinceHub(LogisticsProvider $provider, string $name): LogisticsHub
    {
        return LogisticsHub::query()->create([
            'logistics_provider_id' => $provider->id,
            'name' => $name,
            'hub_type' => LogisticsHub::PROVINCE,
            'region_code' => '040000000',
            'region_name' => 'CALABARZON',
            'province_code' => '043400000',
            'province_name' => 'Laguna',
            'is_active' => true,
            'approval_status' => LogisticsHub::APPROVAL_APPROVED,
            'active_province_slot' => 1,
        ]);
    }

    private function createStationHub(
        LogisticsProvider $provider,
        LogisticsHub $province,
        string $name,
        string $municipality,
        string $municipalityCode,
    ): LogisticsHub {
        return LogisticsHub::query()->create([
            'logistics_provider_id' => $provider->id,
            'parent_hub_id' => $province->id,
            'name' => $name,
            'hub_type' => LogisticsHub::STATION,
            'region_code' => $province->region_code,
            'region_name' => $province->region_name,
            'province_code' => $province->province_code,
            'province_name' => $province->province_name,
            'municipality' => $municipality,
            'city_municipality_code' => $municipalityCode,
            'is_active' => true,
            'approval_status' => LogisticsHub::APPROVAL_APPROVED,
        ]);
    }
}
