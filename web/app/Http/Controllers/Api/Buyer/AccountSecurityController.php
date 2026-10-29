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

        Notification::route('mail', $data['email'])
            ->notify(new BuyerEmailChangeOtp($code));

        return response()->json([
            'ok' => true,
            'message' => 'We sent a verification code to the new email address.',
        ]);
    }

    /**
     * POST /api/account/email/verify
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

    /**
     * POST /api/account/phone/verify
     */
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
