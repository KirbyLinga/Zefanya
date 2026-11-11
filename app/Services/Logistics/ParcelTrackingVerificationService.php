<?php

namespace App\Services\Logistics;

use App\Enums\OrderStatus;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Rider\Rider;
use App\Models\Shared\SellerOrder;
use App\Services\Rider\RiderOperationsService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;

/**
 * Read-only parcel lookup by the opaque waybill tracking number.
 *
 * Scanning / typing a tracking code NEVER changes status — callers must use
 * the returned action URLs (existing authorized endpoints) for handoffs.
 */
class ParcelTrackingVerificationService
{
    public function __construct(
        private readonly RiderOperationsService $riderOps = new RiderOperationsService,
        private readonly ParcelHandoffVerification $handoffVerify = new ParcelHandoffVerification,
        private readonly ParcelHandoffVerification $parcelVerify = new ParcelHandoffVerification,
        private readonly LogisticsHubService $hubs = new LogisticsHubService,
    ) {}

    /**
     * @return array{parcel: array<string, mixed>, actions: list<array<string, mixed>>, context: string}
     */
    public function verifyForRider(Rider $rider, string $trackingNumber, string $method = ParcelHandoffVerification::METHOD_MANUAL): array
    {
        $order = $this->findByTracking($trackingNumber);
        $courier = $this->riderOps->operationalCourier($rider, allowWindDown: true);
        // A suspended company's wind-down rider may only finish parcels already out for delivery.
        $windingDown = $this->riderOps->isWindingDown($rider);

        $isPickup = ! $windingDown
            && (int) $order->courier_id === (int) $courier->id
            && in_array($order->status, RiderOperationsService::PICKUP_STATUSES, true);

        $isDelivery = (int) $order->delivery_courier_id === (int) $courier->id
            && in_array($order->status, $windingDown ? [OrderStatus::OutForDelivery] : [
                OrderStatus::AssignedToRider,
                OrderStatus::OutForDelivery,
                OrderStatus::DeliveryFailed,
            ], true);

        if (! $isPickup && ! $isDelivery) {
            throw new AuthorizationException('This parcel is not assigned to you.');
        }

        $actions = [];

        if ($isPickup && $order->status === OrderStatus::ReadyForPickup) {
            $token = $this->handoffVerify->issueForRider(
                $rider,
                $order,
                ParcelHandoffVerification::ACTION_CONFIRM_PICKUP,
                $method,
            );
            $actions[] = [
                'key' => 'confirm_pickup',
                'label' => 'Confirm Collection',
                'method' => 'POST',
                'url' => route('rider.pickups.confirm', $order),
                'confirm_message' => 'Confirm you have physically collected this parcel from the seller?',
                'verification_token' => $token,
            ];
        }

        if ($isDelivery && $order->status === OrderStatus::AssignedToRider) {
            $token = $this->handoffVerify->issueForRider(
                $rider,
                $order,
                ParcelHandoffVerification::ACTION_START_DELIVERY,
                $method,
            );
            $actions[] = [
                'key' => 'start_delivery',
                'label' => 'Start Delivery',
                'method' => 'POST',
                'url' => route('rider.deliveries.start', $order),
                'confirm_message' => 'Start the delivery run to the buyer?',
                'verification_token' => $token,
            ];
        }

        if ($isDelivery && $order->status === OrderStatus::OutForDelivery) {
            $completeToken = $this->handoffVerify->issueForRider(
                $rider,
                $order,
                ParcelHandoffVerification::ACTION_COMPLETE_DELIVERY,
                $method,
            );
            $failToken = $this->handoffVerify->issueForRider(
                $rider,
                $order,
                ParcelHandoffVerification::ACTION_FAIL_DELIVERY,
                $method,
            );
            $actions[] = [
                'key' => 'proof_of_delivery',
                'label' => 'Submit Proof of Delivery',
                'method' => 'GET',
                'url' => route('rider.deliveries.proof', [
                    'sellerOrder' => $order,
                    'verification_token' => $completeToken,
                ]),
                'confirm_message' => null,
                'verification_token' => $completeToken,
            ];
            $actions[] = [
                'key' => 'mark_failed',
                'label' => 'Mark Delivery Failed',
                'method' => 'GET',
                'url' => route('rider.deliveries.failed.form', [
                    'sellerOrder' => $order,
                    'verification_token' => $failToken,
                ]),
                'confirm_message' => null,
                'verification_token' => $failToken,
            ];
        }

        if ($isPickup || $isDelivery) {
            $actions[] = [
                'key' => 'digital_waybill',
                'label' => 'Open Digital Waybill',
                'method' => 'GET',
                'url' => route('rider.waybills.show', $order),
                'confirm_message' => null,
            ];
        }

        return [
            'parcel' => $this->parcelSummary($order),
            'actions' => $actions,
            'context' => $isPickup ? 'pickup' : 'delivery',
        ];
    }

    /**
     * @return array{parcel: array<string, mixed>, actions: list<array<string, mixed>>, context: string}
     */
    public function verifyForLogistics(
        LogisticsProvider $provider,
        string $trackingNumber,
        string $method = ParcelHandoffVerification::METHOD_MANUAL,
    ): array {
        $order = $this->findByTracking($trackingNumber);
        $order->loadMissing(['courier.provider', 'courier.rider', 'deliveryCourier.provider']);

        if (! Gate::forUser($provider)->allows('advance-shipment', $order)) {
            throw new AuthorizationException('Scan rejected: this parcel is not authorized for your logistics company.');
        }

        // Legacy parcels infer custody from their fleet. Explicit custody takes precedence.
        if ($order->currentCustodyProviderId() === null && $order->courier !== null
            && (int) $order->courier->logistics_provider_id !== (int) $provider->id) {
            throw new AuthorizationException('This parcel belongs to another logistics provider.');
        }

        if ($order->currentCustodyProviderId() === null && $order->courier === null
            && $order->deliveryCourier !== null
            && (int) $order->deliveryCourier->logistics_provider_id !== (int) $provider->id) {
            throw new AuthorizationException('This parcel belongs to another logistics provider.');
        }

        // Authoritative origin Station Hub from PSGC seller-barangay coverage.
        $stationHubId = $this->hubs->sortingHubId((int) $provider->id, $order);
        $actions = [];
        $context = 'ops';
        $needsStationCustody = in_array($order->status, [
            OrderStatus::PickedUp,
            OrderStatus::AtSortingCenter,
        ], true)
            && $order->courier !== null
            && (int) $order->courier->logistics_provider_id === (int) $provider->id;

        if ($needsStationCustody && $stationHubId === null) {
            throw new AuthorizationException(
                'No authorized Station Hub covers this parcel origin. Configure validated barangay coverage before confirming receipt or sorting.'
            );
        }

        if ($order->status === OrderStatus::PickedUp
            && $order->courier !== null
            && (int) $order->courier->logistics_provider_id === (int) $provider->id) {
            $context = 'station_receipt';
            $token = $this->parcelVerify->issueForLogistics(
                $provider,
                $order,
                ParcelHandoffVerification::ACTION_STATION_RECEIPT,
                $method,
                $stationHubId,
            );
            $actions[] = [
                'key' => 'confirm_station_receipt',
                'label' => 'Confirm Station Receipt',
                'method' => 'POST',
                'url' => route('logistics.incoming-parcels.toSortingCenter', $order),
                'confirm_message' => 'Confirm this parcel has been received at the Station Hub?',
                'verification_token' => $token,
            ];
        }

        /** @var RouteExecutionService $execution */
        $execution = app(RouteExecutionService::class);
        $awaitingSort = in_array($order->status, [OrderStatus::AtSortingCenter, OrderStatus::ArrivedAtHub], true);
        $sortingBlocked = $awaitingSort ? $execution->sortingBlockedReason($order) : null;

        if ($sortingBlocked !== null) {
            $context = 'transfer';
            $next = ($route = $order->parcelRoute()->first()) !== null ? $execution->nextDestination($route) : null;

            if ($next !== null && $execution->currentLocation($route) !== null) {
                $internal = (int) $next->logistics_provider_id === (int) $provider->id;
                $actions[] = [
                    'key' => $internal ? 'open_manifests' : 'open_handoffs',
                    'label' => ($internal ? 'Create manifest to ' : 'Request handoff to ').$next->name,
                    'method' => 'GET',
                    'url' => route($internal ? 'logistics.manifests.create' : 'logistics.handoffs.index'),
                    'confirm_message' => null,
                ];
            }
        } elseif ($awaitingSort && $stationHubId !== null) {
            $context = 'sorting';
            $token = $this->parcelVerify->issueForLogistics(
                $provider,
                $order,
                ParcelHandoffVerification::ACTION_SORT,
                $method,
                $stationHubId,
            );
            $actions[] = [
                'key' => 'sort_parcel',
                'label' => 'Sort Parcel',
                'method' => 'POST',
                'url' => route('logistics.parcel-sorting.sort', $order),
                'confirm_message' => 'Confirm sorting for this parcel?',
                'verification_token' => $token,
            ];
        }

        if ($order->status === OrderStatus::Sorted
            && ($order->currentCustodyProviderId() !== null || $order->courier !== null || $order->deliveryCourier !== null)) {
            $context = 'assign_delivery';
            $actions[] = [
                'key' => 'open_assignments',
                'label' => 'Open Assign deliveries',
                'method' => 'GET',
                'url' => route('logistics.delivery-assignments.index').'#stage-'.($order->delivery_courier_id ? 'dispatch' : 'rider'),
                'confirm_message' => null,
            ];
        }

        // A parcel back from a failed delivery attempt: the scan is the custody
        // proof that lets its final attempt go to another Rider.
        $failedReturnHubId = $order->status === OrderStatus::DeliveryFailed
            ? app(CourierAssignmentService::class)->failedReturnStationId($order) : null;
        if ($failedReturnHubId !== null && ! $order->hasActiveReturn() && ! $order->isBackAtStationAfterFailure()) {
            $context = 'failed_return_receipt';
            $actions[] = [
                'key' => 'receive_failed_return',
                'label' => 'Receive back at Station Hub',
                'method' => 'POST',
                'url' => route('logistics.delivery-assignments.receive-back', $order),
                'confirm_message' => 'Confirm this parcel is physically back at the Station Hub after the failed delivery attempt?',
                'verification_token' => $this->parcelVerify->issueForLogistics(
                    $provider, $order, ParcelHandoffVerification::ACTION_FAILED_RETURN_RECEIPT, $method, $failedReturnHubId,
                ),
            ];
        }

        // Every scan can open the parcel's full record, including scans that allow no action here.
        $actions[] = [
            'key' => 'open_details',
            'label' => 'View parcel details',
            'method' => 'GET',
            'url' => route('logistics.delivery-monitoring.show', $order),
            'confirm_message' => null,
        ];

        return [
            'parcel' => $this->parcelSummary($order),
            'actions' => $actions,
            'context' => $context,
            'message' => match ($context) {
                'station_receipt' => 'Parcel verified. Confirm receipt to receive it at the hub.',
                'transfer' => $sortingBlocked,
                'sorting' => 'Parcel already received at the hub. You may proceed with sorting.',
                'assign_delivery' => 'Parcel already received and sorted. Assign its delivery rider in Assign deliveries.',
                'failed_return_receipt' => 'Parcel verified after a failed delivery attempt. Receive it back at the Station Hub so its final attempt can go to any Rider.',
                default => 'Scan blocked: no hub receipt or sorting action is allowed while this parcel is '.$order->status->label().'.',
            },
        ];
    }

    private function findByTracking(string $trackingNumber): SellerOrder
    {
        $tracking = trim($trackingNumber);

        if ($tracking === '') {
            throw (new ModelNotFoundException)->setModel(SellerOrder::class);
        }

        $order = SellerOrder::query()
            ->where('tracking_number', $tracking)
            ->with([
                'order.buyer',
                'seller',
                'orderItems',
                'courier.provider',
                'deliveryCourier.provider',
                'payment',
            ])
            ->first();

        if ($order === null) {
            throw (new ModelNotFoundException)->setModel(SellerOrder::class, [$tracking]);
        }

        return $order;
    }

    /** @return array<string, mixed> */
    private function parcelSummary(SellerOrder $order): array
    {
        $addr = $order->order?->shipping_address ?? [];

        return [
            'id' => $order->id,
            'tracking_number' => $order->tracking_number,
            'status' => $order->status->value,
            'status_label' => $order->status->label(),
            'seller_name' => $order->seller?->business_name ?? $order->seller?->fullName() ?? 'Seller',
            'buyer_municipality' => $addr['municipality'] ?? $order->buyerMunicipality() ?? '—',
            'item_count' => (int) $order->orderItems->sum('quantity'),
            'weight' => $order->formattedParcelWeight(),
            'payment_method' => $order->payment?->method?->label(),
        ];
    }
}
