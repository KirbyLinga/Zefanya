<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Http\Requests\Seller\StoreSellerRegistrationRequest;
use App\Models\Seller\Seller;
use App\Notifications\Seller\SellerRegistrationOtp;
use App\Support\EmailMask;
use App\Support\SignedOtpLink;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class RegisterSellerController extends Controller
{
    public function store(StoreSellerRegistrationRequest $request)
    {
        $data = $request->validated();

        // Private 'local' disk, separate subfolders from buyer uploads.
        $idPath = $request->file('upload_id')->store('seller-ids', 'local');
        $permitPath = $request->file('business_permit')->store('business-permits', 'local');

        try {
            $seller = DB::transaction(function () use ($data, $idPath, $permitPath) {
                // Resume: ARCHIVE any leftover unverified OR rejected row so a
                // half-finished first attempt — or a rejection — never
                // permanently reserves the email/mobile. The row (and its
                // rejection trail) is kept; only the private documents are
                // removed. A still-fresh unverified row never reaches here.
                $existingSeller = Seller::where('email', $data['email'])
                    ->whereIn('status', ['unverified', 'rejected'])
                    ->first();

                if ($existingSeller) {
                    if ($existingSeller->upload_id_path && Storage::disk('local')->exists($existingSeller->upload_id_path)) {
                        Storage::disk('local')->delete($existingSeller->upload_id_path);
                    }
                    if ($existingSeller->business_permit_path && Storage::disk('local')->exists($existingSeller->business_permit_path)) {
                        Storage::disk('local')->delete($existingSeller->business_permit_path);
                    }

                    $existingSeller->archiveRegistration();
                }

                return Seller::create([
                    'last_name' => $data['last_name'],
                    'first_name' => $data['first_name'],
                    'middle_initial' => $data['middle_initial'] ?? null,
                    'sex' => $data['sex'],
                    'email' => $data['email'],
                    'password' => Hash::make($data['password']),
                    'contact_no' => $data['contact_no'],
                    'birthday' => $data['birthday'],
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
                    'business_name' => $data['business_name'],
                    'line_of_business_id' => $data['line_of_business_id'],
                    'upload_id_path' => $idPath,
                    'business_permit_path' => $permitPath,
                    'status' => 'unverified',
                    'email_verified_at' => null,
                    'otp_attempts' => 0,
                ]);
            });

            $otp = $seller->issueOtp();

            // Deliver the code synchronously: a registration only counts as
            // submitted once the applicant can actually receive the OTP.
            $seller->notify(new SellerRegistrationOtp($otp));
        } catch (UniqueConstraintViolationException) {
            // Lost a race against a concurrent request for the same email/mobile.
            Storage::disk('local')->delete($idPath);
            Storage::disk('local')->delete($permitPath);

            return response()->json([
                'message' => 'This email or mobile number is already registered.',
            ], 422);
        } catch (\Throwable $e) {
            // Delivery failed (e.g. mail is down): roll the reservation back so
            // the applicant is never locked out of the email/mobile they used.
            if (isset($seller) && $seller->exists) {
                $seller->delete();
            }

            Storage::disk('local')->delete($idPath);
            Storage::disk('local')->delete($permitPath);

            Log::error('Failed to send seller OTP email', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'We could not send your verification email. Please try again.',
            ], 503);
        }

        Log::info('Seller registration submitted', [
            'seller_id' => $seller->id,
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'seller_id' => $seller->id,
                'email' => $seller->email,
                'email_masked' => EmailMask::mask($seller->email),
                'verify_url' => SignedOtpLink::verifyUrl('seller', $seller),
                'resend_url' => SignedOtpLink::resendUrl('seller', $seller),
            ], 201);
        }

        // No-JS fallback: send the applicant to the SIGNED OTP step.
        return redirect()->to(SignedOtpLink::showUrl('seller', $seller));
    }
}
