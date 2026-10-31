<?php

namespace Tests\Unit\Admin;

use App\Services\Admin\TwoFactorAuthenticator;
use Tests\TestCase;

/**
 * RFC 6238 test vectors (SHA-1, 6-digit truncation of the published 8-digit
 * values) prove the TOTP implementation is interoperable with real
 * authenticator apps, not merely self-consistent.
 */
class TwoFactorAuthenticatorTest extends TestCase
{
    /** ASCII "12345678901234567890" in Base32. */
    private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    private TwoFactorAuthenticator $totp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->totp = new TwoFactorAuthenticator;
    }

    public function test_it_matches_the_rfc_6238_sha1_vectors(): void
    {
        $vectors = [
            59 => '287082',
            1111111109 => '081804',
            1111111111 => '050471',
            1234567890 => '005924',
            2000000000 => '279037',
            20000000000 => '353130',
        ];

        foreach ($vectors as $timestamp => $expected) {
            $this->assertSame(
                $expected,
                $this->totp->currentCode(self::RFC_SECRET, $timestamp),
                "TOTP mismatch at timestamp {$timestamp}",
            );
            $this->assertTrue($this->totp->verify(self::RFC_SECRET, $expected, $timestamp));
        }
    }

    public function test_it_accepts_one_period_of_clock_skew_and_rejects_others(): void
    {
        $code = $this->totp->currentCode(self::RFC_SECRET, 59);

        $this->assertTrue($this->totp->verify(self::RFC_SECRET, $code, 89));
        $this->assertFalse($this->totp->verify(self::RFC_SECRET, $code, 150));
    }

    public function test_it_rejects_malformed_codes_and_empty_secrets(): void
    {
        $this->assertFalse($this->totp->verify(self::RFC_SECRET, 'abcdef', 59));
        $this->assertFalse($this->totp->verify(self::RFC_SECRET, '12345', 59));
        $this->assertFalse($this->totp->verify('', '287082', 59));
    }

    public function test_generated_secrets_are_base32_and_unique(): void
    {
        $first = $this->totp->generateSecret();
        $second = $this->totp->generateSecret();

        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $first);
        $this->assertNotSame($first, $second);
    }

    public function test_recovery_codes_are_hashed_and_single_match(): void
    {
        $codes = $this->totp->generateRecoveryCodes();

        $this->assertCount(8, $codes['plain']);
        $this->assertCount(8, $codes['hashed']);
        $this->assertNotSame($codes['plain'][0], $codes['hashed'][0]);

        $index = $this->totp->matchRecoveryCode($codes['plain'][2], $codes['hashed']);

        $this->assertSame(2, $index);
        $this->assertNull($this->totp->matchRecoveryCode('0000-0000', $codes['hashed']));
    }
}
