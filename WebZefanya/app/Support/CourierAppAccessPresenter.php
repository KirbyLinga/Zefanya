<?php

namespace App\Support;

use App\Models\Admin\AccountActionHistory;
use App\Models\Admin\OperationsAuditLog;
use App\Models\Logistics\CourierAccount;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\TransferCourier;
use App\Models\Seller\StoreSale;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What the existing Logistics Couriers page shows about each Courier's mobile
 * app access: one state, a plain reason why the Courier can or cannot use the
 * app, and the issue/revoke history from the immutable operations audit log.
 * Read-only; never exposes a code, password or token.
 */
final class CourierAppAccessPresenter
{
    /**
     * @param  Collection<int, TransferCourier>  $couriers
     * @return array<int, array{state: string, label: string, reason: string, history: list<array{when: string, action: string, reason: ?string}>}>
     */
    public static function forProvider(LogisticsProvider $provider, Collection $couriers): array
    {
        if (! CourierAccess::schemaReady() || $couriers->isEmpty()) {
            return [];
        }

        $accounts = CourierAccount::query()->whereIn('transfer_courier_id', $couriers->pluck('id'))->get()->keyBy('transfer_courier_id');
        $logs = OperationsAuditLog::query()
            ->where('entity_type', 'transfer_courier')->whereIn('entity_id', $couriers->pluck('id'))
            ->whereIn('action', ['courier_app_access_issued', 'courier_app_access_revoked'])
            ->latest('id')->limit(500)->get()->groupBy('entity_id');
        // The company's own latest suspension (it ends every Courier session).
        $lastSuspension = AccountActionHistory::query()
            ->where('account_type', 'logistics')->where('account_id', $provider->id)->where('action', 'suspended')
            ->latest('id')->value('created_at');

        $out = [];
        foreach ($couriers as $courier) {
            $account = $accounts[$courier->id] ?? null;
            [$state, $label, $reason] = self::describe($courier, $account, $lastSuspension);
            $out[$courier->id] = [
                'state' => $state,
                'label' => $label,
                'reason' => $reason,
                'history' => ($logs[$courier->id] ?? collect())->take(10)->map(fn (OperationsAuditLog $log): array => [
                    'when' => self::when($log->created_at),
                    'action' => $log->action === 'courier_app_access_issued'
                        ? (($log->old_values['app_access'] ?? 'none') === 'none' ? 'Access code issued' : 'New access code issued')
                        : 'App access revoked',
                    'reason' => $log->reason,
                ])->values()->all(),
            ];
        }

        return $out;
    }

    /** @return array{0: string, 1: string, 2: string} */
    private static function describe(TransferCourier $courier, ?CourierAccount $account, mixed $lastSuspension): array
    {
        if ($account === null) {
            return ['none', 'No app access', 'This Courier has not been given app access.'];
        }

        if ($account->status === CourierAccount::REVOKED) {
            return ['revoked', 'Revoked', 'Revoked '.self::when($account->revoked_at).($account->revoked_reason ? ': '.$account->revoked_reason : '').'. Issue a new code to give access again.'];
        }

        if ($account->status === CourierAccount::PENDING) {
            $usedUp = $account->activation_code_hash === null
                || $account->activation_attempts >= (int) config('courier.activation_max_attempts', 5);
            if ($usedUp) {
                return ['pending', 'Code used up', 'The access code was used up by wrong tries. Issue a new code.'];
            }
            if ($account->activation_expires_at === null || $account->activation_expires_at->isPast()) {
                return ['pending', 'Code expired', 'The access code expired '.self::when($account->activation_expires_at).'. Issue a new code.'];
            }

            return ['pending', 'Code issued', 'Waiting for the Courier to activate the app. The code expires '.self::when($account->activation_expires_at).'.'];
        }

        if (! $courier->isActive()) {
            return ['suspended', 'Courier inactive', 'This Courier is inactive, so the app cannot be used.'];
        }

        $signedIn = $account->current_token_id !== null;
        if (! $signedIn && $lastSuspension !== null && ($account->last_login_at === null || $account->last_login_at->lte($lastSuspension))) {
            return ['suspended', 'Signed out (company suspended)', 'Your company\'s suspension on '.self::when($lastSuspension).' signed this Courier out. They can sign in again now.'];
        }

        return $signedIn
            ? ['active', 'Active', 'Signed in to the Courier app. Last sign-in '.self::when($account->last_login_at).'.']
            : ['active', 'Active (signed out)', 'App access is active; the Courier is not signed in right now.'];
    }

    private static function when(mixed $value): string
    {
        if ($value === null) {
            return '—';
        }

        return Carbon::parse($value)->timezone(StoreSale::TIMEZONE)->format('M j, Y g:i A');
    }
}
