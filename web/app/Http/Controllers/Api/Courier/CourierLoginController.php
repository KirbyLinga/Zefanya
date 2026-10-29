<?php

namespace App\Http\Controllers\Api\Courier;

use App\Enums\LogisticsProviderStatus;
use App\Http\Controllers\Controller;
use App\Models\Logistics\LogisticsProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class CourierLoginController extends Controller
{
    /**
     * POST /api/login/courier
     * POST /api/v1/courier/login
     */
    public function store(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $courier = LogisticsProvider::where('email', strtolower(trim($credentials['email'])))->first();

        if (! $courier || ! Hash::check($credentials['password'], $courier->password)) {
            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        if ($gate = $this->gateResponse($courier)) {
            return $gate;
        }

        return response()->json([
            'ok' => true,
            'token' => $courier->createToken('zefanya-mobile-courier')->plainTextToken,
        ] + $this->courierPayload($courier));
    }

    /**
     * GET /api/me/courier
     * GET /api/v1/courier/me
     */
    public function show(Request $request): JsonResponse
    {
        $courier = $request->user();

        abort_unless(
            $courier instanceof LogisticsProvider,
            403,
            'This token belongs to a different kind of account.',
        );

        if ($gate = $this->gateResponse($courier)) {
            return $gate;
        }

        return response()->json(['ok' => true] + $this->courierPayload($courier));
    }

    /**
     * POST /api/logout/courier
     * POST /api/v1/courier/logout
     */
    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user && $user->currentAccessToken()) {
            $user->currentAccessToken()->delete();
        }

        return response()->json([
            'ok' => true,
            'message' => 'Signed out.',
        ]);
    }

    /**
     * The gate this courier account is behind.
     */
    public function gateResponse(LogisticsProvider $courier): ?JsonResponse
    {
        if ($courier->isRejected()) {
            return response()->json([
                'ok' => false,
                'code' => 'ACCOUNT_REJECTED',
                'registration_status' => 'rejected',
                'message' => $courier->rejection_reason ?: 'Your rider application was not approved. Please contact support.',
            ], 403);
        }

        if ($courier->isSuspended()) {
            return response()->json([
                'ok' => false,
                'code' => 'ACCOUNT_SUSPENDED',
                'registration_status' => 'suspended',
                'message' => 'Your rider account has been temporarily suspended. Please contact dispatch.',
            ], 403);
        }

        if (! $courier->isPastEmailVerification()) {
            return response()->json([
                'ok' => false,
                'code' => 'PENDING_VERIFICATION',
                'registration_status' => 'pending_verification',
                'verification_id' => (string) $courier->id,
                'message' => 'Confirm your email address to continue.',
            ], 403);
        }

        if (! $courier->isApproved()) {
            return response()->json([
                'ok' => false,
                'code' => 'PENDING_APPROVAL',
                'registration_status' => 'pending_approval',
                'message' => 'Your rider application is waiting for administrator approval.',
            ], 403);
        }

        return null;
    }

    /**
     * Courier payload in the shape expected by AuthResult.
     */
    public function courierPayload(LogisticsProvider $courier): array
    {
        return [
            'role' => 'courier',
            'name' => $courier->fullName(),
            'email' => $courier->email,
            'registration_status' => $courier->status instanceof LogisticsProviderStatus ? $courier->status->value : (string) $courier->status,
            'vehicle_type' => $courier->vehicle_type,
            'plate_number' => $courier->plate_number,
            'license_number' => $courier->license_number,
            'is_online' => (bool) $courier->is_online,
            'hub' => $courier->hub,
        ];
    }
}
