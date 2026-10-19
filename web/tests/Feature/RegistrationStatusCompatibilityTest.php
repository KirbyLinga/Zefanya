<?php

namespace Tests\Feature;

use App\Enums\LogisticsProviderStatus;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Seller\Seller;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class RegistrationStatusCompatibilityTest extends TestCase
{
    use LazilyRefreshDatabase;

    public static function blockedAccounts(): array
    {
        $cases = [];
        foreach (['buyer', 'seller', 'logistics'] as $role) {
            foreach (['unverified', 'pending', 'rejected', 'suspended'] as $status) {
                $cases[$role.' '.$status] = [$role, $status];
            }
        }

        return $cases;
    }

    #[DataProvider('blockedAccounts')]
    public function test_canonical_nonapproved_accounts_cannot_log_in(string $role, string $status): void
    {
        $model = match ($role) {
            'buyer' => Buyer::class,
            'seller' => Seller::class,
            'logistics' => LogisticsProvider::class,
        };
        $account = $model::factory()->create(['status' => $status, 'password' => 'Password1!']);

        $response = $this->postJson(route('unified.login'), ['email' => $account->email, 'password' => 'Password1!']);

        $response->assertUnprocessable()->assertJsonValidationErrors('email');
        $response->assertSessionHas('auth.status_message', match ($status) {
            'suspended' => 'Your account has been suspended. Please contact support.',
            'unverified' => $role === 'seller'
                ? 'Your email has not been verified yet. Please check your email for the verification code.'
                : 'Please verify your email before logging in. Check your inbox for the code.',
            'pending' => match ($role) {
                'seller' => 'Your seller account is still pending administrator approval.',
                'logistics' => 'Your logistics provider registration is still awaiting administrator approval.',
                'buyer' => 'Your buyer registration is still awaiting administrator approval.',
            },
            'rejected' => match ($role) {
                'seller' => 'Your seller registration was not approved. Please contact support or submit a new registration.',
                'logistics' => 'Your logistics provider registration was not approved.',
                'buyer' => 'Your buyer registration was not approved.',
            },
        });
        foreach (['buyer', 'seller', 'logistics', 'rider'] as $guard) {
            $this->assertGuest($guard);
        }
        $this->assertSame($status, $account->fresh()->getRawOriginal('status'));
    }

    #[TestWith(['unverified', 'Pending email verification'])]
    #[TestWith(['pending', 'Pending approval'])]
    #[TestWith(['approved', 'Approved'])]
    #[TestWith(['rejected', 'Rejected'])]
    #[TestWith(['suspended', 'Suspended'])]
    public function test_logistics_model_reads_all_existing_mysql_statuses(string $status, string $label): void
    {
        $provider = LogisticsProvider::factory()->create(['status' => $status]);

        $this->assertSame(LogisticsProviderStatus::from($status), $provider->fresh()->status);
        $this->assertSame($label, $provider->fresh()->status->label());
        $this->assertSame($status === 'approved', $provider->fresh()->isApproved());
    }

    #[TestWith(['seller'])]
    #[TestWith(['logistics'])]
    public function test_suspended_existing_sessions_cannot_access_a_dashboard(string $role): void
    {
        $model = $role === 'seller' ? Seller::class : LogisticsProvider::class;
        $account = $model::factory()->create(['status' => 'suspended']);

        $this->actingAs($account, $role)->get(route($role.'.dashboard'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('auth.status_message', 'Your account has been suspended. Please contact support.');

        $this->assertGuest($role);
        $this->assertSame('suspended', $account->fresh()->getRawOriginal('status'));
    }

    #[TestWith(['rejected'])]
    #[TestWith(['suspended'])]
    public function test_buyer_otp_cannot_reopen_a_blocked_account(string $status): void
    {
        Notification::fake();
        $buyer = Buyer::factory()->create([
            'status' => $status,
            'email_verification_code' => hash('sha256', '123456'),
            'email_verification_expires_at' => now()->addMinutes(10),
        ]);

        $this->postJson(route('register.buyer.verify-otp', $buyer), ['otp' => '123456'])
            ->assertUnprocessable()->assertJsonPath('message', 'This account cannot verify its email.');
        $this->postJson(route('register.buyer.verify-otp.resend', $buyer))
            ->assertUnprocessable()->assertJsonPath('message', 'This account cannot verify its email.');

        $this->assertSame($status, $buyer->fresh()->status);
        $this->assertSame(hash('sha256', '123456'), $buyer->fresh()->email_verification_code);
        Notification::assertNothingSent();
    }

    #[TestWith(['pending'])]
    #[TestWith(['suspended'])]
    public function test_seller_registration_rejects_existing_canonical_email_and_contact(string $status): void
    {
        Notification::fake();
        $seller = Seller::factory()->create(['status' => $status]);

        $this->postJson(route('register.seller.store'), ['email' => $seller->email, 'contact_no' => $seller->contact_no])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'contact_no'])
            ->assertJsonPath('errors.email.0', 'This email is already registered. Use a different email.')
            ->assertJsonPath('errors.contact_no.0', 'This mobile number is already registered.');

        $this->assertDatabaseCount('sellers', 1);
        $this->assertSame($status, $seller->fresh()->status);
        Notification::assertNothingSent();
    }
}
