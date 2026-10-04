<?php

namespace Tests\Feature\Api;

use App\Models\Buyer\Buyer;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Signing in from the app: `POST /api/login/buyer`, and the authenticated routes
 * that exist so a token has something to prove itself against.
 *
 * The property under test throughout is that a *valid token is not the same as a
 * usable account*. The app's own `login()` returns a result object for exactly
 * this reason, and these tests pin the server half of that contract: every gate
 * has its own `code` and `registration_status`, a rejected account is never
 * described as pending, and no token is issued to an account that cannot use one.
 */
class BuyerLoginApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** The factory's default is an approved buyer with the password `password`. */
    private function approvedBuyer(array $overrides = []): Buyer
    {
        return Buyer::factory()->create($overrides);
    }

    private function login(string $email, string $password = 'password')
    {
        return $this->postJson(
            route('api.login.buyer'),
            ['email' => $email, 'password' => $password],
            ['Accept' => 'application/json'],
        );
    }

    // ── The account that is through both gates ───────────────────────────────

    public function test_an_approved_buyer_gets_a_token_and_an_identity(): void
    {
        $buyer = $this->approvedBuyer();

        $response = $this->login($buyer->email);

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'role' => 'buyer',
                'email' => $buyer->email,
                'registration_status' => 'approved',
            ])
            ->assertJsonStructure(['ok', 'token', 'role', 'name', 'email', 'registration_status']);

        $this->assertNotEmpty($response->json('token'));

        // The token is only worth issuing if it opens something, so the test
        // follows it rather than reading it.
        $this->withHeader('Authorization', 'Bearer '.$response->json('token'))
            ->getJson(route('api.me'))
            ->assertOk()
            ->assertJson(['ok' => true, 'role' => 'buyer', 'email' => $buyer->email]);
    }

    public function test_credentials_are_checked_against_the_stored_hash(): void
    {
        $buyer = $this->approvedBuyer(['password' => Hash::make('Correct1!')]);

        $this->login($buyer->email, 'Correct1!')->assertOk();
        $this->login($buyer->email, 'Wrong1!')->assertStatus(422);
    }

    // ── The gates ────────────────────────────────────────────────────────────

    public function test_the_email_gate_comes_back_with_a_handle_and_no_token(): void
    {
        $buyer = $this->approvedBuyer([
            'status' => 'pending_verification',
            'email_verified_at' => null,
            'approved_at' => null,
        ]);

        $this->login($buyer->email)
            ->assertStatus(403)
            ->assertJson([
                'ok' => false,
                'code' => 'PENDING_VERIFICATION',
                'registration_status' => 'pending_verification',
                // Without this the app could not offer the confirm screen to
                // someone who closed the app before entering their code.
                'verification_id' => (string) $buyer->id,
            ]);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_the_admin_gate_is_its_own_code_and_has_no_handle(): void
    {
        $buyer = $this->approvedBuyer([
            'status' => 'pending_approval',
            'approved_at' => null,
        ]);

        $this->login($buyer->email)
            ->assertStatus(403)
            ->assertJson([
                'ok' => false,
                'code' => 'PENDING_APPROVAL',
                'registration_status' => 'pending_approval',
            ])
            ->assertJsonMissingPath('verification_id');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_a_rejected_account_is_never_described_as_pending(): void
    {
        $buyer = $this->approvedBuyer(['status' => 'rejected', 'approved_at' => null]);

        $response = $this->login($buyer->email);

        $response->assertStatus(403)
            ->assertJson([
                'ok' => false,
                'code' => 'ACCOUNT_REJECTED',
                'registration_status' => 'rejected',
            ]);

        // The bug this guards: `rejected` is not past email verification, so a
        // gate check ordered the other way round would tell someone their
        // application is still being reviewed after it was turned down.
        $this->assertStringNotContainsString(
            'PENDING_',
            (string) $response->getContent(),
        );
    }

    // ── Credentials ──────────────────────────────────────────────────────────

    public function test_a_wrong_password_is_a_422_on_the_email_field(): void
    {
        $buyer = $this->approvedBuyer();

        $this->login($buyer->email, 'Wrong1!')
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        // No token, and no attempt to hand one out anyway.
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_an_unknown_email_gets_the_same_sentence_as_a_wrong_password(): void
    {
        $buyer = $this->approvedBuyer();

        $wrongPassword = $this->login($buyer->email, 'Wrong1!');
        $unknownEmail = $this->login('nobody'.uniqid().'@example.com');

        $this->assertSame(422, $unknownEmail->status());
        $this->assertSame(
            $wrongPassword->json('errors.email.0'),
            $unknownEmail->json('errors.email.0'),
        );
    }

    // ── The authenticated routes ─────────────────────────────────────────────

    public function test_me_without_a_token_is_unauthorized(): void
    {
        $this->getJson(route('api.me'))->assertStatus(401);
    }

    public function test_me_reports_the_gate_when_the_account_moved_after_the_token_was_issued(): void
    {
        $buyer = $this->approvedBuyer();
        $token = $this->login($buyer->email)->json('token');

        // An account can end up behind a gate again — an admin withdrawing an
        // approval is exactly what `rejection_reason`/`approved_by` exist for —
        // and a token issued before that must not keep reading as signed in.
        $buyer->forceFill(['status' => 'pending_approval'])->save();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson(route('api.me'))
            ->assertStatus(403)
            ->assertJson(['code' => 'PENDING_APPROVAL']);
    }

    public function test_a_revoked_token_is_refused(): void
    {
        $buyer = $this->approvedBuyer();
        $token = $this->login($buyer->email)->json('token');

        // Deleted before any request in *this* test has resolved the guard, then
        // presented. This is the assertion the logout test below cannot make:
        // within one test method Laravel reuses the application, and Sanctum's
        // request guard caches the user it resolved, so a follow-up request can
        // look authenticated by the guard's memory rather than by the token.
        $buyer->tokens()->delete();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson(route('api.me'))
            ->assertStatus(401);
    }

    public function test_logout_revokes_only_the_token_that_was_used(): void
    {
        $buyer = $this->approvedBuyer();

        $phone = $this->login($buyer->email)->json('token');
        $tablet = $this->login($buyer->email)->json('token');
        $this->assertDatabaseCount('personal_access_tokens', 2);

        $this->withHeader('Authorization', 'Bearer '.$phone)
            ->postJson(route('api.logout'))
            ->assertOk();

        // The row itself must be gone. `currentAccessToken()` returns Sanctum's
        // `TransientToken` on the session path, whose `delete()` is a no-op — so
        // this endpoint could answer 200 while revoking nothing, and the count
        // is what catches that.
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertNull(PersonalAccessToken::findToken($phone));
        $this->assertNotNull(PersonalAccessToken::findToken($tablet));

        // The other session is untouched: signing out of one device must not
        // sign the person out of the other.
        $this->withHeader('Authorization', 'Bearer '.$tablet)
            ->getJson(route('api.me'))
            ->assertOk();
    }
}
