<?php

namespace App\Http\Controllers\Rider;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rider\StoreRiderRegistrationRequest;
use App\Models\Rider;
use App\Services\RiderRegistrationService;
use App\Support\EmailMask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

class RegistrationController extends Controller
{
    public function store(StoreRiderRegistrationRequest $request, RiderRegistrationService $service): JsonResponse
    {
        $rider = $service->register($request->validated());

        return $this->registrationResponse($rider, 'Verification code sent. Verify your email to submit your application.', 201);
    }

    public function resume(Request $request, RiderRegistrationService $service): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', 'max:128'],
        ]);
        $rider = $service->resume($data['email'], $data['password']);

        return $this->registrationResponse($rider, 'Registration resumed. Request a new code if your previous code has expired.');
    }

    private function registrationResponse(Rider $rider, string $message, int $status = 200): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'data' => [
                'rider_id' => $rider->id,
                'status' => $rider->status,
                'email_masked' => EmailMask::mask($rider->email),
                'verify_url' => URL::temporarySignedRoute('api.rider.verify', now()->addHour(), ['rider' => $rider->id]),
                'resend_url' => URL::temporarySignedRoute('api.rider.resend', now()->addHour(), ['rider' => $rider->id]),
            ],
        ], $status);
    }

    public function verify(Request $request, Rider $rider, RiderRegistrationService $service): JsonResponse
    {
        $data = $request->validate(['otp' => ['required', 'string', 'regex:/^\d{6}$/']]);
        $service->verify($rider, $data['otp']);

        return response()->json(['message' => 'Email verified. Your application is pending Logistics approval.', 'data' => ['rider_id' => $rider->id, 'status' => 'pending']]);
    }

    public function resend(Rider $rider, RiderRegistrationService $service): JsonResponse
    {
        $service->resend($rider);

        return response()->json(['message' => 'A new verification code has been sent.']);
    }
}
