<?php

namespace Tests\Feature\Logistics;

use App\Enums\OrderStatus;
use App\Enums\ParcelReturnStatus;
use App\Enums\PaymentStatus;
use App\Models\Courier;
use App\Models\DeliveryProof;
use App\Models\Logistics\Area;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Rider;
use App\Models\SellerOrder;
use App\Models\StatusHistory;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class DeliveryAssignmentsTest extends TestCase
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

    private function order(OrderStatus $status, ?Courier $deliveryCourier = null): SellerOrder
    {
        return SellerOrder::factory()->create([
            'status' => $status->value,
            'delivery_courier_id' => $deliveryCourier?->id,
        ])->fresh();
    }

    public function test_index_lists_assigned_to_rider_and_out_for_delivery_shipments(): void
    {
        $courier = Courier::factory()->create([
            'logistics_provider_id' => $this->provider->id,
            'is_available' => false,
        ]);

        $assigned = $this->order(OrderStatus::AssignedToRider, $courier);
        $outForDelivery = $this->order(OrderStatus::OutForDelivery, $courier);

        $response = $this->actingAs($this->provider, 'logistics')
            ->get(route('logistics.delivery-assignments.index'))
            ->assertOk()
            ->assertSee('#'.$assigned->id)
            ->assertSee('#'.$outForDelivery->id)
            ->assertSee('Not recorded')
            ->assertSee('Rider account not linked');
    }

    public function test_index_shows_real_station_rider_weight_proof_and_cod_details(): void
    {
        $province = LogisticsHub::query()->create([
            'logistics_provider_id' => $this->provider->id,
            'name' => 'Pampanga Hub',
            'hub_type' => LogisticsHub::PROVINCE,
            'region_code' => '030000000',
            'province_code' => $this->provider->province_code,
            'province_name' => 'Pampanga',
            'is_active' => true,
            'approval_status' => LogisticsHub::APPROVAL_APPROVED,
            'active_province_slot' => 1,
        ]);
        $station = LogisticsHub::query()->create([
            'logistics_provider_id' => $this->provider->id,
            'parent_hub_id' => $province->id,
            'name' => 'San Fernando Station',
            'hub_type' => LogisticsHub::STATION,
            'region_code' => '030000000',
            'province_code' => $this->provider->province_code,
            'province_name' => 'Pampanga',
            'municipality' => 'San Fernando',
            'is_active' => true,
            'approval_status' => LogisticsHub::APPROVAL_APPROVED,
        ]);
        $area = Area::query()->create([
            'logistics_provider_id' => $this->provider->id,
            'station_hub_id' => $station->id,
            'name' => 'San Fernando coverage',
            'municipality' => 'San Fernando',
            'is_active' => true,
        ]);
        $rider = Rider::factory()->create([
            'logistics_provider_id' => $this->provider->id,
            'station_hub_id' => $station->id,
            'first_name' => 'Rider',
            'last_name' => 'One',
            'status' => Rider::STATUS_APPROVED,
            'email_verified_at' => now(),
        ]);
        $courier = Courier::factory()->create([
            'logistics_provider_id' => $this->provider->id,
            'rider_id' => $rider->id,
            'area_id' => $area->id,
            'is_available' => false,
        ]);
        $shipment = $this->order(OrderStatus::Delivered, $courier);
        $shipment->forceFill([
            'tracking_number' => 'TRK-20261021-ABC123',
            'destination_area_id' => $area->id,
        ])->save();
        $product = Product::factory()->create(['seller_id' => $shipment->seller_id, 'weight_grams' => 1250]);
        OrderItem::factory()->create([
            'seller_order_id' => $shipment->id,
            'product_id' => $product->id,
            'quantity' => 1,
        ]);
        Payment::factory()->create([
            'seller_order_id' => $shipment->id,
            'amount_minor' => 150000,
            'method' => 'cod',
            'status' => PaymentStatus::Paid->value,
            'collected_amount_minor' => 150000,
            'collected_by_type' => 'rider',
            'collected_by_id' => $courier->id,
            'collected_at' => now(),
            'paid_at' => now(),
        ]);
        DeliveryProof::query()->create([
            'seller_order_id' => $shipment->id,
            'courier_id' => $courier->id,
            'rider_id' => $rider->id,
            'photo_path' => 'delivery-proofs/'.$shipment->id.'/proof.jpg',
            'photo_mime' => 'image/jpeg',
            'photo_size' => 120,
            'submitted_at' => now(),
        ]);

        $this->actingAs($this->provider, 'logistics')
            ->get(route('logistics.delivery-assignments.index'))
            ->assertOk()
            ->assertSee($shipment->tracking_number)
            ->assertSee($station->name)
            ->assertSee('Rider One')
            ->assertSee('1.25 kg')
            ->assertSee('View proof')
            ->assertSee('Collected');
    }

    /**
     * Provider B may not touch Provider A's parcel: sort, assign-delivery,
     * reschedule, return must all 403 via SellerOrderFleetPolicy, with no
     * state change and no history rows.
     */
    public function test_other_provider_is_forbidden_on_sort_assign_reschedule_and_return(): void
    {
        $otherProvider = LogisticsProvider::factory()->create([
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);
        $aCourier = Courier::factory()->create([
            'logistics_provider_id' => $otherProvider->id,
            'is_available' => false,
        ]);

        $actAsB = fn () => $this->actingAs($this->provider, 'logistics');

        // Slices owned by Provider A.
        $atSorting = SellerOrder::factory()->create([
            'status' => OrderStatus::AtSortingCenter->value,
            'courier_id' => $aCourier->id,
        ])->fresh();
        $sorted = SellerOrder::factory()->create([
            'status' => OrderStatus::Sorted->value,
            'courier_id' => $aCourier->id,
        ])->fresh();
        $deliveryCourier = Courier::factory()->create([
            'logistics_provider_id' => $otherProvider->id,
            'is_available' => false,
        ]);
        $assigned = SellerOrder::factory()->create([
            'status' => OrderStatus::AssignedToRider->value,
            'courier_id' => $aCourier->id,
            'delivery_courier_id' => $deliveryCourier->id,
        ])->fresh();
        $outForDelivery = SellerOrder::factory()->create([
            'status' => OrderStatus::OutForDelivery->value,
            'courier_id' => $aCourier->id,
            'delivery_courier_id' => $deliveryCourier->id,
        ])->fresh();
        $deliveryFailed = SellerOrder::factory()->create([
            'status' => OrderStatus::DeliveryFailed->value,
            'courier_id' => $aCourier->id,
            'delivery_courier_id' => $deliveryCourier->id,
            'delivery_attempts' => 1,
        ])->fresh();

        $attempts = [
            'sort' => fn () => $actAsB()->post(route('logistics.parcel-sorting.sort', $atSorting)),
            'assign' => fn () => $actAsB()->post(route('logistics.parcel-sorting.assignDelivery', $sorted)),
            'reschedule' => fn () => $actAsB()->post(route('logistics.delivery-assignments.reschedule', $deliveryFailed)),
            'return' => fn () => $actAsB()->post(route('logistics.delivery-assignments.return', $deliveryFailed)),
        ];

        $expectations = [
            [$atSorting, OrderStatus::AtSortingCenter],
            [$sorted, OrderStatus::Sorted],
            [$deliveryFailed, OrderStatus::DeliveryFailed],
            [$deliveryFailed, OrderStatus::DeliveryFailed],
        ];

        foreach (array_keys($attempts) as $i => $key) {
            [$slice, $status] = $expectations[$i];

            $beforeFailures = [
                $slice->delivery_failure_reason,
                $slice->delivery_courier_id,
            ];

            $attempts[$key]()->assertForbidden();

            $fresh = $slice->fresh();
            $this->assertSame($status, $fresh->status, "{$key}: status unchanged");
            $this->assertSame(
                $beforeFailures,
                [$fresh->delivery_failure_reason, $fresh->delivery_courier_id],
                "{$key}: courier/reason columns unchanged"
            );
            $this->assertSame(0, StatusHistory::where('seller_order_id', $slice->id)->count(), "{$key}: no history written");
        }
    }

    public function test_reschedule_fails_when_retry_limit_reached(): void
    {
        $courier = Courier::factory()->create([
            'logistics_provider_id' => $this->provider->id,
            'is_available' => false,
        ]);

        $so = $this->order(OrderStatus::DeliveryFailed, $courier);
        $so->forceFill(['delivery_attempts' => 2])->save();

        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.delivery-assignments.reschedule', $so))
            ->assertRedirect()
            ->assertSessionHas('error', 'The two-attempt limit has been reached. Return this parcel to the seller.');

        $this->assertSame(OrderStatus::DeliveryFailed, $so->fresh()->status);
    }

    public function test_return_action_requests_physical_transport_without_completing_the_return(): void
    {
        $courier = Courier::factory()->create([
            'logistics_provider_id' => $this->provider->id,
            'is_available' => false,
        ]);

        $so = $this->order(OrderStatus::DeliveryFailed, $courier);
        $so->forceFill(['delivery_attempts' => 2])->save();

        $response = $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.delivery-assignments.return', $so))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(OrderStatus::DeliveryFailed, $so->fresh()->status);
        $this->assertSame(ParcelReturnStatus::Requested, $so->fresh()->parcelReturn->status);
    }
}
