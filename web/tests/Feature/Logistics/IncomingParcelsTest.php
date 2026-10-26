<?php

namespace Tests\Feature\Logistics;

use App\Enums\OrderStatus;
use App\Models\Buyer\Buyer;
use App\Models\Courier;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Rider;
use App\Models\Seller\Seller;
use App\Models\SellerOrder;
use App\Services\ParcelHandoffVerification;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\CreatesAssignableFleet;
use Tests\Support\IssuesLogisticsHandoff;
use Tests\TestCase;

class IncomingParcelsTest extends TestCase
{
    use CreatesAssignableFleet, IssuesLogisticsHandoff, LazilyRefreshDatabase;

    private LogisticsProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = LogisticsProvider::factory()->create([
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);
        $this->completeNetwork($this->provider);
    }

    private function completeNetwork(LogisticsProvider $provider): void
    {
        // The provider owns one approved Province Hub and Station Hub; coverage
        // is what makes an unclaimed parcel visible/claimable.
        $station = $this->stationForProvider($provider);
        $this->coverMunicipality($provider, 'Quezon City', station: $station);
        $this->coverMunicipality($provider, 'San Fernando', station: $station);
        Rider::factory()->create([
            'logistics_provider_id' => $provider->id, 'status' => 'approved', 'email_verified_at' => now(),
            'station_hub_id' => $station->id,
        ]);
    }

    /**
     * A seller_order parked at the given status, owned by the logged-in
     * logistics provider's courier (or not yet couriered at all).
     */
    private function order(OrderStatus $status, ?Courier $courier = null): SellerOrder
    {
        return SellerOrder::factory()->create([
            'seller_id' => Seller::factory()->create(['barangay_code' => '1374040001'])->id,
            'status' => $status->value,
            'courier_id' => $courier?->id,
        ])->fresh();
    }

    public function test_index_lists_only_ready_for_pickup_and_picked_up_shipments(): void
    {
        // From this provider — should appear.
        $ready = $this->order(OrderStatus::ReadyForPickup);
        $pickedUp = $this->order(OrderStatus::PickedUp, Courier::factory()->create([
            'logistics_provider_id' => $this->provider->id,
            'is_available' => false,
        ]));

        // At the sorting center — should NOT appear here.
        $sorting = $this->order(OrderStatus::AtSortingCenter);

        $this->actingAs($this->provider, 'logistics')
            ->get(route('logistics.incoming-parcels.index'))
            ->assertOk()
            ->assertSee('#'.$ready->id)
            ->assertSee('#'.$pickedUp->id)
            ->assertDontSee('#'.$sorting->id);
    }

    public function test_legacy_pickup_route_is_removed_and_pickup_requires_rider_confirmation(): void
    {
        $ready = $this->order(OrderStatus::ReadyForPickup);

        $courier = $this->assignableCourier($this->provider, [
            'is_available' => true,
            'service_area' => ['Quezon City'],
            'max_weight_grams' => 5000,
        ]);

        $ready->order->update(['shipping_address' => ['municipality' => 'Quezon City', 'barangay_code' => '1374040001']]);

        // The old server-side advance is gone: pickup must go through the rider's
        // F2-verified confirmation.
        $this->actingAs($this->provider, 'logistics')
            ->post("/logistics/incoming-parcels/{$ready->id}/pickup")
            ->assertNotFound();

        $ready->refresh();
        $this->assertSame(OrderStatus::ReadyForPickup, $ready->status);
        $this->assertNull($ready->courier_id);
        $this->assertNull($ready->tracking_number, 'tracking number only minted by rider confirmation');
        $this->assertTrue($courier->fresh()->is_available);
    }

    public function test_pickup_with_no_matching_courier_shows_error(): void
    {
        $ready = $this->order(OrderStatus::ReadyForPickup);
        $order = $ready->order;
        $order->update(['shipping_address' => ['municipality' => 'Quezon City', 'barangay_code' => '1374040001']]);

        // Courier exists but the Rider account is not assignable.
        $courier = $this->assignableCourier($this->provider, [
            'max_weight_grams' => 5000,
        ]);
        $courier->rider->forceFill(['status' => 'pending'])->save();

        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.incoming-parcels.assignPickupRider', $ready), ['courier_id' => $courier->id])
            ->assertRedirect()
            ->assertSessionHas('error', 'No eligible rider in your fleet serves this pickup area.');

        $ready->refresh();
        $this->assertSame(OrderStatus::ReadyForPickup, $ready->status);
        $this->assertNull($ready->courier_id);
    }

    public function test_to_sorting_center_advances_and_releases_pickup_courier(): void
    {
        $this->coverMunicipality($this->provider, 'Quezon City', '137404000');

        $courier = Courier::factory()->create([
            'logistics_provider_id' => $this->provider->id,
            'is_available' => false,
        ]);

        $pickedUp = $this->order(OrderStatus::PickedUp, $courier);

        $this->actingAs($this->provider, 'logistics')
            ->post(
                route('logistics.incoming-parcels.toSortingCenter', $pickedUp),
                $this->withLogisticsHandoff($pickedUp, ParcelHandoffVerification::ACTION_STATION_RECEIPT),
            )
            ->assertRedirect()
            ->assertSessionHas('success');

        $pickedUp->refresh();
        $this->assertSame(OrderStatus::AtSortingCenter, $pickedUp->status);
        $this->assertTrue($courier->fresh()->is_available, 'pickup courier released');
    }

    public function test_to_sorting_center_rejected_if_not_in_pickup_phase(): void
    {
        $ready = $this->order(OrderStatus::ReadyForPickup);

        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.incoming-parcels.toSortingCenter', $ready))
            ->assertRedirect()
            ->assertSessionHas('error');

        $ready->refresh();
        $this->assertSame(OrderStatus::ReadyForPickup, $ready->status);
    }

    public function test_provider_cannot_claim_a_parcel_already_claimed_by_another_provider(): void
    {
        $otherProvider = LogisticsProvider::factory()->create([
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);
        $this->completeNetwork($otherProvider);

        // Provider B reserves the parcel first.
        $bCourier = $this->assignableCourier($otherProvider, [
            'service_area' => ['Quezon City'],
            'is_available' => true,
            'max_weight_grams' => 5000,
        ]);

        $order = $ready = $this->order(OrderStatus::ReadyForPickup);
        $order->update(['shipping_address' => ['municipality' => 'Quezon City', 'barangay_code' => '1374040001']]);

        $this->actingAs($otherProvider, 'logistics')
            ->post(route('logistics.incoming-parcels.assignPickupRider', $ready), ['courier_id' => $bCourier->id])
            ->assertRedirect()
            ->assertSessionHas('success');

        $ready->refresh();
        $this->assertSame(OrderStatus::ReadyForPickup, $ready->status);
        $this->assertSame($bCourier->id, (int) $ready->courier_id);

        // Provider A can no longer claim it (parcel already has Provider B's courier).
        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.incoming-parcels.assignPickupRider', $ready))
            ->assertForbidden();

        $this->assertSame($bCourier->id, (int) $ready->fresh()->courier_id, 'courier unchanged');
    }

    public function test_provider_cannot_assign_another_providers_courier(): void
    {
        $otherProvider = LogisticsProvider::factory()->create([
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);

        // Provider B has a matching courier; Provider A has none.
        $bCourier = $this->assignableCourier($otherProvider, [
            'service_area' => ['Quezon City'],
            'is_available' => true,
            'max_weight_grams' => 5000,
        ]);

        $ready = $this->order(OrderStatus::ReadyForPickup);
        $order = $ready->order;
        $order->update(['shipping_address' => ['municipality' => 'Quezon City', 'barangay_code' => '1374040001']]);

        // Provider A has no couriers in this area — reservePickup filters by
        // provider and finds nothing, even when Provider B's Rider is selected.
        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.incoming-parcels.assignPickupRider', $ready), ['courier_id' => $bCourier->id])
            ->assertRedirect()
            ->assertSessionHas('error', 'No eligible rider in your fleet serves this pickup area.');

        $ready->refresh();
        $this->assertSame(OrderStatus::ReadyForPickup, $ready->status);
        $this->assertNull($ready->courier_id);
    }

    public function test_provider_cannot_see_another_providers_claimed_parcel_in_index(): void
    {
        $otherProvider = LogisticsProvider::factory()->create([
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);

        $bCourier = Courier::factory()->create([
            'logistics_provider_id' => $otherProvider->id,
            'is_available' => false,
        ]);

        // Already claimed by Provider B (has a courier).
        $claimed = $this->order(OrderStatus::PickedUp, $bCourier);

        // Provider A should NOT see Provider B's claimed parcel.
        $this->actingAs($this->provider, 'logistics')
            ->get(route('logistics.incoming-parcels.index'))
            ->assertOk()
            ->assertDontSee('#'.$claimed->id);
    }

    public function test_provider_cannot_see_or_claim_unclaimed_parcels_outside_its_coverage(): void
    {
        // Outsider covers San Fernando (0305401001) only.
        $outsider = LogisticsProvider::factory()->create([
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);
        $this->coverMunicipality($outsider, 'San Fernando');

        // Seller origin is Quezon City (1374040001) — outside that coverage.
        $ready = $this->order(OrderStatus::ReadyForPickup);

        $this->actingAs($outsider, 'logistics')
            ->get(route('logistics.incoming-parcels.index'))
            ->assertOk()
            ->assertDontSee('#'.$ready->id);

        $this->actingAs($outsider, 'logistics')
            ->post(route('logistics.incoming-parcels.assignPickupRider', $ready))
            ->assertForbidden();

        $ready->refresh();
        $this->assertSame(OrderStatus::ReadyForPickup, $ready->status);
        $this->assertNull($ready->courier_id, 'outside-coverage provider cannot claim');

        // Missing seller origin fails closed for every provider.
        $noOrigin = SellerOrder::factory()->create([
            'status' => OrderStatus::ReadyForPickup->value,
            'courier_id' => null,
        ]);

        $this->actingAs($this->provider, 'logistics')
            ->get(route('logistics.incoming-parcels.index'))
            ->assertOk()
            ->assertDontSee('#'.$noOrigin->id);
    }

    public function test_unclaimed_parcel_requires_operational_coverage_not_just_a_row(): void
    {
        // Coverage exists but its Station Hub is deactivated: no longer claimable.
        $provider = LogisticsProvider::factory()->create([
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);
        $station = $this->stationForProvider($provider);
        $this->coverMunicipality($provider, 'Quezon City', station: $station);
        $station->parent->forceFill(['is_active' => false])->save();

        $ready = $this->order(OrderStatus::ReadyForPickup);

        $this->actingAs($provider, 'logistics')
            ->get(route('logistics.incoming-parcels.index'))
            ->assertOk()
            ->assertDontSee('#'.$ready->id);

        $this->actingAs($provider, 'logistics')
            ->post(route('logistics.incoming-parcels.assignPickupRider', $ready))
            ->assertForbidden();
    }

    public function test_pickup_rejected_when_not_ready_for_pickup(): void
    {
        $pickedUp = $this->order(OrderStatus::PickedUp, Courier::factory()->create([
            'logistics_provider_id' => $this->provider->id,
            'is_available' => false,
        ]));

        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.incoming-parcels.assignPickupRider', $pickedUp))
            ->assertRedirect()
            ->assertSessionHas('error', 'This parcel is no longer available for pickup assignment.');

        $this->assertSame(OrderStatus::PickedUp, $pickedUp->refresh()->status);
    }

    public function test_unclaimed_parcel_hides_buyer_name_until_claimed(): void
    {
        $buyer = Buyer::factory()->create([
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);

        $order = Order::factory()->create([
            'buyer_id' => $buyer->id,
            'shipping_address' => ['municipality' => 'Quezon City', 'barangay_code' => '1374040001'],
        ]);
        $unclaimed = SellerOrder::factory()->create([
            'order_id' => $order->id,
            'seller_id' => Seller::factory()->create(['barangay_code' => '1374040001'])->id,
            'status' => OrderStatus::ReadyForPickup->value,
            'courier_id' => null,
        ])->fresh();

        // Owned by this provider: buyer name + municipality visible.
        $claimed = $this->order(OrderStatus::PickedUp, Courier::factory()->create([
            'logistics_provider_id' => $this->provider->id,
            'is_available' => false,
        ]));
        $claimedBuyerName = $claimed->order->buyer->fullName();

        // Area (municipality) is always visible, even for unclaimed parcels.
        $html = $this->actingAs($this->provider, 'logistics')
            ->get(route('logistics.incoming-parcels.index'))
            ->assertOk()
            ->assertSee('#'.$unclaimed->id)
            ->getContent();

        $this->assertStringContainsString('Quezon City', $html, 'area must be visible to decide');
        $this->assertStringNotContainsString(
            $buyer->fullName(),
            $html,
            'unclaimed parcel must not leak the buyer name'
        );
        $this->assertStringContainsString(
            e($claimedBuyerName),
            $html,
            'claimed parcel keeps showing the buyer name'
        );
        $this->assertStringContainsString('Claim to reveal buyer details', $html);
    }

    public function test_unclaimed_parcel_shows_parcel_size(): void
    {
        $order = Order::factory()->create([
            'shipping_address' => ['municipality' => 'Quezon City', 'barangay_code' => '1374040001'],
        ]);
        $unclaimed = SellerOrder::factory()->create([
            'order_id' => $order->id,
            'seller_id' => Seller::factory()->create(['barangay_code' => '1374040001'])->id,
            'status' => OrderStatus::ReadyForPickup->value,
            'courier_id' => null,
        ])->fresh();

        OrderItem::factory()->create([
            'seller_order_id' => $unclaimed->id,
            'quantity' => 2,
            'product_id' => Product::factory()->create([
                'seller_id' => $unclaimed->seller_id,
                'weight_grams' => 600,
            ])->id,
        ]);

        $this->actingAs($this->provider, 'logistics')
            ->get(route('logistics.incoming-parcels.index'))
            ->assertOk()
            ->assertSee('1.2 kg', false);
    }

    public function test_to_sorting_center_rejected_when_not_picked_up(): void
    {
        $ready = $this->order(OrderStatus::ReadyForPickup);

        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.incoming-parcels.toSortingCenter', $ready))
            ->assertRedirect()
            ->assertSessionHas('error', 'This parcel is not in the pickup phase.');

        $this->assertSame(OrderStatus::ReadyForPickup, $ready->refresh()->status);
    }

    /**
     * Regression for the original silent-success bug: the controller used to
     * flash key `ready` while the view only rendered `success`/`error`.
     * Prove the reservation confirmation actually RENDERS on the page.
     */
    public function test_pickup_assignment_success_flash_renders_on_the_page(): void
    {
        $ready = $this->order(OrderStatus::ReadyForPickup);

        $courier = $this->assignableCourier($this->provider, [
            'is_available' => true,
            'service_area' => ['Quezon City'],
            'max_weight_grams' => 5000,
        ]);
        $ready->order->update(['shipping_address' => ['municipality' => 'Quezon City', 'barangay_code' => '1374040001']]);

        $this->from(route('logistics.incoming-parcels.index'));

        $response = $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.incoming-parcels.assignPickupRider', $ready), ['courier_id' => $courier->id])
            ->assertRedirect(route('logistics.incoming-parcels.index'))
            ->assertSessionHas('success');

        $this->actingAs($this->provider, 'logistics')
            ->get($response->headers->get('Location'))
            ->assertOk()
            ->assertSee('Waiting for the rider to confirm collection', false);
    }

    // ── New pickup reservation flow tests ─────────────────────────────────────

    public function test_assign_pickup_rider_reserves_without_changing_status(): void
    {
        $ready = $this->order(OrderStatus::ReadyForPickup);
        $order = $ready->order;
        $order->update(['shipping_address' => ['municipality' => 'Quezon City', 'barangay_code' => '1374040001']]);

        $courier = $this->assignableCourier($this->provider, [
            'is_available' => true,
            'service_area' => ['Quezon City'],
            'max_weight_grams' => 5000,
        ]);

        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.incoming-parcels.assignPickupRider', $ready), ['courier_id' => $courier->id])
            ->assertRedirect()
            ->assertSessionHas('success');

        $ready->refresh();
        // Status should remain ReadyForPickup (not advance to PickedUp)
        $this->assertSame(OrderStatus::ReadyForPickup, $ready->status);
        $this->assertSame($courier->id, (int) $ready->courier_id);
        $this->assertNull($ready->tracking_number, 'tracking number not minted until rider confirms');
        $this->assertFalse($courier->fresh()->is_available, 'courier marked busy');
    }

    public function test_assign_pickup_rider_rejects_duplicate_assignment(): void
    {
        $ready = $this->order(OrderStatus::ReadyForPickup);
        $order = $ready->order;
        $order->update(['shipping_address' => ['municipality' => 'Quezon City', 'barangay_code' => '1374040001']]);

        $courier = $this->assignableCourier($this->provider, [
            'is_available' => true,
            'service_area' => ['Quezon City'],
            'max_weight_grams' => 5000,
        ]);

        // First assignment succeeds
        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.incoming-parcels.assignPickupRider', $ready), ['courier_id' => $courier->id])
            ->assertRedirect()
            ->assertSessionHas('success');

        // Second assignment should be rejected (parcel already has a courier)
        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.incoming-parcels.assignPickupRider', $ready))
            ->assertRedirect()
            ->assertSessionHas('error', 'This parcel already has a pickup rider. Use reassignment to change.');

        $ready->refresh();
        $this->assertSame($courier->id, (int) $ready->courier_id, 'original assignment unchanged');
    }

    public function test_reassign_pickup_rider_releases_old_and_assigns_new(): void
    {
        $ready = $this->order(OrderStatus::ReadyForPickup);
        $order = $ready->order;
        $order->update(['shipping_address' => ['municipality' => 'Quezon City', 'barangay_code' => '1374040001']]);

        $firstCourier = $this->assignableCourier($this->provider, [
            'is_available' => true,
            'service_area' => ['Quezon City'],
            'max_weight_grams' => 5000,
        ]);

        $secondCourier = $this->assignableCourier($this->provider, [], 'Quezon City', '137404000');

        // Initial assignment
        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.incoming-parcels.assignPickupRider', $ready), ['courier_id' => $firstCourier->id])
            ->assertRedirect()
            ->assertSessionHas('success');

        $ready->refresh();
        $this->assertSame($firstCourier->id, (int) $ready->courier_id);

        // Reassign to second rider
        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.incoming-parcels.assignPickupRider', $ready), [
                'courier_id' => $secondCourier->id,
                'reassign' => 1, 'reason' => 'Original Rider unavailable.', 'expected_courier_id' => $firstCourier->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $ready->refresh();
        $this->assertSame($secondCourier->id, (int) $ready->courier_id, 'reassigned to new rider');
        $this->assertTrue($firstCourier->fresh()->is_available, 'first courier released');
        $this->assertFalse($secondCourier->fresh()->is_available, 'second courier marked busy');
    }

    public function test_assign_pickup_rider_enforces_company_boundary(): void
    {
        $otherProvider = LogisticsProvider::factory()->create([
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);
        $this->completeNetwork($otherProvider);

        $ready = $this->order(OrderStatus::ReadyForPickup);
        $order = $ready->order;
        $order->update(['shipping_address' => ['municipality' => 'Quezon City', 'barangay_code' => '1374040001']]);

        // Provider B has a matching courier; Provider A has none.
        $bCourier = $this->assignableCourier($otherProvider, [
            'service_area' => ['Quezon City'],
            'is_available' => true,
            'max_weight_grams' => 5000,
        ]);

        // Provider A tries to assign Provider B's Rider — should fail because
        // no eligible courier exists in Provider A's fleet.
        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.incoming-parcels.assignPickupRider', $ready), ['courier_id' => $bCourier->id])
            ->assertRedirect()
            ->assertSessionHas('error', 'No eligible rider in your fleet serves this pickup area.');

        $ready->refresh();
        $this->assertSame(OrderStatus::ReadyForPickup, $ready->status);
        $this->assertNull($ready->courier_id);
    }

    public function test_index_shows_seller_business_name_and_pickup_address(): void
    {
        $courier = $this->assignableCourier($this->provider, [
            'is_available' => false,
        ]);

        $claimed = $this->order(OrderStatus::PickedUp, $courier);
        // Set seller address details
        $claimed->seller->update([
            'business_name' => 'Test Business Corp',
            'house_number' => '123',
            'street' => 'Main St',
            'barangay_name' => 'Poblacion',
            'municipality_name' => 'San Fernando',
            'province_name' => 'Pampanga',
        ]);

        $html = $this->actingAs($this->provider, 'logistics')
            ->get(route('logistics.incoming-parcels.index'))
            ->assertOk()
            ->assertSee('#'.$claimed->id)
            ->getContent();

        $this->assertStringContainsString('Test Business Corp', $html, 'seller business_name displayed');
        $this->assertStringContainsString('123, Main St, Poblacion, San Fernando, Pampanga', $html, 'pickup address displayed');
    }

    public function test_index_shows_origin_station_for_assigned_parcel(): void
    {
        $courier = $this->assignableCourier($this->provider, [
            'is_available' => false,
        ]);

        $claimed = $this->order(OrderStatus::PickedUp, $courier);
        $claimed->forceFill(['origin_station_hub_id' => $courier->rider->station_hub_id])->save();

        $html = $this->actingAs($this->provider, 'logistics')
            ->get(route('logistics.incoming-parcels.index'))
            ->assertOk()
            ->assertSee('#'.$claimed->id)
            ->getContent();

        $stationName = $courier->rider->station->name;
        $this->assertStringContainsString($stationName, $html, 'origin station name displayed');
    }

    public function test_index_shows_state_labels(): void
    {
        $courier = $this->assignableCourier($this->provider, [
            'is_available' => false,
        ]);

        $unassigned = $this->order(OrderStatus::ReadyForPickup);
        $assigned = $this->order(OrderStatus::ReadyForPickup, $courier);
        $pickedUp = $this->order(OrderStatus::PickedUp, Courier::factory()->create([
            'logistics_provider_id' => $this->provider->id,
            'is_available' => false,
        ]));
        $needsReassign = $this->order(OrderStatus::ReadyForPickup, Courier::factory()->create([
            'logistics_provider_id' => $this->provider->id,
            'is_available' => false,
        ]));
        $needsReassign->forceFill(['needs_reassignment' => true, 'reassignment_reason' => 'Rider suspended'])->save();

        $html = $this->actingAs($this->provider, 'logistics')
            ->get(route('logistics.incoming-parcels.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Unassigned', $html);
        $this->assertStringContainsString('Awaiting pickup', $html);
        $this->assertStringContainsString('Picked up', $html);
        $this->assertStringContainsString('Needs reassignment', $html);
    }

    public function test_assign_pickup_rider_rejects_stale_request_after_concurrent_assignment(): void
    {
        $ready = $this->order(OrderStatus::ReadyForPickup);
        $order = $ready->order;
        $order->update(['shipping_address' => ['municipality' => 'Quezon City', 'barangay_code' => '1374040001']]);

        $courier1 = $this->assignableCourier($this->provider, [
            'is_available' => true,
            'service_area' => ['Quezon City'],
            'max_weight_grams' => 5000,
        ]);
        $courier2 = $this->assignableCourier($this->provider, [], 'Quezon City', '137404000');

        // Simulate concurrent assignment: first request assigns courier1
        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.incoming-parcels.assignPickupRider', $ready), ['courier_id' => $courier1->id])
            ->assertRedirect()
            ->assertSessionHas('success');

        $ready->refresh();
        $this->assertSame($courier1->id, (int) $ready->courier_id);

        // Second request (stale) tries to assign courier2 without reassign flag
        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.incoming-parcels.assignPickupRider', $ready), [
                'courier_id' => $courier2->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('error', 'This parcel already has a pickup rider. Use reassignment to change.');

        $ready->refresh();
        $this->assertSame($courier1->id, (int) $ready->courier_id, 'original assignment preserved');
    }
}
