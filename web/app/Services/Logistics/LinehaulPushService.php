<?php

namespace App\Services\Logistics;

use App\Jobs\SendLinehaulTripPush;
use App\Models\Logistics\LinehaulDriver;
use App\Policies\LinehaulPolicy;
use App\Support\RuntimeSchema;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

class LinehaulPushService
{
    public const TABLE = 'linehaul_push_registrations';

    public static function available(): bool
    {
        $project = config('linehaul.push_project');
        $credentials = config('linehaul.push_credentials_path');

        return is_string($project) && preg_match('/\A[a-z0-9][a-z0-9-]{0,62}\z/', $project) === 1
            && is_string($credentials) && is_file($credentials) && is_readable($credentials)
            && RuntimeSchema::hasTable(self::TABLE) && RuntimeSchema::hasTable('jobs');
    }

    public function change(LinehaulDriver $driver, bool $enabled, #[\SensitiveParameter] ?string $deviceToken): bool
    {
        abort_unless((new LinehaulPolicy)->access($driver) && $driver->currentAccessToken() instanceof PersonalAccessToken, 403);
        if ($enabled && ! self::available()) {
            throw ValidationException::withMessages(['enabled' => 'Courier push notifications are not available on this server.']);
        }
        if (! RuntimeSchema::hasTable(self::TABLE)) {
            return false;
        }
        if ($enabled && $deviceToken === null) {
            throw ValidationException::withMessages(['device_token' => 'The phone did not provide a notification registration.']);
        }

        $sessionId = $driver->currentAccessToken()->getKey();
        DB::transaction(function () use ($driver, $sessionId, $enabled, $deviceToken): void {
            $session = $driver->tokens()->whereKey($sessionId)->lockForUpdate()->first();
            abort_unless($session !== null && ($session->expires_at === null || $session->expires_at->isFuture()), 403);
            $current = DB::table(self::TABLE)->where('personal_access_token_id', $sessionId);
            if (! $enabled) {
                $current->delete();

                return;
            }
            $hash = hash('sha256', $deviceToken);
            $current->where('token_hash', '!=', $hash)->delete();
            DB::table(self::TABLE)->upsert([[
                'token_hash' => $hash,
                'personal_access_token_id' => $sessionId,
                'encrypted_token' => Crypt::encryptString($deviceToken),
            ]], ['token_hash'], ['personal_access_token_id', 'encrypted_token']);
        }, 3);

        return $enabled;
    }

    public static function queue(int $driverId, int $companyId): void
    {
        DB::afterCommit(function () use ($driverId, $companyId): void {
            try {
                if (! self::available()) {
                    return;
                }
                Bus::dispatch(new SendLinehaulTripPush($driverId, $companyId, now()->addMinutes(5)->timestamp));
            } catch (Throwable) {
                Log::warning('Could not queue a Courier trip notification.');
            }
        });
    }
}
