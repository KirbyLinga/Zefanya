<?php

namespace App\Services\Logistics;

use App\Models\Admin\OperationsAuditLog;
use App\Models\Logistics\CodRemittance;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Shared\SellerOrder;
use App\Services\Seller\SellerSettlementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CodRemittanceService
{
    public function __construct(
        private readonly SellerSettlementService $settlements = new SellerSettlementService,
    ) {}

    /** Return false for an already-confirmed receipt without changing its audit. */
    public function confirmReceipt(
        CodRemittance $remittance,
        LogisticsProvider $provider,
        int $receivedAmountMinor,
    ): bool {
        return DB::transaction(function () use ($remittance, $provider, $receivedAmountMinor): bool {
            // Match completion and payout: SellerOrder -> Payment -> Remittance -> Settlement.
            $order = SellerOrder::query()->whereKey($remittance->seller_order_id)->lockForUpdate()->firstOrFail();
            $payment = $order->payment()->lockForUpdate()->firstOrFail();
            $locked = CodRemittance::query()->whereKey($remittance->getKey())
                ->where('seller_order_id', $order->getKey())
                ->where('payment_id', $payment->getKey())->lockForUpdate()->firstOrFail();

            abort_unless(
                (int) $locked->logistics_provider_id === (int) $provider->getKey(),
                403,
                'This remittance belongs to another Logistics provider.',
            );

            if ($receivedAmountMinor < 0 || $receivedAmountMinor !== $locked->amount_minor) {
                throw ValidationException::withMessages([
                    'received_amount' => 'The cash received must equal the exact amount due.',
                ]);
            }

            if ($locked->status === CodRemittance::REMITTED_TO_LOGISTICS) {
                return false;
            }

            if ($locked->status !== CodRemittance::PENDING_REMITTANCE) {
                throw ValidationException::withMessages([
                    'remittance' => 'Only pending remittances can be confirmed.',
                ]);
            }

            $previousStatus = $locked->status;
            $locked->forceFill([
                'status' => CodRemittance::REMITTED_TO_LOGISTICS,
                'confirmed_by_id' => $provider->getKey(),
                'confirmed_at' => now(),
                'received_amount_minor' => $receivedAmountMinor,
            ])->save();

            OperationsAuditLog::record(
                $provider, 'remittance_confirmed', $locked, 'Parcel #'.$order->getKey(),
                ['status' => $previousStatus, 'cash_received' => null],
                ['status' => $locked->status, 'cash_received' => '₱'.number_format($receivedAmountMinor / 100, 2)],
            );

            $this->settlements->prepare($locked);

            return true;
        }, 3);
    }
}
