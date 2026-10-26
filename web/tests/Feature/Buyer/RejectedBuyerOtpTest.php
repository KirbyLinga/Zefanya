<?php

namespace Tests\Feature\Buyer;

use App\Models\Buyer\Buyer;
use App\Support\SignedOtpLink;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * Z-013: only an 'unverified' buyer may use the OTP endpoints. A rejected
 * applicant must not be able to re-enter the verification flow and flip
 * themselves back to 'pending'.
 */
class RejectedBuyerOtpTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function rejectedBuyer(): Buyer
    {
        return Buyer::factory()->create([
            'status' => 'rejected',
            'email_verified_at' => null,
        ]);
    }

    public function test_a_rejected_buyer_cannot_verify_with_an_otp(): void
    {
        $buyer = $this->rejectedBuyer();

        // The code the applicant still holds from before the rejection.
        $otp = $buyer->issueOtp();

        $this->postJson(SignedOtpLink::verifyUrl('buyer', $buyer), ['otp' => $otp])
            ->assertStatus(422);

        $buyer->refresh();
        $this->assertSame('rejected', $buyer->status);
        $this->assertNull($buyer->email_verified_at);
    }

    public function test_a_rejected_buyer_cannot_request_a_new_otp(): void
    {
        $buyer = $this->rejectedBuyer();

        $this->postJson(SignedOtpLink::resendUrl('buyer', $buyer))
            ->assertStatus(422);

        $buyer->refresh();
        $this->assertSame('rejected', $buyer->status);
        $this->assertNull($buyer->email_verification_code);
    }
}
