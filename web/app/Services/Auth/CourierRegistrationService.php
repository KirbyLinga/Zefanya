<?php

namespace App\Services\Auth;

use App\Enums\LogisticsProviderStatus;
use App\Models\Logistics\LogisticsProvider;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;

class CourierRegistrationService
{
    /**
     * Create a pending rider application with document uploads.
     */
    public function register(array $data, array $files = []): LogisticsProvider
    {
        $idFile = $files['upload_id'] ?? null;
        $licenseFile = $files['license_doc'] ?? null;
        $orCrFile = $files['or_cr_doc'] ?? null;

        $idPath = $idFile instanceof UploadedFile ? $idFile->store('logistics/ids', 'local') : 'logistics/ids/default.jpg';
        $licensePath = $licenseFile instanceof UploadedFile ? $licenseFile->store('logistics/licenses', 'local') : null;
        $orCrPath = $orCrFile instanceof UploadedFile ? $orCrFile->store('logistics/or_cr', 'local') : null;

        // Clean phone number
        $cleanPhone = preg_replace('/[\s-]/', '', $data['contact_no']);
        if (str_starts_with($cleanPhone, '+63')) {
            $cleanPhone = '0'.substr($cleanPhone, 3);
        }

        // Calculate age
        $birthday = Carbon::parse($data['birthday']);
        $age = $birthday->age;

        $businessName = $data['company'] ?? $data['business_name'] ?? trim("{$data['first_name']} {$data['last_name']} Courier");

        return LogisticsProvider::create([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'middle_initial' => $data['middle_initial'] ?? null,
            'sex' => strtolower($data['sex']),
            'email' => strtolower(trim($data['email'])),
            'google_sub' => $data['google_sub'] ?? null,
            'password' => Hash::make($data['password']),
            'contact_no' => $cleanPhone,
            'birthday' => $birthday->format('Y-m-d'),
            'age' => $age,
            'business_name' => $businessName,
            'vehicle_type' => $data['vehicle_type'],
            'plate_number' => strtoupper(trim($data['plate_number'])),
            'license_number' => strtoupper(trim($data['license_number'])),
            'license_expiry' => Carbon::parse($data['license_expiry'])->format('Y-m-d'),
            'address_mode' => 'manual',
            'province_name' => $data['province_name'] ?? null,
            'municipality_name' => $data['municipality_name'] ?? null,
            'barangay_name' => $data['barangay_name'] ?? null,
            'street' => $data['street'] ?? null,
            'hub' => $data['hub'] ?? null,
            'upload_id_path' => $idPath,
            'license_path' => $licensePath ?? $idPath,
            'or_cr_path' => $orCrPath ?? $idPath,
            'dti_permit_path' => $licensePath ?? $idPath, // Satisfies non-null web column without schema breakage
            'status' => LogisticsProviderStatus::PendingApproval,
            'is_online' => false,
            'reverification_required' => false,
        ]);
    }
}
