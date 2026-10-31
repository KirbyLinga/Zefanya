<?php

namespace App\Services\Logistics;

use App\Enums\LogisticsProviderStatus;
use App\Enums\OrderActor;
use App\Enums\OrderStatus;
use App\Models\Admin\OperationsAuditLog;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Shared\SellerOrder;
use App\Services\Seller\SellerOrderStatusService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class LogisticsStatusOverrideService
{
    public function __construct(
        private readonly SellerOrderStatusService $statuses = new SellerOrderStatusService,
        private readonly LogisticsHubService $hubs = new LogisticsHubService,
        private readonly CourierAssignmentService $assignments = new CourierAssignmentService,
    ) {}

    /** @return list<OrderStatus> */
    public function options(SellerOrder $parcel): array
    {
        if ($parcel->hasActiveReturn()) {
            return [];
        }

        return match ($parcel->status) {
            OrderStatus::PickedUp => [OrderStatus::AtSortingCenter],
            OrderStatus::AtSortingCenter => [OrderStatus::Sorted],
            OrderStatus::Sorted => [OrderStatus::AssignedToRider],
            OrderStatus::DeliveryFailed => SellerOrderStatusService::canRetryDelivery($parcel) ? [OrderStatus::AssignedToRider] : [],
            default => [],
        };
    }

    public function override(LogisticsProvider $provider, SellerOrder $parcel, OrderStatus $expected, OrderStatus $target, string $reason): SellerOrder
    {
        $reason = trim($reason);
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'min:10', 'max:300']])->validate();

        return DB::transaction(function () use ($provider, $parcel, $expected, $target, $reason): SellerOrder {
            $this->hubs->lockProvider($provider->id);
            $provider = $provider->fresh();
            abort_unless($provider->status === LogisticsProviderStatus::Approved && $provider->archived_at === null, 403);
            $parcel = SellerOrder::query()->lockForUpdate()->findOrFail($parcel->id);
            Gate::forUser($provider)->authorize('advance-shipment', $parcel);
            ParcelIncidentService::assertOperationsAllowed($parcel);
            if ($parcel->status !== $expected) {
                throw ValidationException::withMessages(['status' => 'The parcel status changed. Refresh the page before applying an override.']);
            }
            if (! in_array($target, $this->options($parcel), true)) {
                throw ValidationException::withMessages(['status' => 'This status change is not available to Logistics. Use the authorized rider, buyer or physical return workflow.']);
            }
            $old = $parcel->status;
            if ($target === OrderStatus::AtSortingCenter) {
                $this->hubs->requireOriginStationHubId($provider->id, $parcel);
            } elseif ($target === OrderStatus::Sorted) {
                if ($this->hubs->sortingHubId($provider->id, $parcel) === null) {
                    throw ValidationException::withMessages(['status' => 'Resolve the authorized sorting hub before changing this parcel status.']);
                }
                $area = $this->hubs->areaForBarangay($provider->id, $parcel->buyerBarangayCode());
                if ($area !== null) {
                    $parcel->forceFill(['destination_area_id' => $area->id])->save();
                }
            }
            if ($target === OrderStatus::AssignedToRider) {
                $courier = $this->assignments->assignDelivery($parcel, $provider, rescheduled: $old === OrderStatus::DeliveryFailed, reason: $reason);
                if ($courier === null) {
                    throw ValidationException::withMessages(['status' => 'No eligible delivery rider is available. Resolve routing and rider availability before retrying.']);
                }
            } else {
                $this->statuses->transition($parcel, $target,
                    note: 'Manual Logistics status override. Reason: '.$reason,
                    changedBy: $provider->fullName(), actor: OrderActor::Logistics,
                    actorId: $provider->id, source: 'web', expectedFrom: $expected);
                if ($target === OrderStatus::AtSortingCenter && $parcel->courier !== null) {
                    $this->assignments->release($parcel->courier);
                }
            }
            $parcel->refresh();
            OperationsAuditLog::record($provider, 'shipment_status_overridden', $parcel,
                $parcel->tracking_number ?? 'Parcel #'.$parcel->id,
                ['status' => $old->value], ['status' => $parcel->status->value], $reason);

            return $parcel;
        });
    }
}
