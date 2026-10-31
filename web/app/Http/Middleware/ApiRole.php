<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Api\Traits\ValidatesRoleAbilities;
use App\Models\Buyer\Buyer;
use App\Models\Rider\Rider;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Factory as Auth;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guard for first-party API bearer tokens on /api/buyer/* and /api/rider/*.
 * Missing, invalid, or expired tokens -> 401 JSON (via AuthenticationException).
 * A valid token without the required role ability -> 403 JSON.
 */
class ApiRole
{
    use ValidatesRoleAbilities;

    /** @var array<int, string> */
    private const ALLOWED_ROLES = ['buyer', 'rider'];

    public function __construct(private readonly Auth $auth) {}

    public function handle(Request $request, Closure $next, string $role): Response
    {
        $role = strtolower($role);

        if (! in_array($role, self::ALLOWED_ROLES, true)) {
            throw new \InvalidArgumentException(
                "Unsupported API role [{$role}]. Expected one of: ".implode(', ', self::ALLOWED_ROLES)
            );
        }

        $user = $this->auth->guard('sanctum')->user();

        if (! $user || ! method_exists($user, 'currentAccessToken')) {
            throw new AuthenticationException('Unauthenticated.', ['sanctum']);
        }

        $token = $user->currentAccessToken();

        // Reject session-based (transient) auth: only real bearer tokens are allowed.
        if (! $token instanceof PersonalAccessToken) {
            throw new AuthenticationException('Unauthenticated.', ['sanctum']);
        }

        if ($token->expires_at && $token->expires_at->isPast()) {
            throw new AuthenticationException('Unauthenticated. The token has expired.', ['sanctum']);
        }

        $correctModel = $role === 'buyer' ? $user instanceof Buyer : $user instanceof Rider;

        if (! $correctModel || ! $user->tokenCan($role)) {
            return response()->json([
                'success' => false,
                'message' => 'This token is not allowed to access this endpoint.',
                'data' => null,
                'errors' => ['token' => ["The token must belong to a [{$role}] account and include the [{$role}] ability."]],
            ], 403);
        }

        if (! $this->accountIsLoginable($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Your account is not eligible for API access.',
                'data' => null,
                'errors' => ['account' => ['An approved or active, verified and eligible account is required.']],
            ], 403);
        }

        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
