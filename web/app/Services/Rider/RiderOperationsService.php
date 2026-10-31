<?php

namespace App\Services\Rider;

use App\Enums\DeliveryFailureReason;
use App\Enums\OrderActor;
use App\Enums\OrderStatus;
use App\Models\Logistics\CodRemittance;
use App\Models\Logistics\DeliveryProof;
use App\Models\Rider\Courier;
use App\Models\Rider\Rider;
use App\Models\Shared\Payment;
use App\Models\Shared\SellerOrder;
use App\Notifications\Logistics\RiderParcelActivity;
use App\Services\Logistics\CourierAssignmentService;
use App\Services\Logistics\DeliveryOtpService;
use App\Services\Logistics\ParcelHandoffVerification;
use App\Services\Logistics\ParcelIncidentService;
use App\Services\Seller\SellerOrderStatusService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Rider-facing operational rules.
 *
 * Every method re-checks the SAME invariants the UI relies on — the rider is
 * operational (approved/active, verified, station-owned, linked Courier) and
 * the parcel belongs to that Courier. Status changes always go through
 * SellerOrderStatusService; this service never writes `status` itself.
 */
class RiderOperationsService
{
    /** Parcels waiting at the seller or in transit to the origin hub. */
    public const PICKUP_STATUSES = [
        OrderStatus::ReadyForPickup,
        OrderStatus::PickedUp,
    ];

    /** Parcels on the delivery leg. */
    public const DELIVERY_STATUSES = [
        OrderStatus::AssignedToRider,
        OrderStatus::OutForDelivery,
        OrderStatus::DeliveryFailed,
    ];

    /** Concluded parcels shown in Delivery History. */
    public const HISTORY_STATUSES = [
        OrderStatus::Delivered,
        OrderStatus::Completed,
        OrderStatus::DeliveryFailed,
        OrderStatus::Returned,
    ];

    public function __construct(
        private readonly SellerOrderStatusService $status = new SellerOrderStatusService,
        private readonly CourierAssignmentService $assignment = new CourierAssignmentService,
    ) {}

    /**
     * Resolve (and re-verify) the Courier that carries this rider's work.
     *
     * @throws HttpException when the
     *                       rider is no longer operational or has no fleet record.
     */
    public function operationalCourier(Rider $rider): Courier
    {
        $rider->loadMissing(['station', 'courier']);

        abort_unless(
            $rider->isOperational(),
            403,
            'Your rider account is not allowed to perform this action.',
        );

        /** @var Courier $courier */
        $courier = $rider->courier;

        return $courier;
    }

    /**
     * Dashboard snapshot: real counts, never demo data.
     *
     * @return array{
     *     pending_pickups: int,
     *     active_deliveries: int,
     *     completed_deliveries: int,
     *     needs_attention: int,
     *     attention_parcels: Collection<int, SellerOrder>
     * }
     */
    public function dashboard(Rider $rider): array
    {
        $courier = $this->operationalCourier($rider);

        $attention = SellerOrder::query()->whereDoesntHave('parcelReturn')
            ->where(function ($query) use ($courier): void {
                $query->where(function ($flagged) use ($courier): void {
                    $flagged->where('needs_reassignment', true)
                        ->where(function ($parcel) use ($courier): void {
                            $parcel->where('courier_id', $courier->id)
                                ->orWhere('delivery_courier_id', $courier->id);
                        });
                })->orWhere(function ($failed) use ($courier): void {
                    $failed->where('status', OrderStatus::DeliveryFailed)
                        ->where('delivery_courier_id', $courier->id);
                });
            });

        return [
            'pending_pickups' => $this->pickupQuery($courier)
                ->where('status', OrderStatus::ReadyForPickup)
                ->count(),
            'active_deliveries' => $this->deliveryQuery($courier)
                ->whereIn('status', [OrderStatus::AssignedToRider, OrderStatus::OutForDelivery])
                ->count(),
            'completed_deliveries' => SellerOrder::query()
                ->where('delivery_courier_id', $courier->id)
                ->whereIn('status', [OrderStatus::Delivered, OrderStatus::Completed])
                ->count(),
            'needs_attention' => (clone $attention)->count(),
            'attention_parcels' => $attention
                ->with(['order.buyer', 'seller', 'destinationArea'])
                ->orderByDesc('reassignment_flagged_at')
                ->limit(5)
                ->get(),
        ];
    }

    /** @return Collection<int, SellerOrder> */
    public function pickupAssignments(Rider $rider): Collection
    {
        $courier = $this->operationalCourier($rider);

        return $this->pickupQuery($courier)
            ->orderBy('created_at')
            ->get();
    }

    /** @return Collection<int, SellerOrder> */
    public function deliveryAssignments(Rider $rider): Collection
    {
        $courier = $this->operationalCourier($rider);

        return $this->deliveryQuery($courier)
            ->orderBy('created_at')
            ->get();
    }

    /** @return Collection<int, SellerOrder> */
    public function deliveryHistory(Rider $rider): Collection
    {
        $courier = $this->operationalCourier($rider);

        return SellerOrder::query()
            ->where('delivery_courier_id', $courier->id)
            ->where(fn ($q) => $q->whereIn('status', self::HISTORY_STATUSES)->orWhereHas('parcelReturn'))
            ->with(['order.buyer', 'seller', 'deliveryProof'])
            ->orderByDesc('updated_at')
            ->limit(100)
            ->get();
    }

    /**
     * Rider confirms the physical collection from the seller.
     *
     * ReadyForPickup → PickedUp. Idempotent: a second attempt on a parcel
     * already PickedUp returns without writing another history row.
     */
    public function confirmPickup(Rider $rider, SellerOrder $sellerOrder, ?string $verificationMethod = null): SellerOrder
    {
        $courier = $this->operationalCourier($rider);

        [$order, $changed] = DB::transaction(function () use ($rider, $courier, $sellerOrder, $verificationMethod): array {
            $order = SellerOrder::query()->whereKey($sellerOrder->id)->lockForUpdate()->firstOrFail();
            ParcelIncidentService::assertOperationsAllowed($order);

            $this->assertPickupOwnership($courier, $order);

            if ($order->status === OrderStatus::PickedUp) {
                return [$order, false];
            }

            if ($order->status !== OrderStatus::ReadyForPickup) {
                throw ValidationException::withMessages([
                    'parcel' => 'This parcel is no longer available for pickup.',
                ]);
            }

            $this->assertOriginCovered($courier, $order);

            // The seller → rider handover moment printed on the waybill.
            if ($order->handover_at === null) {
                $order->forceFill(['handover_at' => now()])->save();
            }

            $this->status->transition(
                $order,
                OrderStatus::PickedUp,
                note: 'Parcel collected from seller.'.ParcelHandoffVerification::noteSuffix($verificationMethod),
                changedBy: $rider->fullName(),
                actor: OrderActor::Rider,
                actorId: $courier->id,
                source: 'web',
            );

            return [$order->fresh(), true];
        });

        if ($changed) {
            $this->notifyProvider($courier, $order, RiderParcelActivity::PICKUP, 'The rider collected the parcel from the seller.');
        }

        return $order;
    }

    /**
     * Rider starts the delivery run: AssignedToRider → OutForDelivery.
     *
     * The parcel must already be on the delivery leg (sorted + assigned by
     * Logistics) and, when a destination area was recorded at sorting, that
     * area must belong to the rider's own Station Hub — so a rider can never
     * skip a station handoff or province sorting.
     */
    public function startDelivery(Rider $rider, SellerOrder $sellerOrder, ?string $verificationMethod = null): SellerOrder
    {
        $courier = $this->operationalCourier($rider);

        [$order, $changed] = DB::transaction(function () use ($rider, $courier, $sellerOrder, $verificationMethod): array {
            $order = SellerOrder::query()->whereKey($sellerOrder->id)->lockForUpdate()->firstOrFail();
            ParcelIncidentService::assertOperationsAllowed($order);

            $this->assertDeliveryOwnership($courier, $order);

            if ($order->status === OrderStatus::OutForDelivery) {
                return [$order, false];
            }

            if ($order->status !== OrderStatus::AssignedToRider) {
                throw ValidationException::withMessages([
                    'parcel' => 'This parcel is not ready to go out for delivery.',
                ]);
            }

            $this->assertAtDestinationStation($courier, $order);

            $this->status->transition(
                $order,
                OrderStatus::OutForDelivery,
                note: 'Rider started the delivery run.'.ParcelHandoffVerification::noteSuffix($verificationMethod),
                changedBy: $rider->fullName(),
                actor: OrderActor::Rider,
                actorId: $courier->id,
                source: 'web',
            );

            return [$order->fresh(), true];
        });

        if ($changed) {
            $this->notifyProvider($courier, $order, RiderParcelActivity::OUT_FOR_DELIVERY, 'The rider is on the way to the buyer.');
        }

        return $order;
    }

    /**
     * Successful delivery: proof of delivery + COD collection + status change,
     * all inside ONE transaction so a failed hop leaves neither evidence nor
     * a payment in a half-written state.
     *
     * @param  array{photo: UploadedFile, received_by?: ?string, notes?: ?string, cod_amount_minor?: ?int, verification_method?: ?string, delivery_otp?: ?string}  $data
     */
    public function completeDelivery(Rider $rider, SellerOrder $sellerOrder, array $data): SellerOrder
    {
        $courier = $this->operationalCourier($rider);
        $fresh = $sellerOrder->fresh() ?? $sellerOrder;
        $this->assertDeliveryOwnership($courier, $fresh);
        $verifiedOtp = in_array($fresh->status, [OrderStatus::Delivered, OrderStatus::Completed], true)
            ? null
            : app(DeliveryOtpService::class)->verify($rider, $fresh, $data['delivery_otp'] ?? null);
        $storedPath = null;

        try {
            [$order, $changed] = DB::transaction(function () use ($rider, $courier, $sellerOrder, $data, $verifiedOtp, &$storedPath): array {
                $order = SellerOrder::query()->whereKey($sellerOrder->id)->lockForUpdate()->firstOrFail();
                ParcelIncidentService::assertOperationsAllowed($order);

                $this->assertDeliveryOwnership($courier, $order);

                // Duplicate confirmation: the parcel is already delivered and
                // its proof/payment are final. Do nothing.
                if ($order->status === OrderStatus::Delivered || $order->status === OrderStatus::Completed) {
                    return [$order, false];
                }

                if ($order->status !== OrderStatus::OutForDelivery) {
                    throw ValidationException::withMessages([
                        'parcel' => 'This parcel is not out for delivery.',
                    ]);
                }

                app(DeliveryOtpService::class)->consume($order, $verifiedOtp);

                $collectedPayment = $this->recordCodCollection($order, $courier, $data['cod_amount_minor'] ?? null);

                $proof = DeliveryProof::query()->where('seller_order_id', $order->id)->lockForUpdate()->first();

                if ($proof === null) {
                    /** @var UploadedFile $photo */
                    $photo = $data['photo'];
                    $storedPath = $photo->store('delivery-proofs/'.$order->id, 'local');

                    DeliveryProof::query()->create([
                        'seller_order_id' => $order->id,
                        'courier_id' => $courier->id,
                        'rider_id' => $rider->id,
                        'photo_path' => $storedPath,
                        'photo_mime' => $photo->getClientMimeType(),
                        'photo_size' => $photo->getSize(),
                        'received_by' => $data['received_by'] ?? null,
                        'notes' => $data['notes'] ?? null,
                        'submitted_at' => now(),
                    ]);
                }

                $this->status->transition(
                    $order,
                    OrderStatus::Delivered,
                    note: 'Parcel delivered to buyer with proof of delivery.'.ParcelHandoffVerification::noteSuffix($data['verification_method'] ?? null),
                    changedBy: $rider->fullName(),
                    actor: OrderActor::Rider,
                    actorId: $courier->id,
                    source: 'web',
                );

                if ($collectedPayment !== null) {
                    // The parcel/payment locks serialize delivery retries; the
                    // unique payment_id also enforces one obligation in storage.
                    $remittance = CodRemittance::query()->firstOrNew([
                        'payment_id' => $collectedPayment->id,
                    ]);

                    if (! $remittance->exists) {
                        $remittance->forceFill([
                            'seller_order_id' => $collectedPayment->seller_order_id,
                            'courier_id' => $collectedPayment->collected_by_id,
                            'logistics_provider_id' => $courier->logistics_provider_id,
                            'amount_minor' => $collectedPayment->collected_amount_minor,
                            'status' => CodRemittance::PENDING_REMITTANCE,
                        ])->save();
                    }
                }

                return [$order->fresh(), true];
            });
        } catch (Throwable $e) {
            // Files are not transactional: remove the orphan if the DB write
            // rolled back.
            if ($storedPath !== null) {
                Storage::disk('local')->delete($storedPath);
            }

            throw $e;
        }

        if ($changed) {
            $this->notifyProvider($courier, $order, RiderParcelActivity::DELIVERED, 'Proof of delivery was recorded.');
        }

        return $order;
    }

    /**
     * Failed delivery attempt: OutForDelivery → DeliveryFailed, counting the
     * attempt and preserving the reason for Logistics. Idempotent.
     */
    public function failDelivery(
        Rider $rider,
        SellerOrder $sellerOrder,
        DeliveryFailureReason $reason,
        ?string $notes = null,
        ?string $verificationMethod = null,
    ): SellerOrder {
        $courier = $this->operationalCourier($rider);

        [$order, $changed] = DB::transaction(function () use ($rider, $courier, $sellerOrder, $reason, $notes, $verificationMethod): array {
            $order = SellerOrder::query()->whereKey($sellerOrder->id)->lockForUpdate()->firstOrFail();
            ParcelIncidentService::assertOperationsAllowed($order);

            $this->assertDeliveryOwnership($courier, $order);

            if ($order->status === OrderStatus::DeliveryFailed) {
                return [$order, false];
            }

            if ($order->status !== OrderStatus::OutForDelivery) {
                throw ValidationException::withMessages([
                    'parcel' => 'This parcel is not out for delivery.',
                ]);
            }

            $note = "Delivery failed: {$reason->label()}.";
            if (filled($notes)) {
                $note .= " Notes: {$notes}";
            }
            $note .= ParcelHandoffVerification::noteSuffix($verificationMethod);

            $order->forceFill([
                'delivery_failure_reason' => $reason->value,
                'delivery_failure_notes' => $notes,
            ])->save();

            $this->status->transition(
                $order,
                OrderStatus::DeliveryFailed,
                note: $note,
                changedBy: $rider->fullName(),
                actor: OrderActor::Rider,
                actorId: $courier->id,
                source: 'web',
            );

            return [$order->fresh(), true];
        });

        if ($changed) {
            $this->notifyProvider(
                $courier,
                $order,
                RiderParcelActivity::DELIVERY_FAILED,
                "The rider could not complete the delivery: {$reason->label()}.",
            );
        }

        return $order;
    }

    // ── Queries ─────────────────────────────────────────────────────────────

    private function pickupQuery(Courier $courier): Builder
    {
        return SellerOrder::query()
            ->where('courier_id', $courier->id)
            ->whereIn('status', self::PICKUP_STATUSES)
            ->with(['order.buyer', 'seller', 'orderItems.product', 'courier']);
    }

    private function deliveryQuery(Courier $courier): Builder
    {
        return SellerOrder::query()
            ->where('delivery_courier_id', $courier->id)
            ->whereIn('status', self::DELIVERY_STATUSES)->whereDoesntHave('parcelReturn')
            ->with(['order.buyer', 'seller', 'orderItems.product', 'payment', 'destinationArea']);
    }

    // ── Guards ──────────────────────────────────────────────────────────────

    /**
     * Assignment check for controllers: must run before any idempotent success
     * response so unauthorized actors never see a completed-handoff flash.
     */
    public function assertPickupAssigned(Rider $rider, SellerOrder $order): void
    {
        $this->assertPickupOwnership($this->operationalCourier($rider), $order);
    }

    /**
     * Release the rider's pickup reservation after a first failed attempt.
     *
     * The courier is made available again but remains recorded on the parcel
     * so the same rider can make the one allowed retry; a second failure then
     * cancels the order through SellerOrderStatusService.
     */
    public function releasePickupReservation(SellerOrder $sellerOrder, Rider $rider): void
    {
        $courier = $this->operationalCourier($rider);
        $this->assertPickupOwnership($courier, $sellerOrder);

        $this->assignment->release($courier);
    }

    /**
     * Assignment check for controllers: must run before any idempotent success
     * response so unauthorized actors never see a completed-handoff flash.
     */
    public function assertDeliveryAssigned(Rider $rider, SellerOrder $order): void
    {
        $this->assertDeliveryOwnership($this->operationalCourier($rider), $order);
    }

    private function assertPickupOwnership(Courier $courier, SellerOrder $order): void
    {
        abort_if(
            (int) $order->courier_id !== (int) $courier->id
                || ($order->currentCustodyProviderId() !== null && $order->currentCustodyProviderId() !== (int) $courier->logistics_provider_id),
            403,
            'This pickup is not assigned to you.',
        );
    }

    private function assertDeliveryOwnership(Courier $courier, SellerOrder $order): void
    {
        abort_if(
            (int) $order->delivery_courier_id !== (int) $courier->id
                || ($order->currentCustodyProviderId() !== null && $order->currentCustodyProviderId() !== (int) $courier->logistics_provider_id),
            403,
            'This delivery is not assigned to you.',
        );
    }

    /**
     * Pickup must happen inside the rider's station coverage. Legacy rows with
     * no recorded origin fall back to the destination municipality, mirroring
     * the assignment rule.
     */
    private function assertOriginCovered(Courier $courier, SellerOrder $order): void
    {
        if (filled($order->sellerBarangayCode()) && $this->assignment->coversBarangay($courier, $order->sellerBarangayCode())) {
            return;
        }
        // Reservations made before barangay coverage existed still belong to their rider.
        if ($order->courier_id !== null && $order->deliveryAssignments()->where('role', 'pickup')->exists()) {
            return;
        }
        $code = $order->sellerMunicipalityCode();
        $name = $order->sellerMunicipality();

        if (blank($code) && blank($name)) {
            $code = $order->buyerMunicipalityCode();
            $name = $order->buyerMunicipality();
        }

        if (blank($code) && blank($name)) {
            return;
        }

        if (! $this->assignment->coversMunicipality($courier, $code, $name)) {
            throw ValidationException::withMessages([
                'parcel' => 'This parcel is outside your Station Hub coverage.',
            ]);
        }
    }

    /**
     * When sorting recorded a destination area, its Station Hub must be the
     * rider's own station. Legacy parcels without a destination area are
     * allowed once they reached AssignedToRider (which already required a
     * station handoff and delivery assignment).
     */
    private function assertAtDestinationStation(Courier $courier, SellerOrder $order): void
    {
        $stationId = $order->destinationArea?->station_hub_id;

        if ($stationId !== null && (int) $stationId !== (int) $courier->rider?->station_hub_id) {
            throw ValidationException::withMessages([
                'parcel' => 'This parcel has not arrived at your Station Hub yet.',
            ]);
        }
    }

    /**
     * Cash is collected ONLY at the buyer's door, never at pickup or a station
     * handoff. The exact amount due is validated against the payments row and
     * the collection is recorded under the same row lock as the status change.
     */
    private function recordCodCollection(SellerOrder $order, Courier $courier, ?int $amountMinor): ?Payment
    {
        $payment = $order->payment()->lockForUpdate()->first();

        if ($payment === null || ! $payment->requiresCollection()) {
            return null;
        }

        $due = (int) $payment->amount_minor;
        $collected = $amountMinor ?? -1;

        if ($collected !== $due) {
            throw ValidationException::withMessages([
                'cod_amount_minor' => 'Collect exactly ₱'.number_format($due / 100, 2).' from the buyer.',
            ]);
        }

        return $payment->recordCodCollection($collected, 'rider', $courier->id) ? $payment : null;
    }

    private function notifyProvider(Courier $courier, SellerOrder $order, string $event, ?string $detail = null): void
    {
        $courier->provider?->notify(new RiderParcelActivity($order, $event, $detail));
    }
}
