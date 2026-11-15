<?php

namespace App\Http\Middleware;

use App\Models\Logistics\CourierAccount;
use App\Services\Logistics\CourierAccountService;
use App\Support\CourierAccess;
use Closure;
use Illuminate\Contracts\Auth\Factory as Auth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for /api/courier/*. Separate from ApiRole on purpose: Buyer and Rider
 * authorization (email verification, wind-down) is not touched.
 *
 *   api.courier:guest  → feature gate only (activate, login)
 *   api.courier        → feature gate + the full per-request check
 *
 * When the feature is off or not installed every Courier route answers 404.
 * A protected request needs, all at once: a real bearer personal access token
 * of a CourierAccount, abilities exactly ["courier"], not expired, created in
 * the current enabled period, still the account's current token, an active
 * account, an active Courier record and an approved, non-archived company.
 */
class ApiCourier
{
    public function __construct(
        private readonly Auth $auth,
        private readonly CourierAccountService $accounts,
    ) {}

    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        if (! CourierAccess::enabled()) {
            return $this->json(404, 'Not found.', 'route');
        }
        if ($mode === 'guest') {
            return $next($request);
        }

        $user = $this->auth->guard('sanctum')->user();
        $token = $user instanceof CourierAccount ? $user->currentAccessToken() : null;
        $since = CourierAccess::enabledSince();

        if (! $token instanceof PersonalAccessToken
            || $token->tokenable_type !== $user->getMorphClass()
            || array_values((array) $token->abilities) !== [CourierAccount::ABILITY]
            || ($token->expires_at !== null && $token->expires_at->isPast())
            || $token->created_at === null || $since === null || $token->created_at->lt($since)
            || (int) $token->id !== (int) $user->current_token_id) {
            return $this->json(401, 'Please sign in again.', 'token');
        }

        if (! $this->accounts->eligible($user)) {
            return $this->json(403, 'Your Courier access is not available right now. Contact your logistics company.', 'account');
        }

        $request->setUserResolver(fn () => $user);

        return $next($request);
    }

    private function json(int $status, string $message, string $field): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => null,
            'errors' => [$field => [$message]],
        ], $status);
    }
}
