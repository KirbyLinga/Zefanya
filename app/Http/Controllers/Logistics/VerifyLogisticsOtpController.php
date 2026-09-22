<?php

namespace App\Http\Controllers\Logistics;

use App\Enums\LogisticsProviderStatus;
use App\Http\Controllers\Controller;
use App\Models\Logistics\LogisticsProvider;
use App\Notifications\Logistics\LogisticsRegistrationOtp;
use Illuminate\Http\Request;

class VerifyLogisticsOtpController extends Controller
{
    public function show(LogisticsProvider $logisticsProvider)
    {
        if ($logisticsProvider->status !== LogisticsProviderStatus::PendingVerification) {
            return redirect()->route('register.logistics.pending');
        }

        return view('Logistics.register-logistics-verify-otp', ['logisticsProvider' => $logisticsProvider]);
    }

    public function verify(Request $request, LogisticsProvider $logisticsProvider)
    {
        if ($logisticsProvider->status !== LogisticsProviderStatus::PendingVerification) {
            return $this->respond($request, [
                'redirect' => route('register.logistics.pending'),
            ]);
        }

        $otp = (string) $request->input('otp', '');

        if (! preg_match('/^\d{6}$/', $otp)) {
            return $this->respond($request, ['message' => 'Enter all 6 digits.'], 422);
        }

        if ($logisticsProvider->isOtpExpired()) {
            return $this->respond($request, [
                'message' => 'This code has expired. Tap "Resend code" for a new one.',
            ], 422);
        }

        if (! $logisticsProvider->otpMatches($otp)) {
            return $this->respond($request, [
                'message' => 'That code is incorrect. Please try again.',
            ], 422);
        }

        $logisticsProvider->markEmailVerified();

        return $this->respond($request, [
            'redirect' => route('register.logistics.pending'),
            'message' => 'Email verified! Your registration is now waiting for admin approval.',
        ]);
    }

    public function resend(LogisticsProvider $logisticsProvider)
    {
        if ($logisticsProvider->status !== LogisticsProviderStatus::PendingVerification) {
            return response()->json(['message' => 'This registration is already verified.'], 422);
        }

        $otp = $logisticsProvider->issueOtp();
        $logisticsProvider->notify(new LogisticsRegistrationOtp($otp));

        return response()->json(['message' => 'A new code has been sent to your email.']);
    }

    private function respond(Request $request, array $json, int $status = 200)
    {
        if ($request->wantsJson()) {
            return response()->json($json, $status);
        }

        if (isset($json['redirect'])) {
            return redirect($json['redirect'])->with('success', $json['message'] ?? null);
        }

        return back()->withErrors(['otp' => $json['message']]);
    }
}
