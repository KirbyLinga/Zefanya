<?php

namespace App\Http\Controllers\Api\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Buyer\Buyer;
use App\Notifications\Buyer\BuyerEmailChangeOtp;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AccountSecurityController extends Controller
{
    /**
     * POST /api/account/password
     *
     * Changing a password deliberately asks for the current one. A bearer token
     * proves the phone is signed in, not that the person holding it is the
     * account owner; without the old password, anyone who borrowed an unlocked
     * phone could lock the owner out.
     *
     * Every other token is revoked and the one making this request is kept, so
     * the phone that changed the password stays usable while any other signed-in
     * device is signed out.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $buyer = $this->buyer($request);

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => [
                'required',
                'string',
                'min:8',
                'confirmed',
                'regex:/[a-z]/',
                'regex:/[A-Z]/',
                'regex:/[0-9]/',
                'regex:/[^A-Za-z0-9\s]/',
                'not_regex:/\s/',
            ],
        ]);

        if (! Hash::check($data['current_password'], $buyer->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'Current password is incorrect.',
            ]);
        }

        // The `password` cast hashes on assignment; hashing here as well would
        // hash the hash and lock the account out.
        $buyer->password = $data['password'];
        $buyer->save();

        $current = $request->user()->currentAccessToken();
        if ($current !== null) {
            $buyer->tokens()->where('id', '!=', $current->getKey())->delete();
        } else {
            $buyer->tokens()->delete();
        }

        return response()->json([
            'ok' => true,
            'message' => 'Password changed. Other devices have been signed out.',
        ]);
    }

    /**
     * POST /api/account/email
     *
     * The new address is only stored as pending and a code is mailed to *it*.
     * The current address stays live until the code proves the new one can
     * receive mail, which is the difference between a typo and losing the
     * account's recovery address.
     */
    public function requestEmailChange(Request $request): JsonResponse
    {
        $buyer = $this->buyer($request);

        $data = $request->validate([
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('buyers', 'email')->ignore($buyer->id),
            ],
        ], [
            'email.unique' => 'This email is already in use.',
        ]);

        if (strcasecmp($data['email'], $buyer->email) === 0) {
            throw ValidationException::withMessages([
                'email' => 'This is already your email address.',
            ]);
        }

        $code = $buyer->issuePendingEmailCode($data['email']);

        // Addressed to the *pending* address: sending it to the old one would
        // verify an inbox the account already owns and prove nothing about the
        // new one.
        Notification::route('mail', $data['email'])
            ->notify(new BuyerEmailChangeOtp($code));

        return response()->json([
            'ok' => true,
            'message' => 'We sent a verification code to the new email address.',
        ]);
    }

    /**
     * POST /api/account/email/verify
     *
     * The code is checked before expiry, so "expired" is only ever said about a
     * code that was once right. A wrong code and an expired one both arrive as
     * `422 {ok: false, message}` with no field bag, matching the app's one inline
     * line under the code field.
     */
    public function confirmEmailChange(Request $request): JsonResponse
    {
        $buyer = $this->buyer($request);

        $data = $request->validate([
            'otp' => ['required', 'string', 'regex:/^\d{6}$/'],
        ]);

        if ($buyer->pending_email === null || $buyer->pending_email_code === null) {
            return response()->json([
                'ok' => false,
                'message' => 'No email change is pending. Request a new code.',
            ], 422);
        }

        if (! $buyer->verifyPendingEmailCode($data['otp'])) {
            return response()->json([
                'ok' => false,
                'message' => "That code doesn't match. Please try again.",
            ], 422);
        }

        if ($buyer->isPendingEmailExpired()) {
            return response()->json([
                'ok' => false,
                'expired' => true,
                'message' => 'This code has expired. Please request a new one.',
            ], 422);
        }

        // Someone else could have taken the address between the request and the
        // confirmation. The unique index is the real guard; this check makes the
        // common case a plain 422 rather than a database error.
        if (Buyer::where('email', $buyer->pending_email)
            ->whereKeyNot($buyer->id)
            ->exists()) {
            $buyer->forceFill([
                'pending_email' => null,
                'pending_email_code' => null,
                'pending_email_expires_at' => null,
            ])->save();

            return response()->json([
                'ok' => false,
                'message' => 'This email is already in use.',
            ], 422);
        }

        $email = $buyer->pending_email;

        try {
            $buyer->applyPendingEmail();
        } catch (QueryException) {
            // The narrow window between the check above and the write.
            return response()->json([
                'ok' => false,
                'message' => 'This email is already in use.',
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'email' => $email,
            'message' => 'Email changed. Use the new address to sign in from now on.',
        ]);
    }

    /**
     * POST /api/account/phone
     *
     * The number is validated for PH shape and uniqueness before a code is
     * issued. There is no SMS provider in this codebase yet, so the code is
     * logged for the operator; the stored hash and 10-minute expiry are already
     * what a gateway-backed sender will use.
     */
    public function requestPhoneChange(Request $request): JsonResponse
    {
        $buyer = $this->buyer($request);

        $data = $request->validate([
            'contact_no' => [
                'required',
                'string',
                'regex:/^(09\d{9})$/',
                Rule::unique('buyers', 'contact_no')->ignore($buyer->id),
            ],
        ], [
            'contact_no.regex' => 'Use a PH mobile number in 09XXXXXXXXX format.',
            'contact_no.unique' => 'This phone number is already in use.',
        ]);

        if ($data['contact_no'] === $buyer->contact_no) {
            throw ValidationException::withMessages([
                'contact_no' => 'This is already your phone number.',
            ]);
        }

        $code = $buyer->issuePendingPhoneCode($data['contact_no']);

        Log::warning('Buyer phone change OTP issued via API', [
            'buyer_id' => $buyer->id,
            'phone' => $data['contact_no'],
            'code' => $code,
        ]);

        return response()->json([
            'ok' => true,
            'message' => 'We sent a verification code to the new phone number.',
        ]);
    }

    public function confirmPhoneChange(Request $request): JsonResponse
    {
        $buyer = $this->buyer($request);

        $data = $request->validate([
            'otp' => ['required', 'string', 'regex:/^\d{6}$/'],
        ]);

        if ($buyer->pending_phone === null || $buyer->pending_phone_code === null) {
            return response()->json([
                'ok' => false,
                'message' => 'No phone change is pending. Request a new code.',
            ], 422);
        }

        if (! $buyer->verifyPendingPhoneCode($data['otp'])) {
            return response()->json([
                'ok' => false,
                'message' => "That code doesn't match. Please try again.",
            ], 422);
        }

        if ($buyer->isPendingPhoneExpired()) {
            return response()->json([
                'ok' => false,
                'expired' => true,
                'message' => 'This code has expired. Please request a new one.',
            ], 422);
        }

        if (Buyer::where('contact_no', $buyer->pending_phone)
            ->whereKeyNot($buyer->id)
            ->exists()) {
            $buyer->forceFill([
                'pending_phone' => null,
                'pending_phone_code' => null,
                'pending_phone_expires_at' => null,
            ])->save();

            return response()->json([
                'ok' => false,
                'message' => 'This phone number is already in use.',
            ], 422);
        }

        $phone = $buyer->pending_phone;

        try {
            $buyer->applyPendingPhone();
        } catch (QueryException) {
            return response()->json([
                'ok' => false,
                'message' => 'This phone number is already in use.',
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'contact_no' => $phone,
            'message' => 'Phone number changed.',
        ]);
    }

    /**
     * `auth:sanctum` accepts any tokenable; only buyers may change buyer
     * credentials. The same guard `LoginController::show()` uses.
     */
    private function buyer(Request $request): Buyer
    {
        $buyer = $request->user();

        abort_unless(
            $buyer instanceof Buyer,
            403,
            'This token belongs to a different kind of account.',
        );

        return $buyer;
    }
}
