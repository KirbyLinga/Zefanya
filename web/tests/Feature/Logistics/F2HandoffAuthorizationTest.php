<?php

namespace Tests\Feature\Logistics;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Buyer\Buyer;
use App\Models\Catalog\Product;
use App\Models\Logistics\Area;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Rider\Courier;
use App\Models\Rider\Rider;
use App\Models\Seller\Seller;
use App\Models\Shared\Order;
use App\Models\Shared\OrderItem;
use App\Models\Shared\Payment;
use App\Models\Shared\SellerOrder;
use App\Models\Shared\StatusHistory;
use App\Services\Logistics\LogisticsHubService;
use App\Services\Logistics\ParcelHandoffVerification;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\CreatesAssignableFleet;
use Tests\Support\IssuesLogisticsHandoff;
use Tests\TestCase;

/**
 * F2 Stage 4 hardening — authoritative Station Hub binding and
 * authorization before idempotent handoff success.
 */
class F2HandoffAuthorizationTest extends TestCase
{
    use CreatesAssignableFleet, IssuesLogisticsHandoff, LazilyRefreshDatabase;

    private LogisticsProvider $provider;

    private Courier $courier;

    private Rider $rider;

    private Seller $seller;

    private Buyer $buyer;

    private int $originStationId;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Storage::fake('local');

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

        $this->buyer = Buyer::factory()->create([
            'status' => 'approved',
            'email_verified_at' => now(),
            'municipality_name' => 'Quezon City',
            'municipality_code' => '137404000',
            'barangay_code' => '1374040001',
        ]);

        $this->courier = $this->assignableCourier($this->provider, [], 'Quezon City', '137404000');
        $this->rider = $this->courier->rider;
        $this->rider->forceFill(['password' => Hash::make('Rider@2026')])->save();
        $this->rider = $this->rider->fresh();
        $this->originStationId = (int) $this->rider->station_hub_id;
    }

    public function test_correct_station_hub_receipt_is_allowed(): void
    {
        $parcel = $this->pickedUpParcel();

        $this->assertSame(
            $this->originStationId,
            app(LogisticsHubService::class)->originStationHubId($this->provider->id, $parcel),
        );

        $this->actingAs($this->provider, 'logistics')
            ->post(
                route('logistics.incoming-parcels.toSortingCenter', $parcel),
                $this->withLogisticsHandoff($parcel, ParcelHandoffVerification::ACTION_STATION_RECEIPT),
            )
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(OrderStatus::AtSortingCenter, $parcel->fresh()->status);
    }

    public function test_wrong_station_hub_token_is_rejected(): void
    {
        $parcel = $this->pickedUpParcel();
        $otherStation = $this->otherStationHub();

        $this->actingAs($this->provider, 'logistics')
            ->post(
                route('logistics.incoming-parcels.toSortingCenter', $parcel),
                $this->withLogisticsHandoff(
                    $parcel,
                    ParcelHandoffVerification::ACTION_STATION_RECEIPT,
                    stationHubId: $otherStation->id,
                ),
            )
            ->assertRedirect()
            ->assertSessionHasErrors('verification_token');

        $this->assertSame(OrderStatus::PickedUp, $parcel->fresh()->status);
    }

    public function test_missing_authoritative_station_hub_is_rejected(): void
    {
        $parcel = $this->pickedUpParcel();
        $parcel->forceFill(['origin_station_hub_id' => null])->save();
        $this->rider->forceFill(['station_hub_id' => null])->save();
        Area::query()
            ->where('logistics_provider_id', $this->provider->id)
            ->where('barangay_code', '1374040001')
            ->update(['is_active' => false]);

        $this->actingAs($this->provider, 'logistics')
            ->post(
                route('logistics.incoming-parcels.toSortingCenter', $parcel),
                ['verification_token' => 'unused'],
            )
            ->assertRedirect()
            ->assertSessionHasErrors('station_hub');

        $this->assertSame(OrderStatus::PickedUp, $parcel->fresh()->status);
    }

    public function test_pickup_rider_without_station_linkage_uses_coverage_routing(): void
    {
        $parcel = $this->pickedUpParcel();

        // Detach rider station — Logistics must still use seller barangay coverage.
        $this->rider->forceFill(['station_hub_id' => null])->save();
        $parcel->load('courier.rider');
        $this->assertNull($parcel->courier?->rider?->station_hub_id);

        $this->actingAs($this->provider, 'logistics')
            ->post(
                route('logistics.incoming-parcels.toSortingCenter', $parcel),
                $this->withLogisticsHandoff($parcel, ParcelHandoffVerification::ACTION_STATION_RECEIPT),
            )
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(OrderStatus::AtSortingCenter, $parcel->fresh()->status);
    }

    public function test_unauthorized_rider_retry_of_completed_pickup_is_rejected(): void
    {
        $parcel = $this->pickedUpParcel();

        $foreign = $this->assignableCourier($this->provider, [], 'Quezon City', '137404000');
        $foreign->rider->forceFill(['password' => Hash::make('Rider@2026')])->save();

        $this->actingAs($foreign->rider, 'rider')
            ->post(route('rider.pickups.confirm', $parcel), [
                'verification_token' => 'not-needed-for-idempotent',
            ])
            ->assertForbidden();

        $this->assertSame(
            0,
            StatusHistory::query()
                ->where('seller_order_id', $parcel->id)
                ->where('to_status', OrderStatus::PickedUp->value)
                ->count(),
        );
    }

    public function test_unauthorized_logistics_retry_of_completed_receipt_is_rejected(): void
    {
        $parcel = $this->pickedUpParcel();

        $this->actingAs($this->provider, 'logistics')
            ->post(
                route('logistics.incoming-parcels.toSortingCenter', $parcel),
                $this->withLogisticsHandoff($parcel, ParcelHandoffVerification::ACTION_STATION_RECEIPT),
            )
            ->assertSessionHas('success');

        $other = LogisticsProvider::factory()->create([
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($other, 'logistics')
            ->post(route('logistics.incoming-parcels.toSortingCenter', $parcel), [
                'verification_token' => 'forged',
            ])
            ->assertForbidden();

        $this->assertSame(
            1,
            StatusHistory::query()
                ->where('seller_order_id', $parcel->id)
                ->where('to_status', OrderStatus::AtSortingCenter->value)
                ->count(),
        );
    }

    public function test_wrong_provider_cannot_sort_foreign_parcel(): void
    {
        $parcel = $this->pickedUpParcel();
        $parcel->forceFill(['status' => OrderStatus::AtSortingCenter->value])->save();

        $other = LogisticsProvider::factory()->create([
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($other, 'logistics')
            ->post(route('logistics.parcel-sorting.sort', $parcel), [
                'verification_token' => 'forged',
            ])
            ->assertForbidden();

        $this->assertSame(OrderStatus::AtSortingCenter, $parcel->fresh()->status);
    }

    public function test_authorized_idempotent_receipt_retry_has_no_duplicate_side_effects(): void
    {
        $parcel = $this->pickedUpParcel();

        $this->actingAs($this->provider, 'logistics')
            ->post(
                route('logistics.incoming-parcels.toSortingCenter', $parcel),
                $this->withLogisticsHandoff($parcel, ParcelHandoffVerification::ACTION_STATION_RECEIPT),
            )
            ->assertSessionHas('success');

        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.incoming-parcels.toSortingCenter', $parcel))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(OrderStatus::AtSortingCenter, $parcel->fresh()->status);
        $this->assertSame(
            1,
            StatusHistory::query()
                ->where('seller_order_id', $parcel->id)
                ->where('to_status', OrderStatus::AtSortingCenter->value)
                ->count(),
        );
    }

    public function test_scan_required_sort_still_works_with_authoritative_station(): void
    {
        $parcel = $this->pickedUpParcel();
        $parcel->forceFill(['status' => OrderStatus::AtSortingCenter->value])->save();

        $token = $this->actingAs($this->provider, 'logistics')
            ->postJson(route('logistics.scan.verify'), [
                'tracking_number' => $parcel->tracking_number,
                'method' => 'camera',
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
            'Verified via camera QR',
            (string) StatusHistory::query()
                ->where('seller_order_id', $parcel->id)
                ->where('to_status', OrderStatus::Sorted->value)
                ->value('note'),
        );
    }

    public function test_pod_cod_token_is_preserved_after_invalid_amount(): void
    {
        $parcel = $this->outForDeliveryParcel();
        $token = app(ParcelHandoffVerification::class)->issueForRider(
            $this->rider,
            $parcel,
            ParcelHandoffVerification::ACTION_COMPLETE_DELIVERY,
        );

        $this->actingAs($this->rider, 'rider')
            ->post(route('rider.deliveries.deliver', $parcel), [
                'verification_token' => $token,
                'proof_photo' => UploadedFile::fake()->create('pod.jpg', 80, 'image/jpeg'),
                'cod_amount' => '1.00',
            ])
            ->assertSessionHasErrors('cod_amount_minor');

        $this->assertSame(OrderStatus::OutForDelivery, $parcel->fresh()->status);

        $this->actingAs($this->rider, 'rider')
            ->post(route('rider.deliveries.deliver', $parcel), [
                'verification_token' => $token,
                'proof_photo' => UploadedFile::fake()->create('pod2.jpg', 80, 'image/jpeg'),
                'cod_amount' => '1500.00',
            ])
            ->assertRedirect(route('rider.deliveries.index'))
            ->assertSessionHas('success');

        $this->assertSame(OrderStatus::Delivered, $parcel->fresh()->status);
    }

    private function pickedUpParcel(): SellerOrder
    {
        $order = Order::factory()->create([
            'buyer_id' => $this->buyer->id,
            'shipping_address' => [
                'municipality' => 'Quezon City',
                'municipality_code' => '137404000',
                'barangay_code' => '1374040001',
            ],
        ]);

        $parcel = SellerOrder::factory()->create([
            'order_id' => $order->id,
            'seller_id' => $this->seller->id,
            'status' => OrderStatus::PickedUp->value,
            'courier_id' => $this->courier->id,
            'origin_station_hub_id' => $this->originStationId,
            'tracking_number' => 'TRK-20261021-'.strtoupper(Str::random(6)),
        ]);

        OrderItem::factory()->create([
            'seller_order_id' => $parcel->id,
            'product_id' => Product::factory()->create(['seller_id' => $this->seller->id])->id,
            'quantity' => 1,
        ]);

        return $parcel->fresh(['seller', 'courier.rider']);
    }

    private function outForDeliveryParcel(): SellerOrder
    {
        $parcel = $this->pickedUpParcel();
        $parcel->forceFill([
            'status' => OrderStatus::OutForDelivery->value,
            'delivery_courier_id' => $this->courier->id,
        ])->save();

        Payment::factory()->create([
            'seller_order_id' => $parcel->id,
            'method' => PaymentMethod::Cod,
            'status' => PaymentStatus::Pending,
            'amount_minor' => 150000,
        ]);

        return $parcel->fresh();
    }

    private function otherStationHub(): LogisticsHub
    {
        $province = LogisticsHub::query()
            ->where('logistics_provider_id', $this->provider->id)
            ->where('hub_type', LogisticsHub::PROVINCE)
            ->firstOrFail();

        // Same verified province, different Station Hub — used to prove wrong-station tokens fail.
        return LogisticsHub::query()->create([
            'logistics_provider_id' => $this->provider->id,
            'parent_hub_id' => $province->id,
            'name' => 'Other Station '.Str::random(4),
            'hub_type' => LogisticsHub::STATION,
            'region_code' => $province->region_code ?: '030000000',
            'province_code' => $province->province_code ?: $this->provider->province_code,
            'province_name' => $province->province_name ?: $this->provider->province_name,
            'municipality' => 'Angeles',
            'city_municipality_code' => '030540200',
            'is_active' => true,
            'approval_status' => LogisticsHub::APPROVAL_APPROVED,
        ]);
    }
}
