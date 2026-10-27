<?php

namespace App\Http\Controllers\Api\Buyer;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Traits\ValidatesRoleAbilities;
use App\Models\Buyer\Buyer;
use App\Models\Rider;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Buyer API login.
 *
 * Validates credentials, verifies the account is approved, email-verified,
 * and active, then issues a Sanctum bearer token with the "buyer" ability.
 * Pending, rejected, suspended and unverified accounts receive a clear JSON
 * error and NO session is created.
 *
 * Response (200):
 *   {
 *     "success": true,
 *     "message": "Login successful.",
 *     "data": {
 *       "token": "sanctum-token-string",
 *       "token_type": "Bearer",
 *       "expires_in": 3600,
 *       "expires_at": "2026-10-28T12:34:56Z",
 *       "role": "buyer"
 *     },
 *     "errors": null
 *   }
 */
class LoginController extends Controller
{
    use ValidatesRoleAbilities;

    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $email = strtolower($request->input('email'));
        $password = $request->input('password');

        $account = $this->resolveAccount($email, $password);

        $role = 'buyer';

        if (! $this->accountIsLoginable($account)) {
            return $this->accountNotActiveResponse($account, $role);
        }

        $guard = 'buyer';

        if (! Auth::guard($guard)->attempt(['email' => $email, 'password' => $password], $request->boolean('remember'))) {
            throw new AuthenticationException();
        }

        $request->session()->regenerate();

        $token = $account->createToken('api-token', ['buyer'], now()->addMinutes(config('sanctum.expiration', 60)));

        return response()->json([
            'success' => true,
            'message' => 'Login successful.',
            'data' => [
                'token' => (string) $token->plainTextToken,
                'token_type' => 'Bearer',
                'expires_in' => $token->accessToken->expires_at ? $token->accessToken->expires_at->getTimestamp() - time() : null,
                'expires_at' => $token->accessToken->expires_at ? $token->accessToken->expires_at->toIso8601String() : null,
                'role' => $role,
            ],
            'errors' => null,
        ], 200);
    }

    /** Resolve the account and return the correct guard name. */
    private function resolveAccount(string $email, string $password): object
    {
        $account = Buyer::where('email', $email)->first();

        if ($account instanceof Buyer && $account->password) {
            $this->validatePassword($account, $password);
            return $account;
        }

        $account = Rider::where('email', $email)->first();

        if ($account instanceof Rider && $account->password) {
            $this->validatePassword($account, $password);
            return $account;
        }

        throw new AuthenticationException();
    }

    /** Confirm the password matches so we don't leak which field was wrong. */
    private function validatePassword(object $account, string $password): void
    {
        if (! Hash::check($password, $account->password)) {
            throw new AuthenticationException();
        }
    }

    /** Address cases where the account exists but is not loginable. */
    private function accountNotActiveResponse(object $account, string $role): JsonResponse
    {
        $message = match (true) {
            $account->isEmailVerified() === false => 'Please verify your email before logging in. Check your inbox for the code.',
            $this->isPending($account) => 'Your registration is still awaiting administrator approval.',
            $this->isRejected($account) => 'Your registration was not approved.',
            $this->isSuspended($account) => 'Your account has been suspended. Please contact support.',
            default => 'Your account is not active yet.',
        };

        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => null,
            'errors' => ['email' => [$message]],
        ], 403);
    }

    private function isPending(object $account): bool
    {
        return $account->status === 'pending';
    }

    private function isRejected(object $account): bool
    {
        return $account->status === 'rejected';
    }

    private function isSuspended(object $account): bool
    {
        return $account->status === 'suspended';
    }
}
