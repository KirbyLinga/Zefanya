<?php

namespace App\Services\Seller;

use App\Enums\AdminRole;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Admin\Admin;
use App\Models\Logistics\CodRemittance;
use App\Models\Seller\Seller;
use App\Models\Seller\SellerPayoutAllocation;
use App\Models\Seller\SellerPayoutRequest;
use App\Models\Seller\SellerSettlement;
use App\Models\Shared\SellerOrder;
use App\Services\Shared\CommissionFinalizationService;
use App\Services\Shared\VoucherFundingService;
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
                throw ValidationException::withMessages(['settlement' => 'This settlement is no longer pending payout.']);
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

            if ($settlement?->status === SellerSettlement::PAID) {
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

                return;
            }

            if ($order->commission_bps !== 0 || $order->commission_minor !== 0
                || $order->seller_net_minor !== 0 || $order->commission_finalized_at === null) {
                $order->forceFill([
                    'commission_bps' => 0,
                    'commission_minor' => 0,
                    'seller_net_minor' => 0,
                    'commission_finalized_at' => $order->commission_finalized_at ?? now(),
                ])->save();
            }

            if ($settlement?->status === SellerSettlement::PENDING_PAYOUT) {
                $this->writeReleasedSettlement($settlement);
            }
        }, 3);
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
            $this->writeReleasedSettlement($settlement);

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
