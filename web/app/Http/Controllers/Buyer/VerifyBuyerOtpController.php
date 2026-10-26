<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Concerns\VerifiesEmailOtp;
use App\Http\Controllers\Controller;
use App\Models\Buyer\Buyer;
use App\Notifications\Buyer\BuyerVerifyOtp;
use App\Support\EmailMask;
use App\Support\SignedOtpLink;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VerifyBuyerOtpController extends Controller
{
    use VerifiesEmailOtp;

    /**
     * GET /register/buyer/verify-otp/{buyer}
     * No-JS / deep-link OTP page so an unverified buyer can resume
     * verification without re-registering (mirrors the logistics page).
     */
    public function show(Buyer $buyer)
    {
        if ($buyer->status !== 'unverified') {
            return redirect()->route('register.buyer.pending');
        }

        return view('Buyer.register-buyer-verify-otp', [
            'buyer' => $buyer,
            'emailMasked' => EmailMask::mask($buyer->email),
            'verifyUrl' => SignedOtpLink::verifyUrl('buyer', $buyer),
            'resendUrl' => SignedOtpLink::resendUrl('buyer', $buyer),
        ]);
    }

    /**
     * POST /register/buyer/verify-otp/{buyer}
     * Body: { otp: '123456' }
     * Returns JSON the modal's JS reads directly; a plain form submit is
     * redirected back (see respond()).
     *
     * Rate limited two ways: the route throttle (10/min per IP) and a
     * per-ACCOUNT lock — 5 wrong codes lock verification for 15 minutes. That
     * lock is NOT cleared by requesting a new code (see VerifiesEmailOtp).
     */
    public function verify(Request $request, Buyer $buyer)
    {
        $data = $request->validate([
            'otp' => ['required', 'string', 'regex:/^\d{6}$/'],
        ]);

        // Per-account brute-force lock, checked before anything else so a
        // locked account cannot keep guessing (or be unlocked by a resend).
        if ($this->otpIsLocked('buyer', $buyer->email)) {
            return $this->lockedResponse($request, $buyer);
        }

        // Only allow OTP verification if buyer is in unverified status. A
        // rejected applicant must not be able to re-enter the flow and flip
        // themselves back into the admin queue.
        if ($buyer->status !== 'unverified') {
            return $this->respond($request, [
                'ok' => false,
                'message' => 'Invalid registration session. Your account is already verified.',
            ], 422);
        }

        if (! $buyer->otpMatches($data['otp'])) {
            $buyer->increment('otp_attempts');
            $remaining = $this->registerOtpFailure('buyer', $buyer->email);

            if ($this->otpIsLocked('buyer', $buyer->email)) {
                return $this->lockedResponse($request, $buyer);
            }

            return $this->respond($request, [
                'ok' => false,
                'message' => 'That code doesn\'t match. Please try again.',
                'attempts_remaining' => $remaining,
            ], 422);
        }

        if ($buyer->isOtpExpired()) {
            return $this->respond($request, [
                'ok' => false,
                'expired' => true,
                'message' => 'This code has expired. Please request a new one.',
            ], 422);
        }

        // Wrap in transaction: set status to pending atomically
        DB::transaction(function () use ($buyer) {
            $buyer->markEmailVerified();
        });

        // Only a SUCCESS clears the failure window.
        $this->clearOtpFailures('buyer', $buyer->email);

        return $this->respond($request, [
            'ok' => true,
            'redirect' => route('register.buyer.pending'),
        ]);
    }

    /**
     * POST /register/buyer/verify-otp/{buyer}/resend
     * Generates a fresh 6-digit code and emails it.
     *
     * Per-ACCOUNT limit: 3 resends per 10 minutes. A resend does NOT reset the
     * failure counter and never clears the lock.
     */
    public function resend(Request $request, Buyer $buyer)
    {
        // Only allow a resend if the buyer is still in unverified status.
        if ($buyer->status !== 'unverified') {
            return $this->respond($request, [
                'ok' => false,
                'message' => 'Invalid registration session. Your account is already verified.',
            ], 422);
        }

        if ($this->otpResendIsLimited('buyer', $buyer->email)) {
            return $this->respond($request, [
                'ok' => false,
                'message' => 'You have requested too many codes. Please wait before requesting another one.',
                'retry_after' => $this->otpResendSecondsRemaining('buyer', $buyer->email),
            ], 429);
        }

        // Prepare the replacement code but only commit it after delivery, so a
        // delivery failure leaves the code the user already has valid.
        $otp = $buyer->generateOtp();

        try {
            $buyer->notify(new BuyerVerifyOtp($otp));
        } catch (\Throwable $e) {
            Log::error('Failed to resend buyer OTP email', [
                'buyer_id' => $buyer->id,
                'error' => $e->getMessage(),
            ]);

            return $this->respond($request, [
                'ok' => false,
                'message' => 'We could not send a new code right now. Please try again shortly.',
            ], 503);
        }

        $buyer->commitOtp($otp);
        $this->registerOtpResend('buyer', $buyer->email);

        Log::info('Buyer OTP verification code resent', [
            'buyer_id' => $buyer->id,
        ]);

        return $this->respond($request, [
            'ok' => true,
            'message' => 'A new code has been sent.',
        ]);
    }

    /**
     * "Too many wrong codes" answer. JSON requests get HTTP 429; a normal form
     * submit gets a validation-style error.
     */
    private function lockedResponse(Request $request, Buyer $buyer)
    {
        return $this->respond($request, [
            'ok' => false,
            'locked' => true,
            'retry_after' => $this->otpLockSecondsRemaining('buyer', $buyer->email),
            'message' => $this->otpLockedMessage('buyer', $buyer->email),
        ], 429);
    }

    /**
     * JSON for fetch()/XHR callers, a redirect for a normal form submit.
     */
    private function respond(Request $request, array $json, int $status = 200)
    {
        if ($request->wantsJson()) {
            return response()->json($json, $status);
        }

        if ($status >= 400) {
            return back()->withErrors(['otp' => $json['message'] ?? 'Something went wrong.']);
        }

        if (isset($json['redirect'])) {
            return redirect($json['redirect'])->with('success', $json['message'] ?? null);
        }

        return back()->with('success', $json['message'] ?? 'Done.');
    }
}
