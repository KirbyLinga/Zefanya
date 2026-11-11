<?php

namespace App\Services\Logistics;

use App\Enums\OrderStatus;
use App\Enums\ParcelHandoffStatus;
use App\Enums\ParcelIncidentStatus;
use App\Models\Logistics\ManifestItem;
use App\Models\Logistics\ParcelHandoff;
use App\Models\Shared\Incidents;
use App\Models\Shared\SellerOrder;

/**
 * The one-word state a Buyer or Seller sees on a parcel that is not moving
 * normally: Delayed, Investigating, Lost or Recovered. Derived from what
 * already exists (an overdue transfer, an open exception, a confirmed loss);
 * it never changes a status and never calls a parcel lost before Admin does.
 */
class ParcelTransitAlertService
{
    public const DELAYED = 'delayed';

    public const INVESTIGATING = 'investigating';

    public const LOST = 'lost';

    public const RECOVERED = 'recovered';

    /** @var list<OrderStatus> Parcels that can still be on a transfer. */
    private const MOVING = [
        OrderStatus::PickedUp, OrderStatus::AtSortingCenter, OrderStatus::Sorted, OrderStatus::InTransit,
        OrderStatus::ArrivedAtHub, OrderStatus::AssignedToRider, OrderStatus::OutForDelivery, OrderStatus::DeliveryFailed,
    ];

    /** @return array{key: string, label: string, text: string}|null */
    public function for(SellerOrder $parcel, bool $buyerFacing = false): ?array
    {
        $state = $this->stateOf($parcel);
        if ($state === null) {
            return null;
        }

        [$label, $buyer, $seller] = match ($state) {
            self::DELAYED => ['Delayed',
                'Your parcel is taking longer than expected between hubs. We are following it up with the delivery company. This is not a loss.',
                'The transfer carrying this parcel is overdue. The Logistics company has been told. This is not a loss.'],
            self::INVESTIGATING => ['Investigating',
                'We are looking into where your parcel is. You will be told as soon as we know more. You have not been charged.',
                'This parcel has been reported missing and is being investigated. Nothing is decided until Admin confirms.'],
            self::LOST => ['Lost',
                'This parcel was confirmed lost in transit. You paid nothing: this was cash on delivery and no cash was collected.',
                'Admin confirmed this parcel lost. The responsible Logistics company owes you its merchandise value. See Earnings.'],
            default => ['Recovered',
                'The parcel was found at a hub. This order stays closed while the delivery company and Zefanya decide what happens next.',
                'The parcel was found at a hub. The order stays closed as lost; Admin decides what happens next. Any compensation owed to you stands.'],
        };

        return ['key' => $state, 'label' => $label, 'text' => $buyerFacing ? $buyer : $seller];
    }

    private function stateOf(SellerOrder $parcel): ?string
    {
        if ($parcel->status === OrderStatus::Lost) {
            $case = app(ParcelLossService::class)->caseFor($parcel);

            return $case?->recovered_at !== null ? self::RECOVERED : self::LOST;
        }
        if (! in_array($parcel->status, self::MOVING, true)) {
            return null;
        }

        $open = Incidents::query()->where('incident_seller_order_id', $parcel->id)
            ->whereIn('type', ParcelIncidentService::ACTIVE_TYPES)
            ->whereIn('status', [ParcelIncidentStatus::Open->value, ParcelIncidentStatus::UnderReview->value])->exists();
        if ($open) {
            return self::INVESTIGATING;
        }

        return $this->onOverdueTransfer($parcel) ? self::DELAYED : null;
    }

    private function onOverdueTransfer(SellerOrder $parcel): bool
    {
        $stuck = app(StuckTransferService::class);

        $item = ManifestItem::query()->where('seller_order_id', $parcel->id)
            ->whereNull('received_at')->whereNull('released_at')
            ->whereHas('manifest', fn ($m) => $m->where('status', 'DISPATCHED'))
            ->with('manifest')->latest('id')->first();
        if ($item !== null && $stuck->isOverdue($item->manifest)) {
            return true;
        }

        $handoff = ParcelHandoff::query()->where('seller_order_id', $parcel->id)
            ->where('status', ParcelHandoffStatus::InTransit->value)->latest('id')->first();

        return $handoff !== null && $stuck->isOverdue($handoff);
    }
}
