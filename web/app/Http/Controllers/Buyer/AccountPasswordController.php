<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Concerns\VerifiesEmailOtp;
use App\Http\Controllers\Controller;
use App\Models\Buyer\Buyer;
use App\Notifications\Buyer\BuyerPasswordChanged;
use App\Notifications\Buyer\BuyerPasswordChangeOtp;
use App\Support\PasswordRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Changing the password from the account tab. The new password is only held as
 * a pending hash, and `password` stays the working login until the code sent to
 * the buyer's email is confirmed.
 *
 * Rate limits key on the buyer, so a lock survives reloads and new requests.
 */
class AccountPasswordController extends Controller
{
    use VerifiesEmailOtp;

    private const OTP_SCOPE = 'buyer-password-change';

    private const BAG = 'passwordChange';

    public function request(Request $request): RedirectResponse
    {
        /** @var Buyer $buyer */
        $buyer = $request->user('buyer');

        // Built by hand: the automatic redirect would flash new_password into the session.
        $validator = Validator::make($request->only('current_password', 'new_password', 'new_password_confirmation'), [
            'current_password' => ['required', 'string'],
            'new_password' => PasswordRule::rules(),
        ], [
            'current_password.required' => 'Enter your current password.',
            'new_password.required' => 'Enter a new password.',
            ...PasswordRule::messages('new_password'),
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator);
        }

        $data = $validator->validated();

        // Reject outright if the current password doesn't match — never
        // silently no-op.
        if (! Hash::check($data['current_password'], $buyer->password)) {
            return back()->withErrors(['current_password' => 'That doesn\'t match your current password.']);
        }

        if (Hash::check($data['new_password'], $buyer->password)) {
            return back()->withErrors(['new_password' => 'Choose a password different from your current one.']);
        }

        $this->sendCode($buyer, Hash::make($data['new_password']), 'new_password', 'default');

        return back()->with('success', "We sent a 6-digit code to {$buyer->email}. Your current password keeps working until you enter it.");
    }

    public function resend(Request $request): RedirectResponse
    {
        /** @var Buyer $buyer */
        $buyer = $request->user('buyer');

        if ($buyer->pending_password === null) {
            return to_route('buyer.account.password');
        }

        $this->sendCode($buyer, $buyer->pending_password, 'otp', self::BAG);

        return back()->with('success', "We sent a new code to {$buyer->email}.");
    }

    public function verify(Request $request): RedirectResponse
    {
        /** @var Buyer $buyer */
        $buyer = $request->user('buyer');
        $lockKey = (string) $buyer->id;

        if ($buyer->pending_password === null) {
            return to_route('buyer.account.password');
        }

        $otp = $request->validateWithBag(self::BAG, [
            'otp' => ['required', 'string', 'regex:/^\d{6}$/'],
        ], $this->otpValidationMessages())['otp'];

        if ($this->otpIsLocked(self::OTP_SCOPE, $lockKey)) {
            $this->fail('otp', $this->otpLockedMessage(self::OTP_SCOPE, $lockKey), self::BAG);
        }

        if ($buyer->pending_password_code === null || ! hash_equals($buyer->pending_password_code, Buyer::hashOtp($otp))) {
            $this->registerOtpFailure(self::OTP_SCOPE, $lockKey);

            $this->fail('otp', $this->otpIsLocked(self::OTP_SCOPE, $lockKey)
                ? $this->otpLockedMessage(self::OTP_SCOPE, $lockKey)
                : $this->otpInvalidMessage(), self::BAG);
        }

        if ($buyer->pending_password_expires_at === null || $buyer->pending_password_expires_at->isPast()) {
            $this->fail('otp', $this->otpExpiredMessage(), self::BAG);
        }

        $buyer->forceFill([
            'password' => $buyer->pending_password,
            'remember_token' => Str::random(60),
            'pending_password' => null,
            'pending_password_code' => null,
            'pending_password_expires_at' => null,
        ])->save();

        $this->clearOtpFailures(self::OTP_SCOPE, $lockKey);

        // AuthenticateRoleSession signs out sessions holding the old password's
        // stamp. Dropping this one's stamp keeps this browser signed in.
        $request->session()->forget(['password_hash_buyer', 'password_hash_user_buyer']);
        $request->session()->regenerate();

        $this->noticeChange($buyer);

        return to_route('buyer.account.password')->with('success', 'Your password has been changed.');
    }

    public function cancel(Request $request): RedirectResponse
    {
        /** @var Buyer $buyer */
        $buyer = $request->user('buyer');

        $buyer->forceFill([
            'pending_password' => null,
            'pending_password_code' => null,
            'pending_password_expires_at' => null,
        ])->save();

        return to_route('buyer.account.password')->with('success', 'Password change cancelled. Your current password is unchanged.');
    }

    /** Mail first, persist after: a failed send must not replace a code the buyer already received. */
    private function sendCode(Buyer $buyer, string $passwordHash, string $field, string $bag): void
    {
        $lockKey = (string) $buyer->id;

        if ($this->otpResendIsLimited(self::OTP_SCOPE, $lockKey)) {
            $minutes = max(1, (int) ceil($this->otpResendSecondsRemaining(self::OTP_SCOPE, $lockKey) / 60));
            $this->fail($field, "Too many codes requested. Please try again in {$minutes} minute(s).", $bag);
        }

        $code = $buyer->generateOtp();

        try {
            Notification::route('mail', $buyer->email)->notifyNow(new BuyerPasswordChangeOtp($code, $buyer->fullName()));
        } catch (Throwable $exception) {
            report($exception);
            $this->fail($field, 'We could not send a code to your email. Please try again.', $bag);
        }

        $this->registerOtpResend(self::OTP_SCOPE, $lockKey);

        $buyer->forceFill([
            'pending_password' => $passwordHash,
            'pending_password_code' => Buyer::hashOtp($code),
            'pending_password_expires_at' => now()->addMinutes(Buyer::OTP_TTL_MINUTES),
        ])->save();
    }

    /** The change has already happened, so a mail failure must not look like it did not. */
    private function noticeChange(Buyer $buyer): void
    {
        try {
            Notification::route('mail', $buyer->email)->notifyNow(new BuyerPasswordChanged($buyer->fullName()));
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function fail(string $field, string $message, string $bag): never
    {
        throw ValidationException::withMessages([$field => $message])->errorBag($bag);
    }
}
