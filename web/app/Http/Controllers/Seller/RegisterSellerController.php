<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Http\Requests\Seller\StoreSellerRegistrationRequest;
use App\Models\Seller\Seller;
use App\Notifications\Seller\SellerRegistrationOtp;
use App\Support\EmailMask;
use App\Support\EmailRegistry;
use App\Support\SignedOtpLink;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class RegisterSellerController extends Controller
{
    public function store(StoreSellerRegistrationRequest $request)
    {
        $data = $request->validated();

        // Private 'local' disk, separate subfolders from buyer uploads.
        $idPath = $request->file('upload_id')->store('seller-ids', 'local');
        $permitPath = $request->file('business_permit')->store('business-permits', 'local');

        $secPath = null;
        try {
            if (isset($data['sec_certificate'])) {
                $secPath = $data['sec_certificate']->store('seller-sec-certificates', 'local');
            }
            $seller = EmailRegistry::register($data['email'], 'seller', function () use ($data, $idPath, $permitPath, $secPath) {
                // Resume: ARCHIVE any leftover unverified OR rejected row so a
                // half-finished first attempt — or a rejection — never
                // permanently reserves the email/mobile. The row (and its
                // rejection trail) is kept; only the private documents are
                // removed. A still-fresh unverified row never reaches here.
                $existingSeller = Seller::whereRaw('LOWER(email) = ?', [strtolower($data['email'])])
                    ->whereIn('status', ['unverified', 'rejected'])
                    ->first();

                if ($existingSeller) {
                    $obsoletePaths = array_values(array_filter([$existingSeller->upload_id_path, $existingSeller->business_permit_path, $existingSeller->sec_certificate_path]));
                    // Preserve the old documents if archive-and-retry rolls back.
                    DB::afterCommit(fn () => Storage::disk('local')->delete($obsoletePaths));

                    $existingSeller->forceFill(['tin' => null, 'store_name_key' => null])->save();
                    $existingSeller->archiveRegistration();
                }

                $seller = Seller::create([
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
                    'region_code' => $data['region_code'],
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
                    'tin' => $data['tin'],
                    'seller_type' => $data['seller_type'],
                    'sec_certificate_path' => $secPath,
                    'line_of_business_id' => $data['line_of_business_id'],
                    'upload_id_path' => $idPath,
                    'business_permit_path' => $permitPath,
                    'status' => 'unverified',
                    'email_verified_at' => null,
                    'otp_attempts' => 0,
                ]);
                $seller->forceFill([
                    'store_name_key' => hash('sha256', Seller::normalizedStoreName($data['business_name'])),
                ])->save();

                return $seller;
            }, $data);

            if (! $seller->wasRecentlyCreated) {
                // Double submit: keep the first registration and its OTP.
                Storage::disk('local')->delete(array_values(array_filter([$idPath, $permitPath, $secPath])));
            } else {
                $otp = $seller->issueOtp();

                // Deliver the code synchronously: a registration only counts as
                // submitted once the applicant can actually receive the OTP.
                $seller->notify(new SellerRegistrationOtp($otp));
            }
        } catch (UniqueConstraintViolationException $exception) {
            // Lost a race against a concurrent request for the same email/mobile.
            Storage::disk('local')->delete($idPath);
            Storage::disk('local')->delete($permitPath);
            if ($secPath) {
                Storage::disk('local')->delete($secPath);
            }

            $constraint = (string) ($exception->errorInfo[2] ?? '');
            if (str_contains($constraint, 'store_name_key')) {
                throw ValidationException::withMessages(['business_name' => 'This shop name is already taken.']);
            }
            if (str_contains($constraint, 'tin')) {
                throw ValidationException::withMessages(['tin' => 'This TIN is already registered.']);
            }
            throw ValidationException::withMessages(
                EmailRegistry::registrationConflictErrors($data['email'], 'seller', $data['contact_no'])
            );
        } catch (ValidationException $e) {
            Storage::disk('local')->delete($idPath);
            Storage::disk('local')->delete($permitPath);
            if ($secPath) {
                Storage::disk('local')->delete($secPath);
            }

            throw $e;
        } catch (\Throwable $e) {
            // Delivery failed (e.g. mail is down): roll the reservation back so
            // the applicant is never locked out of the email/mobile they used.
            if (isset($seller) && $seller->exists && $seller->wasRecentlyCreated) {
                $seller->delete();
            }

            Storage::disk('local')->delete($idPath);
            Storage::disk('local')->delete($permitPath);
            if ($secPath) {
                Storage::disk('local')->delete($secPath);
            }

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
