<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Services\ParcelHandoffVerification;
use App\Services\ParcelTrackingVerificationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Logistics camera / manual tracking verification (read-only).
 * Station receipt and sorting confirmations reuse existing POST routes.
 */
class ParcelScanController extends Controller
{
    public function index(): View
    {
        return view('Logistics.Scan.index');
    }

    public function verify(Request $request, ParcelTrackingVerificationService $verification): JsonResponse
    {
        $data = $request->validate([
            'tracking_number' => ['required', 'string', 'max:64'],
            'method' => ['nullable', Rule::in([
                ParcelHandoffVerification::METHOD_CAMERA,
                ParcelHandoffVerification::METHOD_MANUAL,
            ])],
        ]);

        try {
            $result = $verification->verifyForLogistics(
                $request->user('logistics'),
                $data['tracking_number'],
                $data['method'] ?? ParcelHandoffVerification::METHOD_MANUAL,
            );
        } catch (ModelNotFoundException) {
            return response()->json([
                'message' => 'No parcel matches that tracking number.',
            ], 404);
        } catch (AuthorizationException $e) {
            return response()->json([
                'message' => $e->getMessage() ?: 'You are not allowed to access this parcel.',
            ], 403);
        }

        return response()->json([
            'ok' => true,
            ...$result,
        ]);
    }
}
