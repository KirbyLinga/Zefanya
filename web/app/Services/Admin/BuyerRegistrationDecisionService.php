<?php

namespace App\Services\Admin;

use App\Models\Admin\AccountActionHistory;
use App\Models\Admin\Admin;
use App\Models\Buyer\Buyer;
use App\Notifications\Buyer\BuyerRegistrationDecision;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class BuyerRegistrationDecisionService
{
    public function decide(Buyer $buyer, Admin $admin, string $decision, mixed $reason = null): bool
    {
        if (! in_array($decision, ['approved', 'rejected'], true)) {
            throw new \InvalidArgumentException('Unsupported Buyer registration decision.');
        }

        return DB::transaction(function () use ($buyer, $admin, $decision, $reason): bool {
            $current = $buyer->newQuery()->whereKey($buyer->getKey())->lockForUpdate()->firstOrFail();

            if ($current->status !== 'pending') {
                return false;
            }

            $rejectionReason = $decision === 'rejected'
                ? Validator::make(['rejection_reason' => $reason], ['rejection_reason' => ['required', 'string', 'max:500']])->validate()['rejection_reason']
                : null;

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
                'account_type' => 'buyer',
                'account_id' => $current->getKey(),
                'account_name' => $current->fullName(),
                'account_email' => $current->email,
                'action' => $decision,
                'from_status' => 'pending',
                'to_status' => $decision,
                'reason' => $rejectionReason ?? 'Buyer registration approved.',
            ])->save();

            // Also wait for any enclosing transaction. A rollback must not send
            // a decision that was never committed, and a losing request sends none.
            DB::afterCommit(fn () => $current->notify(new BuyerRegistrationDecision($decision, $rejectionReason)));

            return true;
        });
    }
}
