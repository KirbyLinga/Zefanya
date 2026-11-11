<?php

namespace App\Services\Seller;

use App\Models\Seller\Seller;
use App\Models\Seller\SellerDebt;
use App\Models\Seller\SellerPayoutRequest;
use App\Models\Seller\SellerSettlement;
use App\Models\Seller\StoreSale;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A seller's money movements with Zefanya, read from existing records, with a
 * running balance of earnings Zefanya holds for them:
 *
 *   + an order's net once it is available (cash confirmed, order completed)
 *   − a paid withdrawal
 *
 * Read only. Pending earnings, open withdrawal requests, rejected or cancelled
 * requests, returned orders and debts are listed but do not change the
 * balance. A debt is repaid from a withdrawal, shown on that withdrawal.
 */
class SellerTransactionService
{
    public function __construct(private readonly SellerPayoutRequestService $payouts) {}

    /** @return Collection<int, array<string, mixed>> Newest first, with balanceAfterMinor. */
    public function for(Seller $seller): Collection
    {
        $rows = collect();
        $available = array_flip($this->payouts->availableSellerOrderIds($seller));

        SellerSettlement::query()->where('seller_id', $seller->getKey())
            ->whereNotNull('financials_finalized_at')->where('seller_payout_minor', '>', 0)
            ->with(['sellerOrder:id,tracking_number', 'codRemittance:id,zefanya_received_at'])->get()
            ->each(function (SellerSettlement $settlement) use ($rows, $available): void {
                [$counts, $status, $tone] = match (true) {
                    $settlement->status === SellerSettlement::RELEASED => [false, 'Returned', 'rose'],
                    $settlement->status === SellerSettlement::PAID || (int) $settlement->paid_amount_minor > 0 => [true, 'Paid out', 'emerald'],
                    isset($available[(int) $settlement->seller_order_id]) => [true, 'Available', 'emerald'],
                    default => [false, 'Pending', 'amber'],
                };
                $at = $settlement->codRemittance?->zefanya_received_at ?? $settlement->financials_finalized_at;
                $rows->push($this->row($at, 'Order earnings', $settlement->sellerOrder?->tracking_number ?? 'Order #'.$settlement->seller_order_id,
                    null, (int) $settlement->seller_payout_minor, $counts, $status, $tone));
            });

        SellerPayoutRequest::query()->where('seller_id', $seller->getKey())->get()
            ->each(function (SellerPayoutRequest $request) use ($rows): void {
                $debt = (int) $request->debt_deducted_minor;
                $detail = $debt > 0
                    ? self::peso((int) $request->amount_minor - $debt).' sent to you · '.self::peso($debt).' repaid debt'
                    : null;
                [$at, $counts, $status, $tone] = match ($request->status) {
                    SellerPayoutRequest::PAID => [$request->paid_at, true, 'Paid', 'emerald'],
                    SellerPayoutRequest::PENDING => [$request->created_at, false, 'Requested', 'sky'],
                    SellerPayoutRequest::REJECTED => [$request->rejected_at ?? $request->updated_at, false, 'Rejected', 'rose'],
                    default => [$request->cancelled_at ?? $request->updated_at, false, 'Cancelled', 'neutral'],
                };
                $rows->push($this->row($at, 'Withdrawal', $request->payout_reference ?: 'Request #'.$request->id,
                    $detail, -(int) $request->amount_minor, $counts, $status, $tone));
            });

        SellerDebt::query()->where('seller_id', $seller->getKey())->with('sellerOrder:id,tracking_number')->get()
            ->each(fn (SellerDebt $debt) => $rows->push($this->row($debt->created_at, 'Debt to Zefanya',
                $debt->sellerOrder?->tracking_number ?? 'Debt #'.$debt->id, 'Repaid from your next withdrawal', -(int) $debt->amount_minor, false,
                (int) $debt->settled_minor >= (int) $debt->amount_minor ? 'Settled' : 'Open · '.self::peso((int) $debt->amount_minor - (int) $debt->settled_minor).' left',
                (int) $debt->settled_minor >= (int) $debt->amount_minor ? 'emerald' : 'amber')));

        return self::withBalance($rows);
    }

    public function paginate(Seller $seller, int $perPage = 20): LengthAwarePaginator
    {
        $rows = $this->for($seller);
        $page = LengthAwarePaginator::resolveCurrentPage('transactions_page');

        return (new LengthAwarePaginator($rows->forPage($page, $perPage)->values(), $rows->count(), $perPage, $page, [
            'path' => LengthAwarePaginator::resolveCurrentPath(),
            'pageName' => 'transactions_page',
        ]))->withQueryString();
    }

    /**
     * Oldest first, adds the running balance of rows that count, then newest first.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private static function withBalance(Collection $rows): Collection
    {
        $balance = 0;

        return $rows->sortBy([['sortAt', 'asc'], ['seq', 'asc']])->values()
            ->map(function (array $row) use (&$balance): array {
                if ($row['counts']) {
                    $balance += $row['amountMinor'];
                }

                return $row + ['balanceAfterMinor' => $balance];
            })
            ->reverse()->values();
    }

    public static function peso(int $minor): string
    {
        return '₱'.number_format($minor / 100, 2);
    }

    /** @return array<string, mixed> */
    private function row(?Carbon $at, string $type, string $reference, ?string $detail, int $amountMinor, bool $counts, string $status, string $tone): array
    {
        static $seq = 0;
        $at ??= Carbon::createFromTimestamp(0);

        return [
            'at' => $at->copy()->timezone(StoreSale::TIMEZONE),
            'sortAt' => $at->getTimestamp(),
            'seq' => $seq++,
            'type' => $type,
            'reference' => $reference,
            'detail' => $detail,
            'amountMinor' => $amountMinor,
            'counts' => $counts,
            'status' => $status,
            'tone' => $tone,
        ];
    }
}
