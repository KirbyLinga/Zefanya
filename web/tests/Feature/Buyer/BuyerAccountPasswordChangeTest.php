<?php

namespace Tests\Feature\Buyer;

use App\Models\Buyer\Buyer;
use App\Notifications\Buyer\BuyerPasswordChanged;
use App\Notifications\Buyer\BuyerPasswordChangeOtp;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class BuyerAccountPasswordChangeTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const CURRENT = 'Secret-Pass1!';

    private const NEW = 'Brand-New-Pass2!';

    private Buyer $buyer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Notification::fake();

        $this->buyer = Buyer::factory()->create([
            'email' => 'buyer@example.com',
            'password' => self::CURRENT,
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

    private function requestChange(array $overrides = []): TestResponse
    {
        return $this->from(route('buyer.account.password'))->patch(route('buyer.account.password.update'), $overrides + [
            'current_password' => self::CURRENT,
            'new_password' => self::NEW,
            'new_password_confirmation' => self::NEW,
        ]);
    }

    private function latestCode(): string
    {
        $code = null;

        Notification::assertSentOnDemand(BuyerPasswordChangeOtp::class, function (BuyerPasswordChangeOtp $notification, array $channels, AnonymousNotifiable $notifiable) use (&$code): bool {
            $code = $notification->code;

            return $notifiable->routes['mail'] === 'buyer@example.com';
        });

        return (string) $code;
    }

    private function confirm(string $code): TestResponse
    {
        return $this->from(route('buyer.account.password'))->post(route('buyer.account.password.verify'), ['otp' => $code]);
    }

    private function passwordIs(string $password): bool
    {
        return Hash::check($password, $this->buyer->fresh()->password);
    }

    private function wrongCode(string $right): string
    {
        return $right === '111111' ? '222222' : '111111';
    }

    public function test_submitting_the_form_sends_a_code_and_does_not_change_the_password(): void
    {
        $this->requestChange()
            ->assertRedirect(route('buyer.account.password'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        Notification::assertSentOnDemandTimes(BuyerPasswordChangeOtp::class, 1);
        $this->assertTrue($this->passwordIs(self::CURRENT));
        $this->assertFalse($this->passwordIs(self::NEW));
    }

    public function test_the_page_asks_for_the_code_once_it_was_sent(): void
    {
        $this->get(route('buyer.account.password'))->assertOk()
            ->assertSee('name="new_password"', false)
            ->assertDontSee('name="otp"', false);

        $this->requestChange();

        $this->get(route('buyer.account.password'))->assertOk()
            ->assertSee('name="otp"', false)
            ->assertSee('buyer@example.com')
            ->assertSee('Resend code')
            ->assertDontSee('name="new_password"', false);
    }

    public function test_the_right_code_changes_the_password_and_tells_the_buyer(): void
    {
        $this->requestChange();

        $this->confirm($this->latestCode())
            ->assertRedirect(route('buyer.account.password'))
            ->assertSessionHasNoErrors();

        $this->assertTrue($this->passwordIs(self::NEW));
        $this->assertFalse($this->passwordIs(self::CURRENT));
        $this->assertNull($this->buyer->fresh()->pending_password);
        $this->assertNull($this->buyer->fresh()->pending_password_code);
        Notification::assertSentOnDemand(BuyerPasswordChanged::class);
    }

    public function test_this_browser_stays_signed_in_and_other_sessions_are_signed_out(): void
    {
        $this->get(route('buyer.account.password'))->assertOk();
        $oldStamp = session('password_hash_buyer');
        $this->assertNotNull($oldStamp);
        $oldToken = $this->buyer->fresh()->remember_token;

        $this->requestChange();
        $this->confirm($this->latestCode());

        $this->get(route('buyer.account.password'))->assertOk();
        $this->assertNotSame($oldToken, $this->buyer->fresh()->remember_token);

        // A session on another device still holds the old password's stamp.
        $this->withSession(['password_hash_buyer' => $oldStamp, 'password_hash_user_buyer' => $this->buyer->id])
            ->get(route('buyer.account.password'))
            ->assertRedirect();
    }

    public function test_a_wrong_code_changes_nothing(): void
    {
        $this->requestChange();

        $this->confirm($this->wrongCode($this->latestCode()))->assertSessionHasErrorsIn('passwordChange', 'otp');

        $this->assertTrue($this->passwordIs(self::CURRENT));
        $this->assertNotNull($this->buyer->fresh()->pending_password);
    }

    public function test_an_expired_code_changes_nothing(): void
    {
        $this->requestChange();
        $code = $this->latestCode();

        Carbon::setTestNow(now()->addMinutes(Buyer::OTP_TTL_MINUTES)->addSecond());

        $this->confirm($code)->assertSessionHasErrorsIn('passwordChange', 'otp');
        $this->assertTrue($this->passwordIs(self::CURRENT));
    }

    public function test_five_wrong_codes_lock_even_the_right_code_and_cancelling_does_not_unlock(): void
    {
        $this->requestChange();
        $right = $this->latestCode();

        foreach (range(1, 5) as $attempt) {
            $this->confirm($this->wrongCode($right));
        }

        $this->confirm($right)->assertSessionHasErrorsIn('passwordChange', 'otp');
        $this->assertTrue($this->passwordIs(self::CURRENT));

        $this->delete(route('buyer.account.password.cancel'));
        $this->requestChange();

        $this->confirm($this->latestCode())->assertSessionHasErrorsIn('passwordChange', 'otp');
        $this->assertTrue($this->passwordIs(self::CURRENT));
    }

    public function test_resending_replaces_the_code_and_sending_is_limited(): void
    {
        $this->requestChange();
        $first = $this->buyer->fresh()->pending_password_code;

        $this->post(route('buyer.account.password.resend'))->assertSessionHasNoErrors();
        $this->assertNotSame($first, $this->buyer->fresh()->pending_password_code);

        $this->post(route('buyer.account.password.resend'))->assertSessionHasNoErrors();
        $this->post(route('buyer.account.password.resend'))->assertSessionHasErrorsIn('passwordChange', 'otp');

        Notification::assertSentOnDemandTimes(BuyerPasswordChangeOtp::class, 3);
    }

    public function test_cancelling_keeps_the_old_password_and_the_old_code_stops_working(): void
    {
        $this->requestChange();
        $code = $this->latestCode();

        $this->delete(route('buyer.account.password.cancel'))->assertRedirect(route('buyer.account.password'));

        $this->assertNull($this->buyer->fresh()->pending_password);
        $this->confirm($code)->assertRedirect(route('buyer.account.password'));
        $this->assertTrue($this->passwordIs(self::CURRENT));
    }

    public function test_no_code_is_sent_for_a_wrong_current_password(): void
    {
        $this->requestChange(['current_password' => 'Not-The-Password1!'])->assertSessionHasErrors('current_password');

        Notification::assertNothingSent();
        $this->assertNull($this->buyer->fresh()->pending_password);
    }

    public function test_no_code_is_sent_for_a_weak_or_mismatched_or_unchanged_password(): void
    {
        $this->requestChange(['new_password' => 'weak', 'new_password_confirmation' => 'weak'])->assertSessionHasErrors('new_password');
        $this->requestChange(['new_password_confirmation' => 'Different-Pass3!'])->assertSessionHasErrors('new_password');
        $this->requestChange(['new_password' => self::CURRENT, 'new_password_confirmation' => self::CURRENT])->assertSessionHasErrors('new_password');

        Notification::assertNothingSent();
    }

    public function test_a_failed_form_keeps_no_password_in_the_session(): void
    {
        $this->requestChange(['new_password_confirmation' => 'Different-Pass3!']);

        $this->assertNull(session()->getOldInput('new_password'));
        $this->assertNull(session()->getOldInput('current_password'));
    }

    public function test_the_pending_password_is_hashed_and_never_serialized(): void
    {
        $this->requestChange();
        $buyer = $this->buyer->fresh();

        $this->assertNotSame(self::NEW, $buyer->pending_password);
        $this->assertTrue(Hash::check(self::NEW, $buyer->pending_password));
        $this->assertArrayNotHasKey('pending_password', $buyer->toArray());
        $this->assertArrayNotHasKey('pending_password_code', $buyer->toArray());
    }

    public function test_a_code_only_changes_its_own_buyers_password(): void
    {
        $this->requestChange();
        $code = $this->latestCode();
        $other = Buyer::factory()->create(['password' => 'Other-Pass9!', 'status' => 'approved', 'email_verified_at' => now()]);

        $this->actingAs($other, 'buyer');
        $this->confirm($code)->assertRedirect(route('buyer.account.password'));

        $this->assertTrue(Hash::check('Other-Pass9!', $other->fresh()->password));
        $this->assertTrue($this->passwordIs(self::CURRENT));
    }

    public function test_guests_cannot_reach_any_step(): void
    {
        auth('buyer')->logout();

        $this->requestChange()->assertRedirect();
        $this->post(route('buyer.account.password.verify'), ['otp' => '123456'])->assertRedirect();
        $this->post(route('buyer.account.password.resend'))->assertRedirect();
        $this->delete(route('buyer.account.password.cancel'))->assertRedirect();

        Notification::assertNothingSent();
        $this->assertTrue($this->passwordIs(self::CURRENT));
    }
}
