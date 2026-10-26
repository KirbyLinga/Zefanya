<?php

namespace Tests\Feature\Auth;

use App\Models\Buyer\Buyer;
use App\Support\SignedOtpLink;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OtpLockTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_an_unsigned_buyer_otp_verify_request_is_rejected_with_403(): void
    {
        $buyer = Buyer::factory()->create([
            'status' => 'unverified',
            'email_verified_at' => null,
            'approved_at' => null,
        ]);

        $this->postJson(route('register.buyer.verify-otp', $buyer), ['otp' => '123456'])
            ->assertForbidden();
    }

    public function test_five_wrong_buyer_codes_lock_verification_and_resend_does_not_unlock(): void
    {
        Notification::fake();

        $buyer = Buyer::factory()->create([
            'status' => 'unverified',
            'email_verified_at' => null,
            'approved_at' => null,
        ]);
        $buyer->issueOtp();

        $verifyUrl = SignedOtpLink::verifyUrl('buyer', $buyer);

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $this->postJson($verifyUrl, ['otp' => '111111'])->assertStatus(422);
        }

        $this->postJson($verifyUrl, ['otp' => '111111'])->assertStatus(429);

        $this->postJson(SignedOtpLink::resendUrl('buyer', $buyer))->assertOk();

        $this->postJson($verifyUrl, ['otp' => '111111'])->assertStatus(429);

        $this->assertSame('unverified', $buyer->fresh()->status);
    }

    public function test_buyer_otp_lock_survives_archive_and_re_registration(): void
    {
        Notification::fake();

        $email = 'locked-buyer@example.com';
        $buyer = Buyer::factory()->create([
            'email' => $email,
            'status' => 'unverified',
            'email_verified_at' => null,
            'approved_at' => null,
            'contact_no' => '09170000001',
        ]);
        $buyer->issueOtp();

        $verifyUrl = SignedOtpLink::verifyUrl('buyer', $buyer);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson($verifyUrl, ['otp' => '111111']);
        }

        $this->postJson($verifyUrl, ['otp' => '111111'])->assertStatus(429);

        // Abandoned unverified row can be archived on re-register; new id must
        // still be locked because the counter is keyed by email, not id.
        $this->travel(11)->minutes();

        $payload = [
            'last_name' => 'Dela Cruz',
            'first_name' => 'Juan Miguel',
            'sex' => 'male',
            'email' => $email,
            'password' => 'Mamimili@2026',
            'password_confirmation' => 'Mamimili@2026',
            'contact_no' => '09170000001',
            'birthday' => '1998-05-14',
            'address_mode' => 'api',
            'province' => '0300000000',
            'province_name' => 'Batangas',
            'municipality' => '0306800000',
            'municipality_name' => 'Lipa City',
            'barangay' => '030680001',
            'barangay_name' => 'Barangay 1',
            'street' => '123 Rizal St.',
            'upload_id' => UploadedFile::fake()->create('id.pdf', 100, 'application/pdf'),
            'terms' => '1',
        ];

        Storage::fake('local');

        $this->postJson(route('register.buyer.store'), $payload)->assertOk();

        $replacement = Buyer::query()->where('email', $email)->sole();
        $this->assertNotSame($buyer->getKey(), $replacement->getKey());

        $this->postJson(SignedOtpLink::verifyUrl('buyer', $replacement), ['otp' => '111111'])
            ->assertStatus(429);
    }
}
