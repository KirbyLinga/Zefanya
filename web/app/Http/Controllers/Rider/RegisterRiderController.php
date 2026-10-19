<?php

namespace App\Http\Controllers\Rider;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rider\StoreRiderRegistrationRequest;
use App\Services\RiderRegistrationService;
use App\Support\EmailMask;
use App\Support\SignedOtpLink;
use Illuminate\Validation\ValidationException;

class RegisterRiderController extends Controller
{
    public function store(StoreRiderRegistrationRequest $request, RiderRegistrationService $registrations)
    {
        try {
            $application = $registrations->register($request->validated(), $request->file('upload_id'), $request->file('or_cr_upload'));
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable) {
            // Do not log transport exception text: it may contain the OTP or document details.
            if ($request->expectsJson()) {
                return response()->json(['message' => 'We could not complete registration. Please try again.'], 503);
            }

            return back()->withErrors(['registration' => 'We could not complete registration. Please try again.']);
        }
        if ($request->expectsJson()) {
            return response()->json([
                'rider_application_id' => $application->id,
                'email_masked' => EmailMask::mask($application->email),
                'verify_url' => SignedOtpLink::verifyUrl('rider', $application),
                'resend_url' => SignedOtpLink::resendUrl('rider', $application),
            ], 201);
        }

        return redirect()->to(SignedOtpLink::showUrl('rider', $application));
    }
}
