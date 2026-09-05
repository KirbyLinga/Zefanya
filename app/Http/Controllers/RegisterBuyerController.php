<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBuyerRegistrationRequest;
use App\Models\Buyer;
use App\Notifications\BuyerVerifyOtp;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class RegisterBuyerController extends Controller
{
    /**
     * POST /register/buyer
     * Always returns JSON when the request is XHR/fetch (the modal posts via fetch).
     * Non-XHR submissions still get a redirect for graceful fallback.
     */
    public function store(StoreBuyerRegistrationRequest $request)
    {
        $data = $request->validated();

        // Store on the private 'local' disk (storage/app/ids/...), never public.
        $path = $request->file('upload_id')->store('ids', 'local');

        $code = (string) random_int(100000, 999999);

        $buyer = Buyer::create([
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
            'upload_id_path' => $path,
            'email_verification_code' => hash('sha256', $code),
            'email_verification_expires_at' => now()->addMinutes(10),
            'status' => 'pending_verification',
        ]);

        $buyer->notify(new BuyerVerifyOtp($code));

        Log::warning('Buyer registration submitted, OTP sent', [
            'buyer_id' => $buyer->id,
            'email' => $buyer->email,
        ]);

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'ok' => true,
                'buyer_id' => $buyer->id,
                'email' => $buyer->email,
                'verify_url' => route('register.buyer.verify-otp.form', $buyer),
            ]);
        }

        return redirect()->route('register.buyer.verify-otp.form', $buyer);
    }
}

