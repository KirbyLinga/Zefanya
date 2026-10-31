<?php

namespace Tests\Feature\Logistics;

use App\Enums\OrderStatus;
use App\Models\Logistics\Area;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Rider\Courier;
use App\Models\Rider\Rider;
use App\Models\Seller\Seller;
use App\Models\Shared\SellerOrder;
use App\Models\Shared\StatusHistory;
use App\Services\Logistics\ParcelHandoffVerification;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\CreatesAssignableFleet;
use Tests\Support\IssuesLogisticsHandoff;
use Tests\TestCase;

class ParcelSortingTest extends TestCase
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
        $province = LogisticsHub::query()->create([
            'logistics_provider_id' => $this->provider->id, 'name' => 'Pampanga Hub',
            'hub_type' => LogisticsHub::PROVINCE,
            'region_code' => '030000000', 'province_code' => $this->provider->province_code,
            'province_name' => 'Pampanga', 'is_active' => true,
            'approval_status' => LogisticsHub::APPROVAL_APPROVED,
            'active_province_slot' => 1,
        ]);
        $station = LogisticsHub::query()->create([
            'logistics_provider_id' => $this->provider->id, 'parent_hub_id' => $province->id,
            'name' => 'San Fernando Station', 'hub_type' => LogisticsHub::STATION,
            'region_code' => '030000000', 'province_code' => $this->provider->province_code,
            'province_name' => 'Pampanga', 'municipality' => 'San Fernando', 'is_active' => true,
            'approval_status' => LogisticsHub::APPROVAL_APPROVED,
        ]);
        Area::query()->create([
            'logistics_provider_id' => $this->provider->id, 'station_hub_id' => $station->id,
            'name' => 'San Fernando', 'municipality' => 'San Fernando', 'is_active' => true,
            'barangay_code' => '0305401001', 'barangay_name' => 'First', 'coverage_validated_at' => now(),
        ]);
        Rider::factory()->create([
            'logistics_provider_id' => $this->provider->id, 'status' => 'approved', 'email_verified_at' => now(),
            'station_hub_id' => $station->id,
        ]);
        // Authoritative origin Station Hub for fixture sellers (San Fernando barangay).
        Area::query()->updateOrCreate(
            [
                'logistics_provider_id' => $this->provider->id,
                'barangay_code' => '0305401001',
            ],
            [
                'station_hub_id' => $station->id,
                'name' => 'San Fernando',
                'municipality' => 'San Fernando',
                'barangay_name' => 'First',
                'coverage_validated_at' => now(),
                'is_active' => true,
            ],
        );
    }

    private function order(OrderStatus $status, ?Courier $courier = null, ?Courier $deliveryCourier = null): SellerOrder
    {
        return SellerOrder::factory()->create([
            'seller_id' => Seller::factory()->create(['barangay_code' => '0305401001'])->id,
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
        $so->order->update(['shipping_address' => ['municipality' => 'San Fernando', 'barangay_code' => '0305401001']]);

        $this->actingAs($this->provider, 'logistics')
            ->post(
                route('logistics.parcel-sorting.sort', $so),
                $this->withLogisticsHandoff($so, ParcelHandoffVerification::ACTION_SORT),
            )
            ->assertRedirect()
            ->assertSessionHas('success', 'Parcel sorted for San Fernando.');

        $so->refresh();
        $this->assertSame(OrderStatus::Sorted, $so->status);
        $this->assertNotNull($so->destination_area_id);
        $this->assertSame(1, StatusHistory::where('seller_order_id', $so->id)->count());
        $this->assertStringContainsString(
            'Verified via manual tracking entry',
            (string) StatusHistory::query()->where('seller_order_id', $so->id)->value('note'),
        );
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
        $deliveryCourier = $this->assignableCourier($this->provider, [
            'is_available' => true,
            'service_area' => ['Quezon City'],
            'max_weight_grams' => 3000,
        ]);

        $order = $so->order;
        $order->update(['shipping_address' => ['municipality' => 'Quezon City', 'barangay_code' => '1374040001']]);
        $so->forceFill(['custody_provider_id' => $this->provider->id, 'custody_hub_id' => $deliveryCourier->rider->station_hub_id])->save();

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
        $order->update(['shipping_address' => ['municipality' => 'Quezon City', 'barangay_code' => '1374040001']]);

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

    public function test_assign_rider_action_opens_a_modal_listing_available_riders(): void
    {
        $pickupCourier = Courier::factory()->create([
            'logistics_provider_id' => $this->provider->id,
            'is_available' => false,
        ]);
        $deliveryCourier = $this->assignableCourier($this->provider, [
            'is_available' => true,
            'max_weight_grams' => 5000,
        ], 'San Fernando', '030540100');

        $sorted = $this->order(OrderStatus::Sorted, $pickupCourier);

        $this->actingAs($this->provider, 'logistics')
            ->get(route('logistics.parcel-sorting.index', ['tab' => 'sorting']))
            ->assertOk()
            ->assertSee('data-assign-open="assign-rider-'.$sorted->id.'"', false)
            ->assertSee('id="assign-rider-'.$sorted->id.'"', false)
            ->assertSee('Auto-assign by area')
            ->assertSee($deliveryCourier->name)
            ->assertDontSee('Reason for a manual Rider choice');
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

    public function test_parcels_are_sorted_by_destination_region_across_pages_with_unknown_regions_last(): void
    {
        $courier = Courier::factory()->create(['logistics_provider_id' => $this->provider->id]);
        $expected = [];
        foreach (['sorting' => OrderStatus::AtSortingCenter, 'assigned' => OrderStatus::AssignedToRider] as $tab => $status) {
            $groups = [];
            foreach (['13', '03'] as $region) {
                for ($i = 0; $i < 13; $i++) {
                    $parcel = $this->order($status, $courier);
                    $parcel->order->update(['shipping_address' => ['province_code' => $region.'0540000']]);
                    $parcel->forceFill(['created_at' => '2026-10-20 08:00:00'])->save();
                    $groups[$region][] = $parcel->id;
                }
            }
            $unknown = $this->order($status, $courier);
            $unknown->order->update(['shipping_address' => []]);
            $expected[$tab] = [...$groups['03'], ...$groups['13'], $unknown->id];
            $foreign = $this->order($status, $courier);
            $foreign->forceFill(['custody_provider_id' => LogisticsProvider::factory()->create()->id])->save();
        }
        foreach ($expected as $tab => $ids) {
            $first = $this->actingAs($this->provider, 'logistics')
                ->get(route('logistics.parcel-sorting.index', ['tab' => $tab]))
                ->assertOk()->assertSee('Destination region 03')->assertSee('Destination region 13');
            $this->assertSame(27, $first->viewData('shipments')->total());
            $this->assertSame(array_slice($ids, 0, 25), $first->viewData('shipments')->pluck('id')->all());
            $second = $this->get(route('logistics.parcel-sorting.index', ['tab' => $tab, 'page' => 2]))
                ->assertOk()->assertSee('Region unavailable');
            $this->assertSame(array_slice($ids, 25), $second->viewData('shipments')->pluck('id')->all());
        }
    }
}
