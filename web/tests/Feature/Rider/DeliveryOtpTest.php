<?php

namespace Tests\Feature\Rider;

use App\Enums\OrderStatus;
use App\Models\Logistics\DeliveryOtp;
use App\Models\Logistics\DeliveryProof;
use App\Models\Shared\Payment;
use App\Models\Shared\SellerOrder;
use App\Notifications\Buyer\DeliveryOtpCode;
use App\Services\Logistics\DeliveryOtpService;
use App\Services\Logistics\ParcelHandoffVerification;
use App\Services\Rider\RiderOperationsService;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class DeliveryOtpTest extends RiderTestCase
{
    private function delivery(): SellerOrder
    {
        return $this->parcel(OrderStatus::OutForDelivery, ['delivery_courier_id' => $this->courier->id]);
    }

    private function sendCode(SellerOrder $parcel): string
    {
        app(DeliveryOtpService::class)->send($this->rider, $parcel);

        return Notification::sent($this->buyer, DeliveryOtpCode::class)->last()->code;
    }

    private function complete(SellerOrder $parcel, ?string $code, array $extra = []): void
    {
        app(RiderOperationsService::class)->completeDelivery($this->rider, $parcel, array_merge([
            'photo' => UploadedFile::fake()->create('proof.jpg', 10, 'image/jpeg'),
            'delivery_otp' => $code,
        ], $extra));
    }

    public function test_send_endpoint_requires_assignment_and_qr_and_never_returns_the_code(): void
    {
        $parcel = $this->delivery();
        $this->actingAsRider()->post(route('rider.deliveries.otp', $parcel))
            ->assertSessionHasErrors('verification_token');
        Notification::assertNotSentTo($this->buyer, DeliveryOtpCode::class);
        $token = $this->handoffToken($parcel, ParcelHandoffVerification::ACTION_COMPLETE_DELIVERY);
        $this->post(route('rider.deliveries.otp', $parcel), ['verification_token' => $token])
            ->assertRedirect()->assertSessionHas('success');
        $code = Notification::sent($this->buyer, DeliveryOtpCode::class)->last()->code;
        $otp = DeliveryOtp::firstOrFail();
        $this->assertMatchesRegularExpression('/^[0-9]{6}$/', $code);
        $this->assertTrue(Hash::check($code, $otp->code_hash));
        $this->assertArrayNotHasKey('code_hash', $otp->toArray());
        $this->assertStringNotContainsString($code, session('success'));
        $other = $this->assignableCourier($this->provider, [], 'Quezon City', '137404000')->rider;
        $this->actingAsRider($other)->post(route('rider.deliveries.otp', $parcel), ['verification_token' => $token])->assertForbidden();
    }

    public function test_valid_code_is_consumed_with_delivery_and_duplicate_completion_is_idempotent(): void
    {
        $parcel = $this->delivery();
        $code = $this->sendCode($parcel);
        $this->complete($parcel, $code);
        $this->assertSame(OrderStatus::Delivered, $parcel->fresh()->status);
        $this->assertNotNull(DeliveryOtp::firstOrFail()->used_at);
        $this->complete($parcel, null);
        $this->assertSame(1, DeliveryProof::where('seller_order_id', $parcel->id)->count());
    }

    public function test_missing_wrong_and_expired_codes_cannot_complete_a_delivery(): void
    {
        $parcel = $this->delivery();
        foreach ([null, '000000'] as $code) {
            try {
                $this->complete($parcel, $code);
                $this->fail('Missing code must be blocked.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('delivery_otp', $exception->errors());
            }
        }
        $code = $this->sendCode($parcel);
        $this->travel(11)->minutes();
        try {
            $this->complete($parcel, $code);
            $this->fail('Expired code must be blocked.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('expired', $exception->getMessage());
        }
        $this->assertSame(OrderStatus::OutForDelivery, $parcel->fresh()->status);
        $this->assertSame(0, DeliveryProof::count());
    }

    public function test_five_wrong_attempts_persist_and_block_even_the_correct_code(): void
    {
        $parcel = $this->delivery();
        $code = $this->sendCode($parcel);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                $this->complete($parcel, '000000');
                $this->fail('Wrong code must be blocked.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('delivery_otp', $exception->errors());
            }
        }
        $this->assertSame(5, DeliveryOtp::firstOrFail()->attempts);
        try {
            $this->complete($parcel, $code);
            $this->fail('Locked code must be blocked.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Too many', $exception->getMessage());
        }
        $this->assertSame(OrderStatus::OutForDelivery, $parcel->fresh()->status);
    }

    public function test_resend_has_cooldown_and_replaces_the_previous_code(): void
    {
        $parcel = $this->delivery();
        $old = $this->sendCode($parcel);
        try {
            $this->sendCode($parcel);
            $this->fail('Resend must be throttled.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('60 seconds', $exception->getMessage());
        }
        $this->travel(61)->seconds();
        $new = $this->sendCode($parcel);
        $otp = DeliveryOtp::firstOrFail();
        $this->assertTrue(Hash::check($new, $otp->code_hash));
        $this->assertNotSame($old, $new);
        $this->assertSame(0, $otp->attempts);
        try {
            $this->complete($parcel, $old);
            $this->fail('Old code must be blocked.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('delivery_otp', $exception->errors());
        }
    }

    public function test_code_cannot_be_used_for_another_parcel_or_after_reassignment(): void
    {
        $parcel = $this->delivery();
        $code = $this->sendCode($parcel);
        $other = $this->delivery();
        try {
            $this->complete($other, $code);
            $this->fail('Code is parcel-bound.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('delivery_otp', $exception->errors());
        }
        $replacement = $this->assignableCourier($this->provider, [], 'Quezon City', '137404000');
        $parcel->forceFill(['delivery_courier_id' => $replacement->id])->save();
        try {
            app(DeliveryOtpService::class)->verify($replacement->rider, $parcel->fresh(), $code);
            $this->fail('Code is assignment-bound.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('delivery_otp', $exception->errors());
        }
    }

    public function test_cod_failure_rolls_back_otp_consumption_so_the_same_code_can_be_retried(): void
    {
        $parcel = $this->delivery();
        Payment::factory()->create(['seller_order_id' => $parcel->id, 'amount_minor' => 10000]);
        $code = $this->sendCode($parcel);
        try {
            $this->complete($parcel, $code, ['cod_amount_minor' => 9999]);
            $this->fail('Incorrect COD must fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('cod_amount_minor', $exception->errors());
        }
        $this->assertNull(DeliveryOtp::firstOrFail()->used_at);
        $this->complete($parcel->fresh(), $code, ['cod_amount_minor' => 10000]);
        $this->assertSame(OrderStatus::Delivered, $parcel->fresh()->status);
    }

    public function test_rider_can_send_and_submit_the_buyers_code_through_the_existing_proof_form(): void
    {
        $parcel = $this->delivery();
        $token = $this->handoffToken($parcel, ParcelHandoffVerification::ACTION_COMPLETE_DELIVERY);
        $this->actingAsRider()->get(route('rider.deliveries.proof', [$parcel, 'verification_token' => $token]))
            ->assertOk()->assertSee('Send / resend delivery code')->assertSee('name="delivery_otp"', false);
        $this->post(route('rider.deliveries.otp', $parcel), ['verification_token' => $token])
            ->assertSessionHas('success');
        $code = Notification::sent($this->buyer, DeliveryOtpCode::class)->last()->code;
        $this->post(route('rider.deliveries.deliver', $parcel), [
            'verification_token' => $token,
            'delivery_otp' => $code,
            'proof_photo' => UploadedFile::fake()->create('proof.jpg', 10, 'image/jpeg'),
        ])->assertRedirect(route('rider.deliveries.index'))->assertSessionHasNoErrors();
        $this->assertSame(OrderStatus::Delivered, $parcel->fresh()->status);
    }

    public function test_form_and_service_validation_never_flash_the_code_to_session(): void
    {
        $parcel = $this->delivery();
        $token = $this->handoffToken($parcel, ParcelHandoffVerification::ACTION_COMPLETE_DELIVERY);
        $this->actingAsRider()->post(route('rider.deliveries.deliver', $parcel), [
            'verification_token' => $token, 'delivery_otp' => '123456',
        ])->assertSessionHasErrors('proof_photo')->assertSessionMissing('_old_input.delivery_otp');
        $this->post(route('rider.deliveries.deliver', $parcel), [
            'verification_token' => $token, 'delivery_otp' => '123456',
            'proof_photo' => UploadedFile::fake()->create('proof.jpg', 10, 'image/jpeg'),
        ])->assertSessionHasErrors('delivery_otp')->assertSessionMissing('_old_input.delivery_otp');
    }

    public function test_mail_failure_preserves_the_previously_issued_code(): void
    {
        $parcel = $this->delivery();
        $code = $this->sendCode($parcel);
        $hash = DeliveryOtp::firstOrFail()->code_hash;
        $this->travel(61)->seconds();
        $this->mock(Dispatcher::class, function ($mock): void {
            $mock->shouldReceive('send')->once()->andThrow(new \RuntimeException('Mail unavailable'));
        });
        try {
            app(DeliveryOtpService::class)->send($this->rider, $parcel);
            $this->fail('Mail failure must be surfaced.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Mail unavailable', $exception->getMessage());
        }
        $this->assertSame($hash, DeliveryOtp::firstOrFail()->code_hash);
        $this->assertTrue(Hash::check($code, DeliveryOtp::firstOrFail()->code_hash));
    }

    public function test_unverified_buyers_and_parcels_not_out_for_delivery_cannot_receive_codes(): void
    {
        $parcel = $this->delivery();
        $this->buyer->forceFill(['email_verified_at' => null])->save();
        try {
            $this->sendCode($parcel);
            $this->fail('Unverified email must be blocked.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('delivery_otp', $exception->errors());
        }
        $parcel->forceFill(['status' => OrderStatus::AssignedToRider])->save();
        try {
            $this->sendCode($parcel->fresh());
            $this->fail('Wrong stage must be blocked.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('out for delivery', $exception->getMessage());
        }
        $this->assertSame(0, DeliveryOtp::count());
        Notification::assertNotSentTo($this->buyer, DeliveryOtpCode::class);
    }
}
