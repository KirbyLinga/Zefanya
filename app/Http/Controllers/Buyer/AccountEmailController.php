<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Concerns\VerifiesEmailOtp;
use App\Http\Controllers\Controller;
use App\Models\Buyer\Buyer;
use App\Notifications\Buyer\BuyerEmailChanged;
use App\Notifications\Buyer\BuyerEmailChangeOtp;
use App\Support\EmailRegistry;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Changing the login email from the profile tab. The new address is only held
 * as pending, and `email` stays the working login until the code sent to the
 * new address is confirmed.
 *
 * Rate limits key on the buyer, not the address: otherwise trying another
 * address would reset a lock, and a session could mail-bomb strangers.
 */
class AccountEmailController extends Controller
{
    use VerifiesEmailOtp;

    private const OTP_SCOPE = 'buyer-email-change';

    private const TAKEN_MESSAGE = 'This email is already registered. Use a different email.';

    /** Duplicates are refused here, before any code leaves the building. */
    public function request(Request $request): RedirectResponse
    {
        /** @var Buyer $buyer */
        $buyer = $request->user('buyer');

        if (is_string($request->input('email'))) {
            $request->merge(['email' => EmailRegistry::normalize($request->input('email'))]);
        }

        $email = $request->validateWithBag('emailChange', ['email' => EmailRegistry::formatRules()], [
            'email.required' => 'Enter your new email address.',
            'email.email' => EmailRegistry::FORMAT_MESSAGE,
            'email.regex' => EmailRegistry::FORMAT_MESSAGE,
        ])['email'];

        if ($email === EmailRegistry::normalize($buyer->email)) {
            $this->fail('email', 'This is already your email address.');
        }

        $this->sendCode($buyer, $email);

        return back()->with('success', "We sent a 6-digit code to {$email}. Your current email keeps working until you confirm it.");
    }

    public function resend(Request $request): RedirectResponse
    {
        /** @var Buyer $buyer */
        $buyer = $request->user('buyer');

        if ($buyer->pending_email === null) {
            $this->fail('email', 'Enter your new email address first.');
        }

        $this->sendCode($buyer, $buyer->pending_email);

        return back()->with('success', "We sent a new code to {$buyer->pending_email}.");
    }

    public function verify(Request $request): RedirectResponse
    {
        /** @var Buyer $buyer */
        $buyer = $request->user('buyer');
        $lockKey = (string) $buyer->id;

        $otp = $request->validateWithBag('emailChange', [
            'otp' => ['required', 'string', 'regex:/^\d{6}$/'],
        ], $this->otpValidationMessages())['otp'];

        if ($buyer->pending_email === null) {
            $this->fail('email', 'Enter your new email address first.');
        }

        if ($this->otpIsLocked(self::OTP_SCOPE, $lockKey)) {
            $this->fail('otp', $this->otpLockedMessage(self::OTP_SCOPE, $lockKey));
        }

        if ($buyer->pending_email_code === null || ! hash_equals($buyer->pending_email_code, Buyer::hashOtp($otp))) {
            $this->registerOtpFailure(self::OTP_SCOPE, $lockKey);

            $this->fail('otp', $this->otpIsLocked(self::OTP_SCOPE, $lockKey)
                ? $this->otpLockedMessage(self::OTP_SCOPE, $lockKey)
                : $this->otpInvalidMessage());
        }

        if ($buyer->pending_email_expires_at === null || $buyer->pending_email_expires_at->isPast()) {
            $this->fail('otp', $this->otpExpiredMessage());
        }

        $oldEmail = $buyer->email;
        $newEmail = $buyer->pending_email;

        $this->applyChange($buyer, $newEmail);
        $this->clearOtpFailures(self::OTP_SCOPE, $lockKey);
        $this->noticeOldAddress($oldEmail, $newEmail, $buyer->fullName());

        return back()->with('success', "Your email is now {$newEmail}. Use it the next time you sign in.");
    }

    public function cancel(Request $request): RedirectResponse
    {
        /** @var Buyer $buyer */
        $buyer = $request->user('buyer');

        $this->clearPending($buyer);

        return back()->with('success', 'Email change cancelled. Your current email is unchanged.');
    }

    /**
     * Mail first, persist after: a failed send must not replace a code the
     * buyer already received. Neither the check nor the limit can be skipped
     * by resending instead of requesting.
     */
    private function sendCode(Buyer $buyer, string $email): void
    {
        $lockKey = (string) $buyer->id;

        if (EmailRegistry::rolesFor($email) !== []) {
            $this->fail('email', self::TAKEN_MESSAGE);
        }

        if ($this->otpResendIsLimited(self::OTP_SCOPE, $lockKey)) {
            $minutes = max(1, (int) ceil($this->otpResendSecondsRemaining(self::OTP_SCOPE, $lockKey) / 60));
            $this->fail('email', "Too many codes requested. Please try again in {$minutes} minute(s).");
        }

        $code = $buyer->generateOtp();

        try {
            Notification::route('mail', $email)->notifyNow(new BuyerEmailChangeOtp($code, $buyer->fullName()));
        } catch (Throwable $exception) {
            report($exception);
            $this->fail('email', 'We could not send a code to that address. Please check it and try again.');
        }

        $this->registerOtpResend(self::OTP_SCOPE, $lockKey);

        $buyer->forceFill([
            'pending_email' => $email,
            'pending_email_code' => Buyer::hashOtp($code),
            'pending_email_expires_at' => now()->addMinutes(Buyer::OTP_TTL_MINUTES),
        ])->save();
    }

    /** Checked again here: the address may have been taken since the code went out. */
    private function applyChange(Buyer $buyer, string $email): void
    {
        try {
            // register() holds the same lock as registration, so a new account
            // in any role cannot claim this address at the same moment.
            EmailRegistry::register($email, 'buyer', function () use ($buyer, $email): Buyer {
                if (EmailRegistry::rolesFor($email) !== []) {
                    throw new UniqueConstraintViolationException('', '', [], new \Exception);
                }

                $buyer->forceFill([
                    'email' => $email,
                    'email_verified_at' => now(),
                    'pending_email' => null,
                    'pending_email_code' => null,
                    'pending_email_expires_at' => null,
                ])->save();

                return $buyer;
            });
        } catch (UniqueConstraintViolationException|ValidationException) {
            $buyer->refresh();
            $this->clearPending($buyer);
            $this->fail('email', self::TAKEN_MESSAGE);
        }
    }

    private function clearPending(Buyer $buyer): void
    {
        $buyer->forceFill([
            'pending_email' => null,
            'pending_email_code' => null,
            'pending_email_expires_at' => null,
        ])->save();
    }

    /** The change has already happened, so a mail failure must not look like it did not. */
    private function noticeOldAddress(string $oldEmail, string $newEmail, string $name): void
    {
        try {
            Notification::route('mail', $oldEmail)->notifyNow(new BuyerEmailChanged($newEmail, $name));
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /** Errors go in their own bag so they stay on this form, not the profile form above it. */
    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message])->errorBag('emailChange');
    }
}
