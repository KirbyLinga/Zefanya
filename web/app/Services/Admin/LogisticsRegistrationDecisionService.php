<?php

namespace App\Services\Admin;

use App\Enums\LogisticsProviderStatus;
use App\Models\Admin\AccountActionHistory;
use App\Models\Admin\Admin;
use App\Models\Logistics\LogisticsProvider;
use App\Notifications\Logistics\LogisticsRegistrationDecision;
use App\Services\Logistics\ProviderProvinceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class LogisticsRegistrationDecisionService
{
    public function __construct(private ProviderProvinceService $provinces) {}

    public function decide(LogisticsProvider $provider, Admin $admin, string $decision, mixed $reason = null): bool
    {
        if (! in_array($decision, ['approved', 'rejected'], true)) {
            throw new \InvalidArgumentException('Unsupported Logistics registration decision.');
        }

        return DB::transaction(function () use ($provider, $admin, $decision, $reason): bool {
            $current = $provider->newQuery()->whereKey($provider->getKey())->lockForUpdate()->firstOrFail();

            if ($current->status !== LogisticsProviderStatus::Pending) {
                return false;
            }

            $rejectionReason = $decision === 'rejected'
                ? Validator::make(['rejection_reason' => $reason], ['rejection_reason' => ['required', 'string', 'max:500']])->validate()['rejection_reason']
                : null;

            if ($decision === 'approved') {
                $this->provinces->verifyProvinceOnApproval($current, $admin);
            }

            $current->forceFill([
                'status' => $decision,
                'approved_by' => $admin->getKey(),
                'approved_at' => now(),
                'rejection_reason' => $rejectionReason,
            ])->save();

            (new AccountActionHistory)->forceFill([
                'admin_id' => $admin->getKey(),
                'admin_name' => $admin->name,
                'admin_email' => $admin->email,
                'account_type' => 'logistics',
                'account_id' => $current->getKey(),
                'account_name' => $current->fullName(),
                'account_email' => $current->email,
                'action' => $decision,
                'from_status' => 'pending',
                'to_status' => $decision,
                'reason' => $rejectionReason ?? 'Logistics registration approved.',
            ])->save();

            // Also wait for any enclosing transaction. A rollback must not send
            // a decision that was never committed, and a losing request sends none.
            DB::afterCommit(fn () => $current->notify(new LogisticsRegistrationDecision($decision, $rejectionReason)));

            return true;
        });
    }
}
