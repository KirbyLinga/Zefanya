<?php

namespace Tests\Feature\Auth;

use App\Models\Buyer\Buyer;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Seller\Seller;
use App\Notifications\Buyer\BuyerVerifyOtp;
use App\Notifications\Logistics\LogisticsRegistrationOtp;
use App\Notifications\Seller\SellerRegistrationOtp;
use App\Support\SignedOtpLink;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Stage 2: OTP endpoints require a signed URL (ownership proof). Unsigned
 * guesses must not lock an account or trigger OTP mail. OTP notifications
 * must use the mail channel (not database-only).
 */
class OtpSecurityTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_unsigned_buyer_otp_get_and_resend_are_forbidden_and_send_no_mail(): void
    {
        Notification::fake();

        $buyer = Buyer::factory()->create([
            'status' => 'unverified',
            'email_verified_at' => null,
        ]);
        $buyer->issueOtp();

        $this->get(route('register.buyer.verify-otp.show', $buyer))->assertForbidden();
        $this->postJson(route('register.buyer.verify-otp.resend', $buyer))->assertForbidden();

        Notification::assertNothingSent();
    }

    public function test_unsigned_wrong_codes_cannot_lock_a_buyer_otp(): void
    {
        Notification::fake();

        $buyer = Buyer::factory()->create([
            'status' => 'unverified',
            'email_verified_at' => null,
        ]);
        $buyer->issueOtp();

        for ($i = 0; $i < 6; $i++) {
            $this->postJson(route('register.buyer.verify-otp', $buyer), ['otp' => '111111'])
                ->assertForbidden();
        }

        // Signed request must still be allowed (lock never armed by the stranger).
        $this->postJson(SignedOtpLink::verifyUrl('buyer', $buyer), ['otp' => '111111'])
            ->assertStatus(422);
    }

    public function test_unsigned_seller_and_logistics_otp_posts_are_forbidden(): void
    {
        $seller = Seller::factory()->create([
            'status' => 'unverified',
            'email_verified_at' => null,
        ]);
        $provider = LogisticsProvider::factory()->unverified()->create();

        $this->postJson(route('register.seller.verify-otp', $seller), ['otp' => '123456'])
            ->assertForbidden();
        $this->postJson(route('register.seller.verify-otp.resend', $seller))
            ->assertForbidden();

        $this->postJson(route('register.logistics.verify-otp.store', $provider), ['otp' => '123456'])
            ->assertForbidden();
        $this->postJson(route('register.logistics.verify-otp.resend', $provider))
            ->assertForbidden();
    }

    public function test_buyer_otp_notification_uses_the_mail_channel(): void
    {
        Notification::fake();
        Storage::fake('local');

        $this->postJson(route('register.buyer.store'), [
            'last_name' => 'Dela Cruz',
            'first_name' => 'Juan Miguel',
            'sex' => 'male',
            'email' => 'otp-mail-buyer@example.com',
            'password' => 'Mamimili@2026',
            'password_confirmation' => 'Mamimili@2026',
            'contact_no' => '09171112233',
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
        ])->assertOk();

        $buyer = Buyer::query()->where('email', 'otp-mail-buyer@example.com')->sole();

        Notification::assertSentTo($buyer, BuyerVerifyOtp::class, function (BuyerVerifyOtp $notification, array $channels): bool {
            return $channels === ['mail'] && preg_match('/^\d{6}$/', $notification->code) === 1;
        });
    }

    public function test_seller_and_logistics_otp_notifications_use_the_mail_channel(): void
    {
        $this->assertSame(['mail'], (new SellerRegistrationOtp('123456'))->via(new Seller));
        $this->assertSame(['mail'], (new LogisticsRegistrationOtp('123456'))->via(new LogisticsProvider));
        $this->assertSame(['mail'], (new BuyerVerifyOtp('123456'))->via(new Buyer));
    }

    public function test_signed_buyer_otp_show_page_renders(): void
    {
        $buyer = Buyer::factory()->create([
            'status' => 'unverified',
            'email_verified_at' => null,
        ]);

        $this->get(SignedOtpLink::showUrl('buyer', $buyer))
            ->assertOk()
            ->assertSee('Verify your email');
    }
}
