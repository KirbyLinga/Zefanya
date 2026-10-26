<?php

namespace Tests\Feature\Rider;

use App\Enums\OrderStatus;
use App\Models\Buyer\Buyer;
use App\Models\Courier;
use App\Models\Logistics\Area;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Rider;
use App\Models\Seller\Seller;
use App\Models\SellerOrder;
use App\Services\RiderHandoffVerification;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\CreatesAssignableFleet;
use Tests\TestCase;

/**
 * Shared fixture for the Rider web-operations tests: one approved Logistics
 * company, an operational Rider (approved, verified, station-assigned and
 * linked to a Courier), a seller/buyer in the station's coverage, and helpers
 * to seed parcels at any pipeline status.
 */
abstract class RiderTestCase extends TestCase
{
    use CreatesAssignableFleet, LazilyRefreshDatabase;

    protected LogisticsProvider $provider;

    protected Courier $courier;

    protected Rider $rider;

    protected Seller $seller;

    protected Buyer $buyer;

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

        // Factory riders have no password; give the fixture a login.
        $this->rider->forceFill(['password' => Hash::make('Rider@2026')])->save();
        $this->rider = $this->rider->fresh();
    }

    /**
     * Seed a parcel at a pipeline status.
     *
     * @param  array<string, mixed>  $attributes  Fillable columns.
     * @param  array<string, mixed>  $force  Non-fillable columns written via forceFill.
     */
    protected function parcel(OrderStatus $status, array $attributes = [], array $force = []): SellerOrder
    {
        $order = Order::factory()->create([
            'buyer_id' => $this->buyer->id,
            'shipping_address' => [
                'name' => $this->buyer->fullName(),
                'phone' => $this->buyer->contact_no,
                'province' => 'Metro Manila',
                'municipality' => 'Quezon City',
                'municipality_code' => '137404000',
                'barangay_code' => '1374040001',
                'barangay' => 'Barangay 1',
                'street' => '123 Mabini Street',
                'house_number' => '12',
                'address_detail' => null,
                'postal_code' => '1100',
                'full' => '12, 123 Mabini Street, Barangay 1, Quezon City, Metro Manila, 1100',
            ],
        ]);

        $sellerOrder = SellerOrder::factory()->status($status)->create(array_merge([
            'order_id' => $order->id,
            'seller_id' => $this->seller->id,
        ], $attributes));

        $sellerOrder->forceFill(array_merge([
            'tracking_number' => 'TRK-20261020-'.strtoupper(Str::random(6)),
        ], $force))->save();

        $product = Product::factory()->create([
            'seller_id' => $this->seller->id,
            'weight_grams' => 500,
        ]);

        OrderItem::factory()->create([
            'seller_order_id' => $sellerOrder->id,
            'product_id' => $product->id,
            'quantity' => 1,
        ]);

        return $sellerOrder->fresh();
    }

    /** Act as the fixture rider on the rider guard. */
    protected function actingAsRider(?Rider $rider = null): static
    {
        return $this->actingAs($rider ?? $this->rider, 'rider');
    }

    /** Issue a short-lived handoff verification token for the fixture rider. */
    protected function handoffToken(SellerOrder $parcel, string $action, ?Rider $rider = null): string
    {
        return app(RiderHandoffVerification::class)->issue(
            $rider ?? $this->rider,
            $parcel,
            $action,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function withHandoff(SellerOrder $parcel, string $action, array $payload = [], ?Rider $rider = null): array
    {
        return array_merge($payload, [
            'verification_token' => $this->handoffToken($parcel, $action, $rider),
        ]);
    }

    /**
     * Build a second Station Hub (and its coverage area) for the provider, so
     * tests can prove a parcel routed to another station cannot be delivered
     * by this rider.
     */
    protected function otherStationArea(
        string $municipality = 'Angeles',
        string $code = '030540200',
    ): Area {
        $province = LogisticsHub::query()
            ->where('logistics_provider_id', $this->provider->id)
            ->where('hub_type', LogisticsHub::PROVINCE)
            ->firstOrFail();

        // Same verified province, different Station Hub — wrong destination for this rider.
        $station = LogisticsHub::query()->create([
            'logistics_provider_id' => $this->provider->id,
            'parent_hub_id' => $province->id,
            'name' => 'Other Station Hub '.$municipality,
            'hub_type' => LogisticsHub::STATION,
            'region_code' => $province->region_code ?: '030000000',
            'region_name' => $province->region_name ?: 'Region III',
            'province_code' => $province->province_code ?: $this->provider->province_code,
            'province_name' => $province->province_name ?: $this->provider->province_name,
            'city_municipality_code' => $code,
            'municipality' => $municipality,
            'is_active' => true,
            'approval_status' => LogisticsHub::APPROVAL_APPROVED,
        ]);

        return Area::query()->create([
            'logistics_provider_id' => $this->provider->id,
            'station_hub_id' => $station->id,
            'name' => $municipality,
            'municipality' => $municipality,
            'city_municipality_code' => $code,
            'is_active' => true,
        ]);
    }
}
