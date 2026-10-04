<?php

namespace App\Http\Controllers\Api\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Buyer\Buyer;
use App\Notifications\Buyer\BuyerVerifyOtp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class VerifyBuyerOtpController extends Controller
{
    /**
     * POST /api/register/buyer/verify-otp/{buyer}
     * Body: { otp: "123456" }
     *
     * The same three-way answer the web modal gets (already verified / wrong or
     * expired code / accepted), with two differences that are about this client
     * rather than about the rule:
     *
     *  - it says which *gate* the account is now at
     *    (`registration_status: pending_approval`) instead of pointing at a
     *    redirect URL, because a phone has no page to be redirected to; and
     *  - the wrong-code and expired cases are both `422` with a `message` and no
     *    `errors` bag, which is what the app's inline error line renders. A field
     *    bag would claim the server said *which* field was wrong when all it said
     *    was no.
     *
     * The `{buyer}` id is not a secret and is not treated as one: it addresses a
     * row whose only usable secret is the 6-digit code, which is stored as a
     * sha256 hash, expires in 10 minutes, and is rate-limited by the route.
     */
    public function verify(Request $request, Buyer $buyer): JsonResponse
    {
        $data = $request->validate([
            'otp' => ['required', 'string', 'regex:/^\d{6}$/'],
        ]);

        // Already past the gate: answering "yes" rather than refusing means a
        // double-tap, or a resend that arrived after the confirmation, cannot
        // look like a failure to the person who just succeeded.
        if ($buyer->isPastEmailVerification()) {
            return response()->json([
                'ok' => true,
                'already_verified' => true,
                'registration_status' => $buyer->status,
                'message' => 'Your email is already confirmed.',
            ]);
        }

        if (! $buyer->verifyEmailCode($data['otp'])) {
            return response()->json([
                'ok' => false,
                'message' => 'That code doesn\'t match. Please try again.',
            ], 422);
        }

        // Checked after the code, not before: "this code has expired" tells
        // someone a real code was once valid, which is information only worth
        // giving to whoever holds that code.
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
            'registration_status' => $buyer->status,
            'message' => 'Email confirmed. Your application is now with our team.',
        ]);
    }

    /**
     * POST /api/register/buyer/verify-otp/{buyer}/resend
     *
     * Issues a fresh code and emails it. Returning no code and no status is
     * deliberate: the app's screen shows the server's own sentence ("a new code
     * has been sent") and nothing else changes about the account.
     */
    public function resend(Buyer $buyer): JsonResponse
    {
        if ($buyer->isPastEmailVerification()) {
            return response()->json([
                'ok' => false,
                'message' => 'This account is already verified.',
            ], 422);
        }

        $buyer->notify(new BuyerVerifyOtp($buyer->issueEmailVerificationCode()));

        Log::warning('Buyer OTP verification code resent via API', [
            'buyer_id' => $buyer->id,
            'email' => $buyer->email,
        ]);

        return response()->json([
            'ok' => true,
            'message' => 'A new code has been sent.',
        ]);
    }
}
