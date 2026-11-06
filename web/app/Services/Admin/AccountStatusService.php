<?php

namespace App\Services\Admin;

use App\Enums\LogisticsProviderStatus;
use App\Models\Admin\AccountActionHistory;
use App\Models\Admin\Admin;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Seller\Seller;
use Illuminate\Support\Facades\DB;

class AccountStatusService
{
    public const MAX_RIDER_WIND_DOWN_HOURS = 72;

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

            // Any rider wind-down belongs to one suspension; it never carries over.
            if ($current instanceof LogisticsProvider && $current->rider_wind_down_until !== null) {
                $current->forceFill(['rider_wind_down_until' => null])->save();
            }

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

    /**
     * Let a suspended provider's riders finish parcels already out for
     * delivery until the window ends (0 hours ends it now). No new pickups,
     * starts or assignments are allowed. Returns false unless the provider is
     * suspended.
     */
    public function setRiderWindDown(LogisticsProvider $provider, Admin $admin, int $hours, string $reason): bool
    {
        if ($hours < 0 || $hours > self::MAX_RIDER_WIND_DOWN_HOURS) {
            throw new \InvalidArgumentException('Unsupported rider wind-down length.');
        }

        return DB::transaction(function () use ($provider, $admin, $hours, $reason): bool {
            $current = LogisticsProvider::query()->lockForUpdate()->findOrFail($provider->getKey());

            if ($current->status !== LogisticsProviderStatus::Suspended) {
                return false;
            }

            $current->forceFill(['rider_wind_down_until' => $hours === 0 ? null : now()->addHours($hours)])->save();

            (new AccountActionHistory)->forceFill([
                'admin_id' => $admin->id,
                'admin_name' => $admin->name,
                'admin_email' => $admin->email,
                'account_type' => 'logistics',
                'account_id' => $current->id,
                'account_name' => $current->fullName(),
                'account_email' => $current->email,
                'action' => $hours === 0 ? 'wind_down_ended' : 'rider_wind_down',
                'from_status' => 'suspended',
                'to_status' => 'suspended',
                'reason' => $reason,
            ])->save();

            return true;
        });
    }
}
