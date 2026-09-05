<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSellerRegistrationRequest;
use App\Models\Seller;
use App\Notifications\SellerRegistrationOtp;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class RegisterSellerController extends Controller
{
    public function store(StoreSellerRegistrationRequest $request)
    {
        $data = $request->validated();

        // Private 'local' disk, separate subfolders from buyer uploads.
        $idPath = $request->file('upload_id')->store('seller-ids', 'local');
        $permitPath = $request->file('business_permit')->store('business-permits', 'local');

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
            'province_code' => $data['province'] ?? null,
            'province_name' => $data['province_name'] ?? null,
            'municipality_code' => $data['municipality'] ?? null,
            'municipality_name' => $data['municipality_name'] ?? null,
            'barangay_code' => $data['barangay'] ?? null,
            'barangay_name' => $data['barangay_name'] ?? null,
            'street' => $data['street'] ?? null,
            'house_number' => $data['house_number'] ?? null,
            'address_detail' => $data['address_detail'] ?? null,
            'business_name' => $data['business_name'],
            'line_of_business_id' => $data['line_of_business_id'],
            'upload_id_path' => $idPath,
            'business_permit_path' => $permitPath,
            'status' => 'pending_verification',
        ]);

        $otp = $seller->issueOtp();
        $seller->notify(new SellerRegistrationOtp($otp));

        Log::warning('Seller registration submitted, OTP sent', [
            'seller_id' => $seller->id,
            'email' => $seller->email,
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'seller_id' => $seller->id,
                'email' => $seller->email,
                'verify_url' => route('register.seller.verify-otp', $seller),
            ], 201);
        }

        return redirect()->route('register.seller.verify-otp', $seller);
    }
}