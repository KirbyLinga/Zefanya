<?php

namespace App\Services\Buyer;

use App\Enums\OrderActor;
use App\Enums\OrderStatus;
use App\Enums\ParcelIncidentStatus;
use App\Enums\ParcelReturnStatus;
use App\Models\Logistics\ParcelHandoffException;
use App\Models\Shared\SellerOrder;
use App\Services\Seller\SellerOrderStatusService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class AutomaticBuyerReceiptService
{
    public const WAIT_HOURS = 168;

    public function __construct(
        private readonly SellerOrderStatusService $statuses = new SellerOrderStatusService,
    ) {}

    public static function eligibleOrders(CarbonInterface $cutoff): Builder
    {
        return SellerOrder::query()
            ->where('status', OrderStatus::Delivered)
            // A rejected return request no longer holds the order back.
            ->whereDoesntHave('parcelReturn', fn (Builder $return) => $return->where('status', '!=', ParcelReturnStatus::Rejected->value))
            ->whereDoesntHave('incidentSet', function (Builder $incidents): void {
                $incidents->where('status', ParcelIncidentStatus::Open->value)
                    ->orWhereHas('events', fn (Builder $events) => $events
                        ->where('event', 'resolved')
                        ->whereIn('detail', ['LOST', 'DAMAGED', 'CLOSE_LOST', 'CLOSE_DAMAGED']));
            })
            ->whereDoesntHave('parcelHandoffExceptions', fn (Builder $exceptions) => $exceptions
                ->where('status', ParcelHandoffException::OPEN))
            // The delivery transition timestamp is the authoritative delivery clock.
            ->whereHas('statusHistories', fn (Builder $history) => $history
                ->where('to_status', OrderStatus::Delivered)
                ->where('created_at', '<=', $cutoff))
            // Never use an older delivery event if a more recent/undated one exists.
            ->whereDoesntHave('statusHistories', fn (Builder $history) => $history
                ->where('to_status', OrderStatus::Delivered)
                ->where(fn (Builder $dates) => $dates
                    ->whereNull('created_at')->orWhere('created_at', '>', $cutoff)));
    }

    public function complete(int $sellerOrderId, CarbonInterface $cutoff): bool
    {
        return DB::transaction(function () use ($sellerOrderId, $cutoff): bool {
            $order = SellerOrder::query()->whereKey($sellerOrderId)->lockForUpdate()->first();

            // Recheck after obtaining the lock: Buyer receipt, incidents, or returns
            // may have changed since this record was selected in the batch.
            if ($order === null || $order->status !== OrderStatus::Delivered
                || ! self::eligibleOrders($cutoff)->whereKey($sellerOrderId)->exists()) {
                return false;
            }

            $this->statuses->transition(
                $order,
                OrderStatus::Completed,
                note: 'Automatically completed after seven days without Buyer receipt confirmation.',
                changedBy: 'System',
                actor: OrderActor::System,
                source: 'system',
                expectedFrom: OrderStatus::Delivered,
            );

            return true;
        }, 3);
    }
}
