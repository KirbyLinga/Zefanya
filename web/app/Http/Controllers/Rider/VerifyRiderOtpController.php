<?php

namespace App\Http\Controllers\Rider;

use App\Http\Controllers\Concerns\VerifiesEmailOtp;
use App\Http\Controllers\Controller;
use App\Models\RiderApplication;
use App\Notifications\Rider\RiderRegistrationOtp;
use App\Support\EmailMask;
use App\Support\SignedOtpLink;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VerifyRiderOtpController extends Controller
{
    use VerifiesEmailOtp;

    public function show(RiderApplication $riderApplication)
    {
        if ($riderApplication->status !== 'unverified') {
            return redirect()->route('register.rider.pending');
        }

        return view('Rider.verify-otp', [
            'emailMasked' => EmailMask::mask($riderApplication->email),
            'verifyUrl' => SignedOtpLink::verifyUrl('rider', $riderApplication),
            'resendUrl' => SignedOtpLink::resendUrl('rider', $riderApplication),
        ]);
    }

    public function verify(Request $request, RiderApplication $riderApplication)
    {
        return DB::transaction(function () use ($request, $riderApplication) {
            $application = RiderApplication::whereKey($riderApplication->id)->lockForUpdate()->firstOrFail();
            if ($application->status !== 'unverified') {
                return $this->respond($request, ['redirect' => route('register.rider.pending')]);
            }
            if ($this->otpIsLocked('rider', $application->email)) {
                return $this->respond($request, ['message' => $this->otpLockedMessage('rider', $application->email), 'locked' => true], 429);
            }
            $submitted = $request->input('otp', '');
            $otp = is_string($submitted) || is_int($submitted) ? (string) $submitted : '';
            if (! preg_match('/^\d{6}$/', $otp)) {
                return $this->respond($request, ['message' => 'Enter all 6 digits.'], 422);
            }
            if (! $application->email_verification_expires_at || $application->isOtpExpired()) {
                return $this->respond($request, ['message' => 'This code has expired. Tap "Resend code" for a new one.'], 422);
            }
            if (! $application->otpMatches($otp)) {
                $application->increment('otp_attempts');
                $remaining = $this->registerOtpFailure('rider', $application->email);

                return $this->respond($request, [
                    'message' => $this->otpIsLocked('rider', $application->email) ? $this->otpLockedMessage('rider', $application->email) : 'That code is incorrect. Please try again.',
                    'attempts_remaining' => $remaining,
                ], $this->otpIsLocked('rider', $application->email) ? 429 : 422);
            }
            $application->markEmailVerified();
            $this->clearOtpFailures('rider', $application->email);

            return $this->respond($request, [
                'redirect' => route('register.rider.pending'),
                'message' => 'Email verified! Your application is now pending Logistics approval.',
            ]);
        });
    }

    public function resend(Request $request, RiderApplication $riderApplication)
    {
        return DB::transaction(function () use ($request, $riderApplication) {
            $application = RiderApplication::whereKey($riderApplication->id)->lockForUpdate()->firstOrFail();
            if ($application->status !== 'unverified') {
                return $this->respond($request, ['message' => 'This registration is already verified.'], 422);
            }
            if ($this->otpResendIsLimited('rider', $application->email)) {
                return $this->respond($request, ['message' => 'You have requested too many codes. Please wait before requesting another one.', 'retry_after' => $this->otpResendSecondsRemaining('rider', $application->email)], 429);
            }
            $this->registerOtpResend('rider', $application->email);
            $otp = $application->generateOtp();
            try {
                $application->notify(new RiderRegistrationOtp($otp));
            } catch (\Throwable) {
                return $this->respond($request, ['message' => 'We could not send a new code right now. Please try again shortly.'], 503);
            }
            $application->commitOtp($otp);

            return $this->respond($request, ['message' => 'A new code has been sent to your email.']);
        });
    }

    private function respond(Request $request, array $body, int $status = 200)
    {
        if ($request->expectsJson()) {
            return response()->json($body, $status);
        }
        if ($status >= 400) {
            return back()->withErrors(['otp' => $body['message']]);
        }

        return isset($body['redirect']) ? redirect($body['redirect'])->with('success', $body['message'] ?? null) : back()->with('success', $body['message']);
    }
}
