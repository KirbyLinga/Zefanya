<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\Auth\GoogleAuthController;
use App\Http\Controllers\Api\Courier\CourierLoginController;
use App\Services\Auth\CourierRegistrationService;
use App\Services\Auth\GoogleIdTokenVerifier;
use PHPUnit\Framework\TestCase;

/**
 * Data-driven unit tests for GoogleAuthController::claimsProblem().
 *
 * claimsProblem is the controller's defence-in-depth check on normalized
 * claims returned by the verifier. These tests exercise it directly via
 * reflection so every rejection path is covered in isolation, not just
 * through the HTTP endpoint's fake-verifier integration tests.
 */
class ClaimsProblemTest extends TestCase
{
    /** @var list<string> */
    private array $clientIds = ['test-client-id'];

    /** @var array<string, mixed> */
    private array $validClaims = [
        'sub' => 'google-sub-123',
        'email' => 'user@gmail.com',
        'email_verified' => true,
        'name' => 'Test User',
        'iss' => 'https://accounts.google.com',
        'aud' => 'test-client-id',
        'exp' => 4102444800, // 2100-01-01
    ];

    private function claimsProblem(array $claims): ?string
    {
        $verifier = new class implements GoogleIdTokenVerifier
        {
            public function verify(string $idToken): array
            {
                return [];
            }
        };

        $controller = new GoogleAuthController(
            $verifier,
            $this->createStub(CourierRegistrationService::class),
            $this->createStub(CourierLoginController::class),
        );

        $method = (new \ReflectionClass($controller))->getMethod('claimsProblem');

        return $method->invoke($controller, $claims, $this->clientIds);
    }

    public function test_email_verified_false_is_rejected(): void
    {
        $this->assertSame(
            'email_not_verified',
            $this->claimsProblem(array_merge($this->validClaims, ['email_verified' => false])),
        );
    }

    public function test_email_verified_missing_is_rejected(): void
    {
        $claims = $this->validClaims;
        unset($claims['email_verified']);

        $this->assertSame('email_not_verified', $this->claimsProblem($claims));
    }

    public function test_email_verified_string_false_is_rejected(): void
    {
        $this->assertSame(
            'email_not_verified',
            $this->claimsProblem(array_merge($this->validClaims, ['email_verified' => 'false'])),
        );
    }

    public function test_wrong_audience_is_rejected(): void
    {
        $this->assertSame(
            'wrong_audience',
            $this->claimsProblem(array_merge($this->validClaims, ['aud' => 'somebody-elses-client'])),
        );
    }

    public function test_wrong_issuer_is_rejected(): void
    {
        $this->assertSame(
            'wrong_issuer',
            $this->claimsProblem(array_merge($this->validClaims, ['iss' => 'https://evil.example.com'])),
        );
    }

    public function test_expired_token_is_rejected(): void
    {
        $this->assertSame(
            'expired',
            $this->claimsProblem(array_merge($this->validClaims, ['exp' => time() - 60])),
        );
    }

    public function test_empty_sub_is_rejected(): void
    {
        $this->assertSame(
            'missing_email_or_sub',
            $this->claimsProblem(array_merge($this->validClaims, ['sub' => ''])),
        );
    }

    public function test_empty_email_is_rejected(): void
    {
        $this->assertSame(
            'missing_email_or_sub',
            $this->claimsProblem(array_merge($this->validClaims, ['email' => ''])),
        );
    }

    public function test_valid_claims_pass(): void
    {
        $this->assertNull($this->claimsProblem($this->validClaims));
    }
}
