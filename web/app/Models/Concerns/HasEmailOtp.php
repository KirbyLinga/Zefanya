<?php

namespace App\Models\Concerns;

/**
 * Shared email-OTP behaviour for the three self-registering roles
 * (Buyer, Seller, LogisticsProvider).
 *
 * Keeping the generate → hash → expire → compare rules in ONE place means the
 * three roles can never drift apart, and the (future) switch to a keyed hash is
 * a single-line change.
 *
 * The model must have these columns:
 *   • email_verification_code         (string, nullable, stored HASHED)
 *   • email_verification_expires_at   (datetime, nullable)
 *
 * The model keeps its own markEmailVerified() because the resulting status
 * differs per role (string for buyer/seller, LogisticsProviderStatus for the
 * provider).
 */
trait HasEmailOtp
{
    /**
     * Build a fresh 6-digit code WITHOUT persisting it. The caller stores it
     * with commitOtp() only once delivery has succeeded, so a failed send can
     * never invalidate the code the user already received.
     */
    public function generateOtp(): string
    {
        return (string) random_int(100000, 999999);
    }

    /**
     * Persist the hashed code and its expiry window.
     *
     * Deliberately does NOT touch the failure counter: only a successful
     * verification (markEmailVerified) resets it, so a resend can never be used
     * to clear a brute-force lock.
     */
    public function commitOtp(string $otp): void
    {
        $this->forceFill([
            'email_verification_code' => static::hashOtp($otp),
            'email_verification_expires_at' => now()->addMinutes(10),
        ])->save();
    }

    /**
     * Generate AND persist a code (used at registration time).
     */
    public function issueOtp(): string
    {
        $otp = $this->generateOtp();
        $this->commitOtp($otp);

        return $otp;
    }

    public function otpMatches(string $submitted): bool
    {
        return $this->email_verification_code !== null
            && hash_equals($this->email_verification_code, static::hashOtp($submitted));
    }

    public function isOtpExpired(): bool
    {
        return $this->email_verification_expires_at !== null
            && $this->email_verification_expires_at->isPast();
    }

    /**
     * Single definition of the code hash, so generation and verification can
     * never disagree — and so the hash can be keyed in ONE place.
     *
     * Keyed HMAC (not a bare sha256) so a leaked code/hash is useless without
     * APP_KEY. Public + static so tests can seed a verification code exactly
     * the way the models store it (see BuyerRegistrationTest).
     */
    public static function hashOtp(string $otp): string
    {
        return hash_hmac('sha256', $otp, (string) config('app.key'));
    }
}
