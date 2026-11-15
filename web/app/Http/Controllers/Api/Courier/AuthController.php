<?php

namespace App\Http\Controllers\Api\Courier;

use App\Http\Controllers\Controller;
use App\Http\Middleware\Traits\ApiResponses;
use App\Models\Logistics\CourierAccount;
use App\Services\Logistics\CourierAccountService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Courier Mobile sign-in. There is no Courier web login: these JSON
 * endpoints are the only way a Courier authenticates.
 *
 * Until a password (or activation code) is proven correct, every failure
 * gets the same 422 response, so the API never reveals whether a login ID
 * exists, is pending, locked by the limiter or revoked.
 */
class AuthController extends Controller
{
    use ApiResponses;

    private const INVALID_LOGIN = 'Login ID or password is incorrect.';

    private const INVALID_ACTIVATION = 'Login ID or access code is incorrect, or the code has expired.';

    private const INELIGIBLE = 'Your Courier access is not available right now. Contact your logistics company.';

    public function __construct(private readonly CourierAccountService $accounts) {}

    public function activate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'login_id' => ['required', 'string', 'max:40'],
            'activation_code' => ['required', 'string', 'max:40'],
            'password' => ['bail', 'required', 'string', 'min:'.(int) config('courier.password_min_length', 8), 'max:255', $this->validatePasswordInput(...), 'confirmed'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $result = $this->accounts->activate($data['login_id'], $data['activation_code'], $data['password'], $data['device_name'] ?? null);

        return $this->respond($result, self::INVALID_ACTIVATION, 'activation_code', 'Your Courier app is ready.');
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'login_id' => ['required', 'string', 'max:40'],
            'password' => ['bail', 'required', 'string', 'max:255', $this->validatePasswordInput(...)],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $result = $this->accounts->login($data['login_id'], $data['password'], $data['device_name'] ?? null);

        return $this->respond($result, self::INVALID_LOGIN, 'login_id', 'Signed in.');
    }

    private function validatePasswordInput(string $attribute, string $value, Closure $fail): void
    {
        $error = CourierAccountService::passwordInputError($value);

        if ($error !== null) {
            $fail($error);
        }
    }

    public function refresh(Request $request): JsonResponse
    {
        /** @var CourierAccount $account */
        $account = $request->user();
        $session = $this->accounts->refresh($account, $account->currentAccessToken());

        return $session === null
            ? $this->unauthenticated('Please sign in again.')
            : $this->success('Token refreshed.', $session);
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var CourierAccount $account */
        $account = $request->user();
        $this->accounts->logout($account, $account->currentAccessToken());

        return $this->success('Signed out.');
    }

    private function respond(array|string $result, string $invalid, string $field, string $message): JsonResponse
    {
        return match ($result) {
            'invalid' => $this->error($invalid, [$field => [$invalid]], 422),
            'ineligible' => $this->error(self::INELIGIBLE, ['account' => [self::INELIGIBLE]], 403),
            default => $this->success($message, $result),
        };
    }
}
