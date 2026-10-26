<?php

namespace App\Services;

use App\Models\Rider;
use App\Notifications\Rider\RiderNotification;

/**
 * Single entry point for rider-facing database notifications.
 *
 * Callers must invoke send() AFTER their own write transaction has committed
 * (the assignment and registration services all dispatch at that boundary).
 * The dedupe key stored in the payload suppresses a second notification when
 * the same operation is retried or replayed.
 */
class RiderNotifier
{
    /** @return bool true when a new row was stored, false for a replay or a missing Rider. */
    public function send(?Rider $rider, RiderNotification $notification): bool
    {
        if ($rider === null) {
            return false;
        }

        $duplicate = $rider->notifications()
            ->where('type', $notification::class)
            ->where('data->event_key', $notification->dedupeKey())
            ->exists();

        if ($duplicate) {
            return false;
        }

        $rider->notify($notification);

        return true;
    }
}
