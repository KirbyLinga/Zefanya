<?php

namespace App\Notifications\Rider;

use App\Models\Shared\SellerOrder;
use Illuminate\Notifications\Notification;

/**
 * Base class for rider-facing dashboard notifications.
 *
 * These notifications are database-only: the Rider panel is the delivery
 * channel and the `rider.active` middleware decides whether the rider may
 * open the linked operational page. Account-status notifications are stored
 * even while the rider is suspended, so they are waiting after reactivation.
 *
 * Every payload carries the same shape consumed by the bell dropdown and the
 * notifications page: type, title, message, url (an internal rider route) and
 * the parcel reference when the event concerns one.
 */
abstract class RiderNotification extends Notification
{
    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Stable key used to suppress duplicate notifications for a retried or
     * replayed operation.
     */
    abstract public function dedupeKey(): string;

    /** @return array<string, mixed> */
    abstract protected function payload(): array;

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return array_merge($this->payload(), [
            'event_key' => $this->dedupeKey(),
        ]);
    }

    /**
     * Parcel label safe to show in a notification preview. It deliberately
     * never includes buyer names, phones or addresses.
     */
    protected function parcelReference(SellerOrder $sellerOrder): string
    {
        $reference = 'Parcel #'.$sellerOrder->id;

        if (filled($sellerOrder->tracking_number)) {
            $reference .= ' ('.$sellerOrder->tracking_number.')';
        }

        return $reference;
    }
}
