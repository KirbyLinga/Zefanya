<?php

namespace App\Services\Logistics;

use App\Models\Seller\StoreSale;
use App\Models\Shared\SellerOrder;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Picks the pickup window a parcel is scheduled in. A parcel marked ready is put
 * in the window that is open now (so nothing waits for the window to close), or
 * the next one when too little of the current window is left or the day is over.
 */
class PickupWindowService
{
    public function __construct(private readonly PickupPolicy $policy = new PickupPolicy) {}

    /**
     * @return array{key: string, starts_at: Carbon, ends_at: Carbon} UTC instants
     */
    public function windowFor(?CarbonInterface $now = null): array
    {
        $now = Carbon::instance($now ?? now())->setTimezone(StoreSale::TIMEZONE);
        $lead = (int) config('pickup.min_lead_minutes', 30);
        $windows = $this->policy->windows();

        // A window with no safe time left is skipped; day two always has a fresh one.
        for ($day = 0; $day <= 1; $day++) {
            foreach ($windows as $window) {
                $base = $now->copy()->startOfDay()->addDays($day);
                $starts = $base->copy()->addMinutes($window['start']);
                $ends = $base->copy()->addMinutes($window['end']);
                if ($now->lessThanOrEqualTo($ends->copy()->subMinutes($lead))) {
                    return ['key' => $window['key'], 'starts_at' => $starts->utc(), 'ends_at' => $ends->utc()];
                }
            }
        }

        throw new \RuntimeException('No pickup window is configured.');
    }

    /** Schedule the parcel for the window open now. The caller holds the parcel lock. */
    public function schedule(SellerOrder $parcel, ?CarbonInterface $now = null, bool $missed = false): void
    {
        $window = $this->windowFor($now);
        $parcel->forceFill([
            'pickup_window_key' => $window['key'],
            'pickup_window_starts_at' => $window['starts_at'],
            'pickup_window_ends_at' => $window['ends_at'],
            'pickup_missed_windows' => (int) $parcel->pickup_missed_windows + ($missed ? 1 : 0),
        ])->save();
    }

    /** Schedule a ready parcel that has no window yet (it was marked ready before windows existed). */
    public function ensureScheduled(SellerOrder $parcel): void
    {
        if ($parcel->pickup_window_starts_at === null || $parcel->pickup_window_ends_at === null) {
            $this->schedule($parcel);
        }
    }

    /** "Today, 8:00 AM – 12:00 PM" in Philippine time. */
    public static function label(SellerOrder $parcel): ?string
    {
        if ($parcel->pickup_window_starts_at === null || $parcel->pickup_window_ends_at === null) {
            return null;
        }
        $starts = $parcel->pickup_window_starts_at->copy()->setTimezone(StoreSale::TIMEZONE);
        $ends = $parcel->pickup_window_ends_at->copy()->setTimezone(StoreSale::TIMEZONE);
        $today = Carbon::now(StoreSale::TIMEZONE);
        $day = $starts->isSameDay($today) ? 'Today' : ($starts->isSameDay($today->copy()->addDay()) ? 'Tomorrow' : $starts->format('M j'));

        return $day.', '.$starts->format('g:i A').' – '.$ends->format('g:i A');
    }
}
