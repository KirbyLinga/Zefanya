<?php

namespace App\Jobs;

use App\Models\Logistics\LinehaulDriver;
use App\Services\Logistics\LinehaulPushService;
use Firebase\JWT\JWT;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class SendLinehaulTripPush implements ShouldQueue
{
    use Queueable;

    private const OAUTH_URL = 'https://oauth2.googleapis.com/token';

    public int $tries = 1;

    public function __construct(public int $driverId, public int $companyId, public int $expiresAt)
    {
        $this->onConnection('database')->onQueue('courier-push');
    }

    public function handle(): void
    {
        if (now()->timestamp >= $this->expiresAt || ! LinehaulPushService::available()) {
            return;
        }
        $driver = LinehaulDriver::whereKey($this->driverId)->where('logistics_provider_id', $this->companyId)->first();
        if (! $driver?->isOperational()) {
            return;
        }
        $registrations = DB::table(LinehaulPushService::TABLE.' as registration')
            ->join('personal_access_tokens as session', 'registration.personal_access_token_id', '=', 'session.id')
            ->where('session.tokenable_type', $driver->getMorphClass())
            ->where('session.tokenable_id', $driver->id)
            ->where(fn ($query) => $query->whereNull('session.expires_at')->orWhere('session.expires_at', '>', now()))
            ->when((int) config('sanctum.expiration') > 0, fn ($query) =>
                $query->where('session.created_at', '>', now()->subMinutes((int) config('sanctum.expiration'))))
            ->select('registration.*')->get();
        if ($registrations->isEmpty()) {
            return;
        }
        try {
            $accessToken = $this->accessToken();
        } catch (Throwable) {
            Log::warning('Courier push authentication failed.');

            return;
        }
        $project = config('linehaul.push_project');
        foreach ($registrations as $registration) {
            $remaining = $this->expiresAt - now()->timestamp;
            if ($remaining <= 0) {
                return;
            }
            $binding = DB::table(LinehaulPushService::TABLE)->where('token_hash', $registration->token_hash)
                ->where('personal_access_token_id', $registration->personal_access_token_id)
                ->where('encrypted_token', $registration->encrypted_token);
            if (! $binding->exists()) {
                continue;
            }
            try {
                $response = Http::withoutRedirecting()->withToken($accessToken)->timeout(5)
                    ->post("https://fcm.googleapis.com/v1/projects/{$project}/messages:send", [
                        'message' => [
                            'token' => Crypt::decryptString($registration->encrypted_token),
                            'notification' => ['title' => 'Zefanya Courier', 'body' => 'Open Zefanya to view your Courier trips.'],
                            'android' => ['ttl' => "{$remaining}s"],
                        ],
                    ]);
                if ($response->successful()) {
                    continue;
                }
                if (collect($response->json('error.details') ?? [])->contains(
                    fn ($detail) => ($detail['errorCode'] ?? null) === 'UNREGISTERED',
                )) {
                    $binding->delete();
                } else {
                    Log::warning('Courier push delivery was rejected.', ['status' => $response->status()]);
                }
            } catch (Throwable) {
                Log::warning('Courier push delivery failed.');
            }
        }
    }

    private function accessToken(): string
    {
        $credentials = json_decode(file_get_contents(config('linehaul.push_credentials_path')), true, flags: JSON_THROW_ON_ERROR);
        $issued = now()->timestamp;
        $assertion = JWT::encode([
            'iss' => $credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => self::OAUTH_URL,
            'iat' => $issued,
            'exp' => $issued + 300,
        ], $credentials['private_key'], 'RS256', $credentials['private_key_id'] ?? null);
        $token = Http::withoutRedirecting()->asForm()->timeout(5)->post(self::OAUTH_URL, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $assertion,
        ])->json('access_token');
        if (! is_string($token) || $token === '') {
            throw new RuntimeException('Courier push access token unavailable.');
        }

        return $token;
    }
}
