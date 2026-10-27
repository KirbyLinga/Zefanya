<?php

namespace App\Services;

use App\Models\Seller\Seller;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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

        $newLogo = isset($data['logo']) ? $data['logo']->store('seller-logos/'.$seller->id, 'public') : null;
        if ($newLogo === false) {
            throw ValidationException::withMessages(['logo' => 'The logo could not be uploaded. Please try again.']);
        }
        $replaceLogo = $newLogo !== null || ($data['remove_logo'] ?? false);

        try {
            $oldLogo = DB::transaction(function () use ($seller, $data, $resolved, $newLogo, $replaceLogo): ?string {
                $current = Seller::query()->lockForUpdate()->findOrFail($seller->id);
                abort_unless($current->isApproved(), 403);
                $oldLogo = $current->store_logo_path;
                $attributes = Arr::only($data, [
                    'business_name', 'store_description', 'contact_no', 'contact_email',
                    'region_code', 'province_code', 'municipality_code', 'barangay_code',
                    'street', 'house_number', 'address_detail', 'postal_code',
                ]);
                $attributes += [
                    'address_mode' => 'api',
                    'province_name' => $resolved['province_name'],
                    'municipality_name' => $resolved['municipality_name'],
                    'barangay_name' => $resolved['barangay_name'],
                ];
                if ($replaceLogo) {
                    $attributes['store_logo_path'] = $newLogo;
                }
                $current->forceFill($attributes)->save();

                return $oldLogo;
            });
        } catch (\Throwable $exception) {
            if ($newLogo !== null) {
                Storage::disk('public')->delete($newLogo);
            }
            throw $exception;
        }

        if ($replaceLogo && $oldLogo && str_starts_with($oldLogo, 'seller-logos/'.$seller->id.'/')) {
            Storage::disk('public')->delete($oldLogo);
        }

        $seller->refresh();
    }
}
