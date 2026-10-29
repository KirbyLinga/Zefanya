<?php

namespace Tests\Feature\Api;

use App\Models\Buyer\Buyer;
use App\Notifications\Buyer\BuyerEmailChangeOtp;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The account-and-security routes: password, email and phone changes.
 *
 * Each test follows the property the workbook cares about rather than only the
 * response code: the old credential stops working, the new one starts working,
 * and nothing is written until the proof (current password or OTP) arrives.
 */
class AccountSecurityApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function buyer(array $overrides = []): Buyer
    {
        return Buyer::factory()->create($overrides);
    }

    private function password(Buyer $buyer, array $body)
    {
        return $this->withToken($buyer->createToken('phone-a')->plainTextToken)
            ->postJson(route('api.account.password'), $body, ['Accept' => 'application/json']);
    }

    private function login(string $email, string $password)
    {
        return $this->postJson(
            route('api.login.buyer'),
            ['email' => $email, 'password' => $password],
            ['Accept' => 'application/json'],
        );
    }

    // ------ BUY-ACCT-003: correct current password -------------------------

    public function test_the_new_password_works_the_old_one_does_not_and_other_sessions_end(): void
    {
        $buyer = $this->buyer(['password' => Hash::make('OldPass1!')]);
        $current = $buyer->createToken('phone-a')->plainTextToken;
        $other = $buyer->createToken('phone-b')->plainTextToken;

        $this->withToken($current)
            ->postJson(route('api.account.password'), [
                'current_password' => 'OldPass1!',
                'password' => 'NewPass1!',
                'password_confirmation' => 'NewPass1!',
            ])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertTrue(Hash::check('NewPass1!', $buyer->fresh()->password));

        // Laravel's RequestGuard caches the authenticated user for the life of
        // the test application; a second token in the same test would otherwise
        // still be read as the first request's user. A real phone makes a new
        // request against a fresh guard.
        $this->app['auth']->forgetGuards();

        // The phone that made the change keeps its session; the other one is
        // revoked. This is the server half of BUY-ACCT-011.
        $this->withToken($current)->getJson(route('api.me'))->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($other)->getJson(route('api.me'))->assertStatus(401);
        $this->app['auth']->forgetGuards();

        $this->login($buyer->email, 'OldPass1!')
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
        $this->login($buyer->email, 'NewPass1!')->assertOk();
    }

    // ------ BUY-ACCT-004: wrong current password ---------------------------

    public function test_a_wrong_current_password_is_rejected_and_changes_nothing(): void
    {
        $buyer = $this->buyer(['password' => Hash::make('OldPass1!')]);

        $this->password($buyer, [
            'current_password' => 'NotMyPass1!',
            'password' => 'NewPass1!',
            'password_confirmation' => 'NewPass1!',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_password')
            ->assertJsonPath('errors.current_password.0', 'Current password is incorrect.');

        $this->assertTrue(Hash::check('OldPass1!', $buyer->fresh()->password));
        $this->login($buyer->email, 'OldPass1!')->assertOk();
    }

    // ------ BUY-ACCT-005: the new password rule ----------------------------

    public function test_each_broken_password_rule_names_the_password_field(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $buyer = $this->buyer(['password' => Hash::make('OldPass1!')]);

        $bad = [
            'Aa1!aa',       // too short
            'lowercase1!',  // no uppercase
            'UPPERCASE1!',  // no lowercase
            'NoNumber!!',   // no digit
            'NoSymbol11',   // no symbol
            'Abc def1!',    // whitespace
        ];

        foreach ($bad as $password) {
            $this->password($buyer, [
                'current_password' => 'OldPass1!',
                'password' => $password,
                'password_confirmation' => $password,
            ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('password');
        }

        $this->assertTrue(Hash::check('OldPass1!', $buyer->fresh()->password));
    }

    // ------ BUY-ACCT-006/007/008: email change -----------------------------

    public function test_changing_email_needs_the_code_and_only_then_moves_the_login(): void
    {
        Notification::fake();
        $buyer = $this->buyer(['password' => Hash::make('Passw0rd!')]);
        $newEmail = 'new-address@example.com';
        $code = null;

        $this->withToken($buyer->createToken('phone-a')->plainTextToken)
            ->postJson(route('api.account.email.request'), ['email' => $newEmail])
            ->assertOk()
            ->assertJson(['ok' => true]);

        Notification::assertSentOnDemand(
            BuyerEmailChangeOtp::class,
            function ($notification, $channels, $notifiable) use (&$code, $newEmail) {
                $code = $notification->code;
                $routes = (array) ($notifiable->routes['mail'] ?? []);

                return in_array($newEmail, $routes, true);
            },
        );

        // Nothing has moved yet: the old address still signs in and is what
        // /me reports.
        $this->assertSame($buyer->email, $buyer->fresh()->email);
        $this->login($buyer->email, 'Passw0rd!')->assertOk();
        $this->withToken($buyer->createToken('phone-check')->plainTextToken)
            ->getJson(route('api.me'))
            ->assertOk()
            ->assertJsonPath('email', $buyer->email);

        $this->withToken($buyer->createToken('phone-b')->plainTextToken)
            ->postJson(route('api.account.email.verify'), ['otp' => $code])
            ->assertOk()
            ->assertJson(['ok' => true, 'email' => $newEmail]);

        $this->assertSame($newEmail, $buyer->fresh()->email);

        $this->login($buyer->email, 'Passw0rd!')->assertStatus(422);
        $this->login($newEmail, 'Passw0rd!')->assertOk();
    }

    public function test_a_wrong_or_expired_email_code_changes_nothing(): void
    {
        Notification::fake();
        $buyer = $this->buyer();
        $token = $buyer->createToken('phone-a')->plainTextToken;

        $this->withToken($token)
            ->postJson(route('api.account.email.request'), ['email' => 'first@example.com'])
            ->assertOk();

        $wrong = $this->withToken($token)
            ->postJson(route('api.account.email.verify'), ['otp' => '000000'])
            ->assertStatus(422)
            ->assertJson(['ok' => false])
            ->assertJsonMissingPath('errors');

        $this->assertNotSame('first@example.com', $buyer->fresh()->email);

        // A second request issues a fresh code; let it age past ten minutes and
        // the same code must be refused with `expired`, not a silent success.
        $code = null;
        Notification::fake();
        $this->withToken($token)
            ->postJson(route('api.account.email.request'), ['email' => 'second@example.com'])
            ->assertOk();
        Notification::assertSentOnDemand(
            BuyerEmailChangeOtp::class,
            function ($notification) use (&$code) {
                $code = $notification->code;

                return true;
            },
        );

        $this->travel(11)->minutes();

        $this->withToken($token)
            ->postJson(route('api.account.email.verify'), ['otp' => $code])
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'expired' => true]);

        $this->assertSame($buyer->email, $buyer->fresh()->email);
    }

    public function test_a_duplicate_or_current_email_is_refused_before_a_code_is_sent(): void
    {
        Notification::fake();
        $buyer = $this->buyer(['email' => 'mine@example.com']);
        $this->buyer(['email' => 'taken@example.com']);
        $token = $buyer->createToken('phone-a')->plainTextToken;

        $this->withToken($token)
            ->postJson(route('api.account.email.request'), ['email' => 'taken@example.com'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email')
            ->assertJsonPath('errors.email.0', 'This email is already in use.');

        $this->withToken($token)
            ->postJson(route('api.account.email.request'), ['email' => 'mine@example.com'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        Notification::assertNothingSent();
        $this->assertNull($buyer->fresh()->pending_email);
    }

    // ------ BUY-ACCT-009: phone change -------------------------------------

    public function test_invalid_and_duplicate_phones_are_refused_without_issuing_a_code(): void
    {
        Log::spy();
        $buyer = $this->buyer(['contact_no' => '09170000000']);
        $this->buyer(['contact_no' => '09171111111']);
        $token = $buyer->createToken('phone-a')->plainTextToken;

        $this->withToken($token)
            ->postJson(route('api.account.phone.request'), ['contact_no' => '12345'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('contact_no');

        $this->withToken($token)
            ->postJson(route('api.account.phone.request'), ['contact_no' => '09171111111'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('contact_no')
            ->assertJsonPath('errors.contact_no.0', 'This phone number is already in use.');

        $this->withToken($token)
            ->postJson(route('api.account.phone.request'), ['contact_no' => '09170000000'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('contact_no');

        Log::shouldNotHaveReceived('warning');
        $this->assertNull($buyer->fresh()->pending_phone);
    }

    public function test_the_phone_is_saved_only_after_the_code_matches(): void
    {
        Log::spy();
        $buyer = $this->buyer(['contact_no' => '09170000000']);
        $token = $buyer->createToken('phone-a')->plainTextToken;
        $code = null;

        Log::shouldReceive('warning')
            ->once()
            ->andReturnUsing(function ($message, $context) use (&$code) {
                $code = $context['code'] ?? null;
            });

        $this->withToken($token)
            ->postJson(route('api.account.phone.request'), ['contact_no' => '09172222222'])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertSame('09170000000', $buyer->fresh()->contact_no);
        $this->assertSame('09172222222', $buyer->fresh()->pending_phone);

        $this->withToken($token)
            ->postJson(route('api.account.phone.verify'), ['otp' => '000000'])
            ->assertStatus(422)
            ->assertJson(['ok' => false]);
        $this->assertSame('09170000000', $buyer->fresh()->contact_no);

        $this->withToken($token)
            ->postJson(route('api.account.phone.verify'), ['otp' => $code])
            ->assertOk()
            ->assertJson(['ok' => true, 'contact_no' => '09172222222']);

        $buyer->refresh();
        $this->assertSame('09172222222', $buyer->contact_no);
        $this->assertNull($buyer->pending_phone);
        $this->assertNull($buyer->pending_phone_code);
    }

    public function test_the_account_routes_are_authenticated(): void
    {
        $this->postJson(route('api.account.password'), [])->assertStatus(401);
        $this->postJson(route('api.account.email.request'), [])->assertStatus(401);
        $this->postJson(route('api.account.phone.request'), [])->assertStatus(401);
    }
}
