<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\Logistics\LogisticsProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * POST /api/login/courier
 *
 * The courier's door into the same two-gate logic buyers have: email OTP first,
 * then admin approval. One credential check, one token on success.
 *
 * ## Why this is a separate controller from LoginController
 *
 * One guard is one provider. `logistics_providers` is a different table with a
 * different Eloquent model — a `LogisticsProvider`, not a `Buyer` — and Sanctum's
 * `auth:sanctum` resolves a token back to whichever model issued it. A shared
 * endpoint that accepted a `role` field would have to query *both* tables before
 * knowing which credentials to check, and reject one by knowing neither matched —
 * a subtle information-leak and a harder test surface. Two routes, two guards,
 * two providers: the difference is in the data, not in the logic.
 *
 * ## The three answers
 *
 * Same vocabulary as `LoginController`, same gate order:
 *
 *  - `200` with a token: approved and verified.
 *  - `403` with `registration_status`: behind a gate the app routes to.
 *  - `422` on the email field: credentials did not match (same
 *    one-sentence rule — distinguishing "no account" from "wrong password"
 *    leaks which addresses are registered).
 */
class CourierLoginController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $provider = LogisticsProvider::where('email', $credentials['email'])->first();

        if (! $provider || ! Hash::check($credentials['password'], $provider->password)) {
            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        if ($gate = $this->gateResponse($provider)) {
            return $gate;
        }

        return response()->json([
            'ok' => true,
            'token' => $provider->createToken('zefanya-mobile-courier')->plainTextToken,
        ] + $this->courierPayload($provider));
    }

    /**
     * GET /api/me/courier
     *
     * What the app asks on a cold start (courier session) to validate the token
     * and re-check the account's gate status.
     */
    public function show(Request $request): JsonResponse
    {
        $provider = $request->user();

        abort_unless(
            $provider instanceof LogisticsProvider,
            403,
            'This token belongs to a different kind of account.',
        );

        if ($gate = $this->gateResponse($provider)) {
            return $gate;
        }

        return response()->json(['ok' => true] + $this->courierPayload($provider));
    }

    /**
     * POST /api/logout/courier
     */
    public function destroy(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'ok' => true,
            'message' => 'Signed out.',
        ]);
    }

    private function gateResponse(LogisticsProvider $provider): ?JsonResponse
    {
        if ($provider->status->value === 'rejected') {
            return response()->json([
                'ok' => false,
                'code' => 'ACCOUNT_REJECTED',
                'registration_status' => 'rejected',
                'message' => 'Your registration was not approved. Please contact support.',
            ], 403);
        }

        if (! $provider->isPastEmailVerification()) {
            return response()->json([
                'ok' => false,
                'code' => 'PENDING_VERIFICATION',
                'registration_status' => 'pending_verification',
                'verification_id' => (string) $provider->id,
                'message' => 'Confirm your email address to continue.',
            ], 403);
        }

        if (! $provider->isApproved()) {
            return response()->json([
                'ok' => false,
                'code' => 'PENDING_APPROVAL',
                'registration_status' => 'pending_approval',
                'message' => 'Your account is waiting for approval.',
            ], 403);
        }

        return null;
    }

    private function courierPayload(LogisticsProvider $provider): array
    {
        return [
            'role' => 'courier',
            'name' => $provider->fullName(),
            'email' => $provider->email,
            'registration_status' => $provider->status->value,
        ];
    }
}
