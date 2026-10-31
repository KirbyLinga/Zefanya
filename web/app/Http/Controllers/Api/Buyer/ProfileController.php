<?php

namespace App\Http\Controllers\Api\Buyer;

use App\Http\Controllers\Controller;
use App\Http\Middleware\Traits\ApiResponses;
use App\Models\Buyer\Buyer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Buyer API profile.
 *
 * Returns the authenticated buyer's non-sensitive profile fields.
 * Requires a valid bearer token with the "buyer" ability (see api.role:buyer).
 *
 * Response (200):
 *   {
 *     "success": true,
 *     "message": "Profile retrieved.",
 *     "data": {
 *       "id": 123,
 *       "first_name": "Mary",
 *       "middle_initial": "C",
 *       "last_name": "Jane",
 *       "email": "mary.jane@example.com",
 *       "email_verified_at": "2026-10-20T10:00:00Z",
 *       "status": "approved"
 *     },
 *     "errors": null
 *   }
 */
class ProfileController extends Controller
{
    use ApiResponses;

    public function index(Request $request): JsonResponse
    {
        $buyer = $request->user();

        if (! $buyer instanceof Buyer) {
            throw new \Illuminate\Auth\AuthenticationException('Unauthenticated.');
        }

        $data = [
            'id' => $buyer->id,
            'first_name' => $buyer->first_name,
            'middle_initial' => $buyer->middle_initial,
            'last_name' => $buyer->last_name,
            'email' => $buyer->email,
            'email_verified_at' => $buyer->email_verified_at ? $buyer->email_verified_at->toIso8601String() : null,
            'status' => $buyer->status,
        ];

        return $this->success('Profile retrieved.', $data);
    }
}
