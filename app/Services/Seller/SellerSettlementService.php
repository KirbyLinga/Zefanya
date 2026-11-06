<?php

namespace App\Services\Seller;

use App\Enums\AdminRole;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\StaleStateException;
use App\Models\Admin\Admin;
use App\Models\Logistics\CodRemittance;
use App\Models\Seller\Seller;
use App\Models\Seller\SellerDebt;
use App\Models\Seller\SellerPayoutAllocation;
use App\Models\Seller\SellerPayoutRequest;
use App\Models\Seller\SellerSettlement;
use App\Models\Seller\StoreSale;
use App\Models\Shared\SellerOrder;
use App\Services\Shared\CommissionFinalizationService;
use App\Services\Shared\VoucherFundingService;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SellerSettlementService
{
    public function __construct(
        private readonly VoucherFundingService $funding = new VoucherFundingService,
    ) {}

    public function prepare(CodRemittance $remittance): SellerSettlement
    {
        return DB::transaction(function () use ($remittance): SellerSettlement {
            [$locked, $order] = $this->lockEligibleRemittance((int) $remittance->getKey());

            $settlement = SellerSettlement::query()->where('seller_order_id', $order->getKey())
                ->lockForUpdate()->first();

            if ($settlement === null) {
                $settlement = new SellerSettlement;
                $settlement->forceFill([
                    'seller_order_id' => $order->getKey(),
                    'cod_remittance_id' => $locked->getKey(),
                    'payment_id' => $locked->payment_id,
                    'seller_id' => $order->seller_id,
                    'collected_amount_minor' => $locked->amount_minor,
                    'shipping_allocation_minor' => $order->shipping_fee_minor,
                    'commission_bps' => null,
                    'commission_minor' => null,
                    'seller_payout_minor' => null,
                    'financials_finalized_at' => null,
                    'pending_reason' => SellerSettlement::COMMISSION_FINALIZATION_MISSING,
                    'status' => SellerSettlement::PENDING_PAYOUT,
                ])->save();
            }

            // Handles cash remitted after Buyer completion as well as before it.
            $this->applyFinalizedFinancials($settlement, $order);

            return $settlement;
        }, 3);
    }

    /**
     * What platform-funded vouchers cost Zefanya against the commission it earns. A
     * marketplace-funded discount is paid by Zefanya: the Seller gets the full price while the
     * buyer paid less. Released (returned) settlements are left out.
     *
     * @return array{month: array<string, int>, all: array<string, int>}
     */
    public function subsidySummary(): array
    {
        $monthStart = Carbon::now(StoreSale::TIMEZONE)->startOfMonth()->utc();
        $summarize = function (?Carbon $since): array {
            $row = SellerSettlement::query()->whereIn('status', [SellerSettlement::PENDING_PAYOUT, SellerSettlement::PAID])
                ->when($since !== null, fn ($query) => $query->where('created_at', '>=', $since))
                ->selectRaw('COALESCE(SUM(marketplace_funded_discount_minor), 0) as subsidy')
                ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN marketplace_funded_discount_minor ELSE 0 END), 0) as paid_subsidy', [SellerSettlement::PAID])
                ->selectRaw('COALESCE(SUM(commission_minor), 0) as commission')
                ->selectRaw('COALESCE(SUM(CASE WHEN marketplace_funded_discount_minor > 0 THEN 1 ELSE 0 END), 0) as orders')
                ->first();

            return [
                'subsidyMinor' => (int) $row->subsidy,
                'paidSubsidyMinor' => (int) $row->paid_subsidy,
                'pendingSubsidyMinor' => (int) $row->subsidy - (int) $row->paid_subsidy,
                'commissionMinor' => (int) $row->commission,
                'netMinor' => (int) $row->commission - (int) $row->subsidy,
                'orders' => (int) $row->orders,
            ];
        };

        return ['month' => $summarize($monthStart), 'all' => $summarize(null)];
    }

    public function syncFinalizedOrder(SellerOrder $sellerOrder): void
    {
        DB::transaction(function () use ($sellerOrder): void {
            // Shared lock order: SellerOrder -> Payment -> CodRemittance -> SellerSettlement.
            $order = SellerOrder::query()->whereKey($sellerOrder->getKey())->lockForUpdate()->firstOrFail();
            $payment = $order->payment()->lockForUpdate()->first();

            if ($payment === null) {
                return;
            }

            $remittance = CodRemittance::query()->where('seller_order_id', $order->getKey())
                ->where('payment_id', $payment->getKey())->lockForUpdate()->first();

            if ($remittance === null || $remittance->status !== CodRemittance::REMITTED_TO_LOGISTICS) {
                return;
            }

            $settlement = SellerSettlement::query()->where('seller_order_id', $order->getKey())
                ->where('cod_remittance_id', $remittance->getKey())->lockForUpdate()->first();

            if ($settlement !== null) {
                $this->applyFinalizedFinancials($settlement, $order);
            }
        }, 3);
    }

    public function confirmPayout(
        SellerSettlement $settlement,
        Admin $admin,
        int $amountMinor,
        string $reference,
    ): SellerSettlement {
        abort_unless(in_array($admin->role, [AdminRole::Admin->value, AdminRole::SuperAdmin->value], true), 403);

        return DB::transaction(function () use ($settlement, $admin, $amountMinor, $reference): SellerSettlement {
            // Serialize direct payouts with Seller withdrawal reservations.
            $sellerId = SellerSettlement::query()->whereKey($settlement->getKey())->value('seller_id');
            Seller::query()->whereKey($sellerId)->lockForUpdate()->firstOrFail();
            [$remittance, $order] = $this->lockEligibleRemittance((int) $settlement->cod_remittance_id);
            $locked = SellerSettlement::query()->whereKey($settlement->getKey())->lockForUpdate()->firstOrFail();

            if ($remittance->zefanya_received_at === null) {
                throw ValidationException::withMessages([
                    'settlement' => 'Zefanya has not confirmed receiving this cash from the Logistics company yet.',
                ]);
            }

            // An earlier locator read can establish an old REPEATABLE READ snapshot.
            // Read both allocations and requests under lock after the Seller lock.
            if (SellerPayoutAllocation::query()
                ->join('seller_payout_requests', 'seller_payout_requests.id', '=', 'seller_payout_allocations.seller_payout_request_id')
                ->where('seller_payout_allocations.seller_settlement_id', $locked->id)
                ->where('seller_payout_requests.status', SellerPayoutRequest::PENDING)
                ->lockForUpdate()->first(['seller_payout_allocations.id']) !== null) {
                throw ValidationException::withMessages(['settlement' => 'These earnings are reserved by a pending Seller payout request.']);
            }

            if ($locked->status !== SellerSettlement::PENDING_PAYOUT) {
                throw StaleStateException::field('settlement', 'This settlement is no longer pending payout.');
            }

            // A debt can only be repaid through a payout request, which records the deduction.
            if (SellerDebt::query()->where('seller_id', $locked->seller_id)->whereColumn('settled_minor', '<', 'amount_minor')->exists()) {
                throw ValidationException::withMessages(['settlement' => 'This Seller owes money from a returned order. Pay them through a payout request so the debt is deducted.']);
            }

            $allocation = $this->funding->allocationFor($order);
            if ($allocation['pending_reason'] !== null) {
                throw ValidationException::withMessages([
                    'settlement' => 'Voucher funding or allocation is unknown or invalid. Reconcile the recorded checkout allocation before payout.',
                ]);
            }

            if (! $this->hasFinalizedCommission($order)
                || $locked->financials_finalized_at === null
                || $locked->commission_bps === null || $locked->commission_minor === null
                || $locked->seller_payout_minor === null) {
                throw ValidationException::withMessages([
                    'settlement' => 'Commission must be finalized on a completed Seller order before payout.',
                ]);
            }

            if ($locked->pending_reason !== null) {
                throw ValidationException::withMessages([
                    'settlement' => 'The settlement requires financial reconciliation before payout.',
                ]);
            }

            if ((int) $locked->cod_remittance_id !== (int) $remittance->getKey()
                || (int) $locked->seller_order_id !== (int) $order->getKey()
                || (int) $locked->seller_id !== (int) $order->seller_id
                || (int) $locked->payment_id !== (int) $remittance->payment_id
                || $locked->collected_amount_minor !== $remittance->amount_minor
                || $locked->shipping_allocation_minor !== $order->shipping_fee_minor
                || $locked->seller_funded_discount_minor !== $allocation['seller_minor']
                || $locked->marketplace_funded_discount_minor !== $allocation['marketplace_minor']
                || $locked->commission_bps !== CommissionFinalizationService::COMMISSION_BPS
                || $locked->commission_minor < 0 || $locked->seller_payout_minor < 0
                || $locked->commission_bps !== $order->commission_bps
                || $locked->commission_minor !== $order->commission_minor
                || $locked->seller_payout_minor !== $order->seller_net_minor
                || $locked->commission_minor + $locked->seller_payout_minor !== $order->subtotal_minor - $allocation['seller_minor']
                || $locked->collected_amount_minor !== $order->subtotal_minor - $allocation['discount_minor'] - $allocation['shipping_discount_minor'] + $order->shipping_fee_minor
                || $locked->commission_minor + $locked->seller_payout_minor + $locked->shipping_allocation_minor
                    !== $locked->collected_amount_minor + $locked->marketplace_funded_discount_minor) {
                throw ValidationException::withMessages([
                    'settlement' => 'The finalized settlement does not reconcile with collected cash, voucher funding, and shipping.',
                ]);
            }

            if ($amountMinor < 0 || $amountMinor !== $locked->remainingPayoutMinor()) {
                throw ValidationException::withMessages(['amount_minor' => 'The payout must equal the finalized Seller payout amount still owed.']);
            }

            $reference = trim($reference);
            if ($reference === '' || mb_strlen($reference) > 255) {
                throw ValidationException::withMessages(['reference' => 'A payout reference of at most 255 characters is required.']);
            }

            $locked->forceFill([
                'status' => SellerSettlement::PAID,
                'paid_amount_minor' => $locked->seller_payout_minor,
                'payout_reference' => $reference,
                'paid_by_admin_id' => $admin->getKey(),
                'paid_at' => now(),
            ])->save();

            return $locked;
        }, 3);
    }

    /**
     * Drop a still-pending settlement and its commission when a return is
     * approved or refunded. Paid settlements stay as they are.
     */
    public function releasePendingForReturn(SellerOrder $sellerOrder): void
    {
        DB::transaction(function () use ($sellerOrder): void {
            $order = SellerOrder::query()->whereKey($sellerOrder->getKey())->lockForUpdate()->firstOrFail();
            $order->load('parcelReturn');
            if (! $order->parcelReturn?->releasesPendingEarnings()) {
                return;
            }

            $payment = $order->payment()->lockForUpdate()->first();
            $settlement = null;
            if ($payment !== null) {
                $remittance = CodRemittance::query()->where('seller_order_id', $order->getKey())
                    ->where('payment_id', $payment->getKey())->lockForUpdate()->first();
                if ($remittance !== null) {
                    $settlement = SellerSettlement::query()->where('seller_order_id', $order->getKey())
                        ->where('cod_remittance_id', $remittance->getKey())->lockForUpdate()->first();
                }
            }

            $kept = $this->keptAfterReturn($order);

            // The Seller was already paid for this order, so what they received beyond
            // what they keep becomes a debt that later payouts repay. The paid
            // settlement itself stays as the record.
            if ($settlement?->status === SellerSettlement::PAID) {
                $this->recordDebtForPaidReturn($settlement, $order, $kept);

                return;
            }

            if ($settlement?->status === SellerSettlement::PENDING_PAYOUT
                && SellerPayoutAllocation::query()
                    ->join('seller_payout_requests', 'seller_payout_requests.id', '=', 'seller_payout_allocations.seller_payout_request_id')
                    ->where('seller_payout_allocations.seller_settlement_id', $settlement->id)
                    ->where('seller_payout_requests.status', SellerPayoutRequest::PENDING)
                    ->lockForUpdate()->first(['seller_payout_allocations.id']) !== null) {
                throw ValidationException::withMessages([
                    'return' => 'Cancel the pending payout request before this return can leave earnings.',
                ]);
            }

            // Part of this money already reached the Seller, so an Admin must settle the rest by hand.
            if ($settlement?->status === SellerSettlement::PENDING_PAYOUT && (int) $settlement->paid_amount_minor > 0) {
                $settlement->forceFill(['pending_reason' => SellerSettlement::FINANCIAL_RECONCILIATION_REQUIRED])->save();
                $this->recordDebtForPaidReturn($settlement, $order, $kept);

                return;
            }

            // The voucher funding is unknown, so what the Seller keeps cannot be worked out.
            if ($kept === null) {
                if ($settlement !== null && in_array($settlement->status, [SellerSettlement::PENDING_PAYOUT, SellerSettlement::RELEASED], true)) {
                    $settlement->forceFill(['status' => SellerSettlement::PENDING_PAYOUT, 'seller_payout_minor' => null, 'pending_reason' => SellerSettlement::FINANCIAL_RECONCILIATION_REQUIRED])->save();
                }

                return;
            }

            if ($order->commission_bps !== $kept['commission_bps'] || $order->commission_minor !== $kept['commission_minor']
                || $order->seller_net_minor !== $kept['seller_net_minor'] || $order->commission_finalized_at === null) {
                $order->forceFill($this->orderFields($kept) + ['commission_finalized_at' => $order->commission_finalized_at ?? now()])->save();
            }

            if ($settlement !== null && in_array($settlement->status, [SellerSettlement::PENDING_PAYOUT, SellerSettlement::RELEASED], true)) {
                $this->writeReturnSettlement($settlement, $kept, $order);
            }
        }, 3);
    }

    /**
     * What the Seller keeps from an order with an approved return. Until a refund is
     * recorded the full amount is assumed refunded, so nothing is kept. A recorded
     * refund comes off the item amount first. The Seller keeps the rest, less the
     * usual commission on that rest, so they are charged only what was refunded.
     * Returns null when the voucher funding is still unknown.
     *
     * @return array{commission_bps: int, commission_minor: int, seller_net_minor: int}|null
     */
    private function keptAfterReturn(SellerOrder $order): ?array
    {
        return $this->returnBreakdown($order);
    }

    /**
     * A failed delivery sent back to the Seller collected no cash, so the delivery attempt
     * and the trip back are charged as a debt that later payouts repay. Caller holds the
     * parcel lock. A lower amount never drops below what the Seller already repaid.
     */
    public function chargeFailedDelivery(SellerOrder $order, int $amountMinor): void
    {
        $debt = SellerDebt::query()->where('seller_order_id', $order->getKey())->lockForUpdate()->first();
        if ($debt !== null) {
            $amount = max((int) $debt->settled_minor, $amountMinor);
            if ($debt->reason === SellerDebt::FAILED_DELIVERY && (int) $debt->amount_minor !== $amount) {
                $debt->forceFill(['amount_minor' => $amount])->save();
            }

            return;
        }
        if ($amountMinor <= 0) {
            return;
        }

        (new SellerDebt)->forceFill([
            'seller_id' => $order->seller_id,
            'seller_order_id' => $order->getKey(),
            'seller_settlement_id' => null,
            'amount_minor' => $amountMinor,
            'reason' => SellerDebt::FAILED_DELIVERY,
        ])->save();
    }

    /**
     * How an approved return settles for the Seller when the Seller is at fault:
     * - the refund comes off the item amount first, and the Seller keeps the rest less commission;
     * - refunded original shipping and the return trip are charged on top;
     * - a negative result is a shortfall, owed back as a seller debt.
     * Until the refund is recorded, earnings are held and only the return trip is charged.
     * Damaged in transit (Logistics at fault): the Seller keeps the whole order and pays nothing.
     * Returns null when the voucher funding is unknown.
     *
     * @return array{refunded_items_minor: int, commission_bps: int, commission_minor: int, kept_net_minor: int, refunded_shipping_minor: int, return_shipping_minor: int, seller_net_minor: int, shortfall_minor: int}|null
     */
    public function returnBreakdown(SellerOrder $order): ?array
    {
        $return = $order->parcelReturn;
        $logisticsFault = $return?->isLogisticsFault() === true;
        $returnShipping = $logisticsFault ? 0 : (int) ($return?->return_shipping_fee_minor ?? 0);

        if (! $logisticsFault && $return?->refunded_at === null) {
            return $this->withPosition(['refunded_items_minor' => 0, 'commission_bps' => 0, 'commission_minor' => 0, 'kept_net_minor' => 0, 'refunded_shipping_minor' => 0, 'return_shipping_minor' => $returnShipping]);
        }

        $allocation = $this->funding->allocationFor($order);
        if ($allocation['pending_reason'] !== null || $order->subtotal_minor === null) {
            return null;
        }

        $itemAmount = max(0, (int) $order->subtotal_minor - (int) ($order->seller_funded_discount_minor ?? $allocation['seller_minor'] ?? 0));
        $refund = $logisticsFault ? 0 : (int) $return->refund_amount_minor;
        $refundedItems = min($itemAmount, $refund);
        $keptItems = $itemAmount - $refundedItems;
        $commission = $keptItems > 0 ? Money::percentage($keptItems, CommissionFinalizationService::COMMISSION_BPS, 10000) : 0;

        return $this->withPosition([
            'refunded_items_minor' => $refundedItems,
            'commission_bps' => $keptItems > 0 ? CommissionFinalizationService::COMMISSION_BPS : 0,
            'commission_minor' => $commission,
            'kept_net_minor' => $keptItems - $commission,
            'refunded_shipping_minor' => $refund - $refundedItems,
            'return_shipping_minor' => $returnShipping,
        ]);
    }

    private function withPosition(array $breakdown): array
    {
        $position = $breakdown['kept_net_minor'] - $breakdown['refunded_shipping_minor'] - $breakdown['return_shipping_minor'];

        return $breakdown + ['seller_net_minor' => max(0, $position), 'shortfall_minor' => max(0, -$position)];
    }

    /** @return array{commission_bps: int, commission_minor: int, seller_net_minor: int} */
    private function orderFields(array $kept): array
    {
        return array_intersect_key($kept, array_flip(['commission_bps', 'commission_minor', 'seller_net_minor']));
    }

    /**
     * One debt per order: what the Seller was paid beyond what they keep, plus any
     * return charges their earnings could not cover. It follows the latest figures
     * (approval, refund, who pays), never below what the Seller already repaid.
     *
     * @param  array{seller_net_minor: int, shortfall_minor: int}|null  $kept
     */
    private function recordDebtForPaidReturn(SellerSettlement $settlement, SellerOrder $order, ?array $kept): void
    {
        $paid = (int) $settlement->paid_amount_minor;
        $owed = max(0, $paid - (int) ($kept['seller_net_minor'] ?? 0)) + (int) ($kept['shortfall_minor'] ?? 0);
        $debt = SellerDebt::query()->where('seller_order_id', $order->getKey())->lockForUpdate()->first();

        if ($debt !== null) {
            $amount = max((int) $debt->settled_minor, $owed);
            if ((int) $debt->amount_minor !== $amount) {
                $debt->forceFill(['amount_minor' => $amount])->save();
            }

            return;
        }

        if ($owed <= 0) {
            return;
        }

        (new SellerDebt)->forceFill([
            'seller_id' => $settlement->seller_id,
            'seller_order_id' => $order->getKey(),
            'seller_settlement_id' => $settlement->getKey(),
            'amount_minor' => $owed,
            'reason' => $paid > 0 ? SellerDebt::AFTER_PAYOUT_RETURN : SellerDebt::RETURN_CHARGES,
        ])->save();
    }

    /**
     * @param  array{commission_bps: int, commission_minor: int, seller_net_minor: int, shortfall_minor: int}  $kept
     */
    private function writeReturnSettlement(SellerSettlement $settlement, array $kept, SellerOrder $order): void
    {
        if ($kept['seller_net_minor'] === 0) {
            $this->writeReleasedSettlement($settlement);
            // Commission still applies to items the Seller kept, even when charges use up the rest.
            if ($kept['commission_minor'] > 0) {
                $settlement->forceFill(['commission_bps' => $kept['commission_bps'], 'commission_minor' => $kept['commission_minor']])->save();
            }
        } else {
            // A partial refund leaves the Seller the rest, payable like any other order.
            $settlement->forceFill([
                'status' => SellerSettlement::PENDING_PAYOUT,
                'commission_bps' => $kept['commission_bps'],
                'commission_minor' => $kept['commission_minor'],
                'seller_payout_minor' => $kept['seller_net_minor'],
                'financials_finalized_at' => $settlement->financials_finalized_at ?? now(),
                'pending_reason' => null,
            ]);
            if ($settlement->isDirty()) {
                $settlement->save();
            }
        }

        // Charges the order's earnings could not cover are taken from later payouts.
        $this->recordDebtForPaidReturn($settlement, $order, $kept);
    }

    private function writeReleasedSettlement(SellerSettlement $settlement): void
    {
        $settlement->forceFill([
            'status' => SellerSettlement::RELEASED,
            'commission_bps' => 0,
            'commission_minor' => 0,
            'seller_payout_minor' => 0,
            'financials_finalized_at' => $settlement->financials_finalized_at ?? now(),
            'pending_reason' => null,
        ]);
        if ($settlement->isDirty()) {
            $settlement->save();
        }
    }

    /** Caller holds the SellerOrder and settlement locks in the same transaction. */
    private function applyFinalizedFinancials(SellerSettlement $settlement, SellerOrder $order): void
    {
        if ($settlement->status !== SellerSettlement::PENDING_PAYOUT) {
            return;
        }

        if ($order->parcelReturn?->releasesPendingEarnings()) {
            $kept = $this->keptAfterReturn($order);
            if ($kept === null) {
                $settlement->forceFill(['seller_payout_minor' => null, 'pending_reason' => SellerSettlement::FINANCIAL_RECONCILIATION_REQUIRED])->save();
            } else {
                $this->writeReturnSettlement($settlement, $kept, $order);
            }

            return;
        }

        $allocation = $this->funding->allocationFor($order);
        $attributes = [
            'seller_funded_discount_minor' => $allocation['seller_minor'],
            'marketplace_funded_discount_minor' => $allocation['marketplace_minor'],
        ];

        if ($allocation['pending_reason'] !== null) {
            $attributes += [
                'seller_payout_minor' => null,
                'financials_finalized_at' => null,
                'pending_reason' => $allocation['pending_reason'],
            ];
        } elseif (! $this->hasFinalizedCommission($order)) {
            $attributes += [
                'seller_payout_minor' => null,
                'financials_finalized_at' => null,
                'pending_reason' => SellerSettlement::COMMISSION_FINALIZATION_MISSING,
            ];
        } else {
            // Marketplace funding is an allocation, not extra collected Rider cash.
            // Keep the original shipping/cash snapshots; never invent a balancing amount.
            $reconciles = $settlement->shipping_allocation_minor === $order->shipping_fee_minor
                && $order->commission_minor + $order->seller_net_minor === $order->subtotal_minor - $allocation['seller_minor']
                && $settlement->collected_amount_minor === $order->subtotal_minor - $allocation['discount_minor'] - $allocation['shipping_discount_minor'] + $order->shipping_fee_minor
                && $order->commission_minor + $order->seller_net_minor + $settlement->shipping_allocation_minor
                    === $settlement->collected_amount_minor + $allocation['marketplace_minor'];

            $attributes += [
                'commission_bps' => $order->commission_bps,
                'commission_minor' => $order->commission_minor,
                'seller_payout_minor' => $order->seller_net_minor,
                'financials_finalized_at' => $order->commission_finalized_at,
                'pending_reason' => $order->seller_net_minor < 0
                    ? SellerSettlement::NEGATIVE_SELLER_NET
                    : ($reconciles ? null : SellerSettlement::FINANCIAL_RECONCILIATION_REQUIRED),
            ];
        }

        $settlement->forceFill($attributes);
        if ($settlement->isDirty()) {
            $settlement->save();
        }
    }

    private function hasFinalizedCommission(SellerOrder $order): bool
    {
        return $order->status === OrderStatus::Completed
            && $order->commission_finalized_at !== null
            && $order->commission_bps !== null
            && $order->commission_minor !== null
            && $order->seller_net_minor !== null;
    }

    /** @return array{CodRemittance, SellerOrder} */
    private function lockEligibleRemittance(int $id): array
    {
        // This first read only locates the owner. Revalidate under lock below.
        $reference = CodRemittance::query()->findOrFail($id);
        $order = SellerOrder::query()->whereKey($reference->seller_order_id)->lockForUpdate()->firstOrFail();
        $payment = $order->payment()->lockForUpdate()->firstOrFail();
        $remittance = CodRemittance::query()->whereKey($id)
            ->where('seller_order_id', $order->getKey())
            ->where('payment_id', $payment->getKey())->lockForUpdate()->firstOrFail();

        if ($remittance->status !== CodRemittance::REMITTED_TO_LOGISTICS
            || $remittance->confirmed_at === null || $remittance->confirmed_by_id === null
            || $remittance->received_amount_minor !== $remittance->amount_minor
            || $payment->method !== PaymentMethod::Cod || $payment->status !== PaymentStatus::Paid
            || $payment->collected_at === null
            || $payment->collected_amount_minor !== $remittance->amount_minor
            || $payment->amount_minor !== $remittance->amount_minor) {
            throw ValidationException::withMessages([
                'remittance' => 'Settlement requires a matching COD collection confirmed as remitted to Logistics.',
            ]);
        }

        if ($order->shipping_fee_minor === null || $order->shipping_fee_minor < 0
            || $order->shipping_fee_minor > $remittance->amount_minor) {
            throw ValidationException::withMessages(['settlement' => 'The recorded shipping allocation is invalid.']);
        }

        return [$remittance, $order];
    }
}
