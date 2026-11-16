<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * One shared definition of the email-OTP verification/resend rate limits, used
 * by the buyer, seller and logistics verify controllers.
 *
 * Two independent windows, both keyed per EMAIL + role (not per row id), so:
 *   • neither can be bypassed by reloading the page or changing the client
 *   • archiving a row and re-registering (new id) cannot reset a lock
 *
 * Windows:
 *   • wrong codes → max 5, then a 15-minute lock
 *   • resends     → max 3 per 10 minutes
 *
 * A successful verification clears the FAILURE window. Neither window is ever
 * cleared by a resend — request a new code as often as you like and the lock
 * still stands.
 */
trait VerifiesEmailOtp
{
    /** Wrong codes allowed before the account verification is locked. */
    /** Same wording as the "otp" validator in registration-validation.js. */
    protected function otpFormatMessage(): string
    {
        return 'Enter the 6-digit code from your email.';
    }

    protected function otpInvalidMessage(): string
    {
        return 'That code doesn\'t match. Please try again.';
    }

    /** Names the "Resend code" button shown under every OTP form. */
    protected function otpExpiredMessage(): string
    {
        return 'This code has expired. Tap "Resend code" for a new one.';
    }

    /** @return array<string, string> */
    protected function otpValidationMessages(): array
    {
        return [
            'otp.required' => $this->otpFormatMessage(),
            'otp.string' => $this->otpFormatMessage(),
            'otp.regex' => $this->otpFormatMessage(),
        ];
    }

    protected function otpMaxFailures(): int
    {
        return 5;
    }

    /** How long the account stays locked after too many wrong codes. */
    protected function otpLockSeconds(): int
    {
        return 15 * 60;
    }

    /** Resends allowed inside the resend window. */
    protected function otpMaxResends(): int
    {
        return 3;
    }

    /** The resend window length. */
    protected function otpResendWindowSeconds(): int
    {
        return 10 * 60;
    }

    protected function otpEmailKey(string $email): string
    {
        return Str::lower(trim($email));
    }

    protected function otpFailureKey(string $role, string $email): string
    {
        return 'otp-verify:'.$role.':'.$this->otpEmailKey($email);
    }

    protected function otpResendKey(string $role, string $email): string
    {
        return 'otp-resend:'.$role.':'.$this->otpEmailKey($email);
    }

    protected function otpIsLocked(string $role, string $email): bool
    {
        return RateLimiter::tooManyAttempts($this->otpFailureKey($role, $email), $this->otpMaxFailures());
    }

    protected function otpLockSecondsRemaining(string $role, string $email): int
    {
        return RateLimiter::availableIn($this->otpFailureKey($role, $email));
    }

    /**
     * Record one wrong code. Returns how many attempts the user has left
     * (>= 0). The window is (re)armed for another otpLockSeconds() on each hit,
     * so five wrong codes lock the account for 15 minutes from the last try.
     */
    protected function registerOtpFailure(string $role, string $email): int
    {
        $key = $this->otpFailureKey($role, $email);
        RateLimiter::hit($key, $this->otpLockSeconds());

        return max(0, $this->otpMaxFailures() - RateLimiter::attempts($key));
    }

    protected function clearOtpFailures(string $role, string $email): void
    {
        RateLimiter::clear($this->otpFailureKey($role, $email));
    }

    protected function otpResendIsLimited(string $role, string $email): bool
    {
        return RateLimiter::tooManyAttempts($this->otpResendKey($role, $email), $this->otpMaxResends());
    }

    protected function registerOtpResend(string $role, string $email): void
    {
        RateLimiter::hit($this->otpResendKey($role, $email), $this->otpResendWindowSeconds());
    }

    protected function otpResendSecondsRemaining(string $role, string $email): int
    {
        return RateLimiter::availableIn($this->otpResendKey($role, $email));
    }

    /**
     * Human message for a locked account, including the remaining wait. Shared
     * by all three controllers so every surface (JSON and no-JS form) says the
     * same thing.
     */
    protected function otpLockedMessage(string $role, string $email): string
    {
        $minutes = max(1, (int) ceil($this->otpLockSecondsRemaining($role, $email) / 60));

        return "Too many incorrect codes. Please try again in {$minutes} minute(s).";
    }
}
