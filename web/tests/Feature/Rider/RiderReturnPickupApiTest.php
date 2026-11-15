<?php

namespace Tests\Feature\Rider;

use App\Enums\OrderStatus;
use App\Models\Catalog\Product;
use App\Models\Logistics\Area;
use App\Models\Logistics\ParcelReturn;
use App\Models\Rider\Courier;
use App\Models\Rider\Rider;
use App\Models\Shared\OrderItem;
use App\Models\Shared\Payment;
use App\Models\Shared\StatusHistory;
use App\Services\Logistics\ParcelReturnService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\BootstrapsCrossProviderHandoffs;
use Tests\TestCase;

/**
 * Rider mobile return pickups: GET /api/rider/return-pickups and
 * POST /api/rider/return-pickups/{return}/collect. Both delegate to
 * ParcelReturnService (the Rider web page's service), so these tests pin the
 * API contract: ownership, tracking verification, duplicate collection (409),
 * role and token gates, and that custody moves exactly as on the web.
 */
class RiderReturnPickupApiTest extends TestCase
{
    use BootstrapsCrossProviderHandoffs;
    use LazilyRefreshDatabase;

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        $a = $this->operationalHandoffProvider('Laguna A', '043400000', 'Laguna', '043405000', 'Calamba', '0434050001', 'Bucal');
        $station = $a['station']->replicate();
        $station->name = 'Destination Station';
        $station->barangay_code = '0434050002';
        $station->save();
        $area = Area::query()->create(['logistics_provider_id' => $a['provider']->id, 'station_hub_id' => $station->id,
            'name' => 'Destination coverage', 'municipality' => 'Calamba', 'barangay_code' => '0434050002', 'barangay_name' => 'Second', 'city_municipality_code' => '043405000', 'coverage_validated_at' => now(), 'is_active' => true]);
        $seller = $this->sellerIn('043400000', 'Laguna', '043405000', 'Calamba', '0434050001', 'Bucal');
        $buyer = $this->buyerIn('043400000', 'Laguna', '043405000', 'Calamba', '0434050002', 'Destination');
        $parcel = $this->handoffParcel($seller, $buyer, $a['provider'], 'TRK-RETURN-'.bin2hex(random_bytes(4)), OrderStatus::OutForDelivery);
        $rider = Rider::factory()->create(['logistics_provider_id' => $a['provider']->id, 'station_hub_id' => $station->id, 'status' => 'approved', 'email_verified_at' => now()]);
        $courier = Courier::factory()->create(['logistics_provider_id' => $a['provider']->id, 'rider_id' => $rider->id, 'area_id' => $area->id, 'is_available' => false, 'max_weight_grams' => 100000]);
        $parcel->forceFill(['origin_station_hub_id' => $a['station']->id, 'custody_provider_id' => $a['provider']->id, 'custody_hub_id' => $station->id, 'delivery_courier_id' => $courier->id, 'destination_area_id' => $area->id,
            'pickup_address_snapshot' => ['full' => 'Original Seller pickup street, Bucal, Laguna', 'barangay_code' => '0434050001', 'province_code' => '043400000']])->save();
        $product = Product::factory()->create(['seller_id' => $seller->id, 'stock_quantity' => 3, 'weight_grams' => 500]);
        OrderItem::factory()->create(['seller_order_id' => $parcel->id, 'product_id' => $product->id, 'variant_id' => $product->variants()->firstOrFail()->id, 'quantity' => 2]);
        Payment::factory()->create(['seller_order_id' => $parcel->id, 'amount_minor' => 10000]);

        // Delivered, then the buyer asks for a return, the seller approves and
        // Logistics assigns this rider to collect it (the web's own steps).
        $parcel->forceFill(['status' => OrderStatus::Delivered->value])->save();
        StatusHistory::query()->create([
            'seller_order_id' => $parcel->id, 'from_status' => OrderStatus::OutForDelivery,
            'to_status' => OrderStatus::Delivered, 'actor_type' => 'rider', 'source' => 'web',
        ]);
        $returns = app(ParcelReturnService::class);
        $return = $returns->requestByBuyer($parcel->fresh(), $buyer, 'Item arrived damaged');
        $returns->approveBySeller($return, $seller);
        $returns->assignBuyerCollection($return, $a['provider'], $courier->id);

        return ['provider' => $a['provider'], 'station' => $station, 'area' => $area, 'parcel' => $parcel->fresh(), 'rider' => $rider, 'courier' => $courier, 'return' => $return->fresh()];
    }

    private function api(Rider $rider): static
    {
        $this->app['auth']->forgetGuards();
        $token = $rider->createToken('api-token', ['rider'], now()->addHour())->plainTextToken;

        return $this->withToken($token)->withHeader('Accept', 'application/json');
    }

    private function otherRider(array $f): Rider
    {
        $other = Rider::factory()->create(['logistics_provider_id' => $f['provider']->id, 'station_hub_id' => $f['station']->id, 'status' => 'approved', 'email_verified_at' => now()]);
        Courier::factory()->create(['logistics_provider_id' => $f['provider']->id, 'rider_id' => $other->id, 'area_id' => $f['area']->id]);

        return $other;
    }

    public function test_lists_only_my_assigned_returns_with_the_buyer_pickup_details(): void
    {
        $f = $this->fixture();

        $this->api($f['rider'])->getJson('/api/rider/return-pickups')->assertOk()
            ->assertJsonCount(1, 'data.returns')
            ->assertJsonPath('data.returns.0.id', $f['return']->id)
            ->assertJsonPath('data.returns.0.tracking_number', $f['parcel']->tracking_number)
            ->assertJsonPath('data.returns.0.reason', 'Item arrived damaged')
            ->assertJsonPath('data.returns.0.item_count', 2)
            ->assertJsonPath('data.returns.0.collected_at', null);

        $this->api($this->otherRider($f))->getJson('/api/rider/return-pickups')->assertOk()
            ->assertJsonCount(0, 'data.returns');
    }

    public function test_collecting_verifies_the_tracking_number_and_moves_custody_once(): void
    {
        $f = $this->fixture();
        $url = "/api/rider/return-pickups/{$f['return']->id}/collect";

        $this->api($f['rider'])->postJson($url, ['tracking_number' => 'WRONG'])
            ->assertStatus(422)->assertJsonValidationErrors('tracking_number');
        $this->assertNull($f['return']->fresh()->collected_at);

        $this->api($f['rider'])->postJson($url, ['tracking_number' => $f['parcel']->tracking_number])->assertOk()
            ->assertJsonPath('data.id', $f['return']->id)
            ->assertJsonPath('data.station_hub_id', $f['station']->id);

        $parcel = $f['parcel']->fresh();
        $this->assertNotNull($f['return']->fresh()->collected_at);
        $this->assertSame($f['station']->id, (int) $parcel->custody_hub_id);
        $this->assertSame($f['provider']->id, (int) $parcel->custody_provider_id);
        // Collection is custody only: no status change, refund or restock here.
        $this->assertSame(OrderStatus::Delivered, $parcel->status);
        $this->api($f['rider'])->getJson('/api/rider/return-pickups')->assertOk()->assertJsonCount(0, 'data.returns');

        // A repeat (double tap / lost response) is a 409 and changes nothing.
        $collectedAt = $f['return']->fresh()->collected_at;
        $this->api($f['rider'])->postJson($url, ['tracking_number' => $f['parcel']->tracking_number])
            ->assertStatus(409)->assertJsonValidationErrors('return');
        $this->assertEquals($collectedAt, $f['return']->fresh()->collected_at);
    }

    public function test_another_rider_cannot_collect_it(): void
    {
        $f = $this->fixture();

        $this->api($this->otherRider($f))->postJson("/api/rider/return-pickups/{$f['return']->id}/collect", ['tracking_number' => $f['parcel']->tracking_number])
            ->assertForbidden();

        $this->assertNull($f['return']->fresh()->collected_at);
    }

    public function test_validation_and_token_gates(): void
    {
        $f = $this->fixture();
        $url = "/api/rider/return-pickups/{$f['return']->id}/collect";

        $this->api($f['rider'])->postJson($url, [])->assertStatus(422)->assertJsonValidationErrors('tracking_number');
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->postJson($url, ['tracking_number' => $f['parcel']->tracking_number])->assertUnauthorized();
        $this->getJson('/api/rider/return-pickups')->assertUnauthorized();

        $buyerToken = $f['parcel']->order->buyer->createToken('api-token', ['buyer'], now()->addHour())->plainTextToken;
        $this->withToken($buyerToken)->postJson($url, ['tracking_number' => $f['parcel']->tracking_number])->assertForbidden();

        $this->assertNull($f['return']->fresh()->collected_at);
        $this->assertInstanceOf(ParcelReturn::class, $f['return']->fresh());
    }
}
