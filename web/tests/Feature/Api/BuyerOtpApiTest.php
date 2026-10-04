<?php

namespace Tests\Feature\Api;

use App\Models\Buyer\Buyer;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * Confirming the emailed code from the app: the two routes under
 * `/api/register/buyer/verify-otp/{buyer}`.
 *
 * The response shapes here are not arbitrary. The app renders the server's own
 * sentence in one inline line under the field, so:
 *
 *  - a refusal must carry `message` and must **not** carry an `errors` bag
 *    (a field bag would claim the server said *which* field was wrong when all
 *    it said was no), and
 *  - a success must say which gate the account is now at, because a phone has no
 *    redirect URL to follow.
 *
 * The last test in this file is the one that matters most structurally: it
 * checks the mobile route and the web modal agree about whether the gate is
 * open, which is the thing two copies of "does this code match" would silently
 * stop agreeing about.
 */
class BuyerOtpApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * A buyer sitting on the email gate, and the code that would open it.
     *
     * @return array{0: Buyer, 1: string}
     */
    private function awaitingVerification(array $overrides = []): array
    {
        $buyer = Buyer::factory()->create(array_merge([
            'status' => 'pending_verification',
            'email_verified_at' => null,
            'approved_at' => null,
        ], $overrides));

        return [$buyer, $buyer->issueEmailVerificationCode()];
    }

    private function confirm(Buyer $buyer, string $code)
    {
        return $this->postJson(
            route('api.register.buyer.verify-otp', $buyer->id),
            ['otp' => $code],
            ['Accept' => 'application/json'],
        );
    }

    // ── Opening the gate ─────────────────────────────────────────────────────

    public function test_the_right_code_moves_the_account_to_the_admin_gate(): void
    {
        [$buyer, $code] = $this->awaitingVerification();

        $this->confirm($buyer, $code)
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'registration_status' => 'pending_approval',
            ]);

        $buyer->refresh();
        $this->assertSame('pending_approval', $buyer->status);
        $this->assertNotNull($buyer->email_verified_at);
        // Cleared, so the code cannot be replayed by whoever else saw the email.
        $this->assertNull($buyer->email_verification_code);
    }

    public function test_a_confirmed_account_can_confirm_again_without_a_failure(): void
    {
        [$buyer, $code] = $this->awaitingVerification();
        $this->confirm($buyer, $code)->assertOk();

        // A double tap, or a resend whose mail arrived after the confirmation,
        // must not look like a failure to the person who just succeeded.
        $this->confirm($buyer, $code)
            ->assertOk()
            ->assertJson(['ok' => true, 'already_verified' => true]);
    }

    // ── Refusals, and the shape they must have ───────────────────────────────

    public function test_a_wrong_code_is_a_message_without_a_field_bag(): void
    {
        [$buyer, $code] = $this->awaitingVerification();
        $wrong = $code === '000000' ? '111111' : '000000';

        $response = $this->confirm($buyer, $wrong);

        $response->assertStatus(422)
            ->assertJson(['ok' => false])
            ->assertJsonStructure(['ok', 'message'])
            ->assertJsonMissingPath('errors')
            ->assertJsonMissingPath('expired');

        $this->assertSame('pending_verification', $buyer->fresh()->status);
    }

    public function test_an_expired_code_says_expired_and_changes_nothing(): void
    {
        [$buyer, $code] = $this->awaitingVerification();

        $this->travel(11)->minutes();

        $this->confirm($buyer, $code)
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'expired' => true])
            ->assertJsonMissingPath('errors');

        $this->assertSame('pending_verification', $buyer->fresh()->status);
    }

    public function test_the_field_is_validated_before_the_code_is_compared(): void
    {
        [$buyer, $code] = $this->awaitingVerification();

        // The app enforces this shape in the field itself, so a request like
        // this means a client that is not the app — and it must be answered by
        // validation (which names the field) rather than by spending an attempt
        // on a code that could not have been right.
        //
        // Note the shape difference from the refusals above: a validation
        // failure is Laravel's own `{message, errors}` with no `ok` key, while a
        // wrong code is this controller's `{ok: false, message}`. Both arrive at
        // the app as a 422 carrying a sentence, which is all the screen reads.
        $this->confirm($buyer, '12345')
            ->assertStatus(422)
            ->assertJsonValidationErrors('otp')
            ->assertJsonMissingPath('ok');

        $this->confirm($buyer, 'abcdef')
            ->assertStatus(422)
            ->assertJsonValidationErrors('otp');
    }

    // ── Resending ────────────────────────────────────────────────────────────

    public function test_resend_issues_a_new_code_and_retires_the_old_one(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        [$buyer, $oldCode] = $this->awaitingVerification();

        $newCode = null;
        $this->postJson(route('api.register.buyer.verify-otp.resend', $buyer->id))
            ->assertOk()
            ->assertJson(['ok' => true, 'message' => 'A new code has been sent.']);

        \Illuminate\Support\Facades\Notification::assertSentTo(
            $buyer->fresh(),
            \App\Notifications\Buyer\BuyerVerifyOtp::class,
            function ($notification) use (&$newCode) {
                $newCode = $notification->code;

                return true;
            },
        );

        $this->assertNotSame($oldCode, $newCode);

        // Both halves matter: the code just sent works, and the one it replaced
        // does not. A resend that left the first code live would quietly double
        // the window an intercepted email stays useful for.
        if ($oldCode !== $newCode) {
            $this->confirm($buyer, $oldCode)->assertStatus(422);
        }
        $this->confirm($buyer, $newCode)->assertOk();
    }

    public function test_resend_is_refused_once_the_gate_is_open(): void
    {
        [$buyer, $code] = $this->awaitingVerification();
        $this->confirm($buyer, $code)->assertOk();

        $this->postJson(route('api.register.buyer.verify-otp.resend', $buyer->id))
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'message' => 'This account is already verified.']);
    }

    // ── The two doors agree ──────────────────────────────────────────────────

    public function test_the_web_modal_sees_the_gate_the_api_opened(): void
    {
        [$buyer, $code] = $this->awaitingVerification();

        $this->confirm($buyer, $code)->assertOk();

        // The web modal posts to its own route with the same code. It must read
        // the same row as "already verified" — the failure this guards is two
        // controllers with two copies of "is this account past the gate", where
        // one offers a confirmation the other considers finished.
        $this->postJson(
            route('register.buyer.verify-otp', $buyer->id),
            ['otp' => $code],
        )->assertOk()->assertJson(['ok' => true, 'already_verified' => true]);
    }
}
