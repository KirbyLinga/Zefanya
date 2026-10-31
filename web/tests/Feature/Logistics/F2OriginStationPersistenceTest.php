<?php

namespace Tests\Feature\Logistics;

use App\Enums\OrderStatus;
use App\Models\Catalog\Product;
use App\Models\Logistics\Area;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Rider\Courier;
use App\Models\Seller\Seller;
use App\Models\Shared\Order;
use App\Models\Shared\OrderItem;
use App\Models\Shared\SellerOrder;
use App\Services\Logistics\CourierAssignmentService;
use App\Services\Logistics\LogisticsHubService;
use App\Services\Logistics\ParcelHandoffVerification;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Support\CreatesAssignableFleet;
use Tests\Support\IssuesLogisticsHandoff;
use Tests\TestCase;

class F2OriginStationPersistenceTest extends TestCase
{
    use CreatesAssignableFleet, IssuesLogisticsHandoff, LazilyRefreshDatabase;

    private LogisticsProvider $provider;

    private Courier $courier;

    private Seller $seller;

    private int $originStationId;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->provider = LogisticsProvider::factory()->create([
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);
        $this->seller = Seller::factory()->create([
            'status' => 'approved',
            'municipality_name' => 'Quezon City',
            'municipality_code' => '137404000',
            'barangay_code' => '1374040001',
        ]);
        $this->courier = $this->assignableCourier($this->provider, [], 'Quezon City', '137404000');
        $this->originStationId = (int) $this->courier->rider->station_hub_id;
    }

    public function test_reserve_pickup_persists_origin_station_hub(): void
    {
        $parcel = $this->readyParcel();

        $assigned = app(CourierAssignmentService::class)->reservePickup(
            $parcel,
            $this->provider,
            $this->courier->id,
        );

        $this->assertNotNull($assigned);
        $parcel = $parcel->fresh();
        $this->assertSame($this->originStationId, (int) $parcel->origin_station_hub_id);
    }

    public function test_coverage_change_does_not_redirect_persisted_origin_station(): void
    {
        $parcel = $this->readyParcel();
        app(CourierAssignmentService::class)->reservePickup($parcel, $this->provider, $this->courier->id);
        $parcel = $parcel->fresh();
        $this->assertSame($this->originStationId, (int) $parcel->origin_station_hub_id);

        // Move QC barangay coverage to another station after routing.
        $other = $this->otherStation();
        Area::query()
            ->where('logistics_provider_id', $this->provider->id)
            ->where('barangay_code', '1374040001')
            ->update(['station_hub_id' => $other->id]);

        $resolved = app(LogisticsHubService::class)
            ->requireOriginStationHubId($this->provider->id, $parcel->fresh());

        $this->assertSame($this->originStationId, $resolved);
        $this->assertSame($this->originStationId, (int) $parcel->fresh()->origin_station_hub_id);
    }

    public function test_assign_origin_station_never_overwrites_existing(): void
    {
        $parcel = $this->readyParcel();
        $other = $this->otherStation();
        $parcel->forceFill(['origin_station_hub_id' => $other->id])->save();

        $result = app(LogisticsHubService::class)
            ->assignOriginStationIfMissing($parcel->fresh(), $this->provider->id);

        $this->assertSame($other->id, $result);
        $this->assertSame($other->id, (int) $parcel->fresh()->origin_station_hub_id);
    }

    public function test_missing_origin_station_without_unambiguous_legacy_is_rejected(): void
    {
        $parcel = $this->readyParcel();
        $parcel->forceFill([
            'status' => OrderStatus::PickedUp->value,
            'courier_id' => $this->courier->id,
            'origin_station_hub_id' => null,
        ])->save();

        // Detach rider station and remove coverage → no unambiguous signal.
        $this->courier->rider->forceFill(['station_hub_id' => null])->save();
        Area::query()
            ->where('logistics_provider_id', $this->provider->id)
            ->where('barangay_code', '1374040001')
            ->update(['is_active' => false]);

        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.incoming-parcels.toSortingCenter', $parcel), [
                'verification_token' => 'unused',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('station_hub');
    }

    public function test_unauthorized_provider_cannot_use_persisted_station(): void
    {
        $parcel = $this->readyParcel();
        app(CourierAssignmentService::class)->reservePickup($parcel, $this->provider, $this->courier->id);
        $parcel->forceFill(['status' => OrderStatus::PickedUp->value])->save();

        $other = LogisticsProvider::factory()->create([
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($other, 'logistics')
            ->post(
                route('logistics.incoming-parcels.toSortingCenter', $parcel),
                $this->withLogisticsHandoff($parcel, ParcelHandoffVerification::ACTION_STATION_RECEIPT),
            )
            ->assertForbidden();
    }

    public function test_receipt_uses_persisted_station_after_rider_station_cleared(): void
    {
        $parcel = $this->readyParcel();
        app(CourierAssignmentService::class)->reservePickup($parcel, $this->provider, $this->courier->id);
        $parcel->forceFill(['status' => OrderStatus::PickedUp->value])->save();
        $this->courier->rider->forceFill(['station_hub_id' => null])->save();

        $this->actingAs($this->provider, 'logistics')
            ->post(
                route('logistics.incoming-parcels.toSortingCenter', $parcel->fresh()),
                $this->withLogisticsHandoff($parcel->fresh(), ParcelHandoffVerification::ACTION_STATION_RECEIPT),
            )
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(OrderStatus::AtSortingCenter, $parcel->fresh()->status);
    }

    public function test_stale_parcel_cannot_overwrite_a_station_persisted_by_another_caller(): void
    {
        $stale = $this->readyParcel();
        $other = $this->otherStation();
        $stale->fresh()->forceFill(['origin_station_hub_id' => $other->id])->save();

        $resolved = app(LogisticsHubService::class)->assignOriginStationIfMissing($stale, $this->provider->id);

        $this->assertSame($other->id, $resolved);
        $this->assertSame($other->id, (int) $stale->fresh()->origin_station_hub_id);
    }

    public function test_current_rider_station_and_matching_coverage_do_not_prove_legacy_origin(): void
    {
        $parcel = $this->readyParcel();
        $parcel->forceFill(['status' => OrderStatus::PickedUp->value, 'courier_id' => $this->courier->id])->save();

        $this->assertNull(app(LogisticsHubService::class)->originStationHubId($this->provider->id, $parcel));
        $this->assertNull($parcel->originStationName());
        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.incoming-parcels.toSortingCenter', $parcel), ['verification_token' => 'unused'])
            ->assertSessionHasErrors('station_hub');

        $this->assertNull($parcel->fresh()->origin_station_hub_id);
        $this->assertSame($this->courier->id, (int) $parcel->fresh()->courier_id);
        $this->assertSame(OrderStatus::PickedUp, $parcel->fresh()->status);
    }

    public function test_rider_station_change_does_not_redirect_a_routed_parcel(): void
    {
        $parcel = $this->readyParcel();
        app(CourierAssignmentService::class)->reservePickup($parcel, $this->provider, $this->courier->id);
        $this->courier->rider->forceFill(['station_hub_id' => $this->otherStation()->id])->save();

        $resolved = app(LogisticsHubService::class)->requireOriginStationHubId($this->provider->id, $parcel->fresh());

        $this->assertSame($this->originStationId, $resolved);
        $this->assertSame($this->courier->id, (int) $parcel->fresh()->courier_id);
    }

    private function readyParcel(): SellerOrder
    {
        $order = Order::factory()->create([
            'shipping_address' => [
                'municipality' => 'Quezon City',
                'municipality_code' => '137404000',
                'barangay_code' => '1374040001',
            ],
        ]);

        $parcel = SellerOrder::factory()->create([
            'order_id' => $order->id,
            'seller_id' => $this->seller->id,
            'status' => OrderStatus::ReadyForPickup->value,
            'tracking_number' => 'TRK-20261021-'.strtoupper(Str::random(6)),
        ]);

        OrderItem::factory()->create([
            'seller_order_id' => $parcel->id,
            'product_id' => Product::factory()->create(['seller_id' => $this->seller->id, 'weight_grams' => 500])->id,
            'quantity' => 1,
        ]);

        return $parcel->fresh();
    }

    private function otherStation(): LogisticsHub
    {
        $province = LogisticsHub::query()
            ->where('logistics_provider_id', $this->provider->id)
            ->where('hub_type', LogisticsHub::PROVINCE)
            ->firstOrFail();

        return LogisticsHub::query()->create([
            'logistics_provider_id' => $this->provider->id,
            'parent_hub_id' => $province->id,
            'name' => 'Alt Station '.Str::random(4),
            'hub_type' => LogisticsHub::STATION,
            'region_code' => $province->region_code,
            'province_code' => $province->province_code ?: $this->provider->province_code,
            'province_name' => $province->province_name ?: $this->provider->province_name,
            'municipality' => 'Angeles',
            'is_active' => true,
            'approval_status' => LogisticsHub::APPROVAL_APPROVED,
        ]);
    }
}
