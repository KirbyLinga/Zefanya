<?php

namespace Tests\Feature\Logistics;

use App\Enums\OrderStatus;
use App\Models\Courier;
use App\Models\Logistics\LogisticsProvider;
use App\Models\SellerOrder;
use App\Models\StatusHistory;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ParcelSortingTest extends TestCase
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

    private function order(OrderStatus $status, ?Courier $courier = null, ?Courier $deliveryCourier = null): SellerOrder
    {
        return SellerOrder::factory()->create([
            'status' => $status->value,
            'courier_id' => $courier?->id,
            'delivery_courier_id' => $deliveryCourier?->id,
        ])->fresh();
    }

    public function test_index_lists_at_sorting_center_and_sorted_shipments(): void
    {
        $courier = Courier::factory()->create([
            'logistics_provider_id' => $this->provider->id,
            'is_available' => false,
        ]);

        $atSorting = $this->order(OrderStatus::AtSortingCenter, $courier);
        $sorted = $this->order(OrderStatus::Sorted, $courier);

        $this->actingAs($this->provider, 'logistics')
            ->get(route('logistics.parcel-sorting.index', ['tab' => 'sorting']))
            ->assertOk()
            ->assertSee('#'.$atSorting->id)
            ->assertSee('#'.$sorted->id);
    }

    public function test_index_with_assigned_tab_shows_only_assigned_to_rider_shipments(): void
    {
        $courier = Courier::factory()->create([
            'logistics_provider_id' => $this->provider->id,
            'is_available' => false,
        ]);

        $atSorting = $this->order(OrderStatus::AtSortingCenter, $courier);

        $deliveryCourier = Courier::factory()->create([
            'logistics_provider_id' => $this->provider->id,
            'is_available' => false,
        ]);

        $assigned = $this->order(OrderStatus::AssignedToRider, $courier, $deliveryCourier);

        $this->actingAs($this->provider, 'logistics')
            ->get(route('logistics.parcel-sorting.index', ['tab' => 'assigned']))
            ->assertOk()
            ->assertSee('#'.$assigned->id)
            ->assertDontSee('#'.$atSorting->id);
    }

    public function test_invalid_tab_falls_back_to_sorting(): void
    {
        $courier = Courier::factory()->create([
            'logistics_provider_id' => $this->provider->id,
            'is_available' => false,
        ]);

        $atSorting = $this->order(OrderStatus::AtSortingCenter, $courier);

        $this->actingAs($this->provider, 'logistics')
            ->get(route('logistics.parcel-sorting.index', ['tab' => 'bogus']))
            ->assertOk()
            ->assertSee('#'.$atSorting->id)
            ->assertSee('At sorting center', 'active tab highlight');
    }

    public function test_assigned_tab_shows_read_only_columns_without_action_buttons(): void
    {
        $courier = Courier::factory()->create([
            'logistics_provider_id' => $this->provider->id,
            'is_available' => false,
        ]);

        $deliveryCourier = Courier::factory()->create([
            'logistics_provider_id' => $this->provider->id,
            'is_available' => false,
            'name' => 'Delivery Rider Z',
        ]);

        $assigned = SellerOrder::factory()->create([
            'status' => OrderStatus::AssignedToRider->value,
            'courier_id' => $courier->id,
            'delivery_courier_id' => $deliveryCourier->id,
        ])->fresh();

        $html = $this->actingAs($this->provider, 'logistics')
            ->get(route('logistics.parcel-sorting.index', ['tab' => 'assigned']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('#'.$assigned->id, $html);
        $this->assertStringContainsString('Delivery Rider Z', $html);
        // No Sort / Assign buttons in the assigned tab.
        $this->assertStringNotContainsString(route('logistics.parcel-sorting.sort', $assigned), $html);
        $this->assertStringNotContainsString(route('logistics.parcel-sorting.assignDelivery', $assigned), $html);
    }

    public function test_sort_advances_at_sorting_center_to_sorted(): void
    {
        $courier = Courier::factory()->create([
            'logistics_provider_id' => $this->provider->id,
            'is_available' => false,
        ]);

        $so = $this->order(OrderStatus::AtSortingCenter, $courier);

        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.parcel-sorting.sort', $so))
            ->assertRedirect()
            ->assertSessionHas('success', 'Parcel sorted.');

        $so->refresh();
        $this->assertSame(OrderStatus::Sorted, $so->status);
        $this->assertSame(1, StatusHistory::where('seller_order_id', $so->id)->count());
    }

    public function test_sort_rejected_if_not_at_sorting_center(): void
    {
        $so = $this->order(OrderStatus::PickedUp);

        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.parcel-sorting.sort', $so))
            ->assertRedirect()
            ->assertSessionHas('error', 'This parcel has already been sorted.');
    }

    public function test_assign_delivery_assigns_a_courier_and_advances_to_assigned_to_rider(): void
    {
        $pickupCourier = Courier::factory()->create([
            'logistics_provider_id' => $this->provider->id,
            'is_available' => false,
        ]);

        $so = $this->order(OrderStatus::Sorted, $pickupCourier);

        // Delivery courier with lower weight so orderBy-asc prefers it.
        $deliveryCourier = Courier::factory()->create([
            'logistics_provider_id' => $this->provider->id,
            'is_available' => true,
            'service_area' => ['Quezon City'],
            'max_weight_grams' => 3000,
        ]);

        $order = $so->order;
        $order->update(['shipping_address' => ['municipality' => 'Quezon City']]);

        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.parcel-sorting.assignDelivery', $so))
            ->assertRedirect()
            ->assertSessionHas('success');

        $so->refresh();
        $this->assertSame(OrderStatus::AssignedToRider, $so->status);
        $this->assertSame($deliveryCourier->id, (int) $so->delivery_courier_id);
        $this->assertSame($pickupCourier->id, (int) $so->courier_id, 'pickup courier preserved');
        $this->assertFalse($deliveryCourier->fresh()->is_available);
    }

    public function test_assign_delivery_rejected_without_matching_courier(): void
    {
        $pickupCourier = Courier::factory()->create([
            'logistics_provider_id' => $this->provider->id,
            'is_available' => false,
        ]);

        $so = $this->order(OrderStatus::Sorted, $pickupCourier);

        $order = $so->order;
        $order->update(['shipping_address' => ['municipality' => 'Quezon City']]);

        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.parcel-sorting.assignDelivery', $so))
            ->assertRedirect()
            ->assertSessionHas('error');

        $so->refresh();
        $this->assertSame(OrderStatus::Sorted, $so->status);
    }

    public function test_tab_counts_match_the_rows_rendered(): void
    {
        $courier = Courier::factory()->create([
            'logistics_provider_id' => $this->provider->id,
            'is_available' => false,
        ]);
        $deliveryCourier = Courier::factory()->create([
            'logistics_provider_id' => $this->provider->id,
            'is_available' => false,
        ]);

        // Sorting tab: 2 parcels (AtSortingCenter + Sorted).
        $this->order(OrderStatus::AtSortingCenter, $courier);
        $this->order(OrderStatus::Sorted, $courier);
        // Assigned tab: 1 parcel.
        $this->order(OrderStatus::AssignedToRider, $courier, $deliveryCourier);

        $this->actingAs($this->provider, 'logistics')
            ->get(route('logistics.parcel-sorting.index', ['tab' => 'sorting']))
            ->assertOk()
            ->assertViewHas('tab', 'sorting')
            ->assertViewHas('sortingCount', 2)
            ->assertViewHas('assignedCount', 1);

        $this->actingAs($this->provider, 'logistics')
            ->get(route('logistics.parcel-sorting.index', ['tab' => 'assigned']))
            ->assertOk()
            ->assertViewHas('tab', 'assigned')
            ->assertViewHas('sortingCount', 2)
            ->assertViewHas('assignedCount', 1);
    }

    public function test_sorting_tab_filters_out_assigned_parcels_server_side(): void
    {
        $courier = Courier::factory()->create([
            'logistics_provider_id' => $this->provider->id,
            'is_available' => false,
        ]);
        $deliveryCourier = Courier::factory()->create([
            'logistics_provider_id' => $this->provider->id,
            'is_available' => false,
        ]);

        $atSorting = $this->order(OrderStatus::AtSortingCenter, $courier);
        $assigned = $this->order(OrderStatus::AssignedToRider, $courier, $deliveryCourier);

        $this->actingAs($this->provider, 'logistics')
            ->get(route('logistics.parcel-sorting.index', ['tab' => 'sorting']))
            ->assertOk()
            ->assertSee('#'.$atSorting->id)
            ->assertDontSee('#'.$assigned->id);
    }

    public function test_assign_delivery_rejected_when_not_sorted(): void
    {
        $courier = Courier::factory()->create([
            'logistics_provider_id' => $this->provider->id,
            'is_available' => false,
        ]);
        $so = $this->order(OrderStatus::AtSortingCenter, $courier);

        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.parcel-sorting.assignDelivery', $so))
            ->assertRedirect()
            ->assertSessionHas('error', 'This parcel must be sorted first.');

        $so->refresh();
        $this->assertSame(OrderStatus::AtSortingCenter, $so->status);
        $this->assertNull($so->delivery_courier_id);
    }
}
