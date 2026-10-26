<?php

namespace Tests\Feature\Logistics;

use App\Enums\OrderStatus;
use App\Models\Courier;
use App\Models\Logistics\Area;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Seller\Seller;
use App\Models\SellerOrder;
use App\Models\StatusHistory;
use App\Services\WaybillQrCode;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\CreatesAssignableFleet;
use Tests\TestCase;

/**
 * F2 Stage 2 — Logistics tracking verification for station receipt / sorting.
 */
class ParcelScanTest extends TestCase
{
    use CreatesAssignableFleet, LazilyRefreshDatabase;

    private LogisticsProvider $provider;

    private Courier $courier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = LogisticsProvider::factory()->create([
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);

        $province = LogisticsHub::query()->create([
            'logistics_provider_id' => $this->provider->id,
            'name' => 'Pampanga Hub',
            'hub_type' => LogisticsHub::PROVINCE,
            'region_code' => '030000000',
            'province_code' => $this->provider->province_code,
            'province_name' => $this->provider->province_name,
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
            'province_name' => $this->provider->province_name,
            'municipality' => 'San Fernando',
            'is_active' => true,
            'approval_status' => LogisticsHub::APPROVAL_APPROVED,
        ]);
        Area::query()->create([
            'logistics_provider_id' => $this->provider->id,
            'station_hub_id' => $station->id,
            'name' => 'Quezon City',
            'municipality' => 'Quezon City',
            'municipality_code' => '137404000',
            'is_active' => true,
            'barangay_code' => '1374040001',
            'barangay_name' => 'First',
            'coverage_validated_at' => now(),
        ]);

        $this->courier = $this->assignableCourier($this->provider, [], 'Quezon City', '137404000');
    }

    private function parcel(OrderStatus $status, ?Courier $courier = null, ?string $tracking = null): SellerOrder
    {
        $seller = Seller::factory()->create([
            'status' => 'approved',
            'municipality_name' => 'Quezon City',
            'municipality_code' => '137404000',
            'barangay_code' => '1374040001',
        ]);

        $order = Order::factory()->create([
            'shipping_address' => [
                'municipality' => 'Quezon City',
                'municipality_code' => '137404000',
                'barangay_code' => '1374040001',
            ],
        ]);

        $sellerOrder = SellerOrder::factory()->status($status)->create([
            'order_id' => $order->id,
            'seller_id' => $seller->id,
            'courier_id' => $courier?->id,
        ]);

        $sellerOrder->forceFill([
            'tracking_number' => $tracking ?? ('TRK-20261021-'.strtoupper(Str::random(6))),
            'origin_station_hub_id' => $courier?->rider?->station_hub_id,
        ])->save();

        $product = Product::factory()->create(['seller_id' => $seller->id, 'weight_grams' => 400]);
        OrderItem::factory()->create([
            'seller_order_id' => $sellerOrder->id,
            'product_id' => $product->id,
            'quantity' => 1,
        ]);

        return $sellerOrder->fresh();
    }

    public function test_scan_page_renders_for_approved_logistics(): void
    {
        $this->withoutVite();

        $this->actingAs($this->provider, 'logistics')
            ->get(route('logistics.scan.index'))
            ->assertOk()
            ->assertSee('data-parcel-scanner', false)
            ->assertSee(route('logistics.scan.verify'), false);
    }

    public function test_logistics_can_verify_picked_up_parcel_read_only(): void
    {
        $parcel = $this->parcel(OrderStatus::PickedUp, $this->courier);
        $before = StatusHistory::query()->where('seller_order_id', $parcel->id)->count();

        $this->actingAs($this->provider, 'logistics')
            ->postJson(route('logistics.scan.verify'), [
                'tracking_number' => $parcel->tracking_number,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('context', 'station_receipt')
            ->assertJsonPath('actions.0.key', 'confirm_station_receipt')
            ->assertJsonPath('actions.0.url', route('logistics.incoming-parcels.toSortingCenter', $parcel));

        $this->assertSame(OrderStatus::PickedUp, $parcel->fresh()->status);
        $this->assertSame($before, StatusHistory::query()->where('seller_order_id', $parcel->id)->count());
    }

    public function test_station_receipt_confirm_after_verify_uses_existing_transition(): void
    {
        $parcel = $this->parcel(OrderStatus::PickedUp, $this->courier);

        $token = $this->actingAs($this->provider, 'logistics')
            ->postJson(route('logistics.scan.verify'), [
                'tracking_number' => $parcel->tracking_number,
                'method' => 'camera',
            ])
            ->assertOk()
            ->json('actions.0.verification_token');

        $this->assertNotEmpty($token);

        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.incoming-parcels.toSortingCenter', $parcel), [
                'verification_token' => $token,
            ])
            ->assertRedirect();

        $this->assertSame(OrderStatus::AtSortingCenter, $parcel->fresh()->status);
        $history = StatusHistory::query()
            ->where('seller_order_id', $parcel->id)
            ->where('to_status', OrderStatus::AtSortingCenter->value)
            ->first();
        $this->assertNotNull($history);
        $this->assertStringContainsString('Verified via camera QR', (string) $history->note);
    }

    public function test_station_receipt_without_verification_token_is_rejected(): void
    {
        $parcel = $this->parcel(OrderStatus::PickedUp, $this->courier);

        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.incoming-parcels.toSortingCenter', $parcel))
            ->assertRedirect()
            ->assertSessionHasErrors('verification_token');

        $this->assertSame(OrderStatus::PickedUp, $parcel->fresh()->status);
    }

    public function test_replayed_logistics_verification_token_is_rejected(): void
    {
        $parcel = $this->parcel(OrderStatus::PickedUp, $this->courier);

        $token = $this->actingAs($this->provider, 'logistics')
            ->postJson(route('logistics.scan.verify'), [
                'tracking_number' => $parcel->tracking_number,
            ])
            ->json('actions.0.verification_token');

        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.incoming-parcels.toSortingCenter', $parcel), [
                'verification_token' => $token,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(OrderStatus::AtSortingCenter, $parcel->fresh()->status);

        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.incoming-parcels.toSortingCenter', $parcel), [
                'verification_token' => $token,
            ])
            ->assertRedirect();

        $this->assertSame(
            1,
            StatusHistory::query()
                ->where('seller_order_id', $parcel->id)
                ->where('to_status', OrderStatus::AtSortingCenter->value)
                ->count(),
        );
    }

    public function test_sort_confirm_after_verify_records_manual_method(): void
    {
        $parcel = $this->parcel(OrderStatus::AtSortingCenter, $this->courier);

        $token = $this->actingAs($this->provider, 'logistics')
            ->postJson(route('logistics.scan.verify'), [
                'tracking_number' => $parcel->tracking_number,
                'method' => 'manual',
            ])
            ->assertOk()
            ->json('actions.0.verification_token');

        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.parcel-sorting.sort', $parcel), [
                'verification_token' => $token,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(OrderStatus::Sorted, $parcel->fresh()->status);
        $this->assertStringContainsString(
            'Verified via manual tracking entry',
            (string) StatusHistory::query()
                ->where('seller_order_id', $parcel->id)
                ->where('to_status', OrderStatus::Sorted->value)
                ->value('note'),
        );
    }

    public function test_sorting_action_offered_at_sorting_center(): void
    {
        $parcel = $this->parcel(OrderStatus::AtSortingCenter, $this->courier);

        $this->actingAs($this->provider, 'logistics')
            ->postJson(route('logistics.scan.verify'), [
                'tracking_number' => $parcel->tracking_number,
            ])
            ->assertOk()
            ->assertJsonPath('context', 'sorting')
            ->assertJsonPath('actions.0.key', 'sort_parcel');
    }

    public function test_foreign_provider_cannot_verify_another_networks_parcel(): void
    {
        $parcel = $this->parcel(OrderStatus::PickedUp, $this->courier);
        $other = LogisticsProvider::factory()->create([
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($other, 'logistics')
            ->postJson(route('logistics.scan.verify'), [
                'tracking_number' => $parcel->tracking_number,
            ])
            ->assertForbidden();
    }

    public function test_unknown_tracking_rejected(): void
    {
        $this->actingAs($this->provider, 'logistics')
            ->postJson(route('logistics.scan.verify'), [
                'tracking_number' => 'TRK-00000000-MISSING',
            ])
            ->assertNotFound();
    }

    public function test_verify_uses_same_payload_as_waybill_qr(): void
    {
        $tracking = 'TRK-20261021-HUB001';
        $parcel = $this->parcel(OrderStatus::PickedUp, $this->courier, $tracking);

        $this->assertSame($tracking, (new WaybillQrCode)->payload($parcel->tracking_number));

        $this->actingAs($this->provider, 'logistics')
            ->postJson(route('logistics.scan.verify'), ['tracking_number' => $tracking])
            ->assertOk()
            ->assertJsonPath('parcel.tracking_number', $tracking);
    }
}
