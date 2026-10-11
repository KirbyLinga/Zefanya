<?php

namespace Tests\Feature\Logistics;

use App\Enums\OrderStatus;
use App\Models\Buyer\Buyer;
use App\Models\Courier;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\SellerOrder;
use App\Models\StatusHistory;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class IncomingParcelsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private LogisticsProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = LogisticsProvider::factory()->create([
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);
    }

    /**
     * A seller_order parked at the given status, owned by the logged-in
     * logistics provider's courier (or not yet couriered at all).
     */
    private function order(OrderStatus $status, ?Courier $courier = null): SellerOrder
    {
        return SellerOrder::factory()->create([
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

    public function test_pickup_assigns_a_courier_and_advances_to_picked_up(): void
    {
        $ready = $this->order(OrderStatus::ReadyForPickup);

        $courier = Courier::factory()->create([
            'logistics_provider_id' => $this->provider->id,
            'is_available' => true,
            'service_area' => ['Quezon City'],
            'max_weight_grams' => 5000,
        ]);

        // Set municipality for the order so candidate matching succeeds.
        $order = $ready->order;
        $order->update(['shipping_address' => ['municipality' => 'Quezon City']]);

        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.incoming-parcels.pickup', $ready))
            ->assertRedirect()
            ->assertSessionHas('success');

        $ready->refresh();
        $this->assertSame(OrderStatus::PickedUp, $ready->status);
        $this->assertSame($courier->id, (int) $ready->courier_id);
        $this->assertNotNull($ready->tracking_number);
        $this->assertFalse($courier->fresh()->is_available);
        $this->assertSame(1, StatusHistory::where('seller_order_id', $ready->id)->count());
    }

    public function test_pickup_with_no_matching_courier_shows_error(): void
    {
        $ready = $this->order(OrderStatus::ReadyForPickup);
        $order = $ready->order;
        $order->update(['shipping_address' => ['municipality' => 'Quezon City']]);

        // Courier exists but does not serve the municipality.
        Courier::factory()->create([
            'logistics_provider_id' => $this->provider->id,
            'service_area' => ['Cebu City'],
            'is_available' => true,
            'max_weight_grams' => 5000,
        ]);

        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.incoming-parcels.pickup', $ready))
            ->assertRedirect()
            ->assertSessionHas('error', 'No available courier in your fleet serves this area.');

        $ready->refresh();
        $this->assertSame(OrderStatus::ReadyForPickup, $ready->status);
        $this->assertNull($ready->courier_id);
    }

    public function test_to_sorting_center_advances_and_releases_pickup_courier(): void
    {
        $courier = Courier::factory()->create([
            'logistics_provider_id' => $this->provider->id,
            'is_available' => false,
        ]);

        $pickedUp = $this->order(OrderStatus::PickedUp, $courier);

        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.incoming-parcels.toSortingCenter', $pickedUp))
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

        // Provider B's courier claims the parcel first.
        $bCourier = Courier::factory()->create([
            'logistics_provider_id' => $otherProvider->id,
            'service_area' => ['Quezon City'],
            'is_available' => true,
            'max_weight_grams' => 5000,
        ]);

        $order = $ready = $this->order(OrderStatus::ReadyForPickup);
        $order->update(['shipping_address' => ['municipality' => 'Quezon City']]);

        $this->actingAs($otherProvider, 'logistics')
            ->post(route('logistics.incoming-parcels.pickup', $ready))
            ->assertRedirect()
            ->assertSessionHas('success');

        $ready->refresh();
        $this->assertSame(OrderStatus::PickedUp, $ready->status);
        $this->assertSame($bCourier->id, (int) $ready->courier_id);

        // Provider A can no longer claim it (parcel already has Provider B's courier).
        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.incoming-parcels.pickup', $ready))
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
        Courier::factory()->create([
            'logistics_provider_id' => $otherProvider->id,
            'service_area' => ['Quezon City'],
            'is_available' => true,
            'max_weight_grams' => 5000,
        ]);

        $ready = $this->order(OrderStatus::ReadyForPickup);
        $order = $ready->order;
        $order->update(['shipping_address' => ['municipality' => 'Quezon City']]);

        // Provider A has no couriers in this area — assignPickup filters by
        // provider and finds nothing.
        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.incoming-parcels.pickup', $ready))
            ->assertRedirect()
            ->assertSessionHas('error', 'No available courier in your fleet serves this area.');

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

    public function test_pickup_rejected_when_not_ready_for_pickup(): void
    {
        $pickedUp = $this->order(OrderStatus::PickedUp, Courier::factory()->create([
            'logistics_provider_id' => $this->provider->id,
            'is_available' => false,
        ]));

        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.incoming-parcels.pickup', $pickedUp))
            ->assertRedirect()
            ->assertSessionHas('error', 'This parcel is no longer available for pickup.');

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
            'shipping_address' => ['municipality' => 'Quezon City'],
        ]);
        $unclaimed = SellerOrder::factory()->create([
            'order_id' => $order->id,
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
            $claimedBuyerName,
            $html,
            'claimed parcel keeps showing the buyer name'
        );
        $this->assertStringContainsString('Claim to reveal buyer details', $html);
    }

    public function test_unclaimed_parcel_shows_parcel_size(): void
    {
        $order = Order::factory()->create([
            'shipping_address' => ['municipality' => 'Quezon City'],
        ]);
        $unclaimed = SellerOrder::factory()->create([
            'order_id' => $order->id,
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
     * Prove the pickup confirmation actually RENDERS on the page, not just
     * that it sits in the session.
     */
    public function test_pickup_success_flash_renders_on_the_page(): void
    {
        $ready = $this->order(OrderStatus::ReadyForPickup);

        Courier::factory()->create([
            'logistics_provider_id' => $this->provider->id,
            'is_available' => true,
            'service_area' => ['Quezon City'],
            'max_weight_grams' => 5000,
        ]);
        $ready->order->update(['shipping_address' => ['municipality' => 'Quezon City']]);

        $this->from(route('logistics.incoming-parcels.index'));

        $response = $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.incoming-parcels.pickup', $ready))
            ->assertRedirect(route('logistics.incoming-parcels.index'))
            ->assertSessionHas('success');

        $this->actingAs($this->provider, 'logistics')
            ->get($response->headers->get('Location'))
            ->assertOk()
            ->assertSee('picked up and assigned to', false);
    }
}
