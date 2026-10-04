<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Buyer\StoreBuyerRegistrationRequest;
use App\Models\Buyer\Buyer;
use App\Notifications\Buyer\BuyerVerifyOtp;
use Illuminate\Http\JsonResponse;
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

        // The row, then the code that opens its email gate. Both live on the
        // model because the mobile API has to produce the same account in the
        // same two steps — see `Buyer::createFromRegistration()`. The raw code
        // is returned for the mail only; the row keeps its hash.
        $buyer = Buyer::createFromRegistration($data, $path);

        $buyer->notify(new BuyerVerifyOtp($buyer->issueEmailVerificationCode()));

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

