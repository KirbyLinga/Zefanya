<?php

namespace App\Http\Controllers\Logistics;

use App\Enums\LogisticsProviderStatus;
use App\Http\Controllers\Concerns\VerifiesEmailOtp;
use App\Http\Controllers\Controller;
use App\Models\Logistics\LogisticsProvider;
use App\Notifications\Logistics\LogisticsRegistrationOtp;
use App\Support\EmailMask;
use App\Support\SignedOtpLink;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VerifyLogisticsOtpController extends Controller
{
    use VerifiesEmailOtp;

    public function show(LogisticsProvider $logisticsProvider)
    {
        if ($logisticsProvider->status !== LogisticsProviderStatus::Unverified) {
            return redirect()->route('register.logistics.pending');
        }

        return view('Logistics.register-logistics-verify-otp', [
            'logisticsProvider' => $logisticsProvider,
            'emailMasked' => EmailMask::mask($logisticsProvider->email),
            'verifyUrl' => SignedOtpLink::verifyUrl('logistics', $logisticsProvider),
            'resendUrl' => SignedOtpLink::resendUrl('logistics', $logisticsProvider),
        ]);
    }

    /**
     * POST /register/logistics/verify-otp/{logistics_provider}
     * Body: { otp: '123456' }
     *
     * Rate limited: route throttle (10/min per IP) plus a per-ACCOUNT lock — 5
     * wrong codes lock verification for 15 minutes (survives a resend).
     */
    public function verify(Request $request, LogisticsProvider $logisticsProvider)
    {
        if ($logisticsProvider->status !== LogisticsProviderStatus::Unverified) {
            return $this->respond($request, [
                'redirect' => route('register.logistics.pending'),
            ]);
        }

        // Per-account brute-force lock (not resettable by a resend).
        if ($this->otpIsLocked('logistics', $logisticsProvider->email)) {
            return $this->lockedResponse($request, $logisticsProvider);
        }

        $otp = (string) $request->input('otp', '');

        if (! preg_match('/^\d{6}$/', $otp)) {
            return $this->respond($request, ['message' => $this->otpFormatMessage()], 422);
        }

        if ($logisticsProvider->isOtpExpired()) {
            return $this->respond($request, [
                'expired' => true,
                'message' => $this->otpExpiredMessage(),
            ], 422);
        }

        if (! $logisticsProvider->otpMatches($otp)) {
            $logisticsProvider->increment('otp_attempts');
            $remaining = $this->registerOtpFailure('logistics', $logisticsProvider->email);

            if ($this->otpIsLocked('logistics', $logisticsProvider->email)) {
                return $this->lockedResponse($request, $logisticsProvider);
            }

            return $this->respond($request, [
                'message' => $this->otpInvalidMessage(),
                'attempts_remaining' => $remaining,
            ], 422);
        }

        // Wrap in transaction: set status to pending atomically
        DB::transaction(function () use ($logisticsProvider) {
            $logisticsProvider->markEmailVerified();
        });

        // Only a SUCCESS clears the failure window.
        $this->clearOtpFailures('logistics', $logisticsProvider->email);

        return $this->respond($request, [
            'redirect' => route('register.logistics.pending'),
            'message' => 'Email verified! Your registration is now waiting for admin approval.',
        ]);
    }

    /**
     * POST /register/logistics/verify-otp/{logistics_provider}/resend
     * Generates a fresh 6-digit code and emails it.
     *
     * Per-ACCOUNT limit: 3 resends per 10 minutes. A resend does NOT reset the
     * failure counter and never clears the lock.
     */
    public function resend(Request $request, LogisticsProvider $logisticsProvider)
    {
        if ($logisticsProvider->status !== LogisticsProviderStatus::Unverified) {
            return $this->respond($request, ['message' => 'This registration is already verified.'], 422);
        }

        if ($this->otpResendIsLimited('logistics', $logisticsProvider->email)) {
            return $this->respond($request, [
                'message' => 'You have requested too many codes. Please wait before requesting another one.',
                'retry_after' => $this->otpResendSecondsRemaining('logistics', $logisticsProvider->email),
            ], 429);
        }

        // Prepare the replacement code but only commit it after delivery, so a
        // delivery failure leaves the code the user already has valid.
        $otp = $logisticsProvider->generateOtp();

        try {
            $logisticsProvider->notify(new LogisticsRegistrationOtp($otp));
        } catch (\Throwable $e) {
            Log::error('Failed to resend logistics OTP email', [
                'logistics_provider_id' => $logisticsProvider->id,
                'error' => $e->getMessage(),
            ]);

            return $this->respond($request, [
                'message' => 'We could not send a new code right now. Please try again shortly.',
            ], 503);
        }

        $logisticsProvider->commitOtp($otp);
        $this->registerOtpResend('logistics', $logisticsProvider->email);

        return $this->respond($request, ['message' => 'A new code has been sent to your email.']);
    }

    /**
     * "Too many wrong codes" answer. JSON requests get HTTP 429; everyone else
     * gets a normal validation-style error back on the form.
     */
    private function lockedResponse(Request $request, LogisticsProvider $logisticsProvider)
    {
        return $this->respond($request, [
            'locked' => true,
            'retry_after' => $this->otpLockSecondsRemaining('logistics', $logisticsProvider->email),
            'message' => $this->otpLockedMessage('logistics', $logisticsProvider->email),
        ], 429);
    }

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
