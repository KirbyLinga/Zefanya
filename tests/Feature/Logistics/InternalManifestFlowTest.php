<?php

namespace Tests\Feature\Logistics;

use App\Enums\ManifestStatus;
use App\Enums\OrderActor;
use App\Enums\OrderStatus;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\Manifest;
use App\Models\Logistics\ManifestAuditLog;
use App\Models\Logistics\ManifestItem;
use App\Models\Logistics\TransferCourier;
use App\Models\Logistics\TransferCourierAssignment;
use App\Models\Rider\RiderEarning;
use App\Models\Seller\Seller;
use App\Models\Shared\Incidents;
use App\Models\Shared\Order;
use App\Models\Shared\SellerOrder;
use App\Services\Logistics\ManifestService;
use App\Services\Logistics\ParcelHandoffVerification;
use App\Services\Logistics\ParcelIncidentService;
use App\Services\Logistics\StuckTransferService;
use App\Services\Seller\SellerOrderStatusService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
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
            'custody_hub_id' => $this->calamba->id,
            'carrier_provider_id' => $this->provider->id,
            'custody_provider_id' => null,
            'handover_at' => now(),  // Pickup confirmed
        ])->save();

        $this->parcel->refresh();

        $this->manifests = app(ManifestService::class);
    }

    public function test_logistics_manifest_pages_are_provider_scoped(): void
    {
        $this->actingAs($this->provider, 'logistics');

        $this->get(route('logistics.manifests.index'))
            ->assertOk()
            ->assertSee('Transfer Batches');

        $this->get(route('logistics.manifests.create'))
            ->assertOk()
            ->assertSee('Create Transfer Batch');

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

        // A verified dispatch puts every parcel on the batch IN_TRANSIT; custody stays at the origin.
        $this->assertSame(OrderStatus::InTransit, $this->parcel->fresh()->status);
        $this->assertSame(OrderStatus::InTransit, $secondParcel->fresh()->status);
        $this->assertSame((int) $this->calamba->id, (int) $this->parcel->fresh()->custody_hub_id);
        $this->assertSame([OrderStatus::InTransit->value], $this->parcel->fresh()->statusHistories()->get()->map(fn ($h) => $h->to_status->value)->all());
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

        // Receiving records ARRIVED_AT_HUB; manifest scanning never marks DELIVERED/RETURNED.
        $this->assertSame(OrderStatus::ArrivedAtHub, $this->parcel->fresh()->status);
        $this->assertSame(OrderStatus::ArrivedAtHub, $secondParcel->fresh()->status);
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

        // Each transfer recorded its departure and verified arrival, nothing more.
        $this->assertSame(OrderStatus::ArrivedAtHub, $this->parcel->fresh()->status);
        $this->assertSame(
            [OrderStatus::InTransit->value, OrderStatus::ArrivedAtHub->value, OrderStatus::InTransit->value, OrderStatus::ArrivedAtHub->value],
            $this->parcel->fresh()->statusHistories()->orderBy('id')->get()->map(fn ($h) => $h->to_status->value)->all(),
        );

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
            ->assertSee('Add Parcels')
            ->assertSee('Dispatch this batch')
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
            ->assertSee('Batch fully received');
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
            'custody_hub_id' => $origin->id,
            'carrier_provider_id' => $this->provider->id,
            'custody_provider_id' => null,
            'handover_at' => now(),  // Pickup confirmed
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

    public function test_delete_draft_manifest_releases_items_and_deletes_manifest(): void
    {
        $manifest = $this->manifests->create($this->provider, $this->calamba->id, $this->provinceHub->id);
        $item = $this->manifests->addParcel($manifest, $this->parcel);

        $this->actingAs($this->provider, 'logistics');

        $this->delete(route('logistics.manifests.destroy', $manifest))
            ->assertRedirect(route('logistics.manifests.index'))
            ->assertSessionHas('success');

        $this->assertNull(Manifest::query()->find($manifest->id));
        $this->assertNull(ManifestItem::query()->find($item->id));
    }

    public function test_cannot_delete_non_draft_manifest(): void
    {
        $manifest = $this->manifests->create($this->provider, $this->calamba->id, $this->provinceHub->id);
        $item = $this->manifests->addParcel($manifest, $this->parcel);

        $this->actingAs($this->provider, 'logistics');

        $this->dispatchManifest($manifest, [
            $item->id => $this->scanForToken($manifest, $this->parcel, 'dispatch'),
        ]);

        $this->delete(route('logistics.manifests.destroy', $manifest))
            ->assertSessionHasErrors();

        $this->assertNotNull(Manifest::query()->find($manifest->id));
    }

    public function test_cancel_dispatched_manifest_with_reason(): void
    {
        $manifest = $this->manifests->create($this->provider, $this->calamba->id, $this->provinceHub->id);
        $item = $this->manifests->addParcel($manifest, $this->parcel);

        $this->actingAs($this->provider, 'logistics');

        $this->dispatchManifest($manifest, [
            $item->id => $this->scanForToken($manifest, $this->parcel, 'dispatch'),
        ]);

        $this->assertSame(ManifestStatus::Dispatched, $manifest->fresh()->status);

        $this->post(route('logistics.manifests.cancel', $manifest), [
            'cancellation_reason' => 'Weather emergency — delaying all shipments',
        ])->assertRedirect(route('logistics.manifests.show', $manifest))
            ->assertSessionHas('success');

        $manifest->refresh();
        $this->assertSame(ManifestStatus::Cancelled, $manifest->status);
        $this->assertNotNull($manifest->cancelled_at);
        $this->assertSame('logistics', $manifest->cancelled_by_type);
        $this->assertSame($this->provider->id, (int) $manifest->cancelled_by_id);
        $this->assertStringContainsString('Weather emergency', $manifest->cancellation_reason);
        $this->assertNotNull($item->fresh()->released_at);
    }

    public function test_cannot_cancel_manifest_with_empty_reason(): void
    {
        $manifest = $this->manifests->create($this->provider, $this->calamba->id, $this->provinceHub->id);
        $item = $this->manifests->addParcel($manifest, $this->parcel);

        $this->actingAs($this->provider, 'logistics');

        $this->dispatchManifest($manifest, [
            $item->id => $this->scanForToken($manifest, $this->parcel, 'dispatch'),
        ]);

        $this->post(route('logistics.manifests.cancel', $manifest), [
            'cancellation_reason' => '',
        ])->assertSessionHasErrors('cancellation_reason');

        $this->assertSame(ManifestStatus::Dispatched, $manifest->fresh()->status);
    }

    public function test_cannot_cancel_manifest_with_received_items(): void
    {
        $manifest = $this->manifests->create($this->provider, $this->calamba->id, $this->provinceHub->id);
        $item = $this->manifests->addParcel($manifest, $this->parcel);

        $this->actingAs($this->provider, 'logistics');

        $this->dispatchManifest($manifest, [
            $item->id => $this->scanForToken($manifest, $this->parcel, 'dispatch'),
        ]);

        // Receive the item
        $this->post(route('logistics.manifests.receive.store', [$manifest, $item]), [
            'verification_token' => $this->scanForToken($manifest, $this->parcel, 'receive'),
        ]);

        $this->post(route('logistics.manifests.cancel', $manifest), [
            'cancellation_reason' => 'Too late — item already received',
        ])->assertSessionHasErrors('manifest');

        $this->assertSame(ManifestStatus::Received, $manifest->fresh()->status);
    }

    // ── Phase 1: delete draft, cancel batch, and verified recovery ─────────

    public function test_deleting_a_draft_returns_its_parcels_to_the_sending_hub_queue(): void
    {
        $manifest = $this->manifests->create($this->provider, $this->calamba->id, $this->provinceHub->id);
        $this->manifests->addParcel($manifest, $this->parcel);
        $this->actingAs($this->provider, 'logistics');

        $this->get(route('logistics.manifests.show', $manifest))->assertOk()
            ->assertSee('Delete draft')
            ->assertSee("Delete draft batch #{$manifest->id}? It never left the hub", false);

        $this->delete(route('logistics.manifests.destroy', $manifest))
            ->assertRedirect(route('logistics.manifests.index'))
            ->assertSessionHas('success', fn (string $m) => str_contains($m, "back in the sending hub's queue"));

        $parcel = $this->parcel->fresh();
        $this->assertSame((int) $this->calamba->id, (int) $parcel->custody_hub_id);
        $this->assertNull($this->manifests->ineligibilityReason($parcel, (int) $this->calamba->id));
        $next = $this->manifests->create($this->provider, $this->calamba->id, $this->provinceHub->id);
        $this->assertContains($parcel->id, $this->manifests->eligibleParcels($next)->pluck('id')->all());
    }

    public function test_cancel_batch_needs_a_reason_and_is_offered_only_before_any_receipt(): void
    {
        $manifest = $this->manifests->create($this->provider, $this->calamba->id, $this->provinceHub->id);
        $item = $this->manifests->addParcel($manifest, $this->parcel);
        $this->actingAs($this->provider, 'logistics');
        $this->dispatchManifest($manifest, [$item->id => $this->scanForToken($manifest, $this->parcel, 'dispatch')]);

        $this->get(route('logistics.manifests.show', $manifest))->assertOk()
            ->assertSee('Cancel batch')
            ->assertSee('name="cancellation_reason" required', false)
            ->assertSee('Cancelling does not bring the parcels back.');

        $this->post(route('logistics.manifests.cancel', $manifest), ['cancellation_reason' => '   '])
            ->assertSessionHasErrors('cancellation_reason');
        $this->assertSame(ManifestStatus::Dispatched, $manifest->fresh()->status);

        $this->post(route('logistics.manifests.receive.store', [$manifest, $item]), [
            'verification_token' => $this->scanForToken($manifest, $this->parcel, 'receive'),
        ]);
        $this->get(route('logistics.manifests.show', $manifest))->assertOk()->assertDontSee('Cancel batch');
    }

    public function test_a_batch_cancelled_after_dispatch_holds_its_parcels_until_a_verified_recovery_scan(): void
    {
        $manifest = $this->manifests->create($this->provider, $this->calamba->id, $this->provinceHub->id);
        $item = $this->manifests->addParcel($manifest, $this->parcel);
        $this->actingAs($this->provider, 'logistics');
        $this->dispatchManifest($manifest, [$item->id => $this->scanForToken($manifest, $this->parcel, 'dispatch')]);

        $this->post(route('logistics.manifests.cancel', $manifest), ['cancellation_reason' => 'Truck broke down on the highway'])
            ->assertSessionHas('success', fn (string $m) => str_contains($m, 'stays held as an exception'));

        // Cancelling never assumes the parcel came back.
        $this->assertTrue($item->fresh()->awaitsRecovery());
        $this->assertTrue($this->manifests->awaitingRecovery($this->parcel->fresh()));
        $this->assertSame('Awaiting recovery scan after a cancelled transfer',
            $this->manifests->ineligibilityReason($this->parcel->fresh(), (int) $this->calamba->id));
        $next = $this->manifests->create($this->provider, $this->calamba->id, $this->provinceHub->id);
        $this->assertNotContains($this->parcel->id, $this->manifests->eligibleParcels($next)->pluck('id')->all());

        $incident = Incidents::query()->where('incident_seller_order_id', $this->parcel->id)->where('type', 'failed_transfer')->sole();
        try {
            app(ParcelIncidentService::class)->resolveForProvider($incident, $this->provider, ['event' => 'RESUME_ORIGINAL_ROUTE', 'reason' => 'It is back']);
            $this->fail('A route must not resume before the parcel is located.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('must be verified before its route can resume', $e->errors()['resolution'][0]);
        }

        $this->get(route('logistics.manifests.show', $manifest))->assertOk()
            ->assertSee('Recover parcels')
            ->assertSee('1 of 1 parcel still needs a recovery scan.')
            ->assertSee('TRK-CALAMBA-0001')
            ->assertSee('Awaiting recovery scan');

        // Found at the destination hub: record exactly where it is.
        $token = $this->recoveryToken($manifest, $this->parcel, $this->provinceHub);
        $this->post(route('logistics.manifests.items.recover', [$manifest, $item, 'hub' => $this->provinceHub->id]), ['verification_token' => $token])
            ->assertRedirect(route('logistics.manifests.show', $manifest))
            ->assertSessionHas('success', fn (string $m) => str_contains($m, 'recorded at Laguna Province Hub'));

        $item->refresh();
        $parcel = $this->parcel->fresh();
        $this->assertNotNull($item->recovered_at);
        $this->assertSame((int) $this->provinceHub->id, (int) $item->recovered_hub_id);
        $this->assertSame((int) $this->provinceHub->id, (int) $parcel->custody_hub_id);
        $this->assertSame((int) $this->provider->id, (int) $parcel->custody_provider_id);
        $this->assertFalse($this->manifests->awaitingRecovery($parcel));
        $this->assertSame(1, $incident->events()->where('event', 'recovery_received')->count());
        $this->assertContains($incident->fresh()->status->value, ['open', 'under_review'], 'Recovery proves location only; the exception stays for a route decision.');

        // A repeated submit with the spent token changes nothing.
        $this->post(route('logistics.manifests.items.recover', [$manifest, $item, 'hub' => $this->provinceHub->id]), ['verification_token' => $token])
            ->assertRedirect(route('logistics.manifests.show', $manifest));
        $this->assertSame(1, $incident->events()->where('event', 'recovery_received')->count());
        $this->assertSame(1, ManifestAuditLog::query()->where('action', 'recovered')->count());
    }

    public function test_recovery_rejects_forged_wrong_hub_foreign_and_unneeded_scans(): void
    {
        $manifest = $this->manifests->create($this->provider, $this->calamba->id, $this->provinceHub->id);
        $item = $this->manifests->addParcel($manifest, $this->parcel);
        $this->actingAs($this->provider, 'logistics');

        // Nothing to recover on a draft.
        $this->postJson(route('logistics.manifests.scan.verify', [$manifest, 'hub' => $this->calamba->id]), [
            'tracking_number' => $this->parcel->tracking_number, 'phase' => 'recover',
        ])->assertStatus(422)->assertJsonPath('message', 'Only a batch cancelled after dispatch needs recovery scans.');

        $this->dispatchManifest($manifest, [$item->id => $this->scanForToken($manifest, $this->parcel, 'dispatch')]);
        $this->post(route('logistics.manifests.cancel', $manifest), ['cancellation_reason' => 'Driver unavailable']);

        // Only the batch's own origin or destination.
        $this->postJson(route('logistics.manifests.scan.verify', [$manifest, 'hub' => $this->losBanos->id]), [
            'tracking_number' => $this->parcel->tracking_number,
        ])->assertStatus(422)->assertJsonPath('errors.hub.0', "Choose the hub where the parcel is now: this batch's origin or destination.");

        // Forged token, and a token issued for the origin replayed at the destination.
        $this->post(route('logistics.manifests.items.recover', [$manifest, $item, 'hub' => $this->calamba->id]), ['verification_token' => 'forged'])
            ->assertSessionHasErrors();
        $originToken = $this->recoveryToken($manifest, $this->parcel, $this->calamba);
        $this->post(route('logistics.manifests.items.recover', [$manifest, $item, 'hub' => $this->provinceHub->id]), ['verification_token' => $originToken])
            ->assertSessionHasErrors();
        $this->assertNull($item->fresh()->recovered_at);

        // Another company cannot see or recover this batch.
        $other = LogisticsProvider::factory()->create(['status' => 'approved', 'email_verified_at' => now()]);
        $this->actingAs($other, 'logistics')
            ->post(route('logistics.manifests.items.recover', [$manifest, $item, 'hub' => $this->calamba->id]), ['verification_token' => $originToken])
            ->assertNotFound();

        // The genuine origin scan works.
        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.manifests.items.recover', [$manifest, $item, 'hub' => $this->calamba->id]), ['verification_token' => $originToken])
            ->assertSessionHasNoErrors();
        $this->assertSame((int) $this->calamba->id, (int) $item->fresh()->recovered_hub_id);

        // Already recovered: a second scan is refused.
        $this->postJson(route('logistics.manifests.scan.verify', [$manifest, 'hub' => $this->provinceHub->id]), [
            'tracking_number' => $this->parcel->tracking_number,
        ])->assertStatus(422)->assertJsonPath('message', 'This parcel was already recovered at Calamba Station.');
    }

    private function recoveryToken(Manifest $manifest, SellerOrder $parcel, LogisticsHub $hub): string
    {
        $response = $this->postJson(route('logistics.manifests.scan.verify', [$manifest, 'hub' => $hub->id]), [
            'tracking_number' => $parcel->tracking_number,
            'method' => 'manual',
        ]);
        $response->assertOk()->assertJsonPath('context', 'manifest_recover');

        return (string) $response->json('verification_token');
    }

    // ── Phase 2: IN_TRANSIT and ARRIVED_AT_HUB ──────────────────────────────

    public function test_cross_municipality_transfer_moves_in_transit_then_arrived_and_sorting_releases_it(): void
    {
        $manifest = $this->manifests->create($this->provider, $this->calamba->id, $this->losBanos->id);
        $item = $this->manifests->addParcel($manifest, $this->parcel);
        $this->actingAs($this->provider, 'logistics');
        $this->dispatchManifest($manifest, [$item->id => $this->scanForToken($manifest, $this->parcel, 'dispatch')]);

        $parcel = $this->parcel->fresh();
        $this->assertSame(OrderStatus::InTransit, $parcel->status);
        $this->assertSame('In transit', $parcel->status->buyerLabel());
        $this->assertSame((int) $this->calamba->id, $this->manifests->currentHubId($parcel), 'Custody stays at the origin while in transit.');
        $this->assertSame(OrderStatus::InTransit, $this->order->fresh()->status, 'The parent order rolls up to its parcel.');

        // Only a verified scan may move a parcel into the transit statuses.
        try {
            app(SellerOrderStatusService::class)->transition($parcel, OrderStatus::ArrivedAtHub,
                actor: OrderActor::Logistics, actorId: $this->provider->id);
            $this->fail('ARRIVED_AT_HUB must need a verified receiving scan.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Hub-to-hub movement is recorded only by a verified transfer scan.', $e->getMessage());
        }

        $token = $this->scanForToken($manifest, $this->parcel, 'receive');
        $this->post(route('logistics.manifests.receive.store', [$manifest, $item]), ['verification_token' => $token])
            ->assertRedirect(route('logistics.manifests.receive.index', $manifest));
        // A repeated submit records nothing more.
        $this->post(route('logistics.manifests.receive.store', [$manifest, $item]), ['verification_token' => $token]);

        $parcel = $this->parcel->fresh();
        $this->assertSame(OrderStatus::ArrivedAtHub, $parcel->status);
        $this->assertSame((int) $this->losBanos->id, (int) $parcel->custody_hub_id);
        $this->assertSame(1, $parcel->statusHistories()->where('to_status', OrderStatus::ArrivedAtHub->value)->count());
        $this->assertSame(1, $parcel->statusHistories()->where('to_status', OrderStatus::InTransit->value)->count());

        // The movement shows once in tracking, as hub events (not duplicated as statuses).
        $titles = collect($parcel->trackingEvents(true))->pluck('title')->all();
        $this->assertNotContains('In transit', $titles);
        $this->assertNotContains('Arrived at hub', $titles);

        // Sorting at the final station is the only way forward: ARRIVED_AT_HUB → SORTED.
        app(SellerOrderStatusService::class)->transition($parcel, OrderStatus::Sorted,
            actor: OrderActor::Logistics, actorId: $this->provider->id);
        $this->assertSame(OrderStatus::Sorted, $this->parcel->fresh()->status);
    }

    public function test_a_parcel_sorted_before_its_transfer_is_ready_again_at_its_final_hub(): void
    {
        $this->parcel->forceFill(['status' => OrderStatus::Sorted->value])->save();
        $manifest = $this->manifests->create($this->provider, $this->calamba->id, $this->losBanos->id);
        $item = $this->manifests->addParcel($manifest, $this->parcel->fresh());
        $this->actingAs($this->provider, 'logistics');
        $this->dispatchManifest($manifest, [$item->id => $this->scanForToken($manifest, $this->parcel, 'dispatch')]);
        $this->assertSame(OrderStatus::InTransit, $this->parcel->fresh()->status);

        $this->post(route('logistics.manifests.receive.store', [$manifest, $item]), [
            'verification_token' => $this->scanForToken($manifest, $this->parcel, 'receive'),
        ]);

        $this->assertSame(OrderStatus::Sorted, $this->parcel->fresh()->status);
        $this->assertSame(
            [OrderStatus::InTransit->value, OrderStatus::ArrivedAtHub->value, OrderStatus::Sorted->value],
            $this->parcel->fresh()->statusHistories()->orderBy('id')->get()->map(fn ($h) => $h->to_status->value)->all(),
        );
    }

    public function test_a_recovered_parcel_shows_where_it_was_found_and_never_resumes_on_its_own(): void
    {
        $this->parcel->forceFill(['status' => OrderStatus::Sorted->value])->save();
        $manifest = $this->manifests->create($this->provider, $this->calamba->id, $this->provinceHub->id);
        $item = $this->manifests->addParcel($manifest, $this->parcel->fresh());
        $this->actingAs($this->provider, 'logistics');
        $this->dispatchManifest($manifest, [$item->id => $this->scanForToken($manifest, $this->parcel, 'dispatch')]);
        $this->post(route('logistics.manifests.cancel', $manifest), ['cancellation_reason' => 'Ferry cancelled']);

        // Cancelling never assumes the parcel arrived anywhere.
        $this->assertSame(OrderStatus::InTransit, $this->parcel->fresh()->status);

        $this->post(route('logistics.manifests.items.recover', [$manifest, $item, 'hub' => $this->calamba->id]), [
            'verification_token' => $this->recoveryToken($manifest, $this->parcel, $this->calamba),
        ])->assertSessionHasNoErrors();

        $parcel = $this->parcel->fresh();
        // Even an already-sorted parcel stays ARRIVED_AT_HUB: its exception decides what happens next.
        $this->assertSame(OrderStatus::ArrivedAtHub, $parcel->status);
        $history = $parcel->statusHistories()->where('to_status', OrderStatus::ArrivedAtHub->value)->sole();
        $this->assertSame('recovery', $history->source);
        $this->assertStringContainsString('Recovered at Calamba Station', (string) $history->note);
        $this->assertContains('Arrived at hub', collect($parcel->trackingEvents())->pluck('title')->all());
    }

    // ── Phase 3: Couriers (transport personnel records) ────────────────────

    private function courierBatch(): array
    {
        config(['transfers.require_transport' => true]);
        $manifest = $this->manifests->create($this->provider, $this->calamba->id, $this->losBanos->id);
        $item = $this->manifests->addParcel($manifest, $this->parcel->fresh());
        $this->actingAs($this->provider, 'logistics');

        return [$manifest->fresh(), $item];
    }

    private function courier(LogisticsProvider $provider, array $attributes = []): TransferCourier
    {
        static $n = 0;
        $n++;

        return (new TransferCourier)->forceFill($attributes + [
            'logistics_provider_id' => $provider->id, 'name' => 'Courier '.$n, 'contact_no' => sprintf('091700%05d', $n),
            'vehicle_type' => 'Van', 'plate_number' => 'VAN '.(100 + $n), 'status' => TransferCourier::ACTIVE,
        ]);
    }

    public function test_courier_management_keeps_records_for_its_own_company_only(): void
    {
        $this->actingAs($this->provider, 'logistics')
            ->get(route('logistics.transfer-couriers.index'))->assertOk()->assertSee('No Couriers yet');

        $this->post(route('logistics.transfer-couriers.store'), [
            'name' => 'Juan Dela Cruz', 'contact_no' => '+639171234567', 'vehicle_type' => 'Van', 'plate_number' => ' nab  1234 ',
        ])->assertRedirect(route('logistics.transfer-couriers.index'))->assertSessionHasNoErrors();
        $courier = TransferCourier::query()->sole();
        $this->assertSame(['09171234567', 'NAB 1234', TransferCourier::ACTIVE, (int) $this->provider->id],
            [$courier->contact_no, $courier->plate_number, $courier->status, (int) $courier->logistics_provider_id]);

        $this->post(route('logistics.transfer-couriers.store'), ['name' => '', 'contact_no' => '123', 'vehicle_type' => 'Helicopter'])
            ->assertSessionHasErrors(['name', 'contact_no', 'vehicle_type']);
        $this->patch(route('logistics.transfer-couriers.update', $courier), [
            'name' => 'Juan D. Cruz', 'contact_no' => '09181234567', 'vehicle_type' => 'Car',
        ])->assertSessionHasNoErrors();
        $this->assertSame(['Juan D. Cruz', 'Car', null], [$courier->fresh()->name, $courier->fresh()->vehicle_type, $courier->fresh()->plate_number]);
        $this->get(route('logistics.transfer-couriers.index'))->assertSee('Juan D. Cruz')->assertSee('09181234567');

        // A Courier is a record, never an account, and another company cannot touch it.
        $this->assertFalse(Schema::hasColumn('transfer_couriers', 'password'));
        $other = LogisticsProvider::factory()->create(['status' => 'approved', 'email_verified_at' => now()]);
        $this->actingAs($other, 'logistics')->patch(route('logistics.transfer-couriers.update', $courier), [
            'name' => 'Stolen', 'contact_no' => '09171111111', 'vehicle_type' => 'Van',
        ])->assertNotFound();
        $this->post(route('logistics.transfer-couriers.status', $courier), ['active' => 0])->assertNotFound();
        $this->get(route('logistics.transfer-couriers.index'))->assertOk()->assertDontSee('Juan D. Cruz');

        $this->actingAs($this->provider, 'logistics')->post(route('logistics.transfer-couriers.status', $courier), ['active' => 0])->assertSessionHasNoErrors();
        $this->assertSame(TransferCourier::INACTIVE, $courier->fresh()->status);
    }

    public function test_a_new_batch_needs_a_courier_and_keeps_its_assignment_history(): void
    {
        [$manifest, $item] = $this->courierBatch();
        $this->assertTrue($manifest->transport_required);
        $token = $this->scanForToken($manifest, $this->parcel, 'dispatch');

        $this->post(route('logistics.manifests.dispatch', $manifest), ['tokens' => [$item->id => $token]])
            ->assertSessionHasErrors('transfer_courier_id');
        $this->assertSame(ManifestStatus::Draft, $manifest->fresh()->status);

        $first = $this->courier($this->provider);
        $first->save();
        $second = $this->courier($this->provider, ['vehicle_type' => 'Car', 'plate_number' => null]);
        $second->save();
        $this->get(route('logistics.manifests.show', $manifest))->assertOk()
            ->assertSee('Who carries this batch')->assertSee($first->name)->assertSee($second->name);

        // The Courier's usual vehicle and plate apply unless the form overrides them.
        $this->post(route('logistics.manifests.transport', $manifest), ['transfer_courier_id' => $first->id])->assertSessionHasNoErrors();
        $this->post(route('logistics.manifests.transport', $manifest), ['transfer_courier_id' => $first->id])->assertSessionHasNoErrors();
        $this->assertSame([(int) $first->id, 'Van', $first->plate_number],
            [(int) $manifest->fresh()->transfer_courier_id, $manifest->fresh()->transport_vehicle_type, $manifest->fresh()->transport_plate_number]);
        $this->post(route('logistics.manifests.transport', $manifest), [
            'transfer_courier_id' => $second->id, 'transport_vehicle_type' => 'Van', 'transport_plate_number' => 'rent 77',
        ])->assertSessionHasNoErrors();

        $history = TransferCourierAssignment::query()->where('manifest_id', $manifest->id)->orderBy('id')->get();
        $this->assertSame([(int) $first->id, (int) $second->id], $history->pluck('transfer_courier_id')->map(fn ($id) => (int) $id)->all());
        $this->assertNotNull($history[0]->replaced_at);
        $this->assertNull($history[1]->replaced_at);
        $this->assertSame(['Van', 'RENT 77'], [$history[1]->vehicle_type, $history[1]->plate_number]);

        $this->dispatchManifest($manifest, [$item->id => $this->scanForToken($manifest, $this->parcel, 'dispatch')]);
        $this->assertSame(OrderStatus::InTransit, $this->parcel->fresh()->status);
        $this->assertSame((int) $this->calamba->id, $this->manifests->currentHubId($this->parcel->fresh()), 'A Courier never takes custody.');

        // While carrying it, the Courier cannot be changed, deactivated or put on another transfer.
        $this->post(route('logistics.manifests.transport', $manifest), ['transfer_courier_id' => $first->id])->assertSessionHasErrors('transfer_courier_id');
        $this->post(route('logistics.transfer-couriers.status', $second), ['active' => 0])->assertSessionHasErrors('status');
        $other = $this->manifests->create($this->provider, $this->calamba->id, $this->provinceHub->id);
        $this->post(route('logistics.manifests.transport', $other), ['transfer_courier_id' => $second->id])
            ->assertSessionHasErrors(['transfer_courier_id' => $second->name.' is still carrying transfer batch #'.$manifest->id.'. Choose another Courier or wait for its receipt scan.']);

        // Only the receiving hub's verified scan completes it; then the Courier is free again.
        $this->post(route('logistics.manifests.receive.store', [$manifest, $item]), [
            'verification_token' => $this->scanForToken($manifest, $this->parcel, 'receive'),
        ]);
        $this->assertSame((int) $this->losBanos->id, (int) $this->parcel->fresh()->custody_hub_id);
        $this->post(route('logistics.manifests.transport', $other), ['transfer_courier_id' => $second->id])->assertSessionHasNoErrors();
        $this->assertSame(0, RiderEarning::query()->count(), 'Couriers earn nothing through Zefanya.');
    }

    public function test_only_an_active_courier_of_the_sending_company_can_carry_a_batch(): void
    {
        [$manifest] = $this->courierBatch();
        $other = LogisticsProvider::factory()->create(['status' => 'approved', 'email_verified_at' => now()]);
        $foreign = $this->courier($other);
        $foreign->save();
        $inactive = $this->courier($this->provider, ['status' => TransferCourier::INACTIVE]);
        $inactive->save();
        $ok = $this->courier($this->provider);
        $ok->save();

        $assign = fn (array $data) => $this->post(route('logistics.manifests.transport', $manifest), $data);
        $assign(['transfer_courier_id' => $foreign->id])->assertSessionHasErrors(['transfer_courier_id' => 'Choose a Courier from your own Courier list.']);
        $assign(['transfer_courier_id' => $inactive->id])->assertSessionHasErrors('transfer_courier_id');
        $assign(['transfer_courier_id' => $ok->id, 'transport_vehicle_type' => 'Helicopter'])->assertSessionHasErrors('transport_vehicle_type');
        $assign(['transfer_courier_id' => $ok->id, 'transport_plate_number' => 'ABC#1234'])->assertSessionHasErrors('transport_plate_number');
        $this->assertNull($manifest->fresh()->transfer_courier_id);
        $this->assertSame(0, TransferCourierAssignment::query()->count());

        $this->actingAs($other, 'logistics')->post(route('logistics.manifests.transport', $manifest), ['transfer_courier_id' => $foreign->id])
            ->assertNotFound();
    }

    public function test_batches_created_before_the_courier_rule_dispatch_as_before_and_riders_have_no_transfer_trips(): void
    {
        [$manifest, $item] = $this->courierBatch();
        $manifest->forceFill(['transport_required' => false])->save();
        $this->dispatchManifest($manifest, [$item->id => $this->scanForToken($manifest, $this->parcel, 'dispatch')]);

        // Riders keep pickups, deliveries and return pickups only.
        foreach (['rider.transfers.index', 'rider.transfers.start', 'api.rider.transfers.index', 'api.rider.transfers.start'] as $name) {
            $this->assertFalse(Route::has($name), $name);
        }
    }

    // ── Phase 5: stuck transfer monitoring ──────────────────────────────────

    public function test_an_overdue_transfer_is_shown_and_its_sender_is_told_once_without_moving_anything(): void
    {
        config(['transfers.overdue_after_hours' => 6]);
        $manifest = $this->manifests->create($this->provider, $this->calamba->id, $this->losBanos->id);
        $item = $this->manifests->addParcel($manifest, $this->parcel);
        $this->actingAs($this->provider, 'logistics');
        $this->dispatchManifest($manifest, [$item->id => $this->scanForToken($manifest, $this->parcel, 'dispatch')]);

        // Not yet overdue.
        $this->artisan('transfers:flag-overdue')->assertSuccessful();
        $this->assertSame(0, $this->provider->notifications()->count());
        $this->get(route('logistics.dashboard'))->assertOk()->assertViewHas('overdueTransferCount', 0);

        $this->travel(7)->hours();
        $this->get(route('logistics.dashboard'))->assertOk()->assertViewHas('overdueTransferCount', 1)
            ->assertSee('1 overdue transfer');
        $this->get(route('logistics.exceptions.index'))->assertOk()
            ->assertViewHas('overdueTransfers', fn ($list) => $list->pluck('id')->all() === [$manifest->id]);

        $this->artisan('transfers:flag-overdue')->assertSuccessful();
        $this->artisan('transfers:flag-overdue')->assertSuccessful();
        $this->assertSame(1, $this->provider->notifications()->where('data->type', 'transfer_overdue')->count());
        $this->assertNotNull($manifest->fresh()->overdue_notified_at);

        // Custody and route state are untouched.
        $this->assertSame(ManifestStatus::Dispatched, $manifest->fresh()->status);
        $this->assertSame(OrderStatus::InTransit, $this->parcel->fresh()->status);
        $this->assertSame((int) $this->calamba->id, $this->manifests->currentHubId($this->parcel->fresh()));

        // Another company never sees it.
        $other = LogisticsProvider::factory()->create(['status' => 'approved', 'email_verified_at' => now()]);
        $this->assertSame(0, app(StuckTransferService::class)->countFor((int) $other->id));

        // Verified receipt clears it.
        $this->post(route('logistics.manifests.receive.store', [$manifest, $item]), [
            'verification_token' => $this->scanForToken($manifest, $this->parcel, 'receive'),
        ]);
        $this->get(route('logistics.dashboard'))->assertViewHas('overdueTransferCount', 0);
    }
}
