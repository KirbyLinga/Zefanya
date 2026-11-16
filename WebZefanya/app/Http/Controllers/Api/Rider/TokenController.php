<?php

namespace App\Http\Controllers\Api\Rider;

use App\Http\Controllers\Controller;
use App\Http\Middleware\Traits\ApiResponses;
use App\Models\Rider\Rider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/rider/token/refresh — trade a still-valid rider token for a new one.
 *
 * Keeps a rider signed in through a shift the way the web session's sliding
 * lifetime does, without lengthening any single token: the new token gets the
 * same `rider` ability and the same `sanctum.expiration` lifetime as login,
 * and the presented token is deleted in the same transaction. An expired,
 * revoked or wrong-role token never reaches here (api.role answers 401/403),
 * and the account gates run on every refresh exactly as on any other request.
 */
class TokenController extends Controller
{
    use ApiResponses;

    public function refresh(Request $request): JsonResponse
    {
        /** @var Rider $rider */
        $rider = $request->user();
        $current = $rider->currentAccessToken();

        $token = DB::transaction(function () use ($rider, $current) {
            $current->delete();

            return $rider->createToken('api-token', ['rider'], now()->addMinutes(config('sanctum.expiration', 60)));
        });

        $expiresAt = $token->accessToken->expires_at;

        return $this->success('Token refreshed.', [
            'token' => (string) $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_in' => $expiresAt ? $expiresAt->getTimestamp() - now()->getTimestamp() : null,
            'expires_at' => $expiresAt?->toIso8601String(),
            'role' => 'rider',
        ]);
    }
}
