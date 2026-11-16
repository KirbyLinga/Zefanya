<?php

namespace App\Services\Admin;

use App\Enums\OrderStatus;
use App\Models\Logistics\ParcelReturn;
use App\Models\Shared\SellerOrder;
use App\Models\Shared\StatusHistory;
use Illuminate\Support\Collection;

/**
 * Read-only history of a buyer's earlier return requests, for Admin review of
 * a return case. It reports stored outcomes only: it never flags, scores or
 * restricts a buyer, and it never reads the free-text return reasons.
 *
 * The unit is the Seller shipment (seller_orders row): a buyer request needs a
 * delivered shipment and each shipment holds at most one return, so requests
 * and delivered shipments are directly comparable.
 */
class BuyerReturnHistoryService
{
    /** Earlier requests listed as links; the counts always cover all of them. */
    public const RECENT_LIMIT = 5;

    /**
     * Histories for the buyer-requested returns in $returns, keyed by return id.
     * Each history excludes the return it is shown on. Three queries in total.
     *
     * @param  iterable<ParcelReturn>  $returns  with sellerOrder.order loaded
     * @return array<int, array<string, mixed>>
     */
    public function forReturns(iterable $returns): array
    {
        $cases = collect($returns)->filter(fn (ParcelReturn $record): bool => $record->isBuyerRequest()
            && $record->sellerOrder?->order?->buyer_id !== null);
        if ($cases->isEmpty()) {
            return [];
        }

        $buyerIds = $cases->map(fn (ParcelReturn $record): int => (int) $record->sellerOrder->order->buyer_id)->unique()->values();
        $delivered = $this->deliveredShipmentsByBuyer($buyerIds);
        $deliveredCases = StatusHistory::query()
            ->whereIn('seller_order_id', $cases->pluck('seller_order_id'))
            ->where('to_status', OrderStatus::Delivered->value)
            ->distinct()->pluck('seller_order_id')->map(fn ($id): int => (int) $id)->flip();
        $requests = $this->buyerRequests($buyerIds);

        $histories = [];
        foreach ($cases as $record) {
            $buyerId = (int) $record->sellerOrder->order->buyer_id;
            $others = ($requests[$buyerId] ?? collect())->reject(fn (ParcelReturn $row): bool => (int) $row->id === (int) $record->id)->values();
            $otherDelivered = max(0, (int) ($delivered[$buyerId] ?? 0) - ($deliveredCases->has((int) $record->seller_order_id) ? 1 : 0));
            $histories[(int) $record->id] = $this->summarize($others, $otherDelivered);
        }

        return $histories;
    }

    /** @return array<string, mixed> */
    private function summarize(Collection $others, int $otherDelivered): array
    {
        $receiptTracked = ParcelReturn::recoveryAvailable();
        $receiptDisputed = $receiptTracked ? $others->filter(fn (ParcelReturn $row): bool => $row->receipt_disputed_at !== null) : collect();
        $requestCount = $others->count();

        return [
            'delivered_shipments' => $otherDelivered,
            'return_requests' => $requestCount,
            // Null when there is nothing to divide by; the view says so instead of showing 0%.
            'request_rate' => $otherDelivered > 0 ? round($requestCount / $otherDelivered * 100, 1) : null,
            'seller_rejected' => $others->where('seller_decision', ParcelReturn::REJECTED)->count(),
            'dispute_upheld' => $others->where('dispute_outcome', ParcelReturn::DISPUTE_UPHELD)->count(),
            'dispute_overturned' => $others->where('dispute_outcome', ParcelReturn::DISPUTE_OVERTURNED)->count(),
            'dispute_open' => $others->filter(fn (ParcelReturn $row): bool => $row->inDispute())->count(),
            'receipt_tracked' => $receiptTracked,
            'receipt_disputes' => $receiptDisputed->count(),
            'receipt_disputes_open' => $receiptDisputed->filter(fn (ParcelReturn $row): bool => $row->receiptDisputed())->count(),
            'recent' => $others->take(self::RECENT_LIMIT),
        ];
    }

    /**
     * Shipments per buyer that ever reached Delivered, from the append-only
     * status log, so later Completed or Returned shipments still count.
     *
     * @return Collection<int, int>
     */
    private function deliveredShipmentsByBuyer(Collection $buyerIds): Collection
    {
        $histories = (new StatusHistory)->getTable();

        return SellerOrder::query()->toBase()
            ->join('orders', 'orders.id', '=', 'seller_orders.order_id')
            ->whereIn('orders.buyer_id', $buyerIds)
            ->whereExists(fn ($query) => $query->selectRaw('1')->from($histories)
                ->whereColumn($histories.'.seller_order_id', 'seller_orders.id')
                ->where($histories.'.to_status', OrderStatus::Delivered->value))
            ->groupBy('orders.buyer_id')
            ->selectRaw('orders.buyer_id as buyer_id, count(distinct seller_orders.id) as total')
            ->pluck('total', 'buyer_id')
            ->mapWithKeys(fn ($total, $buyerId): array => [(int) $buyerId => (int) $total]);
    }

    /**
     * Every buyer-initiated return for these buyers, newest first, with only
     * the columns the summary needs.
     *
     * @return Collection<int, Collection<int, ParcelReturn>>
     */
    private function buyerRequests(Collection $buyerIds): Collection
    {
        $columns = ['parcel_returns.id', 'parcel_returns.seller_order_id', 'parcel_returns.status', 'parcel_returns.requested_at',
            'parcel_returns.seller_response_due_at', 'parcel_returns.seller_decision', 'parcel_returns.dispute_outcome',
            'orders.buyer_id as history_buyer_id', 'seller_orders.tracking_number as history_tracking_number'];
        if (ParcelReturn::recoveryAvailable()) {
            $columns[] = 'parcel_returns.receipt_disputed_at';
        }

        return ParcelReturn::query()
            ->join('seller_orders', 'seller_orders.id', '=', 'parcel_returns.seller_order_id')
            ->join('orders', 'orders.id', '=', 'seller_orders.order_id')
            ->whereIn('orders.buyer_id', $buyerIds)
            ->whereNotNull('parcel_returns.seller_response_due_at')
            ->orderByDesc('parcel_returns.id')
            ->get($columns)
            ->groupBy(fn (ParcelReturn $row): int => (int) $row->history_buyer_id);
    }
}
