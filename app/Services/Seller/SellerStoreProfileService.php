<?php

namespace App\Services\Seller;

use App\Models\Seller\Seller;
use App\Services\Shared\PhilippineLocationService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SellerStoreProfileService
{
    public function __construct(private PhilippineLocationService $locations) {}

    public function update(Seller $seller, array $data): void
    {
        $resolved = $this->locations->validateAndResolve([
            'region_code' => $data['region_code'],
            'province_code' => $data['province_code'],
            'city_municipality_code' => $data['municipality_code'],
            'barangay_code' => $data['barangay_code'],
        ]);
        if (blank($resolved['region_name']) || blank($resolved['province_name']) || blank($resolved['municipality_name']) || blank($resolved['barangay_name'])) {
            throw ValidationException::withMessages(['location' => 'Select a valid province, city/municipality, and barangay.']);
        }

        $data['business_name'] = trim($data['business_name']);
        Validator::make($data, [
            'business_name' => ['required', 'string', 'min:3', 'max:30'],
            'store_description' => ['nullable', 'string', 'max:500'],
        ])->validate();
        $normalized = Seller::normalizedStoreName($data['business_name']);
        $nameKey = hash('sha256', $normalized);
        $stored = [];
        try {
            foreach (['logo', 'banner'] as $type) {
                if (isset($data[$type])) {
                    $path = $data[$type]->store('seller-'.$type.'s/'.$seller->id, 'public');
                    if ($path === false) {
                        throw ValidationException::withMessages([$type => 'The image could not be uploaded. Please try again.']);
                    }
                    $stored[$type] = $path;
                }
            }
            $oldFiles = DB::transaction(function () use ($seller, $data, $resolved, $stored, $normalized, $nameKey): array {
                $current = Seller::query()->lockForUpdate()->findOrFail($seller->id);
                abort_unless($current->isApproved(), 403);
                // Include unclaimed legacy names without changing or backfilling them.
                $taken = Seller::withoutGlobalScope('not_archived')->whereKeyNot($current->id)
                    ->get(['business_name'])->contains(fn ($other) => Seller::normalizedStoreName((string) $other->business_name) === $normalized);
                if ($taken) {
                    throw ValidationException::withMessages(['business_name' => 'This store name is already taken.']);
                }
                $attributes = Arr::only($data, [
                    'business_name', 'store_description', 'contact_no', 'contact_email',
                    'region_code', 'province_code', 'municipality_code', 'barangay_code',
                    'street', 'house_number', 'address_detail', 'postal_code',
                ]);
                $attributes += [
                    'store_name_key' => $nameKey,
                    'address_mode' => 'api',
                    'province_name' => $resolved['province_name'],
                    'municipality_name' => $resolved['municipality_name'],
                    'barangay_name' => $resolved['barangay_name'],
                ];
                $oldFiles = [];
                foreach (['logo', 'banner'] as $type) {
                    if (isset($stored[$type]) || ($data['remove_'.$type] ?? false)) {
                        $column = 'store_'.$type.'_path';
                        $oldFiles[$type] = $current->{$column};
                        $attributes[$column] = $stored[$type] ?? null;
                    }
                }
                $current->forceFill($attributes)->save();

                return $oldFiles;
            });
        } catch (\Throwable $exception) {
            Storage::disk('public')->delete(array_values($stored));
            if ($exception instanceof UniqueConstraintViolationException && str_contains($exception->getMessage(), 'store_name_key')) {
                throw ValidationException::withMessages(['business_name' => 'This store name is already taken.']);
            }
            throw $exception;
        }

        DB::afterCommit(function () use ($oldFiles, $seller): void {
            foreach ($oldFiles as $type => $path) {
                if ($path && str_starts_with($path, 'seller-'.$type.'s/'.$seller->id.'/')) {
                    Storage::disk('public')->delete($path);
                }
            }
        });
        $seller->refresh();
    }
}
