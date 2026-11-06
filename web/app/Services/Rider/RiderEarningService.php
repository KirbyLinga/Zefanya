<?php

namespace App\Services\Rider;

use App\Enums\OrderStatus;
use App\Models\Admin\OperationsAuditLog;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Rider\Courier;
use App\Models\Rider\RiderEarning;
use App\Models\Rider\RiderPayout;
use App\Models\Seller\StoreSale;
use App\Models\Shared\SellerOrder;
use App\Notifications\Rider\RiderPayoutRecorded;
use App\Services\Logistics\PickupPolicy;
use App\Services\Logistics\PickupSessionService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Rider pay. Pickup is paid per seller visit (a pickup session): the first parcel
 * collected earns the first rate (₱30 by default) and each further parcel in the
 * same session the additional rate (₱5). Delivery earns ₱50 per parcel. Rates come
 * from Platform Settings and are saved on each earning, so a later change never
 * alters a past one. A failed pickup or delivery attempt earns nothing. The
 * Logistics company pays its own riders and records each payment here.
 */
class RiderEarningService
{
    public function __construct(private readonly RiderNotifier $notifier = new RiderNotifier) {}

    /** Caller holds the SellerOrder lock inside the status transaction. */
    public function creditFor(SellerOrder $order, OrderStatus $to): void
    {
        [$job, $courierId] = match ($to) {
            OrderStatus::PickedUp => [RiderEarning::PICKUP, $order->courier_id],
            OrderStatus::Delivered => [RiderEarning::DELIVERY, $order->delivery_courier_id],
            default => [null, null],
        };

        $courier = $courierId === null ? null : Courier::query()->find($courierId);
        if ($job === null || $courier === null) {
            return;
        }

        if (RiderEarning::query()->where('seller_order_id', $order->getKey())->where('job', $job)->exists()) {
            return;
        }

        $rates = (new PickupPolicy)->rates();
        $amount = $job === RiderEarning::PICKUP ? $rates['first_minor'] : $rates['delivery_minor'];
        $session = null;
        $attributes = [];

        // Pickup pay is per seller visit: the first parcel collected in a session
        // earns the first rate, the others the additional rate. The session row is
        // locked until this transaction ends, so a retry or a concurrent scan
        // cannot be paid as first twice.
        if ($job === RiderEarning::PICKUP) {
            $began = app(PickupSessionService::class)->beginPickup($order, $courier);
            if ($began !== null) {
                $session = $began['session'];
                $amount = $began['first'] ? $session->first_minor : $session->additional_minor;
                $attributes = ['pickup_session_id' => $session->getKey(), 'session_first' => $began['first'] ? 1 : null];
            }
        }

        (new RiderEarning)->forceFill([
            'seller_order_id' => $order->getKey(),
            'job' => $job,
            'courier_id' => $courier->getKey(),
            'logistics_provider_id' => $courier->logistics_provider_id,
            'amount_minor' => $amount,
            'earned_at' => now(),
        ] + $attributes)->save();

        if ($session !== null) {
            app(PickupSessionService::class)->afterCollected($session);
        }
    }

    /** @return array{monthMinor: int, pendingMinor: int, paidMinor: int, monthPickups: int, monthDeliveries: int, pickupMinor: int, deliveryMinor: int} */
    public function summaryFor(Courier $courier): array
    {
        $all = RiderEarning::query()->where('courier_id', $courier->getKey());
        $month = (clone $all)->where('earned_at', '>=', $this->monthStart());

        return [
            'monthMinor' => (int) (clone $month)->sum('amount_minor'),
            'pendingMinor' => (int) (clone $all)->whereNull('rider_payout_id')->sum('amount_minor'),
            'paidMinor' => (int) (clone $all)->whereNotNull('rider_payout_id')->sum('amount_minor'),
            'monthPickups' => (clone $month)->where('job', RiderEarning::PICKUP)->count(),
            'monthDeliveries' => (clone $month)->where('job', RiderEarning::DELIVERY)->count(),
            'pickupMinor' => (int) (clone $all)->where('job', RiderEarning::PICKUP)->sum('amount_minor'),
            'deliveryMinor' => (int) (clone $all)->where('job', RiderEarning::DELIVERY)->sum('amount_minor'),
        ];
    }

    /** @return Collection<int, RiderEarning> */
    public function recentFor(Courier $courier, int $limit = 10): Collection
    {
        return RiderEarning::query()->where('courier_id', $courier->getKey())
            ->with(['sellerOrder:id,tracking_number', 'payout:id,reference,paid_on'])
            ->latest('id')->limit($limit)->get();
    }

    /**
     * Unpaid earnings per Courier, highest first. `throughId` is the newest
     * earning shown, so a job finished after the page loaded is not paid unseen.
     *
     * @return Collection<int, array{courierId: int, courier: string, pickups: int, deliveries: int, amountMinor: int, throughId: int}>
     */
    public function unpaidByCourier(LogisticsProvider $provider): Collection
    {
        return RiderEarning::query()->where('logistics_provider_id', $provider->getKey())
            ->whereNull('rider_payout_id')
            ->selectRaw('courier_id, SUM(CASE WHEN job = ? THEN 1 ELSE 0 END) as pickups', [RiderEarning::PICKUP])
            ->selectRaw('SUM(CASE WHEN job = ? THEN 1 ELSE 0 END) as deliveries, SUM(amount_minor) as amount_minor, MAX(id) as through_id', [RiderEarning::DELIVERY])
            ->groupBy('courier_id')
            ->with('courier:id,name')
            ->get()
            ->map(fn (RiderEarning $row): array => [
                'courierId' => (int) $row->courier_id,
                'courier' => $row->courier?->name ?? 'Unknown rider',
                'pickups' => (int) $row->pickups,
                'deliveries' => (int) $row->deliveries,
                'amountMinor' => (int) $row->amount_minor,
                'throughId' => (int) $row->through_id,
            ])
            ->sortByDesc('amountMinor')
            ->values();
    }

    public function paidThisMonthMinor(LogisticsProvider $provider): int
    {
        return (int) RiderPayout::query()->where('logistics_provider_id', $provider->getKey())
            ->whereDoesntHave('reversal')
            ->where('paid_on', '>=', Carbon::now(StoreSale::TIMEZONE)->startOfMonth()->toDateString())
            ->sum('amount_minor');
    }

    /**
     * Record that the company paid a rider every unpaid earning up to `throughId`
     * in one batch. An earning can be in one payout only.
     */
    public function payCourier(LogisticsProvider $provider, Courier $courier, int $throughId, int $expectedMinor, string $reference, string $paidOn): RiderPayout
    {
        abort_unless((int) $courier->logistics_provider_id === (int) $provider->getKey(), 404);
        $reference = trim($reference);

        return DB::transaction(function () use ($provider, $courier, $throughId, $expectedMinor, $reference, $paidOn): RiderPayout {
            // Serializes payouts for one rider so a double submit cannot pay twice.
            $courier = Courier::query()->whereKey($courier->getKey())->lockForUpdate()->firstOrFail();

            $earnings = RiderEarning::query()->where('courier_id', $courier->getKey())
                ->where('logistics_provider_id', $provider->getKey())
                ->whereNull('rider_payout_id')
                ->where('id', '<=', $throughId)
                ->orderBy('id')->lockForUpdate()->get();
            if ($earnings->isEmpty()) {
                throw ValidationException::withMessages(['rider' => 'This rider has no unpaid earnings. They may already have been paid.']);
            }

            $amount = (int) $earnings->sum('amount_minor');
            if ($amount !== $expectedMinor) {
                throw ValidationException::withMessages(['rider' => 'The amount owed changed. Check the new amount before recording the payment.']);
            }

            $payout = new RiderPayout;
            $payout->forceFill([
                'logistics_provider_id' => $provider->getKey(),
                'courier_id' => $courier->getKey(),
                'earning_count' => $earnings->count(),
                'amount_minor' => $amount,
                'reference' => $reference,
                'paid_on' => $paidOn,
                'status' => RiderPayout::PAID,
            ])->save();

            RiderEarning::query()->whereKey($earnings->modelKeys())->whereNull('rider_payout_id')
                ->update(['rider_payout_id' => $payout->getKey()]);

            OperationsAuditLog::record(
                $provider, 'rider_payout_paid', $payout, 'Rider payout #'.$payout->getKey().' · '.$courier->name,
                ['status' => null, 'amount' => null],
                ['status' => 'Paid', 'amount' => '₱'.number_format($amount / 100, 2), 'reference' => $reference, 'paid_on' => $paidOn],
            );

            $this->notifyAfterCommit($payout);

            return $payout;
        }, 3);
    }

    /** Reverse the record, never the actual cash transfer or the earning itself. */
    public function reversePayout(LogisticsProvider $provider, RiderPayout $payout, string $reason): void
    {
        abort_unless((int) $payout->logistics_provider_id === (int) $provider->getKey(), 404);
        $reason = trim($reason);
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'min:10', 'max:500']])->validate();

        DB::transaction(function () use ($provider, $payout, $reason): void {
            // Same lock order as payment: courier, then allocations.
            Courier::query()->whereKey($payout->courier_id)->lockForUpdate()->firstOrFail();
            $locked = RiderPayout::query()->whereKey($payout->getKey())
                ->where('logistics_provider_id', $provider->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->reversal()->exists()) {
                throw ValidationException::withMessages(['payout' => 'This payment record has already been reversed.']);
            }
            $earnings = RiderEarning::query()->where('rider_payout_id', $locked->getKey())
                ->where('logistics_provider_id', $provider->getKey())
                ->orderBy('id')->lockForUpdate()->get();
            if ($earnings->count() !== $locked->earning_count || (int) $earnings->sum('amount_minor') !== $locked->amount_minor) {
                throw ValidationException::withMessages(['payout' => 'The payment allocations changed. Review this payment before correcting it.']);
            }

            OperationsAuditLog::record(
                $provider, 'rider_payout_reversed', $locked, 'Rider payout #'.$locked->getKey(),
                ['status' => 'Paid', 'amount_minor' => $locked->amount_minor, 'reference' => $locked->reference,
                    'paid_on' => $locked->paid_on->toDateString(), 'earning_ids' => implode(', ', $earnings->modelKeys())],
                ['status' => 'Reversed', 'applied_amount_minor' => 0], $reason,
            );
            RiderEarning::query()->whereKey($earnings->modelKeys())->where('rider_payout_id', $locked->getKey())
                ->update(['rider_payout_id' => null]);
            $this->notifyAfterCommit($locked, reversed: true);
        }, 3);
    }

    private function notifyAfterCommit(RiderPayout $payout, bool $reversed = false): void
    {
        DB::afterCommit(function () use ($payout, $reversed): void {
            try {
                $this->notifier->send($payout->courier?->rider, new RiderPayoutRecorded($payout, $reversed));
            } catch (\Throwable $exception) {
                // Notification delivery must never undo a committed payment.
                report($exception);
            }
        });
    }

    private function monthStart(): Carbon
    {
        return Carbon::now(StoreSale::TIMEZONE)->startOfMonth()->utc();
    }
}
