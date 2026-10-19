<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Buyer\StoreBuyerRegistrationRequest;
use App\Models\Buyer\Buyer;
use App\Notifications\Buyer\BuyerVerifyOtp;
use App\Support\EmailMask;
use App\Support\SignedOtpLink;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class RegisterBuyerController extends Controller
{
    /**
     * POST /register/buyer
     * Always returns JSON when the request is XHR/fetch (the modal posts via fetch).
     * Non-XHR submissions still get a redirect for graceful fallback.
     */
    public function store(StoreBuyerRegistrationRequest $request)
    {
        $data = $request->validated();

        // Store on the private 'local' disk (storage/app/ids/...), never public.
        $path = $request->file('upload_id')->store('ids', 'local');

        try {
            $buyer = DB::transaction(function () use ($data, $path) {
                // Resume: ARCHIVE any leftover unverified OR rejected row so a
                // half-finished first attempt — or a rejection — never
                // permanently reserves the email/mobile. The row (and its
                // rejection trail) is kept; only the private ID file is removed.
                // A still-fresh unverified row never reaches here — the form
                // request refuses it so a stranger cannot wipe it.
                $existingBuyer = Buyer::where('email', $data['email'])
                    ->whereIn('status', ['unverified', 'rejected'])
                    ->first();

                if ($existingBuyer) {
                    if ($existingBuyer->upload_id_path && Storage::disk('local')->exists($existingBuyer->upload_id_path)) {
                        Storage::disk('local')->delete($existingBuyer->upload_id_path);
                    }

                    $existingBuyer->archiveRegistration();
                }

                return Buyer::create([
                    'last_name' => $data['last_name'],
                    'first_name' => $data['first_name'],
                    'middle_initial' => $data['middle_initial'] ?? null,
                    'sex' => $data['sex'],
                    'email' => $data['email'],
                    'password' => Hash::make($data['password']),
                    'contact_no' => $data['contact_no'],
                    'birthday' => $data['birthday'],
                    // Column is NOT NULL; model accessor recomputes age on read.
                    'age' => Carbon::parse($data['birthday'])->age,
                    'address_mode' => $data['address_mode'],
                    'province_code' => $data['province'] ?? null,
                    'province_name' => $data['province_name'] ?? null,
                    'municipality_code' => $data['municipality'] ?? null,
                    'municipality_name' => $data['municipality_name'] ?? null,
                    'barangay_code' => $data['barangay'] ?? null,
                    'barangay_name' => $data['barangay_name'] ?? null,
                    'street' => $data['street'] ?? null,
                    'house_number' => $data['house_number'] ?? null,
                    'address_detail' => $data['address_detail'] ?? null,
                    'postal_code' => $data['postal_code'] ?? null,
                    'upload_id_path' => $path,
                    'status' => 'unverified',
                    'otp_attempts' => 0,
                ]);
            });

            // Deliver the code synchronously: a registration only counts as
            // submitted once the applicant can actually receive the OTP.
            $otp = $buyer->issueOtp();
            $buyer->notify(new BuyerVerifyOtp($otp));
        } catch (UniqueConstraintViolationException) {
            // Lost a race against a concurrent request for the same email/mobile.
            Storage::disk('local')->delete($path);

            return response()->json([
                'message' => 'This email or mobile number is already registered.',
            ], 422);
        } catch (\Throwable $e) {
            // Delivery failed (e.g. mail is down): roll the reservation back so
            // the applicant is never locked out of the email/mobile they used.
            if (isset($buyer) && $buyer->exists) {
                $buyer->delete();
            }

            Storage::disk('local')->delete($path);

            Log::error('Failed to send buyer OTP email', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'We could not send your verification email. Please try again.',
            ], 503);
        }

        Log::info('Buyer registration submitted, OTP sent', [
            'buyer_id' => $buyer->id,
        ]);

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'ok' => true,
                'buyer_id' => $buyer->id,
                'email' => $buyer->email,
                'email_masked' => EmailMask::mask($buyer->email),
                'verify_url' => SignedOtpLink::verifyUrl('buyer', $buyer),
                'resend_url' => SignedOtpLink::resendUrl('buyer', $buyer),
            ]);
        }

        // No-JS fallback: send the applicant straight to the SIGNED OTP step for
        // the buyer that was just created.
        return redirect()->to(SignedOtpLink::showUrl('buyer', $buyer));
    }
}
