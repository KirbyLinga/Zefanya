<?php

namespace App\Http\Controllers\Api\Rider;

use App\Http\Controllers\Controller;
use App\Http\Middleware\Traits\ApiResponses;
use App\Models\Rider\Rider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Rider API profile.
 *
 * Returns the authenticated rider's non-sensitive profile fields.
 * Requires a valid bearer token with the "rider" ability (see api.role:rider).
 *
 * Response (200):
 *   {
 *     "success": true,
 *     "message": "Profile retrieved.",
 *     "data": {
 *       "id": 123,
 *       "first_name": "Rafael",
 *       "last_name": "Calderon",
 *       "email": "rafael.calderon@example.com",
 *       "email_verified_at": "2026-10-20T10:00:00Z",
 *       "status": "active",
 *       "station_hub_id": 45,
 *       "license_no": "LT-123456",
 *       "vehicle_type": "Motorcycle"
 *     },
 *     "errors": null
 *   }
 */
class ProfileController extends Controller
{
    use ApiResponses;

    public function index(Request $request): JsonResponse
    {
        $rider = $request->user();

        if (! $rider instanceof Rider) {
            throw new \Illuminate\Auth\AuthenticationException('Unauthenticated.');
        }

        $data = [
            'id' => $rider->id,
            'first_name' => $rider->first_name,
            'last_name' => $rider->last_name,
            'email' => $rider->email,
            'email_verified_at' => $rider->email_verified_at ? $rider->email_verified_at->toIso8601String() : null,
            'status' => $rider->status,
            'station_hub_id' => $rider->station_hub_id,
            'license_no' => $rider->license_no,
            'vehicle_type' => $rider->vehicle_type,
            'plate_number' => $rider->plate_number,
            'contact_no' => $rider->contact_no,
        ];

        return $this->success('Profile retrieved.', $data);
    }
}
