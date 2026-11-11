<?php

namespace App\Services\Logistics;

use App\Exceptions\StaleStateException;
use App\Models\Admin\OperationsAuditLog;
use App\Models\Logistics\CodRemittance;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Rider\Rider;
use App\Models\Shared\SellerOrder;
use App\Services\Seller\SellerSettlementService;
use App\Support\RuntimeSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CodRemittanceService
{
    public function __construct(
        private readonly SellerSettlementService $settlements = new SellerSettlementService,
    ) {}

    /**
     * Record the cash a company actually received from its rider. When less than
     * the full parcel amount arrived, the shortage, the responsible Rider and the
     * reason are stored; the missing money is never marked as received, and no
     * Seller settlement is prepared until it is recovered.
     *
     * Return false for an already-confirmed receipt without changing its audit.
     */
    public function confirmReceipt(
        CodRemittance $remittance,
        LogisticsProvider $provider,
        int $receivedAmountMinor,
        ?int $shortageRiderId = null,
        ?string $shortageReason = null,
    ): bool {
        return DB::transaction(function () use ($remittance, $provider, $receivedAmountMinor, $shortageRiderId, $shortageReason): bool {
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

            if ($receivedAmountMinor < 0 || $receivedAmountMinor > $locked->amount_minor) {
                throw ValidationException::withMessages([
                    'received_amount' => 'Enter the cash actually received. It cannot be more than the amount collected for this parcel.',
                ]);
            }

            if ($locked->status === CodRemittance::REMITTED_TO_LOGISTICS) {
                return false;
            }

            if (! in_array($locked->status, [CodRemittance::PENDING_REMITTANCE], true)) {
                throw StaleStateException::field('remittance', 'Only a pending remittance can be confirmed. Record any shortage recovery separately.');
            }

            $shortage = (int) $locked->amount_minor - $receivedAmountMinor;
            $rider = null;
            $reason = null;
            if ($shortage > 0) {
                if (! RuntimeSchema::hasColumn('cod_remittances', 'shortage_minor')) {
                    throw ValidationException::withMessages(['received_amount' => 'Shortage recording is not available until the database update is applied.']);
                }
                $rider = $shortageRiderId !== null
                    ? Rider::query()->whereKey($shortageRiderId)->where('logistics_provider_id', $provider->getKey())->first()
                    : null;
                if ($rider === null) {
                    throw ValidationException::withMessages(['shortage_rider_id' => 'Choose the Rider responsible for the missing cash.']);
                }
                $reason = trim((string) $shortageReason);
                if (mb_strlen($reason) < 10 || mb_strlen($reason) > 1000) {
                    throw ValidationException::withMessages(['shortage_reason' => 'Explain the shortage in 10 to 1000 characters.']);
                }
            }

            $previousStatus = $locked->status;
            $locked->forceFill([
                'status' => $shortage > 0 ? CodRemittance::PARTIALLY_REMITTED : CodRemittance::REMITTED_TO_LOGISTICS,
                'confirmed_by_id' => $provider->getKey(),
                'confirmed_at' => now(),
                'received_amount_minor' => $receivedAmountMinor,
                'shortage_minor' => $shortage,
                'shortage_rider_id' => $shortage > 0 ? $rider->getKey() : null,
                'shortage_reason' => $shortage > 0 ? $reason : null,
                'shortage_recovered_minor' => 0,
            ])->save();

            OperationsAuditLog::record(
                $provider, 'remittance_confirmed', $locked, 'Parcel #'.$order->getKey(),
                ['status' => $previousStatus, 'cash_received' => null],
                ['status' => $locked->status, 'cash_received' => '₱'.number_format($receivedAmountMinor / 100, 2)]
                    + ($shortage > 0 ? [
                        'shortage' => '₱'.number_format($shortage / 100, 2),
                        'responsible_rider_id' => (int) $rider->getKey(),
                        'shortage_reason' => $reason,
                    ] : []),
            );

            // Only the full amount can prepare a Seller settlement. A shortage stays
            // outstanding and is recovered later; it is never treated as received.
            if ($shortage === 0) {
                $this->settlements->prepare($locked);
            }

            return true;
        }, 3);
    }

    /**
     * Record a later manual recovery of a confirmed shortage. The received cash
     * grows and the outstanding shortage shrinks. When the full amount is in, the
     * only remaining Seller settlement is prepared exactly once.
     */
    public function recordShortageRecovery(
        CodRemittance $remittance,
        LogisticsProvider $provider,
        int $recoveredAmountMinor,
        string $reference,
        ?string $note = null,
    ): void {
        if (! RuntimeSchema::hasColumn('cod_remittances', 'shortage_recovered_minor')) {
            throw ValidationException::withMessages(['recovered_amount' => 'Shortage recovery is not available until the database update is applied.']);
        }
        $reference = trim($reference);
        $note = trim((string) $note);
        if ($reference === '' || mb_strlen($reference) > 255) {
            throw ValidationException::withMessages(['reference' => 'A recovery reference of at most 255 characters is required.']);
        }
        if (mb_strlen($note) > 1000) {
            throw ValidationException::withMessages(['note' => 'Keep the note to 1000 characters or fewer.']);
        }

        DB::transaction(function () use ($remittance, $provider, $recoveredAmountMinor, $reference, $note): void {
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

            if ($locked->status !== CodRemittance::PARTIALLY_REMITTED) {
                throw StaleStateException::field('remittance', 'Only a partially received remittance has a shortage to recover.');
            }
            $outstanding = $locked->outstandingShortageMinor();
            if ($recoveredAmountMinor <= 0 || $recoveredAmountMinor > $outstanding) {
                throw ValidationException::withMessages([
                    'recovered_amount' => 'Enter an amount between ₱0.01 and the ₱'.number_format($outstanding / 100, 2).' still missing.',
                ]);
            }

            $received = (int) $locked->received_amount_minor + $recoveredAmountMinor;
            $recovered = (int) $locked->shortage_recovered_minor + $recoveredAmountMinor;
            $full = $received === (int) $locked->amount_minor;
            $locked->forceFill([
                'received_amount_minor' => $received,
                'shortage_recovered_minor' => $recovered,
                'shortage_recovery_reference' => $reference,
                'shortage_recovery_note' => $note !== '' ? $note : null,
                'shortage_recovered_at' => now(),
                'shortage_recovered_by_id' => $provider->getKey(),
                'status' => $full ? CodRemittance::REMITTED_TO_LOGISTICS : CodRemittance::PARTIALLY_REMITTED,
            ])->save();

            OperationsAuditLog::record(
                $provider, 'cod_shortage_recovered', $locked, 'Parcel #'.$order->getKey(),
                ['shortage_outstanding' => '₱'.number_format($outstanding / 100, 2), 'received' => '₱'.number_format(($received - $recoveredAmountMinor) / 100, 2)],
                ['shortage_outstanding' => '₱'.number_format(($outstanding - $recoveredAmountMinor) / 100, 2), 'received' => '₱'.number_format($received / 100, 2), 'reference' => $reference],
                $note !== '' ? $note : null,
            );

            if ($full) {
                $this->settlements->prepare($locked);
            }
        }, 3);
    }
}
