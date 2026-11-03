<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Api\Courier\CourierLoginController;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\GoogleAuthRequest;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\LogisticsProvider;
use App\Services\Auth\CourierRegistrationService;
use App\Services\Auth\GoogleIdTokenVerifier;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * H2: Google sign-in is fail-closed.
 *
 * The verifier is the only source of identity: it checks Google's signature,
 * issuer, expiry and audience. This controller re-checks the normalized claims
 * (defence in depth, and what lets a fake verifier exercise every rejection),
 * returns one generic 401 for every verification failure, and never echoes the
 * token or its payload into the log.
 */
class GoogleAuthController extends Controller
{
    private const string INVALID_MESSAGE = 'Invalid or unverified Google token.';

    public function __construct(
        protected GoogleIdTokenVerifier $verifier,
        protected CourierRegistrationService $courierRegistrationService,
        protected CourierLoginController $courierLoginController
    ) {}

    /**
     * POST /auth/google
     * POST /api/auth/google
     * POST /api/v1/auth/google
     */
    public function handle(GoogleAuthRequest $request): JsonResponse
    {
        $clientIds = $this->configuredClientIds();

        // Fail closed: without an audience there is nothing to verify against.
        if ($clientIds === []) {
            Log::warning('Google sign-in is not configured: GOOGLE_CLIENT_IDS is empty.');

            return response()->json([
                'ok' => false,
                'message' => 'Google sign-in is not configured.',
            ], 503);
        }

        $idToken = (string) $request->input('id_token');

        try {
            $claims = $this->verifier->verify($idToken);
        } catch (\Throwable $e) {
            // Deliberately no token, no payload, no Google exception message:
            // the reason category is enough to debug without logging a secret.
            Log::warning('Google sign-in rejected.', ['reason' => 'verifier_failed']);

            return $this->invalid();
        }

        $reason = $this->claimsProblem($claims, $clientIds);
        if ($reason !== null) {
            Log::warning('Google sign-in rejected.', ['reason' => $reason]);

            return $this->invalid();
        }

        $email = strtolower(trim((string) $claims['email']));
        $googleSub = (string) $claims['sub'];

        // A known Google identity wins, whichever table it lives in.
        $buyer = Buyer::where('google_sub', $googleSub)->first();
        if ($buyer !== null) {
            return $this->buyerSignIn($buyer);
        }

        $courier = LogisticsProvider::where('google_sub', $googleSub)->first();
        if ($courier !== null) {
            return $this->courierSignIn($courier);
        }

        // No subject match: link by email only when the local account has
        // already proven that it owns that address.
        $existingBuyer = Buyer::where('email', $email)->first();
        if ($existingBuyer !== null) {
            if (! $existingBuyer->isEmailVerified()) {
                return $this->emailUnverified();
            }

            $existingBuyer->forceFill(['google_sub' => $googleSub])->save();

            return $this->buyerSignIn($existingBuyer);
        }

        $existingCourier = LogisticsProvider::where('email', $email)->first();
        if ($existingCourier !== null) {
            if (! $existingCourier->isEmailVerified()) {
                return $this->emailUnverified();
            }

            $existingCourier->forceFill(['google_sub' => $googleSub])->save();

            return $this->courierSignIn($existingCourier);
        }

        // A brand-new identity must choose what it is registering as.
        if (! $request->has('role')) {
            return response()->json([
                'ok' => true,
                'needs_role' => true,
                'email' => $email,
                'name' => (string) ($claims['name'] ?? 'Google User'),
            ]);
        }

        $role = strtolower(trim((string) $request->input('role')));

        if ($role === 'buyer') {
            return $this->registerGoogleBuyer($request, $claims);
        }

        if ($role === 'courier' || $role === 'logistics') {
            return $this->registerGoogleCourier($request, $claims);
        }

        return response()->json([
            'ok' => false,
            'message' => 'Google sign-in can only create buyer or rider accounts.',
        ], 422);
    }

    private function invalid(): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'message' => self::INVALID_MESSAGE,
        ], 401);
    }

    private function emailUnverified(): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'code' => 'EMAIL_UNVERIFIED',
            'message' => 'Please verify your email address before linking with Google.',
        ], 409);
    }

    /**
     * @param  list<string>  $clientIds
     * @return string|null  a short category for the log, or null when valid
     */
    private function claimsProblem(array $claims, array $clientIds): ?string
    {
        if (($claims['email_verified'] ?? null) !== true) {
            return 'email_not_verified';
        }

        $email = strtolower(trim((string) ($claims['email'] ?? '')));
        $sub = (string) ($claims['sub'] ?? '');
        if ($email === '' || $sub === '') {
            return 'missing_email_or_sub';
        }

        $issuer = $claims['iss'] ?? null;
        if ($issuer !== 'https://accounts.google.com' && $issuer !== 'accounts.google.com') {
            return 'wrong_issuer';
        }

        $audience = $claims['aud'] ?? null;
        if (! is_string($audience) || ! in_array($audience, $clientIds, true)) {
            return 'wrong_audience';
        }

        $expires = $claims['exp'] ?? null;
        if (! is_numeric($expires) || (int) $expires <= time()) {
            return 'expired';
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function configuredClientIds(): array
    {
        $raw = config('services.google.client_ids', '');

        if (is_array($raw)) {
            return array_values(array_filter(array_map(
                fn ($id): string => trim((string) $id),
                $raw,
            ), fn (string $id): bool => $id !== ''));
        }

        return array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $raw),
        ), fn (string $id): bool => $id !== ''));
    }

    private function buyerSignIn(Buyer $buyer): JsonResponse
    {
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

    /**
     * Riders only receive a token once the administrator has approved them.
     * Pending and rejected applications get the gate response, never a token.
     */
    private function courierSignIn(LogisticsProvider $courier): JsonResponse
    {
        if ($gate = $this->courierLoginController->gateResponse($courier)) {
            return $gate;
        }

        return response()->json([
            'ok' => true,
            'token' => $courier->createToken('zefanya-mobile-courier')->plainTextToken,
        ] + $this->courierLoginController->courierPayload($courier));
    }

    protected function registerGoogleBuyer(GoogleAuthRequest $request, array $claims): JsonResponse
    {
        $idFile = $request->file('upload_id');
        $idPath = $idFile instanceof UploadedFile ? $idFile->store('buyer/ids', 'local') : 'buyer/ids/default.jpg';

        $cleanPhone = preg_replace('/[\s-]/', '', (string) $request->input('contact_no'));
        if (str_starts_with($cleanPhone, '+63')) {
            $cleanPhone = '0'.substr($cleanPhone, 3);
        }

        $birthday = Carbon::parse($request->input('birthday'));

        $buyer = Buyer::create([
            'first_name' => $request->input('first_name'),
            'last_name' => $request->input('last_name'),
            'middle_initial' => $request->input('middle_initial'),
            'sex' => strtolower($request->input('sex')),
            'email' => strtolower(trim((string) $claims['email'])),
            'google_sub' => (string) $claims['sub'],
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

    /**
     * A Google registration is an application, not an approval. It ends at
     * pending_approval and returns no bearer token: an unapproved rider must
     * not be able to call rider routes while the administrator is reviewing it.
     */
    protected function registerGoogleCourier(GoogleAuthRequest $request, array $claims): JsonResponse
    {
        $data = $request->validated();
        $data['email'] = strtolower(trim((string) $claims['email']));
        $data['google_sub'] = (string) $claims['sub'];
        $data['password'] = Str::random(32);
        $data['password_confirmation'] = $data['password'];

        $courier = $this->courierRegistrationService->register($data, $request->allFiles());

        $courier->forceFill([
            'email_verified_at' => now(),
        ])->save();

        return response()->json([
            'ok' => true,
            'role' => 'courier',
            'name' => $courier->fullName(),
            'email' => $courier->email,
            'registration_status' => 'pending_approval',
            'message' => 'Courier registration complete and submitted for approval.',
        ], 201);
    }
}
