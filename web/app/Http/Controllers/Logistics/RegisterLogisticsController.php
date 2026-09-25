<?php

namespace App\Http\Controllers\Logistics;

use App\Enums\LogisticsProviderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Logistics\StoreLogisticsRegistrationRequest;
use App\Models\Logistics\LogisticsProvider;
use App\Notifications\Logistics\LogisticsRegistrationOtp;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class RegisterLogisticsController extends Controller
{
    public function store(StoreLogisticsRegistrationRequest $request)
    {
        $data = $request->validated();

        // Private 'local' disk, separate subfolders from buyer/seller uploads.
        $idPath = $request->file('upload_id')->store('logistics-ids', 'local');
        $permitPath = $request->file('dti_permit_upload')->store('logistics-permits', 'local');

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
            'upload_id_path' => $idPath,
            'dti_permit_path' => $permitPath,
            'status' => LogisticsProviderStatus::PendingVerification,
        ]);

        $otp = $provider->issueOtp();
        $provider->notify(new LogisticsRegistrationOtp($otp));

        Log::warning('Logistics registration submitted, OTP sent', [
            'logistics_provider_id' => $provider->id,
            'email' => $provider->email,
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'logistics_provider_id' => $provider->id,
                'email' => $provider->email,
                'verify_url' => route('register.logistics.verify-otp', $provider),
            ], 201);
        }

        return redirect()->route('register.logistics.verify-otp', $provider);
    }
}
