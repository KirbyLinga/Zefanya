<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\Buyer\Buyer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    /**
     * POST /api/login/buyer
     *
     * Three answers, and the difference between them is the whole reason the
     * app's `login()` returns a result object instead of a boolean: credentials
     * can be perfectly correct while the account is not usable yet.
     *
     *  - `200` with a token when the account is approved;
     *  - `403` with `registration_status` when a gate is still shut, so the app
     *    routes to the screen that gate belongs to (confirm your email, or wait
     *    for review) instead of telling someone their password was wrong;
     *  - `422` on the email field when the credentials do not match.
     *
     * The token is the only credential this client has: no cookie, no session,
     * no CSRF token — see `routes/api.php`.
     */
    public function store(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $buyer = Buyer::where('email', $credentials['email'])->first();

        // One sentence for "no such account" and "wrong password", and one 422
        // under the email field. Distinguishing them would tell anyone who asks
        // which addresses are registered, and the app's form shows the message
        // where the person's next act is — the email they just typed.
        if (! $buyer || ! Hash::check($credentials['password'], $buyer->password)) {
            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        if ($gate = $this->gateResponse($buyer)) {
            return $gate;
        }

        return response()->json([
            'ok' => true,
            'token' => $buyer->createToken('zefanya-mobile')->plainTextToken,
        ] + $this->buyerPayload($buyer));
    }

    /**
     * GET /api/me
     *
     * What the app asks on a cold start to find out whether its stored token is
     * still good and what the account is allowed to do. It answers with the same
     * gate vocabulary as sign-in on purpose: a token issued to an account that
     * was later rejected, or that never finished verifying, must not read as
     * "signed in" just because the token itself is valid.
     */
    public function show(Request $request): JsonResponse
    {
        $buyer = $request->user();

        // `auth:sanctum` accepts any tokenable; only buyers can hold a token
        // today. This is the guard for the day that stops being true.
        abort_unless(
            $buyer instanceof Buyer,
            403,
            'This token belongs to a different kind of account.',
        );

        if ($gate = $this->gateResponse($buyer)) {
            return $gate;
        }

        return response()->json(['ok' => true] + $this->buyerPayload($buyer));
    }

    /**
     * POST /api/logout
     *
     * Revokes the token that made the request, and only that one: the same
     * person may be signed in on a phone and a tablet, and signing out of one
     * must not sign them out of the other. "A token the server has rejected is
     * worse than no token" — this is how a token stops being accepted.
     */
    public function destroy(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'ok' => true,
            'message' => 'Signed out.',
        ]);
    }

    /**
     * The gate this account is behind, as a `403`, or `null` when it is through
     * both.
     *
     * Shared by sign-in and `/me` so the two can never disagree about which gate
     * an account is at: an app told "wait for review" by one and "confirm your
     * email" by the other would send the person to the wrong screen half the
     * time, and each would look correct on its own.
     *
     * Email first, then approval — an account can be behind both, and the one to
     * name is the one the person can act on.
     */
    private function gateResponse(Buyer $buyer): ?JsonResponse
    {
        // Before either gate, because a rejected account is behind neither: it
        // has an answer already, and telling someone their application is
        // "pending" when it was turned down is the one lie this flow cannot
        // afford.
        if ($buyer->status === 'rejected') {
            return response()->json([
                'ok' => false,
                'code' => 'ACCOUNT_REJECTED',
                'registration_status' => 'rejected',
                'message' => 'Your registration was not approved. Please contact support.',
            ], 403);
        }

        if (! $buyer->isPastEmailVerification()) {
            return response()->json([
                'ok' => false,
                'code' => 'PENDING_VERIFICATION',
                'registration_status' => 'pending_verification',
                // The same handle a registration returns, so someone who closed
                // the app before entering their code can finish from the login
                // screen rather than owning a token they cannot use.
                'verification_id' => (string) $buyer->id,
                'message' => 'Confirm your email address to continue.',
            ], 403);
        }

        if (! $buyer->isApproved()) {
            return response()->json([
                'ok' => false,
                'code' => 'PENDING_APPROVAL',
                'registration_status' => 'pending_approval',
                'message' => 'Your account is waiting for approval.',
            ], 403);
        }

        return null;
    }

    /**
     * Who the account is, in the shape the app's `AuthResult` reads.
     *
     * `registration_status` is the server's own string rather than a boolean,
     * because the two pending gates collapse into one screen state in the app
     * while their *copy* still differs.
     */
    private function buyerPayload(Buyer $buyer): array
    {
        return [
            'role' => 'buyer',
            'name' => $buyer->fullName(),
            'email' => $buyer->email,
            'registration_status' => $buyer->status,
        ];
    }
}
