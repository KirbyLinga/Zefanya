<?php

namespace App\Http\Controllers\Logistics;

use App\Enums\LogisticsProviderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Logistics\StoreLogisticsRegistrationRequest;
use App\Models\Logistics\LogisticsProvider;
use App\Notifications\Logistics\LogisticsRegistrationOtp;
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

class RegisterLogisticsController extends Controller
{
    public function store(StoreLogisticsRegistrationRequest $request)
    {
        $data = $request->validated();

        // Private 'local' disk, separate subfolders from buyer/seller uploads.
        $idPath = $request->file('upload_id')->store('logistics-ids', 'local');
        $permitPath = $request->file('dti_permit_upload')->store('logistics-permits', 'local');

        try {
            $provider = EmailRegistry::register($data['email'], 'logistics', function () use ($data, $idPath, $permitPath) {
                // Resume: ARCHIVE any leftover unverified OR rejected row so a
                // half-finished first attempt — or a rejection — never
                // permanently reserves the email/mobile. The row (and its
                // rejection trail) is kept; only the private documents are
                // removed. A still-fresh unverified row never reaches here.
                $existingProvider = LogisticsProvider::whereRaw('LOWER(email) = ?', [strtolower($data['email'])])
                    ->whereIn('status', [LogisticsProviderStatus::Unverified, LogisticsProviderStatus::Rejected])
                    ->first();

                if ($existingProvider) {
                    $obsoletePaths = array_values(array_filter([$existingProvider->upload_id_path, $existingProvider->dti_permit_path]));
                    // Preserve the old documents if archive-and-retry rolls back.
                    DB::afterCommit(fn () => Storage::disk('local')->delete($obsoletePaths));

                    $existingProvider->forceFill(['tin' => null, 'sec_number' => null, 'company_name_key' => null])->save();
                    $existingProvider->archiveRegistration();
                }

                $provider = LogisticsProvider::create([
                    'last_name' => $data['last_name'],
                    'first_name' => $data['first_name'],
                    'middle_initial' => $data['middle_initial'] ?? null,
                    'sex' => $data['sex'],
                    'email' => $data['email'],
                    'password' => Hash::make($data['password']),
                    'contact_no' => $data['contact_no'],
                    'birthday' => $data['birthday'],
                    // Age is never submitted — the client only displays it.
                    'age' => Carbon::parse($data['birthday'])->age,
                    'business_name' => $data['business_name'],
                    'tin' => $data['tin'],
                    'sec_number' => $data['sec_number'],
                    'service_areas' => $data['service_areas'],
                    'region_code' => $data['region_code'],
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
                    'upload_id_path' => $idPath,
                    'dti_permit_path' => $permitPath,
                    'status' => LogisticsProviderStatus::Unverified,
                    'otp_attempts' => 0,
                ]);
                $provider->forceFill(['company_name_key' => hash('sha256', LogisticsProvider::normalizedCompanyName($data['business_name']))])->save();

                return $provider;
            }, $data);

            if (! $provider->wasRecentlyCreated) {
                // Double submit: keep the first registration and its OTP.
                Storage::disk('local')->delete([$idPath, $permitPath]);
            } else {
                // Deliver the code synchronously: a registration only counts as
                // submitted once the applicant can actually receive the OTP.
                $otp = $provider->issueOtp();
                $provider->notify(new LogisticsRegistrationOtp($otp));
            }
        } catch (UniqueConstraintViolationException $exception) {
            // Lost a race against a concurrent request for the same email/mobile.
            Storage::disk('local')->delete($idPath);
            Storage::disk('local')->delete($permitPath);

            $constraint = (string) ($exception->errorInfo[2] ?? '');
            if (str_contains($constraint, 'sec_number')) {
                throw ValidationException::withMessages(['sec_number' => 'This SEC number is already registered.']);
            }
            if (str_contains($constraint, 'company_name_key')) {
                throw ValidationException::withMessages(['business_name' => 'This company name is already registered.']);
            }
            if (str_contains($constraint, 'tin')) {
                throw ValidationException::withMessages(['tin' => 'This TIN is already registered.']);
            }
            throw ValidationException::withMessages(
                EmailRegistry::registrationConflictErrors($data['email'], 'logistics', $data['contact_no'])
            );
        } catch (ValidationException $e) {
            Storage::disk('local')->delete($idPath);
            Storage::disk('local')->delete($permitPath);

            throw $e;
        } catch (\Throwable $e) {
            // Delivery failed (e.g. mail is down): roll the reservation back so
            // the applicant is never locked out of the email/mobile they used.
            if (isset($provider) && $provider->exists && $provider->wasRecentlyCreated) {
                $provider->delete();
            }

            Storage::disk('local')->delete($idPath);
            Storage::disk('local')->delete($permitPath);

            Log::error('Failed to send logistics OTP email', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'We could not send your verification email. Please try again.',
            ], 503);
        }

        Log::info('Logistics registration submitted, OTP sent', [
            'logistics_provider_id' => $provider->id,
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'logistics_provider_id' => $provider->id,
                'email' => $provider->email,
                'email_masked' => EmailMask::mask($provider->email),
                'verify_url' => SignedOtpLink::verifyUrl('logistics', $provider),
                'resend_url' => SignedOtpLink::resendUrl('logistics', $provider),
            ], 201);
        }

        return redirect()->to(SignedOtpLink::showUrl('logistics', $provider));
    }
}
