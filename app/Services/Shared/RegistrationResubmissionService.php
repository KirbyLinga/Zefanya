<?php

namespace App\Services\Shared;

use App\Models\Logistics\LogisticsProvider;
use App\Models\Seller\Seller;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class RegistrationResubmissionService
{
    public function resubmit(Seller|LogisticsProvider $account, array $data): void
    {
        $stored = [];
        try {
            DB::transaction(function () use ($account, $data, &$stored): void {
                $current = $account->newQuery()->whereKey($account->getKey())->lockForUpdate()->firstOrFail();
                if ($current->getRawOriginal('status') !== 'rejected' || $current->email_verified_at === null) {
                    throw ValidationException::withMessages(['application' => 'Only verified rejected applications can be resubmitted.']);
                }
                if (strtolower($current->email) !== $data['email']) {
                    throw ValidationException::withMessages(['email' => 'Keep your verified email address when resubmitting.']);
                }
                $seller = $current instanceof Seller;
                $attributes = Arr::only($data, [
                    'first_name', 'last_name', 'middle_initial', 'sex', 'contact_no', 'birthday',
                    'business_name', 'tin', 'region_code', 'street', 'house_number', 'address_detail', 'postal_code',
                ]);
                $attributes['address_mode'] = 'api';
                $attributes['age'] = Carbon::parse($data['birthday'])->age;
                foreach (['province', 'municipality', 'barangay'] as $part) {
                    $attributes[$part.'_code'] = $data[$part];
                    $attributes[$part.'_name'] = $data[$part.'_name'];
                }
                $documents = $seller ? [
                    'upload_id' => ['upload_id_path', 'seller-ids'],
                    'business_permit' => ['business_permit_path', 'business-permits'],
                    'sec_certificate' => ['sec_certificate_path', 'seller-sec-certificates'],
                ] : [
                    'upload_id' => ['upload_id_path', 'logistics-ids'],
                    'dti_permit_upload' => ['dti_permit_path', 'logistics-permits'],
                ];
                $obsolete = [];
                foreach ($documents as $field => [$column, $directory]) {
                    if (! isset($data[$field])) {
                        continue;
                    }
                    $path = $data[$field]->store($directory, 'local');
                    if (! is_string($path)) {
                        throw new \RuntimeException('The replacement document could not be stored.');
                    }
                    $stored[] = $path;
                    $attributes[$column] = $path;
                    if ($current->{$column}) {
                        $obsolete[] = $current->{$column};
                    }
                }
                if ($seller) {
                    $attributes += Arr::only($data, ['seller_type', 'line_of_business_id']);
                    $attributes['store_name_key'] = hash('sha256', Seller::normalizedStoreName($data['business_name']));
                } else {
                    $attributes += Arr::only($data, ['sec_number', 'service_areas']);
                    $attributes['company_name_key'] = hash('sha256', LogisticsProvider::normalizedCompanyName($data['business_name']));
                }
                $current->forceFill($attributes + [
                    'status' => 'pending', 'approved_by' => null, 'approved_at' => null, 'rejection_reason' => null,
                ])->save();
                DB::afterCommit(fn () => rescue(fn () => Storage::disk('local')->delete($obsolete)));
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($stored);
            if ($exception instanceof UniqueConstraintViolationException) {
                $constraint = (string) ($exception->errorInfo[2] ?? '');
                $field = match (true) {
                    str_contains($constraint, 'store_name_key'), str_contains($constraint, 'company_name_key') => 'business_name',
                    str_contains($constraint, 'tin') => 'tin',
                    str_contains($constraint, 'sec_number') => 'sec_number',
                    str_contains($constraint, 'contact_no') => 'contact_no',
                    default => 'application',
                };
                throw ValidationException::withMessages([$field => 'This value is already registered. Use a different value.']);
            }
            throw $exception;
        }
    }
}
