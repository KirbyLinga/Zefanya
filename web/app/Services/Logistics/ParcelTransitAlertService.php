<?php

namespace App\Services\Logistics;

use App\Enums\ManifestStatus;
use App\Enums\OrderStatus;
use App\Enums\ParcelHandoffStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Logistics\Manifest;
use App\Models\Logistics\ParcelHandoff;
use App\Models\Shared\SellerOrder;

class ParcelTransitAlertService
{
    public function __construct(
        private readonly ParcelLossService $losses,
        private readonly ParcelIncidentService $incidents,
        private readonly StuckTransferService $transfers,
    ) {}

    /** @return array{key: string, label: string, text: string}|null */
    public function for(SellerOrder $shipment, bool $buyerFacing): ?array
    {
        if ($shipment->status === OrderStatus::Lost) {
            $case = $this->losses->caseFor($shipment);
            if ($case?->recovered_at !== null) {
                return ['key' => 'recovered', 'label' => 'Parcel found', 'text' => 'The parcel was found at a hub. This shipment remains closed.'];
            }
            $payment = $buyerFacing ? $shipment->payment : null;
            $uncollected = $payment?->method === PaymentMethod::Cod && $payment->status === PaymentStatus::Cancelled
                && $payment->collected_by_id === null && (int) $payment->collected_amount_minor === 0;

            return ['key' => 'lost', 'label' => 'Shipment issue', 'text' => 'This parcel was confirmed lost.'
                .($uncollected ? ' You paid nothing for this shipment.' : '')];
        }

        if ($this->incidents->hasOpenIncident($shipment)) {
            return ['key' => 'investigating', 'label' => 'Under investigation', 'text' => 'Logistics is checking this parcel before it can move again.'];
        }
        if ($shipment->status !== OrderStatus::InTransit) {
            return null;
        }

        $batch = Manifest::query()->where('status', ManifestStatus::Dispatched)
            ->whereHas('items', fn ($query) => $query->where('seller_order_id', $shipment->id)->whereNull('received_at')->whereNull('released_at'))
            ->latest('id')->first();
        $transfer = $batch ?? ParcelHandoff::query()->where('seller_order_id', $shipment->id)
            ->where('status', ParcelHandoffStatus::InTransit)->latest('id')->first();

        return $transfer !== null && $this->transfers->isOverdue($transfer)
            ? ['key' => 'delayed', 'label' => 'Delayed', 'text' => 'The parcel is still in transit. This is not a loss.']
            : null;
    }
}
