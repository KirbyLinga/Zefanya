<?php

namespace App\Http\Controllers\Api\Rider;

use App\Http\Controllers\Controller;
use App\Http\Middleware\Traits\ApiResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Rider API logout.
 *
 * Revokes the CURRENT bearer token (the one attached to the request) and
 * invalidates the session. The sanctum guard's `currentAccessToken()` is
 * revoked via `revoke()` on the token model, so subsequent requests with the
 * same token fail with an "invalid token" error. No session cookie is set:
 * this is a stateless Bearer-token flow.
 *
 * Response (204 No Content):
 *   (empty body)
 */
class LogoutController extends Controller
{
    use ApiResponses;

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
                'data' => null,
                'errors' => ['token' => ['The provided token is invalid, expired, or belongs to a different role.']],
            ], 401);
        }

        // Revoke the current token. Sanctum's `revoke()` deletes the row from
        // the `personal_access_tokens` table (or sets a revoked flag per the
        // installed version), so the same token can no longer authenticate.
        if ($request->user() instanceof \Laravel\Sanctum\Contracts\HasApiTokens) {
            $token = $request->user()->currentAccessToken();

            if ($token) {
                $token->revoke();
            }
        }

        // End the session guard for this role.
        Auth::guard('rider')->logout();

        return response()->json([
            'success' => true,
            'message' => 'Logged out.',
            'data' => null,
            'errors' => null,
        ], 204);
    }
}
