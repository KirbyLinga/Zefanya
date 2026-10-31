<?php

namespace App\Services\Seller;

use App\Enums\AdminRole;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Admin\Admin;
use App\Models\Admin\OperationsAuditLog;
use App\Models\Logistics\CodRemittance;
use App\Models\Seller\Seller;
use App\Models\Seller\SellerPayoutRequest;
use App\Models\Seller\SellerSettlement;
use App\Notifications\Seller\PayoutRequestDecided;
use App\Services\Shared\VoucherFundingService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SellerPayoutRequestService
{
    public function __construct(private readonly VoucherFundingService $funding = new VoucherFundingService) {}

    public function availableBalance(Seller $seller): int
    {
        $eligible = (int) $this->eligibleSettlements($seller)->sum(fn (SellerSettlement $settlement): int => $settlement->remainingPayoutMinor());
        $reserved = $this->reservedMinor($seller);

        return max(0, $eligible - $reserved);
    }

    /** Seller orders whose reconciled earnings can be withdrawn. */
    public function availableSellerOrderIds(Seller $seller): array
    {
        return $this->eligibleSettlements($seller)
            ->pluck('seller_order_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    public function reservedMinor(Seller $seller): int
    {
        return (int) SellerPayoutRequest::query()->where('seller_id', $seller->id)
            ->where('status', SellerPayoutRequest::PENDING)->sum('amount_minor');
    }

    public function request(Seller $seller, mixed $amount, mixed $idempotencyKey, ?string $destination = null): SellerPayoutRequest
    {
        $data = Validator::make(['amount' => $amount, 'idempotency_key' => $idempotencyKey], [
            'amount' => ['required', 'numeric', 'regex:/\A[0-9]+(?:\.[0-9]{1,2})?\z/'],
            'idempotency_key' => ['required', 'uuid'],
        ])->validate();
        // Parse decimal pesos without binary floating-point conversion or truncation.
        $minor = bcmul((string) $data['amount'], '100', 0);
        if (bccomp($minor, (string) PHP_INT_MAX, 0) > 0) {
            throw ValidationException::withMessages(['amount' => 'The amount exceeds the supported earnings balance.']);
        }
        $amountMinor = (int) $minor;
        $data['idempotency_key'] = strtolower($data['idempotency_key']);
        if ($amountMinor < 10000) {
            throw ValidationException::withMessages(['amount' => 'The minimum payout request is ₱100.00.']);
        }

        return DB::transaction(function () use ($seller, $amountMinor, $data, $destination): SellerPayoutRequest {
            // Also held by direct settlement payouts: neither path can spend reserved earnings.
            $lockedSeller = Seller::query()->whereKey($seller->id)->lockForUpdate()->firstOrFail();
            abort_unless($lockedSeller->status === 'approved', 403);
            $existing = SellerPayoutRequest::query()->where('seller_id', $lockedSeller->id)
                ->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing !== null) {
                if ($existing->amount_minor !== $amountMinor) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This submission key was already used for a different amount.']);
                }

                return $existing;
            }

            if (SellerPayoutRequest::query()->where('seller_id', $lockedSeller->id)
                ->where('status', SellerPayoutRequest::PENDING)->exists()) {
                throw ValidationException::withMessages(['amount' => 'A payout request is already pending.']);
            }

            $settlements = $this->eligibleSettlements($lockedSeller, true);
            if ($amountMinor > (int) $settlements->sum(fn (SellerSettlement $settlement): int => $settlement->remainingPayoutMinor())) {
                throw ValidationException::withMessages(['amount' => 'The amount exceeds your available earnings.']);
            }

            $payout = new SellerPayoutRequest;
            $payout->forceFill([
                'seller_id' => $lockedSeller->id,
                'pending_seller_id' => $lockedSeller->id,
                'idempotency_key' => $data['idempotency_key'],
                'amount_minor' => $amountMinor,
                'status' => SellerPayoutRequest::PENDING,
                'payout_destination' => filled($destination) ? trim($destination) : null,
            ])->save();

            $remaining = $amountMinor;
            foreach ($settlements as $settlement) {
                $allocated = min($remaining, $settlement->remainingPayoutMinor());
                $payout->allocations()->make()->forceFill([
                    'seller_payout_request_id' => $payout->id,
                    'seller_settlement_id' => $settlement->id,
                    'amount_minor' => $allocated,
                ])->save();
                $remaining -= $allocated;
                if ($remaining === 0) {
                    break;
                }
            }

            return $payout;
        }, 3);
    }

    public function cancel(Seller $seller, int $id): SellerPayoutRequest
    {
        return DB::transaction(function () use ($seller, $id): SellerPayoutRequest {
            $lockedSeller = Seller::query()->whereKey($seller->id)->lockForUpdate()->firstOrFail();
            abort_unless($lockedSeller->status === 'approved', 403);
            $payout = SellerPayoutRequest::query()->where('seller_id', $lockedSeller->id)
                ->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($payout->status === SellerPayoutRequest::PENDING) {
                $payout->forceFill([
                    'status' => SellerPayoutRequest::CANCELLED,
                    'pending_seller_id' => null,
                    'cancelled_at' => now(),
                ])->save();
            }

            return $payout;
        }, 3);
    }

    /**
     * Record that Admin sent the money. A settlement closes only when all of it
     * has been paid; a partly paid one keeps the rest available to withdraw.
     * Return false for an already-paid request without changing its record.
     */
    public function pay(SellerPayoutRequest $payout, Admin $admin, string $reference): bool
    {
        $this->authorizeAdmin($admin);
        $reference = trim($reference);

        $paid = DB::transaction(function () use ($payout, $admin, $reference): bool {
            // Same Seller lock as requests and direct payouts: none can overlap.
            Seller::query()->whereKey($payout->seller_id)->lockForUpdate()->firstOrFail();
            $locked = SellerPayoutRequest::query()->whereKey($payout->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === SellerPayoutRequest::PAID) {
                return false;
            }
            if ($locked->status !== SellerPayoutRequest::PENDING) {
                throw ValidationException::withMessages(['payout' => 'Only a pending request can be paid.']);
            }

            $allocations = $locked->allocations()->orderBy('seller_settlement_id')->get();
            $settlements = SellerSettlement::query()->whereKey($allocations->pluck('seller_settlement_id'))
                ->with(['sellerOrder', 'payment', 'codRemittance'])->lockForUpdate()->get()->keyBy('id');

            foreach ($allocations as $allocation) {
                $settlement = $settlements->get($allocation->seller_settlement_id);
                if ($settlement === null || $settlement->status !== SellerSettlement::PENDING_PAYOUT
                    || $allocation->amount_minor > $settlement->remainingPayoutMinor() || ! $this->isReconciled($settlement)) {
                    throw ValidationException::withMessages(['payout' => 'These earnings no longer reconcile. Reject this request and review the orders.']);
                }

                $paidSoFar = (int) $settlement->paid_amount_minor + $allocation->amount_minor;
                $isFull = $paidSoFar === $settlement->seller_payout_minor;
                $settlement->forceFill([
                    'paid_amount_minor' => $paidSoFar,
                    'status' => $isFull ? SellerSettlement::PAID : SellerSettlement::PENDING_PAYOUT,
                    'payout_reference' => $isFull ? $reference : $settlement->payout_reference,
                    'paid_by_admin_id' => $isFull ? $admin->getKey() : $settlement->paid_by_admin_id,
                    'paid_at' => $isFull ? now() : null,
                ])->save();
            }

            $locked->forceFill([
                'status' => SellerPayoutRequest::PAID,
                'pending_seller_id' => null,
                'payout_reference' => $reference,
                'decided_by_admin_id' => $admin->getKey(),
                'paid_at' => now(),
            ])->save();

            OperationsAuditLog::record(
                $admin, 'seller_payout_paid', $locked, 'Payout #'.$locked->getKey(),
                ['status' => 'Pending'],
                ['status' => 'Paid', 'amount' => '₱'.number_format($locked->amount_minor / 100, 2), 'reference' => $reference],
            );

            return true;
        }, 3);

        if ($paid) {
            $this->tellSeller($payout);
        }

        return $paid;
    }

    /** Releases the reservation, so the earnings can be requested again. */
    public function reject(SellerPayoutRequest $payout, Admin $admin, string $reason): void
    {
        $this->authorizeAdmin($admin);

        DB::transaction(function () use ($payout, $admin, $reason): void {
            Seller::query()->whereKey($payout->seller_id)->lockForUpdate()->firstOrFail();
            $locked = SellerPayoutRequest::query()->whereKey($payout->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== SellerPayoutRequest::PENDING) {
                throw ValidationException::withMessages(['payout' => 'Only a pending request can be rejected.']);
            }

            $locked->forceFill([
                'status' => SellerPayoutRequest::REJECTED,
                'pending_seller_id' => null,
                'decided_by_admin_id' => $admin->getKey(),
                'rejected_at' => now(),
                'rejection_reason' => trim($reason),
            ])->save();

            OperationsAuditLog::record(
                $admin, 'seller_payout_rejected', $locked, 'Payout #'.$locked->getKey(),
                ['status' => 'Pending'], ['status' => 'Rejected'], $reason,
            );
        }, 3);

        $this->tellSeller($payout);
    }

    /** The decision is already saved, so a mail or notification failure must not undo the page. */
    private function tellSeller(SellerPayoutRequest $payout): void
    {
        try {
            $fresh = $payout->fresh('seller');
            $fresh->seller?->notify(new PayoutRequestDecided($fresh));
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    private function authorizeAdmin(Admin $admin): void
    {
        abort_unless(in_array($admin->role, [AdminRole::Admin->value, AdminRole::SuperAdmin->value], true), 403);
    }

    private function eligibleSettlements(Seller $seller, bool $lock = false): Collection
    {
        $query = SellerSettlement::query()->where('seller_id', $seller->id)
            ->where('status', SellerSettlement::PENDING_PAYOUT)->whereNull('paid_at')
            ->whereNull('pending_reason')->whereNotNull('financials_finalized_at')
            ->where('seller_payout_minor', '>', 0)
            ->whereHas('sellerOrder', fn ($query) => $query->where('status', OrderStatus::Completed->value))
            ->with(['sellerOrder', 'payment', 'codRemittance'])->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get()->filter(fn (SellerSettlement $settlement): bool => $settlement->remainingPayoutMinor() > 0
            && $this->isReconciled($settlement));
    }

    private function isReconciled(SellerSettlement $settlement): bool
    {
        $order = $settlement->sellerOrder;
        $payment = $settlement->payment;
        $remittance = $settlement->codRemittance;
        if ($order === null || $payment === null || $remittance === null
            || (int) $order->seller_id !== (int) $settlement->seller_id
            || (int) $payment->seller_order_id !== (int) $order->id
            || (int) $remittance->seller_order_id !== (int) $order->id
            || (int) $remittance->payment_id !== (int) $payment->id
            || $remittance->status !== CodRemittance::REMITTED_TO_LOGISTICS
            || $remittance->confirmed_at === null || $remittance->confirmed_by_id === null
            || $remittance->zefanya_received_at === null
            || $remittance->amount_minor !== $remittance->received_amount_minor
            || $payment->method !== PaymentMethod::Cod || $payment->status !== PaymentStatus::Paid
            || $payment->collected_at === null
            || $payment->amount_minor !== $remittance->amount_minor
            || $payment->collected_amount_minor !== $remittance->amount_minor
            || $settlement->collected_amount_minor !== $remittance->amount_minor
            || $order->commission_finalized_at === null
            || $order->commission_bps === null || $order->commission_minor === null || $order->commission_minor < 0
            || $order->seller_net_minor !== $settlement->seller_payout_minor
            || $order->commission_bps !== $settlement->commission_bps
            || $order->commission_minor !== $settlement->commission_minor
            || $order->shipping_fee_minor === null || $order->shipping_fee_minor < 0
            || $settlement->shipping_allocation_minor !== $order->shipping_fee_minor) {
            return false;
        }

        $allocation = $this->funding->allocationFor($order);

        return $allocation['pending_reason'] === null
            && $settlement->seller_funded_discount_minor === $allocation['seller_minor']
            && $settlement->marketplace_funded_discount_minor === $allocation['marketplace_minor']
            && $order->commission_minor + $order->seller_net_minor === $order->subtotal_minor - $allocation['seller_minor']
            && $settlement->collected_amount_minor === $order->subtotal_minor - $allocation['discount_minor'] - $allocation['shipping_discount_minor'] + $order->shipping_fee_minor
            && $order->commission_minor + $order->seller_net_minor + $settlement->shipping_allocation_minor
                === $settlement->collected_amount_minor + $allocation['marketplace_minor'];
    }
}
