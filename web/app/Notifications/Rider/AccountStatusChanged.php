<?php

namespace App\Notifications\Rider;

use App\Models\Rider;

/**
 * Logistics suspended, deactivated or reactivated this rider.
 *
 * Stored even when the rider is suspended: the `rider.active` middleware
 * blocks every operational page (and therefore every notification link)
 * until Logistics reactivates the account.
 */
class AccountStatusChanged extends RiderNotification
{
    /** @param  'suspended'|'deactivated'|'active'  $status */
    public function __construct(
        public Rider $rider,
        public string $status,
        public ?string $reason = null,
    ) {}

    public function dedupeKey(): string
    {
        return 'account-status:'.$this->rider->id.':'.$this->status.':'
            .($this->rider->status_changed_at?->getTimestamp() ?? now()->getTimestamp());
    }

    /** @return array<string, mixed> */
    protected function payload(): array
    {
        $message = match ($this->status) {
            Rider::STATUS_SUSPENDED => 'Your rider account was suspended by your Logistics company. You cannot open operational pages until it is reactivated.',
            Rider::STATUS_DEACTIVATED => 'Your rider account was deactivated by your Logistics company.',
            Rider::STATUS_ACTIVE => 'Your rider account was reactivated. You can open your assignments again.',
            default => 'Your rider account status changed to '.str_replace('_', ' ', $this->status).'.',
        };

        if ($this->status !== Rider::STATUS_ACTIVE && filled($this->reason)) {
            $message .= ' Reason: '.$this->reason;
        }

        return [
            'type' => 'rider_account_status_changed',
            'title' => match ($this->status) {
                Rider::STATUS_SUSPENDED => 'Account suspended',
                Rider::STATUS_DEACTIVATED => 'Account deactivated',
                Rider::STATUS_ACTIVE => 'Account reactivated',
                default => 'Account status updated',
            },
            'message' => $message,
            'url' => route('rider.account.show'),
        ];
    }
}
