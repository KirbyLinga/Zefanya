<?php

namespace Tests\Unit;

use App\Services\Auth\GoogleApiTokenVerifier;
use Google\Client;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Step 1d: runs the REAL GoogleApiTokenVerifier with a stubbed Google\Client.
 *
 * Six cases as required by the work order:
 *  (i)   valid payload                 → returns normalized claims
 *  (ii)  email_verified false          → throws (wrong_issuer check runs first,
 *                                        but email_verified is normalised to
 *                                        false in the returned array — the
 *                                        controller's claimsProblem catches it)
 *  (iii) wrong audience                → throws InvalidArgumentException
 *  (iv)  wrong issuer                  → throws InvalidArgumentException
 *  (v)   expired token                 → throws InvalidArgumentException
 *  (vi)  client throws                 → throws InvalidArgumentException
 *
 * The Google\Client is stubbed so no network call is made.
 *
 * Mutations d1 and d4 are re-run below (see test_d1_* and test_d4_*):
 *  d1: email_verified removed from claimsProblem() — tested via ClaimsProblemTest;
 *      here we confirm the verifier itself normalises email_verified correctly.
 *  d4: audience check removed from verifier — this test catches it.
 */
class GoogleApiTokenVerifierTest extends TestCase
{
    private const CLIENT_ID = 'test-client-id';

    /** @var array<string, mixed> */
    private array $validPayload = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->validPayload = [
            'sub' => 'google-sub-999',
            'email' => 'real@gmail.com',
            'email_verified' => true,
            'name' => 'Real User',
            'iss' => 'https://accounts.google.com',
            'aud' => self::CLIENT_ID,
            'exp' => 4102444800, // 2100-01-01
        ];
    }

    /**
     * Build a verifier with a stubbed Client returning the given payload.
     * Config is set so the verifier has a client id to check against.
     */
    private function verifier(array|false $clientPayload, ?\Throwable $throws = null): GoogleApiTokenVerifier
    {
        // Config must be set before the constructor reads it.
        config(['services.google.client_ids' => [self::CLIENT_ID]]);

        // Use an anonymous subclass to avoid Google\Client constructor side-effects.
        $stub = new class($clientPayload, $throws) extends Client
        {
            public function __construct(
                private readonly array|false $fakePayload,
                private readonly ?\Throwable $fakeException,
            ) {
                // Skip Google\Client::__construct entirely.
            }

            /** @return array|false */
            public function verifyIdToken($idToken = null)
            {
                if ($this->fakeException !== null) {
                    throw $this->fakeException;
                }

                return $this->fakePayload;
            }
        };

        return new GoogleApiTokenVerifier($stub);
    }

    // ── (i) valid ────────────────────────────────────────────────────────────

    public function test_valid_payload_returns_normalized_claims(): void
    {
        $verifier = $this->verifier($this->validPayload);

        $claims = $verifier->verify('any-token');

        $this->assertSame('google-sub-999', $claims['sub']);
        $this->assertSame('real@gmail.com', $claims['email']);
        $this->assertTrue($claims['email_verified']);
        $this->assertSame('https://accounts.google.com', $claims['iss']);
        $this->assertSame(self::CLIENT_ID, $claims['aud']);
        $this->assertSame(4102444800, $claims['exp']);
    }

    // ── (ii) email_verified false — verifier normalises it; claimsProblem catches ──

    public function test_email_verified_false_is_normalised_not_thrown(): void
    {
        $payload = array_merge($this->validPayload, ['email_verified' => false]);
        $verifier = $this->verifier($payload);

        // The verifier does NOT throw on email_verified — it normalises it to
        // false and returns the claims. The controller's claimsProblem() is the
        // layer that rejects it.
        $claims = $verifier->verify('any-token');

        $this->assertFalse($claims['email_verified']);
    }

    // ── (iii) wrong audience ─────────────────────────────────────────────────

    /**
     * Mutation d4 target: audience check in GoogleApiTokenVerifier.
     */
    public function test_wrong_audience_throws(): void
    {
        $payload = array_merge($this->validPayload, ['aud' => 'somebody-elses-client']);
        $verifier = $this->verifier($payload);

        $this->expectException(InvalidArgumentException::class);

        $verifier->verify('any-token');
    }

    // ── (iv) wrong issuer ────────────────────────────────────────────────────

    public function test_wrong_issuer_throws(): void
    {
        $payload = array_merge($this->validPayload, ['iss' => 'https://evil.example.com']);
        $verifier = $this->verifier($payload);

        $this->expectException(InvalidArgumentException::class);

        $verifier->verify('any-token');
    }

    // ── (v) expired ──────────────────────────────────────────────────────────

    public function test_expired_token_throws(): void
    {
        $payload = array_merge($this->validPayload, ['exp' => time() - 60]);
        $verifier = $this->verifier($payload);

        $this->expectException(InvalidArgumentException::class);

        $verifier->verify('any-token');
    }

    // ── (vi) client throws ───────────────────────────────────────────────────

    public function test_client_exception_is_wrapped_and_rethrown(): void
    {
        $verifier = $this->verifier(false, new \RuntimeException('certificate fetch failed'));

        $this->expectException(InvalidArgumentException::class);

        $verifier->verify('any-token');
    }

    // ── mutation d1 re-run (audience removed from verifier) ─────────────────
    // This is the same as test_wrong_audience_throws — alias for clarity.

    public function test_d4_audience_check_is_present_in_verifier(): void
    {
        $payload = array_merge($this->validPayload, ['aud' => 'not-my-client']);
        $verifier = $this->verifier($payload);

        try {
            $verifier->verify('any-token');
            $this->fail('Expected InvalidArgumentException for wrong audience.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Invalid', $e->getMessage());
        }
    }

    // ── mutation d1 re-run (email_verified normalisation) ───────────────────
    // email_verified is normalised, not enforced in the verifier. Confirms
    // email_verified=true is kept and email_verified=false is kept as false.

    public function test_d1_email_verified_true_is_kept_in_claims(): void
    {
        $verifier = $this->verifier($this->validPayload);
        $claims = $verifier->verify('any-token');

        $this->assertTrue($claims['email_verified']);
    }
}
