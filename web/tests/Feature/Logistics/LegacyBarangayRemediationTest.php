<?php

namespace Tests\Feature\Logistics;

use App\Enums\OrderStatus;
use App\Models\Logistics\Area;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Order;
use App\Models\SellerOrder;
use App\Services\CourierAssignmentService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\CreatesAssignableFleet;
use Tests\TestCase;

class LegacyBarangayRemediationTest extends TestCase
{
    use CreatesAssignableFleet, LazilyRefreshDatabase;

    private function fixture(): array
    {
        Http::preventStrayRequests();
        Cache::put('psgc:provinces:all', [['code' => '043400000', 'name' => 'Laguna']]);
        Cache::put('psgc:cities:043400000:none', [['code' => '043411000', 'name' => "Los Ba\u{00F1}os"]]);
        Cache::put('psgc:barangays:043411000', [['code' => '043411006', 'name' => 'Bayog']]);
        $provider = LogisticsProvider::factory()->create(['status' => 'approved']);
        $courier = $this->assignableCourier($provider);
        $station = $courier->rider->station;
        $legacy = Area::query()->create(['logistics_provider_id' => $provider->id, 'station_hub_id' => $station->id,
            'name' => 'Legacy municipality', 'municipality' => "Los Ba\u{00F1}os", 'city_municipality_code' => '043411000', 'is_active' => true]);
        $area = Area::query()->create(['logistics_provider_id' => $provider->id, 'station_hub_id' => $station->id,
            'name' => 'Bayog', 'municipality' => "Los Ba\u{00F1}os", 'city_municipality_code' => '043411000',
            'barangay_code' => '043411006', 'barangay_name' => 'Bayog', 'coverage_validated_at' => now(), 'is_active' => true]);
        $order = Order::factory()->create(['shipping_address' => ['province' => 'Laguna', 'municipality' => "Los Ba\u{00F1}os", 'barangay' => 'Bayog', 'street' => 'Original checkout street']]);
        $parcel = SellerOrder::factory()->status(OrderStatus::Sorted)->create(['order_id' => $order->id]);
        $parcel->forceFill(['courier_id' => $courier->id, 'destination_area_id' => $legacy->id, 'origin_station_hub_id' => $station->id])->save();
        $courier->update(['area_id' => $legacy->id]);

        return compact('provider', 'courier', 'station', 'legacy', 'area', 'parcel', 'order');
    }

    private function confirmation(SellerOrder $parcel): array
    {
        return ['snapshot_hash' => $parcel->shippingAddressFingerprint(), 'barangay_code' => '043411006',
            'evidence' => 'Reviewed the original checkout snapshot province, municipality and Bayog barangay.', 'confirmed' => 1];
    }

    public function test_parcel_14_scenario_preserves_snapshot_and_pickup_while_verifying_and_reconciling_delivery(): void
    {
        extract($this->fixture());
        $original = $order->shipping_address;
        $this->actingAs($provider, 'logistics')->get(route('logistics.parcel-sorting.address.preview', $parcel))
            ->assertOk()->assertSee('Original checkout street')->assertSee('043411006');
        $this->assertNull($parcel->fresh()->delivery_address_verification);
        $this->actingAs($provider, 'logistics')->post(route('logistics.parcel-sorting.address.verify', $parcel), $this->confirmation($parcel))
            ->assertRedirect(route('logistics.parcel-sorting.index'))->assertSessionHasNoErrors();
        $parcel->refresh();
        $this->assertSame($original, $order->fresh()->shipping_address);
        $this->assertSame(OrderStatus::Sorted, $parcel->status);
        $this->assertSame($courier->id, $parcel->courier_id);
        $this->assertSame($area->id, $parcel->destination_area_id);
        $this->assertSame($legacy->id, $parcel->delivery_address_verification['original_destination_area_id']);
        $this->assertSame($provider->id, $parcel->delivery_address_verification['verified_by_provider_id']);
        $this->assertSame('043411006', $parcel->buyerBarangayCode());
        $assigned = (new CourierAssignmentService)->assignDelivery($parcel, $provider, areaId: $area->id);
        $this->assertSame($courier->id, $assigned?->id);
        $this->assertSame(OrderStatus::AssignedToRider, $parcel->fresh()->status);
        $this->assertSame($original, $order->fresh()->shipping_address);
    }

    public function test_legacy_area_hint_is_reconciled_for_an_existing_snapshot_code(): void
    {
        extract($this->fixture());
        $order->update(['shipping_address' => $order->shipping_address + ['barangay_code' => '043411006']]);
        $assigned = (new CourierAssignmentService)->assignDelivery($parcel->fresh(), $provider, areaId: $legacy->id);
        $this->assertSame($courier->id, $assigned?->id);
        $this->assertSame($area->id, $parcel->fresh()->destination_area_id);
    }

    public function test_missing_snapshot_code_cannot_be_replaced_by_current_buyer_profile(): void
    {
        extract($this->fixture());
        $order->buyer->update(['barangay_code' => '043411006']);
        $this->assertNull($parcel->buyerBarangayCode());
        $this->assertNull((new CourierAssignmentService)->assignDelivery($parcel, $provider));
        $this->assertNull($parcel->fresh()->delivery_courier_id);
    }

    public function test_cross_company_and_unclaimed_parcels_cannot_be_remediated(): void
    {
        extract($this->fixture());
        $other = LogisticsProvider::factory()->create(['status' => 'approved']);
        $this->actingAs($other, 'logistics')->get(route('logistics.parcel-sorting.address.preview', $parcel))->assertForbidden();
        $this->actingAs($other, 'logistics')->post(route('logistics.parcel-sorting.address.verify', $parcel), $this->confirmation($parcel))->assertForbidden();
        $parcel->forceFill(['courier_id' => null])->save();
        $this->actingAs($provider, 'logistics')->get(route('logistics.parcel-sorting.address.preview', $parcel))->assertForbidden();
        $this->assertNull($parcel->fresh()->delivery_address_verification);
    }

    #[TestWith(['missing'])]
    #[TestWith(['ambiguous'])]
    #[TestWith(['conflicting'])]
    public function test_inadequate_original_evidence_is_rejected(string $scenario): void
    {
        extract($this->fixture());
        if ($scenario === 'missing') {
            $address = $order->shipping_address;
            unset($address['barangay']);
            $order->update(['shipping_address' => $address]);
        } elseif ($scenario === 'ambiguous') {
            Cache::put('psgc:barangays:043411000', [['code' => 'one', 'name' => 'Bayog'], ['code' => 'two', 'name' => 'Bayog']]);
        } else {
            $order->update(['shipping_address' => $order->shipping_address + ['municipality_code' => 'wrong']]);
        }
        $this->actingAs($provider, 'logistics')->get(route('logistics.parcel-sorting.address.preview', $parcel))->assertSessionHasErrors('address');
        $this->assertNull($parcel->fresh()->delivery_address_verification);
        $this->assertSame($legacy->id, $parcel->fresh()->destination_area_id);
    }

    #[TestWith(['stale'])]
    #[TestWith(['forged'])]
    #[TestWith(['no_coverage'])]
    #[TestWith(['assigned'])]
    #[TestWith(['unconfirmed'])]
    public function test_unsafe_confirmation_is_rejected_without_mutation(string $scenario): void
    {
        extract($this->fixture());
        $data = $this->confirmation($parcel);
        if ($scenario === 'stale') {
            $data['snapshot_hash'] = str_repeat('0', 64);
        }
        if ($scenario === 'forged') {
            $data['barangay_code'] = 'invented';
        }
        if ($scenario === 'no_coverage') {
            $area->update(['is_active' => false]);
        }
        if ($scenario === 'assigned') {
            $parcel->forceFill(['delivery_courier_id' => $courier->id])->save();
        }
        if ($scenario === 'unconfirmed') {
            $data['confirmed'] = 0;
        }
        $this->actingAs($provider, 'logistics')->post(route('logistics.parcel-sorting.address.verify', $parcel), $data)->assertSessionHasErrors();
        $this->assertNull($parcel->fresh()->delivery_address_verification);
        $this->assertSame($legacy->id, $parcel->fresh()->destination_area_id);
    }

    public function test_verification_cannot_be_overwritten_and_is_invalidated_by_snapshot_changes(): void
    {
        extract($this->fixture());
        $data = $this->confirmation($parcel);
        $this->actingAs($provider, 'logistics')->post(route('logistics.parcel-sorting.address.verify', $parcel), $data)->assertSessionHasNoErrors();
        $audit = $parcel->fresh()->delivery_address_verification;
        $this->actingAs($provider, 'logistics')->post(route('logistics.parcel-sorting.address.verify', $parcel), $data)->assertSessionHasErrors('address');
        $this->assertSame($audit, $parcel->fresh()->delivery_address_verification);
        $address = $order->shipping_address;
        $address['barangay'] = 'Changed';
        $order->update(['shipping_address' => $address]);
        $this->assertNull($parcel->fresh()->buyerBarangayCode());
    }

    public function test_conflicting_legacy_station_reference_is_not_silently_rewritten(): void
    {
        extract($this->fixture());
        $legacy->update(['station_hub_id' => null]);
        $this->actingAs($provider, 'logistics')->post(route('logistics.parcel-sorting.address.verify', $parcel), $this->confirmation($parcel))->assertSessionHasErrors('address');
        $this->assertSame($legacy->id, $parcel->fresh()->destination_area_id);
    }
}
