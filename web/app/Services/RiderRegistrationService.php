<?php

namespace App\Services;

use App\Enums\LogisticsProviderStatus;
use App\Models\Logistics\LogisticsProvider;
use App\Models\RiderApplication;
use App\Notifications\Rider\RiderRegistrationOtp;
use App\Support\EmailRegistry;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class RiderRegistrationService
{
    public function register(array $data, UploadedFile $id, UploadedFile $orCr): RiderApplication
    {
        // Serializes repeat Rider submissions; database unique indexes also guard email/mobile.
        return Cache::lock('rider-register:'.hash('sha256', $data['email']), 60)->block(5, function () use ($data, $id, $orCr) {
            $paths = [];
            $oldPaths = [];
            try {
                $application = DB::transaction(function () use ($data, $id, $orCr, &$paths, &$oldPaths) {
                    $provider = LogisticsProvider::whereKey($data['logistics_provider_id'])->lockForUpdate()->first();
                    if (! $provider || $provider->status !== LogisticsProviderStatus::Approved) {
                        throw ValidationException::withMessages(['logistics_provider_id' => 'Select an approved Logistics provider.']);
                    }
                    if (EmailRegistry::otherRolesFor($data['email'], 'rider') !== []) {
                        throw ValidationException::withMessages(['email' => 'This email is already registered under another account type.']);
                    }
                    $existing = RiderApplication::whereRaw('LOWER(email) = ?', [$data['email']])->lockForUpdate()->first();
                    if ($existing) {
                        if (! ($existing->status === 'rejected' || ($existing->status === 'unverified' && $existing->isAbandonedRegistration()))) {
                            throw ValidationException::withMessages(['email' => 'This email is already registered. Please finish verification or sign in.']);
                        }
                        $oldPaths = [$existing->upload_id_path, $existing->or_cr_path];
                        $existing->archiveRegistration();
                    }
                    $paths[] = $id->store('rider-ids', 'local');
                    $paths[] = $orCr->store('rider-or-cr', 'local');
                    if (in_array(false, $paths, true)) {
                        throw new \RuntimeException('Rider document storage failed.');
                    }
                    $application = new RiderApplication;
                    $application->fill(Arr::only($data, $application->getFillable()));
                    foreach (['province', 'municipality', 'barangay'] as $part) {
                        $application->{$part.'_code'} = $data[$part];
                    }
                    $application->forceFill([
                        'logistics_provider_id' => $provider->id, 'status' => 'unverified',
                        'upload_id_path' => $paths[0], 'or_cr_path' => $paths[1],
                    ])->save();
                    // The application and hashed OTP commit together. Failed delivery rolls both back.
                    $otp = $application->issueOtp();
                    $application->notify(new RiderRegistrationOtp($otp));

                    return $application;
                });
            } catch (\Throwable $e) {
                Storage::disk('local')->delete(array_filter($paths));
                if ($e instanceof UniqueConstraintViolationException) {
                    throw ValidationException::withMessages(['email' => 'This email or mobile number is already registered.']);
                }
                throw $e;
            }
            // Cleanup must not turn a committed registration into a reported failure.
            try {
                Storage::disk('local')->delete(array_filter($oldPaths));
            } catch (\Throwable) {
                Log::warning('Archived Rider documents could not be removed', ['rider_application_id' => $application->id]);
            }

            return $application;
        });
    }
}
