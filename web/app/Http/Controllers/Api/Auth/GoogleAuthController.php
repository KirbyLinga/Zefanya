<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Api\Courier\CourierLoginController;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\GoogleAuthRequest;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\LogisticsProvider;
use App\Services\Auth\CourierRegistrationService;
use App\Services\Auth\GoogleTokenVerifier;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class GoogleAuthController extends Controller
{
    public function __construct(
        protected GoogleTokenVerifier $verifier,
        protected CourierRegistrationService $courierRegistrationService,
        protected LoginController $buyerLoginController,
        protected CourierLoginController $courierLoginController
    ) {}

    /**
     * POST /auth/google
     * POST /api/auth/google
     * POST /api/v1/auth/google
     */
    public function handle(GoogleAuthRequest $request): JsonResponse
    {
        $idToken = (string) $request->input('id_token');

        try {
            $claims = $this->verifier->verify($idToken);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => 'Invalid or unverified Google token: ' . $e->getMessage(),
            ], 422);
        }

        $email = strtolower(trim($claims['email']));

        // Check if user already exists
        $buyer = Buyer::where('email', $email)->first();
        if ($buyer) {
            // Check gate status via reflection/method
            if ($buyer->status === 'rejected') {
                return response()->json([
                    'ok' => false,
                    'code' => 'ACCOUNT_REJECTED',
                    'registration_status' => 'rejected',
                    'message' => 'Your registration was not approved. Please contact support.',
                ], 403);
            }

            if (! $buyer->isPastEmailVerification()) {
                return response()->json([
                    'ok' => false,
                    'code' => 'PENDING_VERIFICATION',
                    'registration_status' => 'pending_verification',
                    'verification_id' => (string) $buyer->id,
                    'message' => 'Confirm your email address to continue.',
                ], 403);
            }

            if (! $buyer->isApproved()) {
                return response()->json([
                    'ok' => false,
                    'code' => 'PENDING_APPROVAL',
                    'registration_status' => 'pending_approval',
                    'message' => 'Your account is waiting for approval.',
                ], 403);
            }

            return response()->json([
                'ok' => true,
                'token' => $buyer->createToken('zefanya-mobile')->plainTextToken,
                'role' => 'buyer',
                'name' => $buyer->fullName(),
                'email' => $buyer->email,
                'registration_status' => $buyer->status,
            ]);
        }

        $courier = LogisticsProvider::where('email', $email)->first();
        if ($courier) {
            if ($gate = $this->courierLoginController->gateResponse($courier)) {
                return $gate;
            }

            return response()->json([
                'ok' => true,
                'token' => $courier->createToken('zefanya-mobile-courier')->plainTextToken,
            ] + $this->courierLoginController->courierPayload($courier));
        }

        // Account does not exist yet.
        // If no role was supplied, tell the app to ask for one:
        if (! $request->has('role')) {
            return response()->json([
                'ok' => true,
                'needs_role' => true,
                'email' => $email,
                'name' => $claims['name'],
            ]);
        }

        // Role was supplied, complete registration:
        $role = strtolower(trim((string) $request->input('role')));

        if ($role === 'buyer') {
            return $this->registerGoogleBuyer($request, $claims);
        }

        if ($role === 'courier' || $role === 'logistics') {
            return $this->registerGoogleCourier($request, $claims);
        }

        return response()->json([
            'ok' => false,
            'message' => "Unsupported role '{$role}'.",
        ], 422);
    }

    protected function registerGoogleBuyer(GoogleAuthRequest $request, array $claims): JsonResponse
    {
        $idFile = $request->file('upload_id');
        $idPath = $idFile instanceof UploadedFile ? $idFile->store('buyer/ids', 'local') : 'buyer/ids/default.jpg';

        $cleanPhone = preg_replace('/[\s-]/', '', (string) $request->input('contact_no'));
        if (str_starts_with($cleanPhone, '+63')) {
            $cleanPhone = '0' . substr($cleanPhone, 3);
        }

        $birthday = Carbon::parse($request->input('birthday'));

        $buyer = Buyer::create([
            'first_name' => $request->input('first_name'),
            'last_name' => $request->input('last_name'),
            'middle_initial' => $request->input('middle_initial'),
            'sex' => strtolower($request->input('sex')),
            'email' => $claims['email'],
            'password' => Hash::make(Str::random(32)),
            'contact_no' => $cleanPhone,
            'birthday' => $birthday->format('Y-m-d'),
            'age' => $birthday->age,
            'address_mode' => $request->input('address_mode', 'manual'),
            'province_name' => $request->input('province_name'),
            'municipality_name' => $request->input('municipality_name'),
            'barangay_name' => $request->input('barangay_name'),
            'street' => $request->input('street'),
            'upload_id_path' => $idPath,
            'status' => 'pending_approval',
        ]);

        $buyer->forceFill([
            'email_verified_at' => now(),
        ])->save();

        return response()->json([
            'ok' => true,
            'token' => $buyer->createToken('zefanya-mobile')->plainTextToken,
            'role' => 'buyer',
            'name' => $buyer->fullName(),
            'email' => $buyer->email,
            'registration_status' => 'pending_approval',
            'message' => 'Registration complete and submitted for approval.',
        ], 201);
    }

    protected function registerGoogleCourier(GoogleAuthRequest $request, array $claims): JsonResponse
    {
        $data = $request->validated();
        $data['email'] = $claims['email'];
        $data['password'] = Str::random(32);
        $data['password_confirmation'] = $data['password'];

        $courier = $this->courierRegistrationService->register($data, $request->allFiles());

        $courier->forceFill([
            'email_verified_at' => now(),
        ])->save();

        return response()->json([
            'ok' => true,
            'token' => $courier->createToken('zefanya-mobile-courier')->plainTextToken,
            'role' => 'courier',
            'name' => $courier->fullName(),
            'email' => $courier->email,
            'registration_status' => 'pending_approval',
            'message' => 'Courier registration complete and submitted for approval.',
        ], 201);
    }
}
