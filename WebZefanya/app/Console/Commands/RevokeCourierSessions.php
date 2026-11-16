<?php

namespace App\Console\Commands;

use App\Models\Logistics\CourierAccount;
use App\Support\CourierAccess;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Operational rollback for Courier Mobile app access: signs every Courier out
 * by deleting their API tokens. Touches nothing else: Courier accounts, their
 * status, audit logs, Courier records, assignments and transfers are kept.
 *
 * Run it after setting COURIER_ENABLED=false and BEFORE reverting any code,
 * so no token is left pointing at a removed model.
 */
class RevokeCourierSessions extends Command
{
    protected $signature = 'courier:revoke-sessions {--force : Run without the confirmation prompt}';

    protected $description = 'Sign out every Courier Mobile session (tokens only; accounts and history are kept)';

    public function handle(): int
    {
        if (! CourierAccess::schemaReady()) {
            $this->info('Courier app access is not installed (no courier_accounts table). Nothing to revoke.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Sign out every Courier Mobile session now?')) {
            $this->info('Cancelled. Nothing changed.');

            return self::SUCCESS;
        }

        [$tokens, $accounts] = DB::transaction(function (): array {
            $ids = CourierAccount::query()->orderBy('id')->lockForUpdate()->pluck('id');
            $tokens = PersonalAccessToken::query()->where('tokenable_type', (new CourierAccount)->getMorphClass())->delete();
            $accounts = CourierAccount::query()->whereIn('id', $ids)->whereNotNull('current_token_id')->count();
            CourierAccount::query()->whereIn('id', $ids)->update([
                'current_token_id' => null,
                'session_generation' => DB::raw('session_generation + 1'),
                'updated_at' => now(),
            ]);

            return [$tokens, $accounts];
        });

        $this->info("Signed out {$accounts} Courier session(s); deleted {$tokens} token(s). Accounts and history were kept.");

        return self::SUCCESS;
    }
}
