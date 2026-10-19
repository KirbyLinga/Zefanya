<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Http\Requests\Seller\StoreSellerRegistrationRequest;
use App\Models\Seller\Seller;
use App\Notifications\Seller\SellerRegistrationOtp;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class RegisterSellerController extends Controller
{
    public function store(StoreSellerRegistrationRequest $request)
    {
        $data = $request->validated();

        // If there's an existing unverified seller with this email,
        // delete it so we can start fresh with new files
        Seller::where('email', $data['email'])
            ->where('status', 'unverified')
            ->delete();

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
            'status' => 'unverified',
            'email_verified_at' => null,
        ]);

        $otp = $seller->issueOtp();

        // Store seller_id in session for OTP verification
        session(['seller_registration_id' => $seller->id]);

        // Log the OTP for development/testing (remove this in production)
        Log::info('Seller registration OTP code', [
            'seller_id' => $seller->id,
            'email' => $seller->email,
            'otp_code' => $otp,
        ]);

        try {
            $seller->notify(new SellerRegistrationOtp($otp));
        } catch (\Exception $e) {
            Log::error('Failed to send seller OTP email', [
                'seller_id' => $seller->id,
                'email' => $seller->email,
                'error' => $e->getMessage(),
            ]);
        }

        Log::warning('Seller registration submitted', [
            'seller_id' => $seller->id,
            'email' => $seller->email,
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'seller_id' => $seller->id,
                'email' => $seller->email,
            ], 201);
        }

        return redirect()->route('register.seller.pending');
    }
}
