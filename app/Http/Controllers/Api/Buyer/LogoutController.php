<?php

namespace App\Http\Controllers\Api\Buyer;

use App\Http\Controllers\Controller;
use App\Http\Middleware\Traits\ApiResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Buyer API logout.
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

        // End the session guard for this role. This clears the guard's session
        // user so a browser that also holds a session cookie returns to guest
        // next request instead of remaining signed in under a stale guard.
        Auth::guard($this->roleGuard($user))->logout();

        return response()->json([
            'success' => true,
            'message' => 'Logged out.',
            'data' => null,
            'errors' => null,
        ], 204);
    }

    /** Return the session guard that owns this tokenable model. */
    private function roleGuard(\Illuminate\Contracts\Auth\Authenticatable $user): string
    {
        if ($user instanceof \App\Models\Buyer\Buyer) {
            return 'buyer';
        }

        if ($user instanceof \App\Models\Rider) {
            return 'rider';
        }

        throw new \RuntimeException('Unsupported API tokenable model.');
    }
}
