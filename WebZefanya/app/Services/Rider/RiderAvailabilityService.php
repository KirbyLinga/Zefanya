<?php

namespace App\Services\Rider;

use App\Enums\OrderStatus;
use App\Models\Rider\Rider;
use App\Models\Shared\SellerOrder;
use App\Notifications\Logistics\RiderAssignmentsNeedReassignment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The Rider's online/offline switch.
 *
 * Going offline flags (never moves or unassigns) the Rider's jobs that have
 * not started yet, so Logistics sees them under "Needs attention" and can
 * reassign them:
 *   - pickups reserved but not collected (READY_FOR_PICKUP, no handover), and
 *   - deliveries reserved or assigned but not out yet (SORTED, ASSIGNED_TO_RIDER).
 * Parcels already in the Rider's hands (PICKED_UP, OUT_FOR_DELIVERY) stay with
 * them to finish. Parcels already flagged for another reason are left alone.
 *
 * Going back online clears only the flags this switch raised, on jobs still
 * held by the same Rider.
 */
class RiderAvailabilityService
{
    public const FLAG_PREFIX = 'Rider offline:';

    /** @return array{changed: bool, flagged: int, cleared: int} */
    public function setOnline(Rider $rider, bool $online): array
    {
        $result = DB::transaction(function () use ($rider, $online): array {
            $row = Rider::query()->whereKey($rider->id)->lockForUpdate()->firstOrFail();
            if ($row->isOnline() === $online) {
                return ['changed' => false, 'flagged' => 0, 'cleared' => 0, 'rider' => $row];
            }
            $row->forceFill(['is_online' => $online, 'online_changed_at' => now()])->save();

            $courier = $row->courier()->first();
            if ($courier === null) {
                return ['changed' => true, 'flagged' => 0, 'cleared' => 0, 'rider' => $row];
            }

            if (! $online) {
                $flagged = $this->pendingJobs($courier->id)
                    ->where('needs_reassignment', false)
                    ->update([
                        'needs_reassignment' => true,
                        'reassignment_reason' => self::FLAG_PREFIX.' '.$row->fullName()
                            .' went offline before starting this job. Reassign it or wait until the Rider is back online.',
                        'reassignment_flagged_at' => now(),
                    ]);

                return ['changed' => true, 'flagged' => $flagged, 'cleared' => 0, 'rider' => $row];
            }

            $cleared = $this->pendingJobs($courier->id)
                ->where('needs_reassignment', true)
                ->where('reassignment_reason', 'like', self::FLAG_PREFIX.'%')
                ->update([
                    'needs_reassignment' => false,
                    'reassignment_reason' => null,
                    'reassignment_flagged_at' => null,
                ]);

            return ['changed' => true, 'flagged' => 0, 'cleared' => $cleared, 'rider' => $row];
        }, 3);

        // Keep the caller's model (e.g. the signed-in guard user) in step.
        if ($result['changed']) {
            $rider->refresh();
        }

        // Tell Logistics only after the flags are committed.
        if ($result['flagged'] > 0) {
            $result['rider']->provider?->notify(new RiderAssignmentsNeedReassignment($result['rider'], $result['flagged']));
        }
        unset($result['rider']);

        return $result;
    }

    /** Jobs held by this Courier that have not physically started. */
    private function pendingJobs(int $courierId): Builder
    {
        return SellerOrder::query()->where(function (Builder $query) use ($courierId): void {
            $query->where(function (Builder $pickup) use ($courierId): void {
                $pickup->where('courier_id', $courierId)
                    ->where('status', OrderStatus::ReadyForPickup)
                    ->whereNull('handover_at');
            })->orWhere(function (Builder $delivery) use ($courierId): void {
                $delivery->where('delivery_courier_id', $courierId)
                    ->whereIn('status', [OrderStatus::Sorted, OrderStatus::AssignedToRider]);
            });
        });
    }
}
