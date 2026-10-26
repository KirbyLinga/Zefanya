<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Buyer\Buyer;
use App\Models\Courier;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Rider;
use App\Models\Seller\Seller;
use App\Models\SellerOrder;
use App\Models\StatusHistory;
use App\Services\ParcelHandoffVerification;
use App\Services\RiderHandoffVerification;
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
 * F2 Stage 4 — end-to-end scan-required lifecycle across Seller → Rider →
 * Logistics → Rider → Buyer, plus enforcement negatives.
 */
class F2ScanEnforcedLifecycleTest extends TestCase
{
    use CreatesAssignableFleet, IssuesLogisticsHandoff, LazilyRefreshDatabase;

    private LogisticsProvider $provider;

    private Courier $courier;

    private Rider $rider;

    private Seller $seller;

    private Buyer $buyer;

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
    }

    public function test_scan_enforced_lifecycle_from_seller_ready_to_buyer_confirm(): void
    {
        $tracking = 'TRK-20261021-E2E001';
        $product = Product::factory()->create([
            'seller_id' => $this->seller->id,
            'weight_grams' => 500,
            'status' => 'active',
        ]);

        $order = Order::factory()->create([
            'buyer_id' => $this->buyer->id,
            'shipping_address' => [
                'name' => $this->buyer->fullName(),
                'phone' => $this->buyer->contact_no,
                'municipality' => 'Quezon City',
                'municipality_code' => '137404000',
                'barangay_code' => '1374040001',
                'barangay' => 'Barangay 1',
                'street' => '123 Mabini',
                'province' => 'Metro Manila',
                'postal_code' => '1100',
            ],
        ]);

        $parcel = SellerOrder::factory()->create([
            'order_id' => $order->id,
            'seller_id' => $this->seller->id,
            'status' => OrderStatus::Preparing->value,
            'tracking_number' => $tracking,
            'subtotal_minor' => 150000,
        ]);

        OrderItem::factory()->create([
            'seller_order_id' => $parcel->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price_minor' => 150000,
        ]);

        Payment::factory()->create([
            'seller_order_id' => $parcel->id,
            'method' => PaymentMethod::Cod,
            'status' => PaymentStatus::Pending,
            'amount_minor' => 150000,
        ]);

        // 1. Seller prepares parcel and prints QR waybill.
        $this->actingAs($this->seller, 'seller')
            ->post(route('seller.orders.markReady', $parcel))
            ->assertRedirect();

        $parcel = $parcel->fresh();
        $this->assertSame(OrderStatus::ReadyForPickup, $parcel->status);

        $this->actingAs($this->seller, 'seller')
            ->get(route('seller.shipments.label', $parcel))
            ->assertOk()
            ->assertSee($tracking, false)
            ->assertSee('data-qr-payload="'.$tracking.'"', false);

        // Logistics reserves pickup rider (no status change).
        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.incoming-parcels.assignPickupRider', $parcel), [
                'courier_id' => $this->courier->id,
                'reason' => 'Station Rider selected for collection.',
            ])
            ->assertRedirect();

        $parcel = $parcel->fresh();
        $this->assertSame($this->courier->id, (int) $parcel->courier_id);
        $this->assertSame(OrderStatus::ReadyForPickup, $parcel->status);

        // 2. Pickup Rider scans and confirms collection.
        $pickupToken = $this->actingAs($this->rider, 'rider')
            ->postJson(route('rider.scan.verify'), [
                'tracking_number' => $tracking,
                'method' => 'camera',
            ])
            ->assertOk()
            ->json('actions.0.verification_token');

        $this->actingAs($this->rider, 'rider')
            ->post(route('rider.pickups.confirm', $parcel), [
                'verification_token' => $pickupToken,
            ])
            ->assertRedirect();

        $parcel = $parcel->fresh();
        $this->assertSame(OrderStatus::PickedUp, $parcel->status);
        $this->assertStringContainsString(
            'Verified via camera QR',
            (string) StatusHistory::query()
                ->where('seller_order_id', $parcel->id)
                ->where('to_status', OrderStatus::PickedUp->value)
                ->value('note'),
        );

        // Missing logistics scan cannot advance station receipt.
        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.incoming-parcels.toSortingCenter', $parcel))
            ->assertSessionHasErrors('verification_token');
        $this->assertSame(OrderStatus::PickedUp, $parcel->fresh()->status);

        // 3. Logistics scans and confirms Station receipt.
        $receiptToken = $this->actingAs($this->provider, 'logistics')
            ->postJson(route('logistics.scan.verify'), [
                'tracking_number' => $tracking,
                'method' => 'manual',
            ])
            ->assertOk()
            ->assertJsonPath('actions.0.key', 'confirm_station_receipt')
            ->json('actions.0.verification_token');

        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.incoming-parcels.toSortingCenter', $parcel), [
                'verification_token' => $receiptToken,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $parcel = $parcel->fresh();
        $this->assertSame(OrderStatus::AtSortingCenter, $parcel->status);
        $this->assertStringContainsString(
            'Verified via manual tracking entry',
            (string) StatusHistory::query()
                ->where('seller_order_id', $parcel->id)
                ->where('to_status', OrderStatus::AtSortingCenter->value)
                ->value('note'),
        );

        // Replay of station-receipt token does not create a second history row.
        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.incoming-parcels.toSortingCenter', $parcel), [
                'verification_token' => $receiptToken,
            ])
            ->assertRedirect();
        $this->assertSame(
            1,
            StatusHistory::query()
                ->where('seller_order_id', $parcel->id)
                ->where('to_status', OrderStatus::AtSortingCenter->value)
                ->count(),
        );

        // 4. Logistics scans and confirms sorting.
        $sortToken = $this->actingAs($this->provider, 'logistics')
            ->postJson(route('logistics.scan.verify'), [
                'tracking_number' => $tracking,
                'method' => 'camera',
            ])
            ->assertOk()
            ->json('actions.0.verification_token');

        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.parcel-sorting.sort', $parcel), [
                'verification_token' => $sortToken,
            ])
            ->assertRedirect();

        $parcel = $parcel->fresh();
        $this->assertSame(OrderStatus::Sorted, $parcel->status);
        $this->assertStringContainsString(
            'Verified via camera QR',
            (string) StatusHistory::query()
                ->where('seller_order_id', $parcel->id)
                ->where('to_status', OrderStatus::Sorted->value)
                ->value('note'),
        );

        // Assign delivery rider.
        $this->courier->forceFill(['is_available' => true])->save();
        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.parcel-sorting.assignDelivery', $parcel), [
                'courier_id' => $this->courier->id,
                'reason' => 'The Station Rider will handle the final delivery leg.',
            ])
            ->assertRedirect();

        $parcel = $parcel->fresh();
        $this->assertSame(OrderStatus::AssignedToRider, $parcel->status);
        $this->assertSame($this->courier->id, (int) $parcel->delivery_courier_id);

        // 5. Delivery Rider scans and starts delivery.
        $startToken = $this->actingAs($this->rider, 'rider')
            ->postJson(route('rider.scan.verify'), [
                'tracking_number' => $tracking,
                'method' => 'manual',
            ])
            ->assertOk()
            ->json('actions.0.verification_token');

        $this->actingAs($this->rider, 'rider')
            ->post(route('rider.deliveries.start', $parcel), [
                'verification_token' => $startToken,
            ])
            ->assertRedirect();

        $this->assertSame(OrderStatus::OutForDelivery, $parcel->fresh()->status);

        // 6. Delivery Rider scans and completes POD/COD.
        $podToken = $this->actingAs($this->rider, 'rider')
            ->postJson(route('rider.scan.verify'), [
                'tracking_number' => $tracking,
                'method' => 'camera',
            ])
            ->assertOk()
            ->json('actions.0.verification_token');

        $this->actingAs($this->rider, 'rider')
            ->post(route('rider.deliveries.deliver', $parcel), [
                'verification_token' => $podToken,
                'proof_photo' => UploadedFile::fake()->create('pod.jpg', 120, 'image/jpeg'),
                'received_by' => 'Buyer',
                'cod_amount' => '1500.00',
            ])
            ->assertRedirect(route('rider.deliveries.index'));

        $parcel = $parcel->fresh();
        $this->assertSame(OrderStatus::Delivered, $parcel->status);
        $this->assertStringContainsString(
            'Verified via camera QR',
            (string) StatusHistory::query()
                ->where('seller_order_id', $parcel->id)
                ->where('to_status', OrderStatus::Delivered->value)
                ->value('note'),
        );

        // 7. Buyer confirms receipt.
        $this->actingAs($this->buyer, 'buyer')
            ->post(route('buyer.orders.confirmReceived', $order))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(OrderStatus::Completed, $parcel->fresh()->status);
    }

    public function test_expired_logistics_token_is_rejected(): void
    {
        $parcel = $this->seedPickedUpParcel();
        $token = app(ParcelHandoffVerification::class)->issueForLogistics(
            $this->provider,
            $parcel,
            ParcelHandoffVerification::ACTION_STATION_RECEIPT,
            ParcelHandoffVerification::METHOD_MANUAL,
            (int) $this->rider->station_hub_id,
        );

        $this->travel(11)->minutes();

        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.incoming-parcels.toSortingCenter', $parcel), [
                'verification_token' => $token,
            ])
            ->assertSessionHasErrors('verification_token');

        $this->assertSame(OrderStatus::PickedUp, $parcel->fresh()->status);
    }

    public function test_wrong_station_hub_token_is_rejected(): void
    {
        $parcel = $this->seedPickedUpParcel();
        $otherStation = $this->otherStationHubId();

        $token = app(ParcelHandoffVerification::class)->issueForLogistics(
            $this->provider,
            $parcel,
            ParcelHandoffVerification::ACTION_STATION_RECEIPT,
            ParcelHandoffVerification::METHOD_MANUAL,
            $otherStation,
        );

        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.incoming-parcels.toSortingCenter', $parcel), [
                'verification_token' => $token,
            ])
            ->assertSessionHasErrors('verification_token');

        $this->assertSame(OrderStatus::PickedUp, $parcel->fresh()->status);
    }

    public function test_wrong_rider_cannot_confirm_pickup_with_foreign_token(): void
    {
        $parcel = $this->seedReadyParcel();

        $foreignCourier = $this->assignableCourier($this->provider, [], 'Quezon City', '137404000');
        $foreignRider = $foreignCourier->rider;
        $foreignRider->forceFill(['password' => Hash::make('Rider@2026')])->save();

        $token = app(RiderHandoffVerification::class)->issue(
            $this->rider,
            $parcel,
            RiderHandoffVerification::ACTION_CONFIRM_PICKUP,
        );

        $this->actingAs($foreignRider, 'rider')
            ->post(route('rider.pickups.confirm', $parcel), [
                'verification_token' => $token,
            ])
            ->assertForbidden();

        $this->assertSame(OrderStatus::ReadyForPickup, $parcel->fresh()->status);
    }

    public function test_concurrent_station_receipt_confirmations_do_not_double_transition(): void
    {
        $parcel = $this->seedPickedUpParcel();

        $tokenA = app(ParcelHandoffVerification::class)->issueForLogistics(
            $this->provider,
            $parcel,
            ParcelHandoffVerification::ACTION_STATION_RECEIPT,
            ParcelHandoffVerification::METHOD_CAMERA,
            (int) $this->rider->station_hub_id,
        );
        $tokenB = app(ParcelHandoffVerification::class)->issueForLogistics(
            $this->provider,
            $parcel,
            ParcelHandoffVerification::ACTION_STATION_RECEIPT,
            ParcelHandoffVerification::METHOD_MANUAL,
            (int) $this->rider->station_hub_id,
        );

        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.incoming-parcels.toSortingCenter', $parcel), [
                'verification_token' => $tokenA,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.incoming-parcels.toSortingCenter', $parcel), [
                'verification_token' => $tokenB,
            ])
            ->assertRedirect();

        $this->assertSame(OrderStatus::AtSortingCenter, $parcel->fresh()->status);
        $this->assertSame(
            1,
            StatusHistory::query()
                ->where('seller_order_id', $parcel->id)
                ->where('to_status', OrderStatus::AtSortingCenter->value)
                ->count(),
        );
    }

    private function seedReadyParcel(): SellerOrder
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
            'status' => OrderStatus::ReadyForPickup->value,
            'courier_id' => $this->courier->id,
            'tracking_number' => 'TRK-20261021-'.strtoupper(Str::random(6)),
        ]);

        OrderItem::factory()->create([
            'seller_order_id' => $parcel->id,
            'product_id' => Product::factory()->create(['seller_id' => $this->seller->id])->id,
            'quantity' => 1,
        ]);

        return $parcel->fresh();
    }

    private function seedPickedUpParcel(): SellerOrder
    {
        $parcel = $this->seedReadyParcel();
        $parcel->forceFill([
            'status' => OrderStatus::PickedUp->value,
            'origin_station_hub_id' => $this->rider->station_hub_id,
        ])->save();

        return $parcel->fresh();
    }

    private function otherStationHubId(): int
    {
        $province = LogisticsHub::query()
            ->where('logistics_provider_id', $this->provider->id)
            ->where('hub_type', LogisticsHub::PROVINCE)
            ->firstOrFail();

        $station = LogisticsHub::query()->create([
            'logistics_provider_id' => $this->provider->id,
            'parent_hub_id' => $province->id,
            'name' => 'Other Station '.Str::random(4),
            'hub_type' => LogisticsHub::STATION,
            'region_code' => $province->region_code,
            'province_code' => $province->province_code ?: $this->provider->province_code,
            'province_name' => $province->province_name ?: $this->provider->province_name,
            'municipality' => 'Angeles',
            'is_active' => true,
            'approval_status' => LogisticsHub::APPROVAL_APPROVED,
        ]);

        return (int) $station->id;
    }
}
