<?php

namespace App\Http\Controllers;

use App\Models\Buyer;
use App\Notifications\BuyerVerifyOtp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class VerifyBuyerOtpController extends Controller
{
    /**
     * GET /register/buyer/verify-otp/{buyer}
     * Standalone fallback page for deep-links. The modal flow doesn't use this —
     * the OTP modal lives inside the page that already has the registration modal.
     */
    public function show(Buyer $buyer)
    {
        return view('Buyer.verify-otp', ['buyer' => $buyer]);
    }

    /**
     * POST /register/buyer/verify-otp/{buyer}
     * Body: { otp: '123456' }
     * Returns JSON the modal's JS reads directly (no full page reload).
     */
    public function verify(Request $request, Buyer $buyer): JsonResponse
    {
        $data = $request->validate([
            'otp' => ['required', 'string', 'regex:/^\d{6}$/'],
        ]);

        if ($buyer->status === 'pending_approval' || $buyer->status === 'approved') {
            return response()->json([
                'ok' => true,
                'already_verified' => true,
                'redirect' => route('register.buyer.pending'),
            ]);
        }

        $incoming = hash('sha256', $data['otp']);

        if ($buyer->email_verification_code === null
            || ! hash_equals($buyer->email_verification_code, $incoming)) {
            return response()->json([
                'ok' => false,
                'message' => 'That code doesn\'t match. Please try again.',
            ], 422);
        }

        if ($buyer->isVerificationLinkExpired()) {
            return response()->json([
                'ok' => false,
                'expired' => true,
                'message' => 'This code has expired. Please request a new one.',
            ], 422);
        }

        $buyer->markEmailVerified();

        return response()->json([
            'ok' => true,
            'redirect' => route('register.buyer.pending'),
        ]);
    }

    /**
     * POST /register/buyer/verify-otp/{buyer}/resend
     * Generates a fresh 6-digit code, hashes it, and emails it.
     * Throttled at the route level.
     */
    public function resend(Buyer $buyer): JsonResponse
    {
        if ($buyer->status === 'pending_approval' || $buyer->status === 'approved') {
            return response()->json([
                'ok' => false,
                'message' => 'This account is already verified.',
            ], 422);
        }

        $code = (string) random_int(100000, 999999);

        $buyer->forceFill([
            'email_verification_code' => hash('sha256', $code),
            'email_verification_expires_at' => now()->addMinutes(10),
        ])->save();

        $buyer->notify(new BuyerVerifyOtp($code));

        Log::warning('Buyer OTP verification code resent', [
            'buyer_id' => $buyer->id,
            'email' => $buyer->email,
        ]);

        return response()->json([
            'ok' => true,
            'message' => 'A new code has been sent.',
        ]);
    }
}
