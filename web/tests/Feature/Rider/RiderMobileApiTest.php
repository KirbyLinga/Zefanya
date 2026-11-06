<?php

namespace Tests\Feature\Rider;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Logistics\DeliveryProof;
use App\Models\Rider\Rider;
use App\Models\Shared\Payment;
use App\Models\Shared\SellerOrder;
use App\Notifications\Buyer\DeliveryOtpCode;
use App\Services\Logistics\ParcelHandoffVerification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;

/**
 * Rider mobile bearer-token API (/api/rider/...): every endpoint delegates to
 * the same services as the Rider web panel, so these tests focus on the API
 * contract — auth/ability, ownership, scan-token enforcement, JSON 409/422,
 * COD/OTP server authority and idempotent repeats.
 */
class RiderMobileApiTest extends RiderTestCase
{
    private function token(?Rider $rider = null): string
    {
        $this->app['auth']->forgetGuards();

        return ($rider ?? $this->rider)->createToken('api-token', ['rider'], now()->addHour())->plainTextToken;
    }

    private function api(?Rider $rider = null): static
    {
        return $this->withToken($this->token($rider))->withHeader('Accept', 'application/json');
    }

    private function outForDelivery(int $codMinor = 0): SellerOrder
    {
        $parcel = $this->parcel(
            OrderStatus::OutForDelivery,
            ['delivery_courier_id' => $this->courier->id],
            ['destination_area_id' => $this->courier->area_id],
        );

        if ($codMinor > 0) {
            Payment::factory()->create(['seller_order_id' => $parcel->id, 'amount_minor' => $codMinor]);
        }

        return $parcel->fresh();
    }

    public function test_endpoints_require_a_rider_bearer_token(): void
    {
        $this->getJson('/api/rider/jobs')->assertUnauthorized();

        // A buyer token is rejected even though it is a valid Sanctum token.
        $buyerToken = $this->buyer->createToken('api-token', ['buyer'], now()->addHour())->plainTextToken;
        $this->withToken($buyerToken)->getJson('/api/rider/jobs')->assertForbidden();

        // A web session alone is not a bearer token.
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->actingAsRider()->getJson('/api/rider/jobs')->assertUnauthorized();
    }

    public function test_jobs_lists_only_my_parcels_and_never_exposes_the_delivery_code(): void
    {
        $mine = $this->outForDelivery(150000);
        $this->deliveryOtp($mine);
        $other = $this->assignableCourier($this->provider, [], 'Quezon City', '137404000');
        $this->parcel(OrderStatus::OutForDelivery, ['delivery_courier_id' => $other->id]);

        $response = $this->api()->getJson('/api/rider/jobs')->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data.deliveries')
            ->assertJsonPath('data.deliveries.0.id', $mine->id)
            ->assertJsonPath('data.deliveries.0.requires_cod_collection', true)
            ->assertJsonPath('data.deliveries.0.cod_amount_minor', 150000);

        $this->assertStringNotContainsString('123456', $response->getContent());
        $this->assertStringNotContainsString('code_hash', $response->getContent());
    }

    public function test_availability_toggle_uses_the_availability_service(): void
    {
        $this->api()->postJson('/api/rider/availability', ['online' => false])
            ->assertOk()->assertJsonPath('data.online', false);
        $this->assertFalse($this->rider->fresh()->is_online);

        $this->api()->postJson('/api/rider/availability', [])->assertStatus(422);
    }

    public function test_scan_verify_returns_action_tokens_without_web_urls(): void
    {
        $parcel = $this->outForDelivery();

        $response = $this->api()->postJson('/api/rider/scan/verify', ['tracking_number' => $parcel->tracking_number])
            ->assertOk()->assertJsonPath('data.context', 'delivery');

        $keys = collect($response->json('data.actions'))->pluck('key')->all();
        $this->assertContains('proof_of_delivery', $keys);
        $this->assertStringNotContainsString('/rider/deliveries', $response->getContent());

        $this->api()->postJson('/api/rider/scan/verify', ['tracking_number' => 'NOPE-0000'])->assertNotFound();
    }

    public function test_start_delivery_requires_a_scan_token_and_rejects_other_riders(): void
    {
        $parcel = $this->parcel(
            OrderStatus::AssignedToRider,
            ['delivery_courier_id' => $this->courier->id],
            ['destination_area_id' => $this->courier->area_id],
        );

        $this->api()->postJson("/api/rider/deliveries/{$parcel->id}/start")
            ->assertStatus(422)->assertJsonValidationErrors('verification_token');
        $this->assertSame(OrderStatus::AssignedToRider, $parcel->fresh()->status);

        $other = $this->assignableCourier($this->provider, [], 'Quezon City', '137404000')->rider;
        $this->api($other)->postJson("/api/rider/deliveries/{$parcel->id}/start", [
            'verification_token' => $this->handoffToken($parcel, ParcelHandoffVerification::ACTION_START_DELIVERY),
        ])->assertForbidden();

        $this->api()->postJson("/api/rider/deliveries/{$parcel->id}/start", [
            'verification_token' => $this->handoffToken($parcel, ParcelHandoffVerification::ACTION_START_DELIVERY),
        ])->assertOk()->assertJsonPath('data.status', OrderStatus::OutForDelivery->value);

        // Idempotent repeat: no token needed once already out for delivery.
        $this->api()->postJson("/api/rider/deliveries/{$parcel->id}/start")->assertOk();
    }

    public function test_send_otp_notifies_the_buyer_and_never_returns_the_code(): void
    {
        $parcel = $this->outForDelivery();

        $response = $this->api()->postJson("/api/rider/deliveries/{$parcel->id}/otp", [
            'verification_token' => $this->handoffToken($parcel, ParcelHandoffVerification::ACTION_COMPLETE_DELIVERY),
        ])->assertOk();

        $code = Notification::sent($this->buyer, DeliveryOtpCode::class)->last()->code;
        $this->assertStringNotContainsString($code, $response->getContent());
    }

    public function test_deliver_with_cod_records_proof_and_payment_once(): void
    {
        $parcel = $this->outForDelivery(150000);
        $payload = $this->withHandoff($parcel, ParcelHandoffVerification::ACTION_COMPLETE_DELIVERY, [
            'proof_photo' => UploadedFile::fake()->image('proof.jpg'),
            'cod_amount' => '1500.00',
        ]);

        $this->api()->post("/api/rider/deliveries/{$parcel->id}/deliver", $payload)
            ->assertOk()->assertJsonPath('data.status', OrderStatus::Delivered->value);

        $payment = Payment::query()->where('seller_order_id', $parcel->id)->firstOrFail();
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertSame(1, DeliveryProof::query()->where('seller_order_id', $parcel->id)->count());

        // Duplicate submission (e.g. retry after a lost response) writes nothing new.
        $this->api()->post("/api/rider/deliveries/{$parcel->id}/deliver", [
            'proof_photo' => UploadedFile::fake()->image('again.jpg'),
            'cod_amount' => '1500.00',
        ])->assertOk();
        $this->assertSame(1, DeliveryProof::query()->where('seller_order_id', $parcel->id)->count());
    }

    public function test_deliver_rejects_tampered_cod_amount_and_bad_proof_without_state_change(): void
    {
        $parcel = $this->outForDelivery(150000);

        $this->api()->post("/api/rider/deliveries/{$parcel->id}/deliver", $this->withHandoff($parcel, ParcelHandoffVerification::ACTION_COMPLETE_DELIVERY, [
            'proof_photo' => UploadedFile::fake()->image('proof.jpg'),
            'cod_amount' => '1.00',
        ]))->assertStatus(422)->assertJsonValidationErrors('cod_amount_minor');

        $this->api()->post("/api/rider/deliveries/{$parcel->id}/deliver", $this->withHandoff($parcel, ParcelHandoffVerification::ACTION_COMPLETE_DELIVERY, [
            'proof_photo' => UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf'),
            'cod_amount' => '1500.00',
        ]))->assertStatus(422)->assertJsonValidationErrors('proof_photo');

        $this->assertSame(OrderStatus::OutForDelivery, $parcel->fresh()->status);
        $this->assertSame(0, DeliveryProof::query()->where('seller_order_id', $parcel->id)->count());
    }

    public function test_stale_delivery_returns_409_and_changes_nothing(): void
    {
        $parcel = $this->outForDelivery();
        $token = $this->handoffToken($parcel, ParcelHandoffVerification::ACTION_FAIL_DELIVERY);

        // Logistics moved the parcel after the app rendered it.
        $parcel->forceFill(['status' => OrderStatus::AssignedToRider])->save();

        $this->api()->postJson("/api/rider/deliveries/{$parcel->id}/failed", [
            'failure_reason' => 'recipient_unavailable',
            'verification_token' => $token,
        ])->assertStatus(409);

        $this->assertSame(OrderStatus::AssignedToRider, $parcel->fresh()->status);
    }

    public function test_failed_delivery_validates_reason_and_records_attempt(): void
    {
        $parcel = $this->outForDelivery();

        $this->api()->postJson("/api/rider/deliveries/{$parcel->id}/failed", $this->withHandoff($parcel, ParcelHandoffVerification::ACTION_FAIL_DELIVERY, [
            'failure_reason' => 'not-a-reason',
        ]))->assertStatus(422)->assertJsonValidationErrors('failure_reason');

        $this->api()->postJson("/api/rider/deliveries/{$parcel->id}/failed", $this->withHandoff($parcel, ParcelHandoffVerification::ACTION_FAIL_DELIVERY, [
            'failure_reason' => 'recipient_unavailable',
        ]))->assertOk()->assertJsonPath('data.status', OrderStatus::DeliveryFailed->value);
    }

    public function test_pickup_confirm_requires_assignment(): void
    {
        $parcel = $this->parcel(OrderStatus::ReadyForPickup, ['courier_id' => $this->courier->id]);
        $other = $this->assignableCourier($this->provider, [], 'Quezon City', '137404000')->rider;

        $this->api($other)->postJson("/api/rider/pickups/{$parcel->id}/confirm", [
            'verification_token' => $this->handoffToken($parcel, ParcelHandoffVerification::ACTION_CONFIRM_PICKUP),
        ])->assertForbidden();

        $this->api()->postJson("/api/rider/pickups/{$parcel->id}/confirm", [
            'verification_token' => $this->handoffToken($parcel, ParcelHandoffVerification::ACTION_CONFIRM_PICKUP),
        ])->assertOk()->assertJsonPath('data.status', OrderStatus::PickedUp->value);
    }

    public function test_earnings_are_server_computed(): void
    {
        $this->api()->getJson('/api/rider/earnings')->assertOk()
            ->assertJsonStructure(['data' => ['summary' => ['monthMinor', 'pendingMinor', 'paidMinor'], 'cod' => ['holding_minor'], 'recent']]);
    }
}
