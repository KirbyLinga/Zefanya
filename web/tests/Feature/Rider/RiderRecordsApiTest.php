<?php

namespace Tests\Feature\Rider;

use App\Enums\OrderStatus;
use App\Models\Logistics\DeliveryProof;
use App\Models\Rider\Rider;
use App\Models\Rider\RiderEarning;
use App\Models\Rider\RiderPayout;
use App\Models\Shared\Payment;
use App\Models\Shared\SellerOrder;
use App\Services\Logistics\ParcelHandoffVerification;
use App\Services\Rider\RiderEarningService;
use Illuminate\Http\UploadedFile;

/**
 * Rider mobile records: payouts (GET /api/rider/payouts), the digital waybill
 * (GET /api/rider/waybills/{id}), proof photos (GET /api/rider/proofs/{id}),
 * the proof link on history and the station/coverage on the profile. Each
 * mirrors a Rider web page and its access rule.
 */
class RiderRecordsApiTest extends RiderTestCase
{
    private function api(?Rider $rider = null): static
    {
        $this->app['auth']->forgetGuards();
        $token = ($rider ?? $this->rider)->createToken('api-token', ['rider'], now()->addHour())->plainTextToken;

        return $this->withToken($token)->withHeader('Accept', 'application/json');
    }

    private function earning(int $amount, ?RiderPayout $payout = null): RiderEarning
    {
        $parcel = $this->parcel(OrderStatus::Delivered, ['delivery_courier_id' => $this->courier->id]);

        return RiderEarning::query()->forceCreate([
            'courier_id' => $this->courier->id,
            'logistics_provider_id' => $this->provider->id,
            'seller_order_id' => $parcel->id,
            'job' => RiderEarning::DELIVERY,
            'amount_minor' => $amount,
            'earned_at' => now(),
            'rider_payout_id' => $payout?->id,
        ]);
    }

    private function payout(int $amount, string $reference, ?int $courierId = null): RiderPayout
    {
        $payout = new RiderPayout;
        $payout->forceFill([
            'logistics_provider_id' => $this->provider->id,
            'courier_id' => $courierId ?? $this->courier->id,
            'earning_count' => 1,
            'amount_minor' => $amount,
            'reference' => $reference,
            'paid_on' => now()->toDateString(),
        ])->save();

        return $payout;
    }

    private function delivered(): DeliveryProof
    {
        $parcel = $this->parcel(
            OrderStatus::OutForDelivery,
            ['delivery_courier_id' => $this->courier->id],
            ['destination_area_id' => $this->courier->area_id],
        );
        $this->actingAsRider()->post(route('rider.deliveries.deliver', $parcel), $this->withHandoff($parcel, ParcelHandoffVerification::ACTION_COMPLETE_DELIVERY, [
            'proof_photo' => UploadedFile::fake()->image('proof.jpg'),
        ]))->assertRedirect();

        return DeliveryProof::query()->where('seller_order_id', $parcel->id)->firstOrFail();
    }

    public function test_payouts_show_what_logistics_paid_me_with_reversals_flagged(): void
    {
        $paid = $this->payout(6000, 'GCASH-001');
        $this->earning(6000, $paid);
        $this->earning(2500);
        $reversed = $this->payout(3000, 'GCASH-002');
        $this->earning(3000, $reversed);
        // Logistics reverses it through the real service: its job is unpaid again.
        app(RiderEarningService::class)->reversePayout($this->provider, $reversed, 'Recorded the wrong amount.');
        $other = $this->assignableCourier($this->provider, [], 'Quezon City', '137404000');
        $this->payout(99900, 'OTHER-RIDER', $other->id);

        $response = $this->api()->getJson('/api/rider/payouts')->assertOk()
            ->assertJsonPath('data.summary.paid_minor', 6000)
            ->assertJsonPath('data.summary.pending_minor', 5500)
            ->assertJsonCount(2, 'data.payouts');

        $rows = collect($response->json('data.payouts'))->keyBy('reference');
        $this->assertFalse($rows['GCASH-001']['reversed']);
        $this->assertTrue($rows['GCASH-002']['reversed']);
        $this->assertSame(6000, $rows['GCASH-001']['amount_minor']);
        $this->assertStringNotContainsString('OTHER-RIDER', $response->getContent());
    }

    public function test_pickup_waybill_shows_the_seller_and_hides_the_buyer(): void
    {
        $parcel = $this->parcel(OrderStatus::ReadyForPickup, ['courier_id' => $this->courier->id]);

        $response = $this->api()->getJson("/api/rider/waybills/{$parcel->id}")->assertOk()
            ->assertJsonPath('data.context', 'pickup')
            ->assertJsonPath('data.tracking_number', $parcel->tracking_number)
            ->assertJsonPath('data.buyer', null)
            ->assertHeader('Pragma', 'no-cache');

        $this->assertNotNull($response->json('data.seller.name'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_delivery_waybill_shows_the_buyer_and_the_cod_to_collect(): void
    {
        $parcel = $this->parcel(
            OrderStatus::OutForDelivery,
            ['delivery_courier_id' => $this->courier->id],
            ['destination_area_id' => $this->courier->area_id],
        );
        Payment::factory()->create(['seller_order_id' => $parcel->id, 'amount_minor' => 150000]);

        $this->api()->getJson("/api/rider/waybills/{$parcel->id}")->assertOk()
            ->assertJsonPath('data.context', 'delivery')
            ->assertJsonPath('data.seller', null)
            ->assertJsonPath('data.collect_cod_minor', 150000);
    }

    public function test_waybill_is_refused_for_another_riders_parcel_or_a_finished_one(): void
    {
        $other = $this->assignableCourier($this->provider, [], 'Quezon City', '137404000');
        $theirs = $this->parcel(OrderStatus::ReadyForPickup, ['courier_id' => $other->id]);
        $finished = $this->parcel(OrderStatus::Delivered, ['delivery_courier_id' => $this->courier->id]);

        $this->api()->getJson("/api/rider/waybills/{$theirs->id}")->assertForbidden();
        $this->api()->getJson("/api/rider/waybills/{$finished->id}")->assertForbidden();
    }

    public function test_proof_photo_streams_only_to_the_rider_who_submitted_it_and_history_links_it(): void
    {
        $proof = $this->delivered();

        $this->api()->get("/api/rider/proofs/{$proof->id}")->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->api()->getJson('/api/rider/history')->assertOk()
            ->assertJsonPath('data.history.0.id', $proof->seller_order_id)
            ->assertJsonPath('data.history.0.proof_id', $proof->id);

        $other = $this->assignableCourier($this->provider, [], 'Quezon City', '137404000');
        $this->api($other->rider->fresh())->getJson("/api/rider/proofs/{$proof->id}")->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->getJson("/api/rider/proofs/{$proof->id}")->assertUnauthorized();
        $buyerToken = $this->buyer->createToken('api-token', ['buyer'], now()->addHour())->plainTextToken;
        $this->withToken($buyerToken)->getJson("/api/rider/proofs/{$proof->id}")->assertForbidden();
    }

    public function test_profile_includes_the_station_and_coverage_area(): void
    {
        $this->rider->loadMissing(['station', 'courier.area']);

        $this->api()->getJson('/api/rider/profile')->assertOk()
            ->assertJsonPath('data.station.id', $this->rider->station_hub_id)
            ->assertJsonPath('data.station.municipality', $this->rider->station->municipality)
            ->assertJsonPath('data.coverage_area.id', $this->courier->area_id);
    }

    public function test_records_need_a_rider_token(): void
    {
        $parcel = $this->parcel(OrderStatus::ReadyForPickup, ['courier_id' => $this->courier->id]);

        $this->getJson('/api/rider/payouts')->assertUnauthorized();
        $this->getJson("/api/rider/waybills/{$parcel->id}")->assertUnauthorized();

        $buyerToken = $this->buyer->createToken('api-token', ['buyer'], now()->addHour())->plainTextToken;
        $this->withToken($buyerToken)->getJson('/api/rider/payouts')->assertForbidden();
        $this->withToken($buyerToken)->getJson("/api/rider/waybills/{$parcel->id}")->assertForbidden();
        $this->assertInstanceOf(SellerOrder::class, $parcel->fresh());
    }
}
