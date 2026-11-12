<?php

namespace App\Services\Logistics;

use App\Exceptions\StaleStateException;
use App\Models\Admin\Admin;
use App\Models\Admin\OperationsAuditLog;
use App\Models\Logistics\CodRemittance;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Rider\Courier;
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

    /** Return false for an already-confirmed receipt without changing its audit. */
    public function confirmReceipt(
        CodRemittance $remittance,
        LogisticsProvider $provider,
        int $receivedAmountMinor,
        ?string $recoveryReference = null,
    ): bool {
        return DB::transaction(function () use ($remittance, $provider, $receivedAmountMinor, $recoveryReference): bool {
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
                throw StaleStateException::field('remittance', 'Only pending remittances can be confirmed.');
            }

            $case = $locked->discrepancy;
            if ($case !== null) {
                if ($case['status'] !== 'recovery_authorized' || blank($recoveryReference)) {
                    throw ValidationException::withMessages([
                        'received_amount' => 'An Admin must authorize recovery and Logistics must record the physical cash reference.',
                    ]);
                }

                $resolved = array_replace($case, [
                    'status' => 'resolved',
                    'recovered_reference' => trim($recoveryReference),
                    'resolved_at' => now()->toIso8601String(),
                ]);
                $locked->forceFill(['discrepancy' => $resolved]);
                OperationsAuditLog::record($provider, 'cod_discrepancy_resolved', $locked, 'Parcel #'.$order->getKey(), $case, $resolved);
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

    public function recordDiscrepancy(CodRemittance $remittance, LogisticsProvider $provider, int $counted, string $staff, string $reason, string $reference): void
    {
        if (! RuntimeSchema::hasColumn('cod_remittances', 'discrepancy')) {
            throw ValidationException::withMessages(['received_amount' => 'Cash discrepancy recording requires the current database migration.']);
        }
        DB::transaction(function () use ($remittance, $provider, $counted, $staff, $reason, $reference): void {
            $locked = CodRemittance::query()->whereKey($remittance->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $locked->logistics_provider_id === (int) $provider->id, 403);

            if ($locked->status !== CodRemittance::PENDING_REMITTANCE || $counted < 0 || $counted === $locked->amount_minor
                || blank($staff) || blank($reason) || blank($reference)) {
                throw ValidationException::withMessages(['received_amount' => 'Record a different cash count, the staff member, a reason and an evidence reference on a pending receipt.']);
            }

            $original = [
                'counted_minor' => $counted,
                'difference_minor' => $counted - $locked->amount_minor,
                'staff' => trim($staff),
                'reason' => trim($reason),
                'reference' => trim($reference),
            ];
            if ($locked->discrepancy !== null) {
                if (array_intersect_key($locked->discrepancy, $original) == $original) {
                    return;
                }
                throw StaleStateException::field('received_amount', 'The original cash count is already recorded. Review the discrepancy.');
            }

            $case = $original + ['status' => 'awaiting_review', 'recorded_at' => now()->toIso8601String()];
            $locked->forceFill(['discrepancy' => $case])->save();
            OperationsAuditLog::record($provider, 'cod_discrepancy_recorded', $locked, 'Parcel #'.$locked->seller_order_id, null, $case, $reason);
        }, 3);
    }

    public function explain(CodRemittance $remittance, Courier $courier, string $explanation): void
    {
        DB::transaction(function () use ($remittance, $courier, $explanation): void {
            $locked = CodRemittance::query()->whereKey($remittance->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $locked->courier_id === (int) $courier->id, 404);
            $case = $locked->discrepancy;
            if (($case['rider_explanation'] ?? null) === trim($explanation)) {
                return;
            }
            if (! in_array($case['status'] ?? null, ['awaiting_review', 'recovery_authorized'], true) || isset($case['rider_explanation']) || blank($explanation)) {
                throw StaleStateException::field('explanation', 'An explanation is no longer available for this case.');
            }
            $locked->forceFill(['discrepancy' => array_replace($case, [
                'status' => 'awaiting_review',
                'rider_explanation' => trim($explanation),
                'rider_id' => $courier->rider_id,
                'explained_at' => now()->toIso8601String(),
            ])])->save();
        }, 3);
    }

    public function review(CodRemittance $remittance, Admin $admin, string $decision, string $note): void
    {
        abort_unless($admin->role === 'admin', 403);
        DB::transaction(function () use ($remittance, $admin, $decision, $note): void {
            $locked = CodRemittance::query()->whereKey($remittance->id)->lockForUpdate()->firstOrFail();
            $case = $locked->discrepancy;
            if (($case['status'] ?? null) !== 'awaiting_review' || ! in_array($decision, ['keep_open', 'authorize_recovery'], true) || blank($note)) {
                throw StaleStateException::field('decision', 'Only an open discrepancy can be reviewed.');
            }
            if ($decision === 'keep_open' && ($case['review_decision'] ?? null) === $decision && ($case['review_note'] ?? null) === trim($note)) {
                return;
            }
            $reviewed = array_replace($case, [
                'status' => $decision === 'authorize_recovery' ? 'recovery_authorized' : 'awaiting_review',
                'review_decision' => $decision,
                'review_note' => trim($note),
                'reviewed_by' => $admin->id,
                'reviewed_at' => now()->toIso8601String(),
            ]);
            $locked->forceFill(['discrepancy' => $reviewed])->save();
            OperationsAuditLog::record($admin, 'cod_discrepancy_reviewed', $locked, 'Parcel #'.$locked->seller_order_id, $case, $reviewed, $note);
        }, 3);
    }
}
