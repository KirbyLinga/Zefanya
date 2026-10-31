<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Concerns\VerifiesEmailOtp;
use App\Http\Controllers\Controller;
use App\Models\Seller\Seller;
use App\Notifications\Seller\SellerRegistrationOtp;
use App\Support\EmailMask;
use App\Support\SignedOtpLink;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VerifySellerOtpController extends Controller
{
    use VerifiesEmailOtp;

    /**
     * GET /register/seller/verify-otp/{seller}
     * No-JS / deep-link OTP page so an unverified seller can resume
     * verification without re-registering (mirrors the logistics page).
     */
    public function show(Seller $seller)
    {
        if ($seller->status !== 'unverified') {
            return redirect()->route('register.seller.pending');
        }

        return view('Seller.register-seller-verify-otp', [
            'seller' => $seller,
            'emailMasked' => EmailMask::mask($seller->email),
            'verifyUrl' => SignedOtpLink::verifyUrl('seller', $seller),
            'resendUrl' => SignedOtpLink::resendUrl('seller', $seller),
        ]);
    }

    /**
     * POST /register/seller/verify-otp/{seller}
     * Body: { otp: '123456' }
     * Finds seller via model binding, verifies OTP, returns JSON for the modal;
     * a plain form submit is redirected back (see respond()).
     *
     * Rate limited: route throttle (10/min per IP) plus a per-ACCOUNT lock — 5
     * wrong codes lock verification for 15 minutes (survives a resend).
     */
    public function verify(Request $request, Seller $seller)
    {
        $data = $request->validate([
            'otp' => ['required', 'string', 'regex:/^\d{6}$/'],
        ], $this->otpValidationMessages());

        if ($this->otpIsLocked('seller', $seller->email)) {
            return $this->lockedResponse($request, $seller);
        }

        // Only allow OTP verification if seller is in unverified status
        if ($seller->status !== 'unverified') {
            return $this->respond($request, [
                'ok' => false,
                'message' => 'Invalid registration session. Your account is already verified.',
            ], 422);
        }

        // Check if OTP has expired before comparing
        if ($seller->isOtpExpired()) {
            return $this->respond($request, [
                'ok' => false,
                'message' => $this->otpExpiredMessage(),
                'expired' => true,
            ], 422);
        }

        if (! $seller->otpMatches($data['otp'])) {
            $seller->increment('otp_attempts');
            $remaining = $this->registerOtpFailure('seller', $seller->email);

            if ($this->otpIsLocked('seller', $seller->email)) {
                return $this->lockedResponse($request, $seller);
            }

            return $this->respond($request, [
                'ok' => false,
                'message' => $this->otpInvalidMessage(),
                'attempts_remaining' => $remaining,
            ], 422);
        }

        // Wrap in transaction: set status to pending atomically
        DB::transaction(function () use ($seller) {
            $seller->markEmailVerified();
        });

        // Only a SUCCESS clears the failure window.
        $this->clearOtpFailures('seller', $seller->email);

        return $this->respond($request, [
            'ok' => true,
            'redirect' => route('register.seller.pending'),
            'message' => 'Email verified successfully.',
        ], 200);
    }

    /**
     * POST /register/seller/verify-otp/{seller}/resend
     * Finds seller via model binding, generates fresh OTP, emails it.
     *
     * Per-ACCOUNT limit: 3 resends per 10 minutes. A resend does NOT reset the
     * failure counter and never clears the lock.
     */
    public function resend(Request $request, Seller $seller)
    {
        // Only allow resend if seller is in unverified status
        if ($seller->status !== 'unverified') {
            return $this->respond($request, [
                'ok' => false,
                'message' => 'Invalid registration session. Your account is already verified.',
            ], 422);
        }

        if ($this->otpResendIsLimited('seller', $seller->email)) {
            return $this->respond($request, [
                'ok' => false,
                'message' => 'You have requested too many codes. Please wait before requesting another one.',
                'retry_after' => $this->otpResendSecondsRemaining('seller', $seller->email),
            ], 429);
        }

        // Prepare the replacement code but only commit it after delivery, so a
        // delivery failure leaves the code the user already has valid.
        $otp = $seller->generateOtp();

        try {
            $seller->notify(new SellerRegistrationOtp($otp));
        } catch (\Throwable $e) {
            Log::error('Failed to resend seller OTP email', [
                'seller_id' => $seller->id,
                'error' => $e->getMessage(),
            ]);

            return $this->respond($request, [
                'ok' => false,
                'message' => 'We could not send a new code right now. Please try again shortly.',
            ], 503);
        }

        $seller->commitOtp($otp);
        $this->registerOtpResend('seller', $seller->email);

        Log::info('Seller OTP verification code resent', [
            'seller_id' => $seller->id,
        ]);

        return $this->respond($request, [
            'ok' => true,
            'message' => 'A new code has been sent to your email.',
        ], 200);
    }

    /**
     * "Too many wrong codes" answer. JSON requests get HTTP 429; a normal form
     * submit gets a validation-style error.
     */
    private function lockedResponse(Request $request, Seller $seller)
    {
        return $this->respond($request, [
            'ok' => false,
            'locked' => true,
            'retry_after' => $this->otpLockSecondsRemaining('seller', $seller->email),
            'message' => $this->otpLockedMessage('seller', $seller->email),
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
