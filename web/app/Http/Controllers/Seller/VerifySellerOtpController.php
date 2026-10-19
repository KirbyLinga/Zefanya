<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Seller\Seller;
use App\Notifications\Seller\SellerRegistrationOtp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class VerifySellerOtpController extends Controller
{
    /**
     * POST /register/seller/verify-otp
     * Body: { otp: '123456' }
     * Finds seller from session, verifies OTP, returns 200 or 422 JSON.
     * No page view — modal-only flow.
     */
    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'otp' => ['required', 'string', 'regex:/^\d{6}$/'],
        ]);

        $sellerId = session('seller_registration_id');
        Log::warning('OTP verify attempt', [
            'seller_registration_id_from_session' => $sellerId,
            'session_keys' => array_keys(session()->all()),
        ]);

        if (! $sellerId) {
            return response()->json([
                'message' => 'Session expired. Please register again.',
            ], 422);
        }

        $seller = Seller::find($sellerId);
        if (! $seller) {
            return response()->json([
                'message' => 'Invalid registration session.',
            ], 422);
        }

        // Only allow OTP verification if seller is in unverified status
        if ($seller->status !== 'unverified') {
            return response()->json([
                'message' => 'Invalid registration session. Your account is '.$seller->status.'.',
            ], 422);
        }

        // Check if OTP has expired before comparing
        if ($seller->isOtpExpired()) {
            return response()->json([
                'message' => 'This code has expired. Please request a new one.',
                'expired' => true,
            ], 422);
        }

        $incoming = hash('sha256', $data['otp']);

        if ($seller->email_verification_code === null
            || ! hash_equals($seller->email_verification_code, $incoming)) {
            return response()->json([
                'message' => 'That code doesn\'t match. Please try again.',
            ], 422);
        }

        $seller->markEmailVerified();
        session()->forget('seller_registration_id');

        return response()->json([
            'message' => 'Email verified successfully.',
        ], 200);
    }

    /**
     * POST /register/seller/verify-otp/resend
     * Finds seller from session, generates fresh OTP, emails it.
     * Throttled at route level.
     */
    public function resend(Request $request): JsonResponse
    {
        $sellerId = session('seller_registration_id');
        if (! $sellerId) {
            return response()->json([
                'message' => 'Session expired. Please register again.',
            ], 422);
        }

        $seller = Seller::find($sellerId);
        if (! $seller) {
            return response()->json([
                'message' => 'Invalid registration session.',
            ], 422);
        }

        // Only allow resend if seller is in unverified status
        if ($seller->status !== 'unverified') {
            return response()->json([
                'message' => 'Invalid registration session. Your account is '.$seller->status.'.',
            ], 422);
        }

        $code = (string) random_int(100000, 999999);

        $seller->forceFill([
            'email_verification_code' => hash('sha256', $code),
            'email_verification_expires_at' => now()->addMinutes(10),
        ])->save();

        $seller->notify(new SellerRegistrationOtp($code));

        Log::warning('Seller OTP verification code resent', [
            'seller_id' => $seller->id,
            'email' => $seller->email,
        ]);

        return response()->json([
            'message' => 'A new code has been sent to your email.',
        ], 200);
    }
}
