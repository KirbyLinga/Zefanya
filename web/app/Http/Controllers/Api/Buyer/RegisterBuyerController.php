<?php

namespace App\Http\Controllers\Api\Buyer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Buyer\StoreBuyerRegistrationRequest;
use App\Models\Buyer\Buyer;
use App\Notifications\Buyer\BuyerVerifyOtp;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class RegisterBuyerController extends Controller
{
    /**
     * POST /api/register/buyer
     *
     * The mobile app's door into the same registration the web modal uses: the
     * same `StoreBuyerRegistrationRequest` (so the rules cannot differ between
     * the two doors), the same `Buyer::createFromRegistration()` (so the account
     * cannot differ), and the same `issueEmailVerificationCode()` (so the code
     * cannot differ).
     *
     * The body is multipart, not JSON: `upload_id` is a file part and the server
     * stores it on the private disk. A JSON body cannot carry it, which is why
     * the app's client needs a multipart method rather than a JSON one.
     *
     * 201 rather than the web controller's 200: a row was created, and the app's
     * client treats every 2xx the same way, so this is the server's own
     * vocabulary rather than a shape the client depends on.
     */
    public function store(StoreBuyerRegistrationRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Private disk (storage/app/ids/...), never public — an ID document is
        // the one thing here that must not be reachable by URL.
        $path = $request->file('upload_id')->store('ids', 'local');

        $buyer = Buyer::createFromRegistration($data, $path);

        $buyer->notify(new BuyerVerifyOtp($buyer->issueEmailVerificationCode()));

        Log::warning('Buyer registration submitted via API, OTP sent', [
            'buyer_id' => $buyer->id,
            'email' => $buyer->email,
        ]);

        return response()->json([
            'ok' => true,
            // The app treats this as opaque: it is the server's row id, and the
            // app neither parses it nor builds one. It is what the confirm and
            // resend routes are keyed on, so without it the app would have
            // nothing to send a code against.
            'verification_id' => (string) $buyer->id,
            'email' => $buyer->email,
            'registration_status' => $buyer->status,
        ], 201);
    }
}
