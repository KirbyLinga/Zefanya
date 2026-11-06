<?php

namespace App\Services\Logistics;

use App\Enums\ParcelReturnStatus;
use App\Exceptions\StaleStateException;
use App\Models\Admin\Admin;
use App\Models\Admin\OperationsAuditLog;
use App\Models\Logistics\CodRemittance;
use App\Models\Logistics\LogisticsReturnCharge as Charge;
use App\Models\Logistics\ParcelReturn;
use App\Models\Rider\Courier;
use App\Services\Seller\SellerSettlementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Keeps the money a return moves between Zefanya and Logistics in step with the return:
 * - damaged in transit: the delivering company repays the buyer's refund;
 * - Seller at fault: Zefanya pays the collecting company the return trip (the Seller is charged it);
 * - failed delivery returned to the Seller: Zefanya pays the carrier for the attempt and the
 *   trip back, and the Seller is charged both as a debt.
 * Settled charges never change; unsettled ones follow the latest decision.
 */
class ReturnChargeService
{
    /** Caller holds the return and parcel locks. */
    public function sync(ParcelReturn $record): void
    {
        $order = $record->sellerOrder;
        $collectedCod = CodRemittance::query()->where('seller_order_id', $order->id)->exists();
        $completed = $record->status === ParcelReturnStatus::Completed;
        $fee = (int) $order->shipping_fee_minor;

        $this->put($record, Charge::TRANSIT_DAMAGE_REFUND, Charge::TO_ZEFANYA,
            $record->isLogisticsFault() && $record->refunded_at !== null ? (int) $record->refund_amount_minor : 0,
            $this->deliveringProviderId($record));

        $this->put($record, Charge::RETURN_TRIP, Charge::TO_PROVIDER,
            $completed && $record->isBuyerRequest() && $record->fault_party === ParcelReturn::FAULT_SELLER ? (int) $record->return_shipping_fee_minor : 0,
            $this->collectingProviderId($record));

        $failedDelivery = $completed && ! $record->isBuyerRequest() && (int) $record->delivery_attempts > 0 && ! $collectedCod;
        $failedAmount = $failedDelivery ? 2 * $fee : 0;
        $this->put($record, Charge::FAILED_DELIVERY, Charge::TO_PROVIDER, $failedAmount,
            $order->carrier_provider_id ?? $record->origin_provider_id);
        app(SellerSettlementService::class)->chargeFailedDelivery($order, $failedAmount);
    }

    public function settle(Charge $charge, Admin $admin, string $reference): void
    {
        $reference = trim($reference);
        if ($reference === '' || mb_strlen($reference) > 100) {
            throw ValidationException::withMessages(['reference' => 'Enter the payment reference, at most 100 characters.']);
        }

        DB::transaction(function () use ($charge, $admin, $reference): void {
            $locked = Charge::query()->whereKey($charge->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->settled_at !== null) {
                throw StaleStateException::field('reference', 'This charge is already settled.');
            }
            $locked->forceFill(['settled_at' => now(), 'settled_by_admin_id' => $admin->getKey(), 'settlement_reference' => $reference])->save();
            OperationsAuditLog::record($admin, 'return_charge_settled', $locked, 'Return charge #'.$locked->id,
                ['settled' => false], ['settled' => true, 'amount_minor' => $locked->amount_minor, 'direction' => $locked->direction, 'reference' => $reference]);
        }, 3);
    }

    private function put(ParcelReturn $record, string $reason, string $direction, int $amount, ?int $providerId): void
    {
        $charge = Charge::query()->where('parcel_return_id', $record->id)->where('reason', $reason)->lockForUpdate()->first();
        if ($charge?->settled_at !== null) {
            return;
        }
        if ($amount <= 0 || $providerId === null) {
            $charge?->delete();

            return;
        }

        ($charge ?? new Charge)->forceFill([
            'logistics_provider_id' => $providerId,
            'parcel_return_id' => $record->id,
            'seller_order_id' => $record->seller_order_id,
            'direction' => $direction,
            'reason' => $reason,
            'amount_minor' => $amount,
        ])->save();
    }

    /** The company that delivered the parcel and collected the cash. */
    private function deliveringProviderId(ParcelReturn $record): ?int
    {
        $order = $record->sellerOrder;

        return CodRemittance::query()->where('seller_order_id', $order->id)->value('logistics_provider_id')
            ?? $order->handoff_destination_provider_id
            ?? $order->carrier_provider_id;
    }

    /** The company whose Rider collected the parcel from the buyer. */
    private function collectingProviderId(ParcelReturn $record): ?int
    {
        $courierId = $record->collection_courier_id;

        return ($courierId ? Courier::query()->whereKey($courierId)->value('logistics_provider_id') : null)
            ?? $record->origin_provider_id;
    }
}
