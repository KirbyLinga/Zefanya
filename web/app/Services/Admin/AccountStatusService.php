<?php

namespace App\Services\Admin;

use App\Models\Admin\AccountActionHistory;
use App\Models\Admin\Admin;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Seller\Seller;
use Illuminate\Support\Facades\DB;

class AccountStatusService
{
    public function transition(Buyer|Seller|LogisticsProvider $account, Admin $admin, string $to, string $reason): bool
    {
        [$from, $action] = match ($to) {
            'suspended' => ['approved', 'suspended'],
            'approved' => ['suspended', 'reactivated'],
            default => throw new \InvalidArgumentException('Unsupported account status action.'),
        };

        return DB::transaction(function () use ($account, $admin, $from, $to, $action, $reason): bool {
            $current = $account->newQuery()->lockForUpdate()->findOrFail($account->getKey());
            $status = $current->status instanceof \BackedEnum ? $current->status->value : $current->status;

            if ($status !== $from) {
                return false;
            }

            $current->forceFill(['status' => $to])->save();

            (new AccountActionHistory)->forceFill([
                'admin_id' => $admin->id,
                'admin_name' => $admin->name,
                'admin_email' => $admin->email,
                'account_type' => match (true) {
                    $current instanceof Buyer => 'buyer',
                    $current instanceof Seller => 'seller',
                    $current instanceof LogisticsProvider => 'logistics',
                },
                'account_id' => $current->id,
                'account_name' => $current->fullName(),
                'account_email' => $current->email,
                'action' => $action,
                'from_status' => $from,
                'to_status' => $to,
                'reason' => $reason,
            ])->save();

            return true;
        });
    }
}
