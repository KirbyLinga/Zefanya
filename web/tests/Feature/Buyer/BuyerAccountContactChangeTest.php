<?php

namespace Tests\Feature\Buyer;

use App\Models\Buyer\Buyer;
use App\Models\Rider\Rider;
use App\Models\Seller\Seller;
use App\Notifications\Buyer\BuyerEmailChanged;
use App\Notifications\Buyer\BuyerEmailChangeOtp;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** BUY-ACCT-006 to 009: email change by OTP, duplicate checks, phone change. */
class BuyerAccountContactChangeTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Buyer $buyer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Notification::fake();

        $this->buyer = Buyer::factory()->create([
            'email' => 'old@example.com',
            'password' => 'Secret-Pass1!',
            'contact_no' => '09171234567',
            'status' => 'approved',
            'email_verified_at' => now()->subYear(),
        ]);
        $this->actingAs($this->buyer, 'buyer');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function requestChange(string $email = 'new@example.com'): TestResponse
    {
        return $this->from(route('buyer.account.index'))->post(route('buyer.account.email.request'), ['email' => $email]);
    }

    /** The raw code in the most recent mail to this address. */
    private function codeSentTo(string $email): string
    {
        $code = null;

        Notification::assertSentOnDemand(BuyerEmailChangeOtp::class, function (BuyerEmailChangeOtp $notification, array $channels, AnonymousNotifiable $notifiable) use ($email, &$code): bool {
            if ($notifiable->routes['mail'] === $email) {
                $code = $notification->code;

                return true;
            }

            return false;
        });

        return (string) $code;
    }

    private function confirm(string $code): TestResponse
    {
        return $this->from(route('buyer.account.index'))->post(route('buyer.account.email.verify'), ['otp' => $code]);
    }

    private function canSignInWith(string $email): bool
    {
        return Auth::guard('buyer')->validate(['email' => $email, 'password' => 'Secret-Pass1!']);
    }

    /** @param array<string, mixed> $overrides */
    private function updateProfile(array $overrides): TestResponse
    {
        return $this->from(route('buyer.account.index'))->patch(route('buyer.account.update'), $overrides + [
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'sex' => 'female',
            'contact_no' => '09171234567',
            'birthday' => '1995-06-15',
        ]);
    }

    public function test_acct_006_code_goes_to_the_new_address_and_the_old_one_keeps_working(): void
    {
        $this->requestChange('  New@Example.com ')->assertRedirect()->assertSessionHasNoErrors();

        $buyer = $this->buyer->fresh();
        $this->assertSame('new@example.com', $buyer->pending_email);
        $this->assertSame('old@example.com', $buyer->email);
        $this->assertNotSame($this->codeSentTo('new@example.com'), $buyer->pending_email_code, 'the code is stored hashed');
        Notification::assertNotSentTo($this->buyer, BuyerEmailChangeOtp::class);

        $this->assertTrue($this->canSignInWith('old@example.com'));
        $this->assertFalse($this->canSignInWith('new@example.com'));
    }

    public function test_acct_006_the_right_code_switches_the_login_and_tells_the_old_address(): void
    {
        $this->requestChange();

        $this->confirm($this->codeSentTo('new@example.com'))->assertRedirect()->assertSessionHasNoErrors();

        $buyer = $this->buyer->fresh();
        $this->assertSame('new@example.com', $buyer->email);
        $this->assertNull($buyer->pending_email);
        $this->assertNull($buyer->pending_email_code);
        $this->assertTrue($buyer->email_verified_at->isToday());
        $this->assertTrue($this->canSignInWith('new@example.com'));
        $this->assertFalse($this->canSignInWith('old@example.com'));

        Notification::assertSentOnDemand(BuyerEmailChanged::class, fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'old@example.com');
    }

    public function test_acct_006_a_wrong_code_changes_nothing(): void
    {
        $this->requestChange();
        $right = $this->codeSentTo('new@example.com');

        $this->confirm($right === '111111' ? '222222' : '111111')->assertSessionHasErrorsIn('emailChange', 'otp');

        $this->assertSame('old@example.com', $this->buyer->fresh()->email);
        $this->assertSame('new@example.com', $this->buyer->fresh()->pending_email);
    }

    public function test_acct_006_an_expired_code_changes_nothing(): void
    {
        $this->requestChange();
        $code = $this->codeSentTo('new@example.com');

        Carbon::setTestNow(now()->addMinutes(6));

        $this->confirm($code)->assertSessionHasErrorsIn('emailChange', 'otp');

        $this->assertSame('old@example.com', $this->buyer->fresh()->email);
    }

    public function test_acct_006_five_wrong_codes_lock_even_for_the_right_code_and_a_new_address_does_not_unlock(): void
    {
        $this->requestChange();
        $right = $this->codeSentTo('new@example.com');
        $wrong = $right === '111111' ? '222222' : '111111';

        foreach (range(1, 5) as $attempt) {
            $this->confirm($wrong);
        }

        $this->confirm($right)->assertSessionHasErrorsIn('emailChange', 'otp');
        $this->assertSame('old@example.com', $this->buyer->fresh()->email);

        $this->post(route('buyer.account.email.cancel'), ['_method' => 'DELETE']);
        $this->requestChange('other@example.com');

        $this->confirm($this->codeSentTo('other@example.com'))->assertSessionHasErrorsIn('emailChange', 'otp');
        $this->assertSame('old@example.com', $this->buyer->fresh()->email);
    }

    public function test_acct_006_resending_replaces_the_code_and_sending_is_limited(): void
    {
        $this->requestChange();
        $first = $this->buyer->fresh()->pending_email_code;

        $this->post(route('buyer.account.email.resend'))->assertSessionHasNoErrors();
        $this->assertNotSame($first, $this->buyer->fresh()->pending_email_code);

        $this->post(route('buyer.account.email.resend'))->assertSessionHasNoErrors();
        $this->post(route('buyer.account.email.resend'))->assertSessionHasErrorsIn('emailChange', 'email');

        Notification::assertSentOnDemandTimes(BuyerEmailChangeOtp::class, 3);
    }

    public function test_acct_006_cancelling_clears_the_pending_change(): void
    {
        $this->requestChange();
        $code = $this->codeSentTo('new@example.com');

        $this->delete(route('buyer.account.email.cancel'))->assertSessionHasNoErrors();

        $this->assertNull($this->buyer->fresh()->pending_email);
        $this->confirm($code)->assertSessionHasErrorsIn('emailChange', 'email');
        $this->assertSame('old@example.com', $this->buyer->fresh()->email);
    }

    /** @return array<string, array{0: string}> */
    public static function badEmails(): array
    {
        return [
            'empty' => [''],
            'no domain dot' => ['someone@example'],
            'no at' => ['example.com'],
            'space inside' => ['some one@example.com'],
        ];
    }

    #[DataProvider('badEmails')]
    public function test_acct_007_a_malformed_email_gets_no_code(string $email): void
    {
        $this->requestChange($email)->assertSessionHasErrorsIn('emailChange', 'email');

        $this->assertNull($this->buyer->fresh()->pending_email);
        Notification::assertNothingSent();
    }

    public function test_acct_007_an_email_used_by_any_role_gets_no_code(): void
    {
        Buyer::factory()->create(['email' => 'buyer@example.com']);
        Seller::factory()->create(['email' => 'seller@example.com']);
        Rider::factory()->create(['email' => 'rider@example.com']);

        foreach (['buyer@example.com', 'SELLER@example.com', 'rider@example.com'] as $taken) {
            $this->requestChange($taken)->assertSessionHasErrorsIn('emailChange', 'email');
        }

        $this->assertNull($this->buyer->fresh()->pending_email);
        Notification::assertNothingSent();
    }

    public function test_acct_007_your_own_email_gets_no_code(): void
    {
        $this->requestChange('OLD@example.com')->assertSessionHasErrorsIn('emailChange', 'email');

        Notification::assertNothingSent();
    }

    public function test_acct_007_an_address_taken_after_the_code_was_sent_is_refused(): void
    {
        $this->requestChange();
        $code = $this->codeSentTo('new@example.com');

        Seller::factory()->create(['email' => 'new@example.com']);

        $this->post(route('buyer.account.email.resend'))->assertSessionHasErrorsIn('emailChange', 'email');
        Notification::assertSentOnDemandTimes(BuyerEmailChangeOtp::class, 1);

        $this->confirm($code)->assertSessionHasErrorsIn('emailChange', 'email');

        $this->assertSame('old@example.com', $this->buyer->fresh()->email);
        $this->assertNull($this->buyer->fresh()->pending_email);
    }

    public function test_acct_007_two_buyers_racing_for_one_address_only_one_wins(): void
    {
        $this->requestChange();
        $mine = $this->codeSentTo('new@example.com');

        $rival = Buyer::factory()->create(['email' => 'rival@example.com', 'status' => 'approved']);
        $rival->forceFill([
            'pending_email' => 'new@example.com',
            'pending_email_code' => Buyer::hashOtp('654321'),
            'pending_email_expires_at' => now()->addMinutes(5),
        ])->save();

        $this->actingAs($rival, 'buyer');
        $this->confirm('654321')->assertSessionHasNoErrors();
        $this->assertSame('new@example.com', $rival->fresh()->email);

        $this->actingAs($this->buyer->fresh(), 'buyer');
        $this->confirm($mine)->assertSessionHasErrorsIn('emailChange', 'email');
        $this->assertSame('old@example.com', $this->buyer->fresh()->email);
    }

    /** @return array<string, array{0: string}> */
    public static function badPhones(): array
    {
        return [
            'too short' => ['0917123456'],
            'too long' => ['091712345678'],
            'wrong prefix' => ['08171234567'],
            'letters' => ['0917abc4567'],
            'wrong country' => ['+649171234567'],
            'empty' => [''],
        ];
    }

    #[DataProvider('badPhones')]
    public function test_acct_008_a_malformed_phone_is_refused(string $phone): void
    {
        $this->updateProfile(['contact_no' => $phone])->assertSessionHasErrors('contact_no');

        $this->assertSame('09171234567', $this->buyer->fresh()->contact_no);
    }

    /** @return array<string, array{0: string}> */
    public static function goodPhones(): array
    {
        return [
            'local' => ['09181234567'],
            'international' => ['+639181234567'],
            'with spaces' => ['0918 123 4567'],
        ];
    }

    #[DataProvider('goodPhones')]
    public function test_acct_008_a_valid_phone_is_saved_in_its_stored_form(string $phone): void
    {
        $this->updateProfile(['contact_no' => $phone])->assertSessionHasNoErrors();

        $this->assertSame('09181234567', $this->buyer->fresh()->contact_no);
    }

    public function test_acct_009_a_phone_used_by_any_role_is_refused(): void
    {
        Buyer::factory()->create(['contact_no' => '09181111111']);
        Seller::factory()->create(['contact_no' => '09182222222']);
        Rider::factory()->create(['contact_no' => '09183333333']);

        foreach (['09181111111', '+639182222222', '09183333333'] as $taken) {
            $this->updateProfile(['contact_no' => $taken])->assertSessionHasErrors('contact_no');
        }

        $this->assertSame('09171234567', $this->buyer->fresh()->contact_no);
    }

    public function test_acct_009_keeping_your_own_phone_is_not_a_duplicate(): void
    {
        $this->updateProfile(['first_name' => 'Renamed'])->assertSessionHasNoErrors();

        $this->assertSame('Renamed', $this->buyer->fresh()->first_name);
    }

    public function test_profile_page_shows_the_right_email_form_and_keeps_errors_on_it(): void
    {
        $page = route('buyer.account.index');

        $this->get($page)->assertOk()
            ->assertSee('action="'.route('buyer.account.email.request').'"', false)
            ->assertDontSee('Contact Zefanya Concierge');

        $this->from($page)->followingRedirects()->post(route('buyer.account.email.request'), ['email' => 'not-an-email'])
            ->assertOk()
            ->assertSee('Enter a valid email address with a domain.');

        $this->requestChange();

        $this->get($page)->assertOk()
            ->assertSee('new@example.com')
            ->assertSee('action="'.route('buyer.account.email.verify').'"', false)
            ->assertSee('action="'.route('buyer.account.email.resend').'"', false)
            ->assertDontSee('id="newEmail"', false)
            ->assertDontSee($this->buyer->fresh()->pending_email_code);
    }

    public function test_guests_cannot_start_a_change(): void
    {
        Auth::guard('buyer')->logout();

        $this->post(route('buyer.account.email.request'), ['email' => 'new@example.com'])->assertRedirect();

        Notification::assertNothingSent();
    }
}
