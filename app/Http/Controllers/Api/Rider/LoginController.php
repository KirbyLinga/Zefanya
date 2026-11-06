<?php

namespace App\Http\Controllers\Api\Rider;

use App\Http\Controllers\Api\Traits\ValidatesRoleAbilities;
use App\Http\Controllers\Controller;
use App\Models\Rider\Rider;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/** Rider API login.
 *
 * Validates credentials, verifies the account is approved/active, email
 * verified, and operational (assigned to an approved, active Station Hub and
 * linked to a Courier), then issues a Sanctum bearer token with the "rider"
 * ability. Pending, rejected, suspended, deactivated and unverified accounts
 * receive a clear JSON error and NO session is created.
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
 *       "role": "rider"
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

        $role = 'rider';

        // Check status: approved or active + verified + operational.
        if (! $this->accountIsLoginable($account)) {
            return $this->accountNotActiveResponse($account);
        }

        $token = $account->createToken('api-token', ['rider'], now()->addMinutes(config('sanctum.expiration', 60)));

        return response()->json([
            'success' => true,
            'message' => 'Login successful.',
            'data' => [
                'token' => (string) $token->plainTextToken,
                'token_type' => 'Bearer',
                'expires_in' => $token->accessToken->expires_at ? $token->accessToken->expires_at->getTimestamp() - now()->getTimestamp() : null,
                'expires_at' => $token->accessToken->expires_at ? $token->accessToken->expires_at->toIso8601String() : null,
                'role' => $role,
            ],
            'errors' => null,
        ], 200);
    }

    /** Resolve the rider account. */
    private function resolveAccount(string $email, string $password): object
    {
        $account = Rider::where('email', $email)->first();

        if ($account instanceof Rider && $account->password) {
            $this->validatePassword($account, $password);

            return $account;
        }

        throw new AuthenticationException;
    }

    private function validatePassword(object $account, string $password): void
    {
        if (! Hash::check($password, $account->password)) {
            throw new AuthenticationException;
        }
    }

    /** Address cases where the account exists but is not loginable. */
    private function accountNotActiveResponse(object $account): JsonResponse
    {
        $message = match (true) {
            $account->isEmailVerified() === false => 'Please verify your email before logging in. Check your inbox for the code.',
            $account->status === 'pending' => 'Your registration is still awaiting administrator approval.',
            $account->status === 'rejected' => 'Your registration was not approved.',
            $account->status === 'suspended' => 'Your account has been suspended. Please contact your logistics company.',
            $account->status === 'deactivated' => 'Your account has been deactivated.',
            default => 'Your account is not active yet.',
        };

        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => null,
            'errors' => ['email' => [$message]],
        ], 403);
    }
}
