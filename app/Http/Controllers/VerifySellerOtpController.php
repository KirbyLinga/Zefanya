<?php

namespace App\Http\Controllers;

use App\Models\Seller;
use App\Notifications\SellerRegistrationOtp;
use Illuminate\Http\Request;

class VerifySellerOtpController extends Controller
{
    public function show(Seller $seller)
    {
        if ($seller->status !== 'pending_verification') {
            return redirect()->route('register.seller.pending');
        }

        return view('Seller.register-seller-verify-otp', ['seller' => $seller]);
    }

    public function verify(Request $request, Seller $seller)
    {
        if ($seller->status !== 'pending_verification') {
            return $this->respond($request, [
                'redirect' => route('register.seller.pending'),
            ]);
        }

        $otp = (string) $request->input('otp', '');

        if (! preg_match('/^\d{6}$/', $otp)) {
            return $this->respond($request, ['message' => 'Enter all 6 digits.'], 422);
        }

        if ($seller->isOtpExpired()) {
            return $this->respond($request, [
                'message' => 'This code has expired. Tap "Resend code" for a new one.',
            ], 422);
        }

        if (! $seller->otpMatches($otp)) {
            return $this->respond($request, [
                'message' => 'That code is incorrect. Please try again.',
            ], 422);
        }

        $seller->markEmailVerified();

        return $this->respond($request, [
            'redirect' => route('register.seller.pending'),
            'message' => 'Email verified! Your registration is now waiting for admin approval.',
        ]);
    }

    public function resend(Seller $seller)
    {
        if ($seller->status !== 'pending_verification') {
            return response()->json(['message' => 'This registration is already verified.'], 422);
        }

        $otp = $seller->issueOtp();
        $seller->notify(new SellerRegistrationOtp($otp));

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