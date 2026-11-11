<?php

namespace App\Services\Logistics;

use App\Enums\ParcelHandoffStatus;
use App\Enums\ParcelIncidentType;
use App\Exceptions\StaleStateException;
use App\Models\Admin\Admin;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\LogisticsProviderLink;
use App\Models\Logistics\ParcelHandoff;
use App\Models\Logistics\ParcelHandoffException;
use App\Models\Logistics\ParcelRoute;
use App\Models\Shared\SellerOrder;
use App\Support\RuntimeSchema;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase 5.3–5.4 cross-provider handoff lifecycle.
 *
 * Handoff state is deliberately independent of SellerOrder.status. Accepting
 * reserves the destination, dispatching proves the parcel left the origin, and
 * only a verified destination receiving scan transfers physical custody.
 * Tracking numbers and canonical delivery statuses are never changed here.
 */
class ParcelHandoffService
{
    public const PHASE_DISPATCH = 'dispatch';

    public const PHASE_RECEIVE = 'receive';

    /** A cancelled in-transit handoff's parcel verified back at the sending hub. */
    public const PHASE_RECOVER = 'recover';

    public const ACTOR_LOGISTICS = 'logistics';

    /** @var list<string> */
    public const ACTIVE_STATUSES = ['REQUESTED', 'ACCEPTED', 'IN_TRANSIT'];

    /** @var list<string> */
    public const TERMINAL_ORDER_STATUSES = ['DELIVERED', 'COMPLETED', 'RETURNED', 'CANCELLED'];

    public function __construct(
        private readonly LogisticsHubService $hubs = new LogisticsHubService,
        private readonly ParcelHandoffVerification $verification = new ParcelHandoffVerification,
    ) {}

    /**
     * Check if a parcel is eligible for a handoff request (pickup confirmed).
     * Returns null if eligible, or an ineligibility reason string if not.
     *
     * For handoffs, only pickup confirmation is required.
     */
    public function ineligibilityReason(SellerOrder $parcel): ?string
    {
        if ($parcel->handover_at === null) {
            return 'Not yet picked up';
        }

        return null;
    }

    // Readable aliases used by HTTP controllers and feature tests.
    /** Resolution acknowledges remediation; it never moves a parcel or bypasses scans. */
    public function resolveException(ParcelHandoffException $exception, Admin $admin, string $note): void
    {
        abort_unless(in_array($admin->role, ['super_admin', 'admin', 'moderator'], true), 403);
        $this->require(filled(trim($note)) && mb_strlen($note) <= 1000, 'resolution_note', 'Provide a resolution note of 1000 characters or fewer.');

        DB::transaction(function () use ($exception, $admin, $note): void {
            $locked = ParcelHandoffException::query()->whereKey($exception->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === ParcelHandoffException::RESOLVED) {
                return;
            }
            $locked->forceFill([
                'status' => ParcelHandoffException::RESOLVED,
                'resolved_at' => now(),
                'resolved_by' => $admin->id,
                'resolution_note' => trim($note),
            ])->save();
        });
    }

    /**
     * @return Collection<int, LogisticsProviderLink>
     */
    public function eligibleDestinations(SellerOrder $parcel, LogisticsProvider $origin): Collection
    {
        return $this->eligibleLinks($parcel, $origin);
    }

    /**
     * Active, approved links whose destination can receive at this barangay.
     * Results are ordered by Admin priority (lowest value first), then id.
     *
     * @return Collection<int, LogisticsProviderLink>
     */
    public function serviceableLinks(
        LogisticsProvider $origin,
        ?string $barangayCode,
        ?string $provinceCode = null,
        bool $lock = false,
    ): Collection {
        if (blank($barangayCode)) {
            return collect();
        }

        $query = LogisticsProviderLink::query()
            ->where('origin_provider_id', $origin->id)
            ->where('status', LogisticsProviderLink::ACTIVE)
            ->with(['originProvider', 'destinationProvider'])
            ->orderBy('priority')
            ->orderBy('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get()
            ->filter(fn (LogisticsProviderLink $link): bool => $this->linkIsServiceable($link, $barangayCode, $provinceCode))
            ->values();
    }

    /**
     * Eligible destinations for one parcel, honouring the buyer's frozen
     * destination address.
     *
     * @return Collection<int, LogisticsProviderLink>
     */
    public function eligibleLinks(SellerOrder $parcel, LogisticsProvider $origin): Collection
    {
        $parcel->loadMissing(['order', 'seller']);

        if ($parcel->hasActiveReturn()) {
            return $this->serviceableLinks($origin, $this->routingBarangay($parcel), $this->buyerProvinceCode($parcel))
                ->filter(fn ($link) => (int) $link->destination_provider_id === (int) $parcel->parcelReturn->origin_provider_id)->values();
        }

        if (($route = $parcel->parcelRoute()->first()) !== null) {
            return $this->savedRouteLinks($parcel, $route, $origin);
        }

        return $this->prioritizePlannedDestination(
            $parcel,
            $this->serviceableLinks(
                $origin,
                $this->routingBarangay($parcel),
                $this->buyerProvinceCode($parcel),
            ),
        );
    }

    /**
     * Parcels physically held by this provider that can still be handed off.
     *
     * @return EloquentCollection<int, SellerOrder>
     */
    public function eligibleParcels(LogisticsProvider $provider, int $limit = 25): EloquentCollection
    {
        return SellerOrder::query()
            ->where(function ($query) use ($provider): void {
                $query->where('custody_provider_id', $provider->id)
                    ->orWhere(function ($inferred) use ($provider): void {
                        $inferred->whereNull('custody_provider_id')
                            ->where('carrier_provider_id', $provider->id);
                    });
            })
            ->whereNotIn('status', self::TERMINAL_ORDER_STATUSES)
            ->whereDoesntHave('parcelHandoffs', fn ($handoff) => $handoff->whereIn('status', self::ACTIVE_STATUSES))
            ->whereNotNull('handover_at')  // Must be picked up
            ->with(['order.buyer', 'seller', 'orderItems'])
            ->latest('id')
            ->limit($limit)
            ->get()
            ->filter(fn (SellerOrder $parcel): bool => $this->eligibleLinks($parcel, $provider)->isNotEmpty())
            ->values();
    }

    /**
     * Origin-initiated request. Selecting no link uses the highest-priority
     * serviceable destination. Repeating the same request is idempotent.
     */
    public function request(
        SellerOrder $parcel,
        LogisticsProvider $origin,
        LogisticsProviderLink|int|null $link = null,
        array $excludeLinkIds = [],
    ): ParcelHandoff {
        $requestedLinkId = $link instanceof LogisticsProviderLink ? (int) $link->id : ($link !== null ? (int) $link : null);

        return DB::transaction(function () use ($parcel, $origin, $requestedLinkId, $excludeLinkIds): ?ParcelHandoff {
            $parcel = $this->lockParcel($parcel);
            $this->require($origin->isApproved() && $origin->hasVerifiedProvince(), 'provider', 'Only an approved, province-verified provider can request a handoff.');
            abort_unless($parcel->currentCustodyProviderId() === (int) $origin->id, 403);
            $this->assertParcelOpen($parcel);

            // Enforce pickup eligibility
            $reason = $this->ineligibilityReason($parcel);
            if ($reason !== null) {
                throw ValidationException::withMessages([
                    'seller_order_id' => $reason,
                ]);
            }

            $existing = ParcelHandoff::query()
                ->where('seller_order_id', $parcel->id)
                ->whereIn('status', self::ACTIVE_STATUSES)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ($requestedLinkId === null || (int) $existing->logistics_provider_link_id === $requestedLinkId) {
                    return $existing;
                }

                throw ValidationException::withMessages([
                    'handoff' => 'This parcel already has an active cross-provider handoff.',
                ]);
            }

            return $this->createHandoff($parcel, $origin, $requestedLinkId, $excludeLinkIds, throwWhenUnavailable: false);
        }) ?? $this->throwUnavailable($parcel, $origin);
    }

    /**
     * Destination-only acceptance. This reserves the destination but does not
     * move physical custody.
     */
    public function accept(ParcelHandoff $handoff, LogisticsProvider $destination): ParcelHandoff
    {
        $this->assertDestinationProvider($handoff, $destination);

        return DB::transaction(function () use ($handoff, $destination): ParcelHandoff {
            $parcel = $this->lockParcel($handoff->sellerOrder()->firstOrFail());
            $locked = $this->lockHandoff($handoff);

            $this->assertDestinationProvider($locked, $destination);
            $this->conflict($locked->status === ParcelHandoffStatus::Requested, 'status', 'Only requested handoffs can be accepted.');
            $this->assertOriginCustody($parcel, $locked->originProvider()->firstOrFail());
            $this->assertLinkStillServiceable($locked, $parcel);

            $locked->forceFill([
                'status' => ParcelHandoffStatus::Accepted->value,
                'accepted_at' => now(),
                'accepted_by_type' => self::ACTOR_LOGISTICS,
                'accepted_by_id' => $destination->id,
            ])->save();

            return $locked->fresh(['sellerOrder', 'link', 'originProvider', 'destinationProvider', 'sendingHub', 'receivingHub']);
        });
    }

    /**
     * Destination-only rejection. The rejected row stays as history, then the
     * highest-priority remaining partner is requested automatically. If none
     * remains, an Admin-visible exception is opened. Custody never moves.
     */
    public function reject(
        ParcelHandoff $handoff,
        LogisticsProvider $destination,
        string $reason,
        bool $selectNext = true,
    ): ParcelHandoff {
        $this->assertDestinationProvider($handoff, $destination);

        return DB::transaction(function () use ($handoff, $destination, $reason, $selectNext): ParcelHandoff {
            $parcel = $this->lockParcel($handoff->sellerOrder()->firstOrFail());
            $locked = $this->lockHandoff($handoff);

            $this->assertDestinationProvider($locked, $destination);
            $this->conflict($locked->status === ParcelHandoffStatus::Requested, 'status', 'Only requested handoffs can be rejected.');
            $this->require(filled($reason) && mb_strlen($reason) <= 500, 'rejection_reason', 'Provide a rejection reason of 500 characters or fewer.');

            $locked->forceFill([
                'status' => ParcelHandoffStatus::Rejected->value,
                'rejected_at' => now(),
                'rejected_by_type' => self::ACTOR_LOGISTICS,
                'rejected_by_id' => $destination->id,
                'rejection_reason' => trim($reason),
            ])->save();

            if ($locked->logistics_provider_link_id === null) {
                // An Admin-authorized return has no other partner to try.
                $this->recordException($parcel, $locked->originProvider()->firstOrFail(), $this->routingBarangay($parcel));
            } elseif ($selectNext) {
                $origin = $locked->originProvider()->firstOrFail();

                try {
                    $this->createHandoff(
                        $parcel,
                        $origin,
                        requestedLinkId: null,
                        excludeLinkIds: [(int) $locked->logistics_provider_link_id],
                        throwWhenUnavailable: false,
                    );
                } catch (ValidationException) {
                    // The rejection itself must still commit. If the origin can
                    // no longer produce a valid sending hub, keep the parcel
                    // held and make the exception visible to Admin.
                    $this->recordException($parcel, $origin, $this->routingBarangay($parcel));
                }
            }

            return $locked->fresh(['sellerOrder', 'link', 'originProvider', 'destinationProvider', 'sendingHub', 'receivingHub']);
        });
    }

    /**
     * Issue a short-lived, single-use scan token bound to this handoff, parcel,
     * provider, and expected hub. Verification alone changes nothing.
     *
     * @return array<string, mixed>
     */
    public function verifyScan(
        ParcelHandoff $handoff,
        LogisticsProvider $provider,
        string $trackingNumber,
        string $phase,
        string $method = ParcelHandoffVerification::METHOD_MANUAL,
    ): array {
        $handoff = $handoff->fresh(['sellerOrder', 'originProvider', 'destinationProvider', 'sendingHub', 'receivingHub']);
        $this->assertParticipant($handoff, $provider);

        $trackingNumber = trim($trackingNumber);
        if ($trackingNumber === '') {
            throw ValidationException::withMessages([
                'tracking_number' => 'Scan the waybill QR or enter the tracking number.',
            ]);
        }

        if ($phase === self::PHASE_DISPATCH) {
            $this->require((int) $handoff->origin_provider_id === (int) $provider->id, 'provider', 'Only the sending provider can dispatch this handoff.');
            $this->conflict($handoff->status === ParcelHandoffStatus::Accepted, 'status', 'Only accepted handoffs can be dispatched.');
            $expectedHubId = (int) $handoff->sending_hub_id;
            $action = ParcelHandoffVerification::ACTION_HANDOFF_DISPATCH;
            $label = 'Dispatch to '.($handoff->destinationProvider?->business_name ?? 'destination provider');
            $url = route('logistics.handoffs.dispatch', $handoff);
            $confirm = 'Confirm this parcel physically leaves the sending hub? Custody stays with the origin until the destination verifies receipt.';
            $context = 'handoff_dispatch';
        } elseif ($phase === self::PHASE_RECEIVE) {
            $this->require((int) $handoff->destination_provider_id === (int) $provider->id, 'provider', 'Only the destination provider can receive this handoff.');
            $this->require($handoff->status === ParcelHandoffStatus::InTransit, 'status', 'Only in-transit handoffs can be received.');
            $expectedHubId = (int) $handoff->receiving_hub_id;
            $action = ParcelHandoffVerification::ACTION_HANDOFF_RECEIVE;
            $label = 'Receive at '.($handoff->receivingHub?->name ?? 'destination hub');
            $url = route('logistics.handoffs.receive', $handoff);
            $confirm = 'Confirm this parcel was physically received? This transfers custody to your provider and never changes delivery status.';
            $context = 'handoff_receive';
        } elseif ($phase === self::PHASE_RECOVER) {
            $this->require((int) $handoff->origin_provider_id === (int) $provider->id, 'provider', 'Only the sending provider can record this parcel back at its sending hub.');
            $this->conflict($handoff->awaitsRecovery(), 'status', 'Only a handoff cancelled after dispatch, and not yet recovered, needs a recovery scan.');
            $expectedHubId = (int) $handoff->sending_hub_id;
            $action = ParcelHandoffVerification::ACTION_HANDOFF_RECOVER;
            $label = 'Record back at '.($handoff->sendingHub?->name ?? 'the sending hub');
            $url = route('logistics.handoffs.recover', $handoff);
            $confirm = 'Confirm this parcel is physically back at the sending hub? It stays held as an exception until its route is decided.';
            $context = 'handoff_recover';
        } else {
            throw ValidationException::withMessages([
                'phase' => 'Choose whether this scan is for dispatch or receiving.',
            ]);
        }

        $order = $handoff->sellerOrder;
        if ($order === null || (string) $order->tracking_number !== $trackingNumber) {
            throw ValidationException::withMessages([
                'tracking_number' => 'That tracking number does not match this handoff.',
            ]);
        }

        $fromOrigin = $phase !== self::PHASE_RECEIVE;
        $hubOwnerId = $fromOrigin
            ? (int) $handoff->origin_provider_id
            : (int) $handoff->destination_provider_id;
        $this->assertHandoffHub($hubOwnerId, $expectedHubId, $fromOrigin ? 'sending_hub_id' : 'receiving_hub_id');
        if ($phase === self::PHASE_RECOVER) {
            // A cancelled transfer is held as an exception; only custody is checked.
            $this->require($order->currentCustodyProviderId() === (int) $handoff->origin_provider_id, 'seller_order_id', 'Only the provider holding this parcel can record it.');
        } else {
            $this->assertOriginCustody($order, $handoff->originProvider()->firstOrFail());
        }

        $token = $this->verification->issueForHandoff($provider, $handoff, $action, $expectedHubId, $method);

        return [
            'context' => $context,
            'handoff' => $this->handoffSummary($handoff),
            'parcel' => $this->parcelSummary($order),
            'actions' => [[
                'key' => $action,
                'label' => $label,
                'method' => 'POST',
                'url' => $url,
                'confirm_message' => $confirm,
                'verification_token' => $token,
            ]],
            'verification_token' => $token,
        ];
    }

    /**
     * Origin-only dispatch. Moves the handoff to IN_TRANSIT but does not grant
     * the destination custody.
     */
    public function dispatch(ParcelHandoff $handoff, LogisticsProvider $origin, ?string $token): ParcelHandoff
    {
        $this->assertOriginProvider($handoff, $origin);

        if ($handoff->fresh()->status === ParcelHandoffStatus::Accepted) {
            app(ParcelIncidentService::class)->detectUnavailableDeparture($handoff->sellerOrder()->firstOrFail(), $origin);
        }

        $dispatched = DB::transaction(function () use ($handoff, $origin, $token): ParcelHandoff {
            $parcel = $this->lockParcel($handoff->sellerOrder()->firstOrFail());
            $locked = $this->lockHandoff($handoff);

            $this->assertOriginProvider($locked, $origin);
            $this->conflict($locked->status === ParcelHandoffStatus::Accepted, 'status', 'Only accepted handoffs can be dispatched.');
            app(TransferCourierService::class)->assertReadyForDispatch($locked, $origin);
            app(RoutePlannerService::class)->requireAvailableDeparture($parcel);
            $this->assertOriginCustody($parcel, $origin);
            $hub = $this->assertHandoffHub((int) $origin->id, (int) $locked->sending_hub_id, 'sending_hub_id');

            $this->verification->assertValidForHandoff(
                $origin,
                $locked,
                ParcelHandoffVerification::ACTION_HANDOFF_DISPATCH,
                $token,
                (int) $hub->id,
            );

            $locked->forceFill([
                'status' => ParcelHandoffStatus::InTransit->value,
                'dispatched_at' => now(),
                'dispatched_by_type' => self::ACTOR_LOGISTICS,
                'dispatched_by_id' => $origin->id,
            ])->save();

            // Custody stays with the origin until the destination's verified scan.
            app(ParcelTransitStatusService::class)->departed($parcel, $origin,
                'Handed off from '.$hub->name.' to '.($locked->receivingHub?->name ?? 'the partner hub').' (handoff #'.$locked->id.').', 'handoff');

            app(ParcelReturnService::class)->movement($parcel, $locked);

            return $locked;
        });

        $this->verification->consumeAfterSuccess(fn () => $this->verification->consumeForHandoff(
            $origin,
            $dispatched,
            ParcelHandoffVerification::ACTION_HANDOFF_DISPATCH,
            $token,
            (int) $dispatched->sending_hub_id,
        ));

        return $dispatched->fresh(['sellerOrder', 'link', 'originProvider', 'destinationProvider', 'sendingHub', 'receivingHub']);
    }

    /**
     * Destination-only verified receipt. This is the single event that moves
     * physical custody. Canonical delivery status and tracking are untouched.
     */
    public function receive(ParcelHandoff $handoff, LogisticsProvider $destination, ?string $token): ParcelHandoff
    {
        $this->assertDestinationProvider($handoff, $destination);

        if ($handoff->fresh()->status === ParcelHandoffStatus::InTransit) {
            $this->verification->assertValidForHandoff($destination, $handoff,
                ParcelHandoffVerification::ACTION_HANDOFF_RECEIVE, $token, (int) $handoff->receiving_hub_id);
            app(ParcelIncidentService::class)->detectReceiptDiscrepancy($handoff->sellerOrder()->firstOrFail(),
                (int) $handoff->sending_hub_id, (int) $handoff->receiving_hub_id, $destination, $handoff);
        }

        $received = DB::transaction(function () use ($handoff, $destination, $token): ParcelHandoff {
            $parcel = $this->lockParcel($handoff->sellerOrder()->firstOrFail(), receiving: true);
            $locked = $this->lockHandoff($handoff);

            $this->assertDestinationProvider($locked, $destination);
            $this->conflict($locked->status === ParcelHandoffStatus::InTransit, 'status', 'Only an in-transit handoff can be received.');
            $this->assertOriginCustody($parcel, $locked->originProvider()->firstOrFail());
            app(ParcelLegService::class)->requireReceiptOrigin($parcel, (int) $locked->origin_provider_id, (int) $locked->sending_hub_id);
            $hub = $this->assertHandoffHub((int) $destination->id, (int) $locked->receiving_hub_id, 'receiving_hub_id');

            $this->verification->assertValidForHandoff(
                $destination,
                $locked,
                ParcelHandoffVerification::ACTION_HANDOFF_RECEIVE,
                $token,
                (int) $hub->id,
            );

            $locked->forceFill([
                'status' => ParcelHandoffStatus::Received->value,
                'received_at' => now(),
                'received_by_type' => self::ACTOR_LOGISTICS,
                'received_by_id' => $destination->id,
            ])->save();

            // Custody history lives on the handoff row. These fields only make
            // the current physical holder explicit for downstream operations.
            $parcel->forceFill([
                'custody_provider_id' => $destination->id,
                'custody_hub_id' => $hub->id,
            ])->save();
            app(ParcelLegService::class)->advanceAfterReceipt($parcel, $locked);
            app(ParcelTransitStatusService::class)->arrivedAtHub($parcel, $destination,
                'Received at '.$hub->name.' from handoff #'.$locked->id.'.', 'handoff');
            app(ParcelReturnService::class)->movement($parcel, $locked);

            return $locked;
        });

        $this->verification->consumeAfterSuccess(fn () => $this->verification->consumeForHandoff(
            $destination,
            $received,
            ParcelHandoffVerification::ACTION_HANDOFF_RECEIVE,
            $token,
            (int) $received->receiving_hub_id,
        ));

        app(CourierAssignmentService::class)->dispatchAfterCommit($received->sellerOrder);

        return $received->fresh(['sellerOrder', 'link', 'originProvider', 'destinationProvider', 'sendingHub', 'receivingHub']);
    }

    /**
     * The sending company cancels a handoff, with a reason.
     *
     * Before dispatch the parcel never left the sending hub, so it simply goes
     * back to the sending company's handoff queue. After dispatch, nothing is
     * assumed: custody stays with the sending company, the parcel stays in
     * transit, and an exception holds it until a verified recovery scan.
     */
    public function cancel(ParcelHandoff $handoff, LogisticsProvider $origin, string $reason): ParcelHandoff
    {
        $this->assertOriginProvider($handoff, $origin);
        $reason = trim($reason);
        $this->require($reason !== '' && mb_strlen($reason) <= 1000, 'cancellation_reason', 'Provide a cancellation reason of 1000 characters or fewer.');

        return DB::transaction(function () use ($handoff, $origin, $reason): ParcelHandoff {
            $parcel = SellerOrder::query()->whereKey($handoff->seller_order_id)->lockForUpdate()->firstOrFail();
            $locked = $this->lockHandoff($handoff);
            $this->assertOriginProvider($locked, $origin);

            if ($locked->status === ParcelHandoffStatus::Cancelled) {
                return $locked; // A repeated submit changes nothing.
            }
            $this->conflict(in_array($locked->status, [ParcelHandoffStatus::Requested, ParcelHandoffStatus::Accepted, ParcelHandoffStatus::InTransit], true),
                'status', 'Only a handoff that has not been received can be cancelled.');
            $leftTheHub = $locked->status === ParcelHandoffStatus::InTransit;

            $locked->forceFill([
                'status' => ParcelHandoffStatus::Cancelled->value,
                'cancelled_at' => now(),
                'cancelled_by_type' => self::ACTOR_LOGISTICS,
                'cancelled_by_id' => $origin->id,
                'cancellation_reason' => $reason,
            ])->save();

            if ($leftTheHub) {
                app(ParcelIncidentService::class)->recordRoutingBlocker($parcel, ParcelIncidentType::FailedTransfer,
                    'Dispatched handoff #'.$locked->id.' cancelled: '.$reason, (int) $origin->id);
            }

            return $locked;
        });
    }

    /**
     * Verified recovery of a parcel from a handoff cancelled after dispatch,
     * scanned back at the sending hub. Records where it is; its exception
     * still decides whether the route resumes or changes.
     */
    public function recover(ParcelHandoff $handoff, LogisticsProvider $origin, ?string $token): ParcelHandoff
    {
        $this->assertOriginProvider($handoff, $origin);

        $recovered = DB::transaction(function () use ($handoff, $origin, $token): ParcelHandoff {
            $parcel = SellerOrder::query()->whereKey($handoff->seller_order_id)->lockForUpdate()->firstOrFail();
            $locked = $this->lockHandoff($handoff);
            $this->assertOriginProvider($locked, $origin);

            if ($locked->status === ParcelHandoffStatus::Cancelled && $locked->recovered_at !== null) {
                return $locked; // Already recorded; a repeated submit changes nothing.
            }
            $this->conflict($locked->awaitsRecovery(), 'status', 'Only a handoff cancelled after dispatch needs a recovery scan.');
            $hub = $this->assertHandoffHub((int) $origin->id, (int) $locked->sending_hub_id, 'sending_hub_id');
            $this->verification->assertValidForHandoff($origin, $locked, ParcelHandoffVerification::ACTION_HANDOFF_RECOVER, $token, (int) $hub->id);

            $locked->forceFill([
                'recovered_at' => now(),
                'recovered_by_type' => self::ACTOR_LOGISTICS,
                'recovered_by_id' => $origin->id,
            ])->save();
            $parcel->forceFill(['custody_provider_id' => $origin->id, 'custody_hub_id' => $hub->id])->save();
            app(ParcelTransitStatusService::class)->arrivedAtHub($parcel, $origin,
                'Recovered at '.$hub->name.' after handoff #'.$locked->id.' was cancelled.', 'recovery', resumeSorted: false);
            app(ParcelIncidentService::class)->recordCancelledHandoffRecovery($parcel, $locked, $hub, (int) $origin->id);

            return $locked;
        });

        if ($token !== null) {
            $this->verification->consumeAfterSuccess(fn () => $this->verification->consumeForHandoff(
                $origin, $recovered, ParcelHandoffVerification::ACTION_HANDOFF_RECOVER, $token, (int) $recovered->sending_hub_id,
            ));
        }

        return $recovered->fresh(['sellerOrder', 'link', 'originProvider', 'destinationProvider', 'sendingHub', 'receivingHub']);
    }

    /** True while a handoff of this parcel was cancelled in transit and not yet recovered. */
    public function awaitingRecovery(SellerOrder $parcel): bool
    {
        if (! RuntimeSchema::hasColumn('parcel_handoffs', 'recovered_at')) {
            return false;
        }

        return $parcel->parcelHandoffs()
            ->where('status', ParcelHandoffStatus::Cancelled->value)
            ->whereNotNull('dispatched_at')
            ->whereNull('recovered_at')
            ->exists();
    }

    /**
     * Create the handoff row while the parcel, link, and hub rows are locked.
     */
    private function createHandoff(
        SellerOrder $parcel,
        LogisticsProvider $origin,
        ?int $requestedLinkId,
        array $excludeLinkIds,
        bool $throwWhenUnavailable,
    ): ?ParcelHandoff {
        $buyerBarangay = $this->routingBarangay($parcel);
        $excluded = fn (LogisticsProviderLink $link): bool => in_array((int) $link->id, array_map('intval', $excludeLinkIds), true);
        $reverseTarget = null;
        $route = null;

        if ($parcel->hasActiveReturn()) {
            $reverseTarget = $this->reverseTarget($parcel, $origin, $excluded);
            $links = collect([$reverseTarget['link'] ?? null])->filter();
        } elseif (($route = $parcel->parcelRoute()->lockForUpdate()->first()) !== null) {
            $leg = $route->currentLeg();
            $this->require(! $route->isCompleted() && $leg !== null, 'route', 'This saved route has no pending transport leg.');
            $this->require(
                (int) LogisticsHub::query()->whereKey($leg['hub_id'])->value('logistics_provider_id') !== (int) $origin->id,
                'route',
                'The next saved leg stays inside your network. Use an internal manifest for it.',
            );
            $links = $this->savedRouteLinks($parcel, $route, $origin, lock: true)->reject($excluded);
        } else {
            $links = $this->prioritizePlannedDestination($parcel, $this->serviceableLinks(
                $origin,
                $buyerBarangay,
                $this->buyerProvinceCode($parcel),
                lock: true,
            )->reject($excluded));
        }

        if ($requestedLinkId !== null) {
            $chosen = $links->firstWhere('id', $requestedLinkId);
            if ($chosen === null) {
                throw ValidationException::withMessages([
                    'logistics_provider_link_id' => 'That partnership is not active, approved, or serviceable for this parcel destination.',
                ]);
            }
        } else {
            $chosen = $links->first();
        }

        if ($chosen === null && $reverseTarget === null) {
            $this->recordException($parcel, $origin, $buyerBarangay);

            if ($throwWhenUnavailable) {
                throw ValidationException::withMessages([
                    'handoff' => 'No eligible destination provider is available. Admin has been notified of this exception.',
                ]);
            }

            return null;
        }

        $destination = LogisticsProvider::query()
            ->whereKey($reverseTarget['provider_id'] ?? $chosen->destination_provider_id)
            ->lockForUpdate()
            ->firstOrFail();

        $sendingHub = $this->sendingHub($parcel, $origin);

        if ($reverseTarget !== null) {
            $receivingHub = $this->assertHandoffHub((int) $destination->id, $reverseTarget['hub_id'], 'receiving_hub_id');
        } elseif ($route === null) {
            $this->require($this->linkIsServiceable($chosen, $buyerBarangay, $this->buyerProvinceCode($parcel)), 'logistics_provider_link_id', 'The selected partnership is no longer serviceable.');
            $receivingArea = $this->hubs->areaForBarangay((int) $destination->id, $buyerBarangay, lock: true);
            $this->require($receivingArea !== null, 'receiving_hub_id', 'The destination provider no longer covers this barangay.');
            $receivingHub = $this->assertHandoffHub((int) $destination->id, (int) $receivingArea->station_hub_id, 'receiving_hub_id');
        } else {
            $expectedOrigin = (int) ($leg['origin_hub_id'] ?? ($route->current_leg_index === 0
                ? $route->origin_hub_id : $route->legs[$route->current_leg_index - 1]['hub_id']));
            $this->require((int) $sendingHub->id === $expectedOrigin
                && app(ParcelLegService::class)->verifiedLocation($parcel)?->id === $expectedOrigin,
                'sending_hub_id', 'Verified custody must match the saved route departure hub.');
            $receivingHub = $this->assertHandoffHub((int) $destination->id, (int) $leg['hub_id'], 'receiving_hub_id');
        }

        $handoff = new ParcelHandoff;
        $handoff->forceFill([
            'seller_order_id' => $parcel->id,
            'logistics_provider_link_id' => $chosen?->id,
            'origin_provider_id' => $origin->id,
            'destination_provider_id' => $destination->id,
            'sending_hub_id' => $sendingHub->id,
            'receiving_hub_id' => $receivingHub->id,
            'status' => ParcelHandoffStatus::Requested->value,
            'direction' => $parcel->hasActiveReturn() ? 'return' : 'forward',
            'requested_at' => now(),
            'requested_by_type' => self::ACTOR_LOGISTICS,
            'requested_by_id' => $origin->id,
        ]);
        if (TransferCourierService::available()) {
            // Handoffs requested from now on need the sending company's Courier before dispatch.
            $handoff->forceFill(['transport_required' => (bool) config('transfers.require_transport')]);
        }
        $handoff->save();

        // A restored route makes the Admin-visible exception stale. Keep the
        // row for history but close it so dashboards reflect the live state.
        ParcelHandoffException::query()
            ->where('seller_order_id', $parcel->id)
            ->where('status', ParcelHandoffException::OPEN)
            ->update([
                'status' => ParcelHandoffException::RESOLVED,
                'resolved_at' => now(),
                'resolution_note' => 'Automatically resolved when an eligible destination partner became available.',
            ]);

        return $handoff;
    }

    /**
     * The partnership a saved route needs for its next leg. An intermediate
     * provider only has to be eligible and linked; the final provider must also
     * still cover the buyer's barangay.
     *
     * @return Collection<int, LogisticsProviderLink>
     */
    private function savedRouteLinks(SellerOrder $parcel, ParcelRoute $route, LogisticsProvider $origin, bool $lock = false): Collection
    {
        $leg = $route->currentLeg();

        if ($route->isCompleted() || $leg === null) {
            return collect();
        }

        $query = LogisticsProviderLink::query()
            ->where('origin_provider_id', $origin->id)
            ->where('destination_provider_id', LogisticsHub::query()->whereKey($leg['hub_id'])->value('logistics_provider_id'))
            ->where('status', LogisticsProviderLink::ACTIVE)
            ->with(['originProvider', 'destinationProvider'])
            ->orderBy('priority')
            ->orderBy('id');

        return ($lock ? $query->lockForUpdate() : $query)->get()
            ->filter(function (LogisticsProviderLink $link) use ($parcel, $route): bool {
                $destination = $link->destinationProvider;

                if ($destination === null || ! $destination->isApproved() || $destination->archived_at !== null || ! $destination->hasVerifiedProvince()) {
                    return false;
                }

                return ! $route->isCurrentLegFinal()
                    || $this->linkIsServiceable($link, $this->routingBarangay($parcel), $this->buyerProvinceCode($parcel));
            })
            ->values();
    }

    /**
     * Where a physical return may go next, one authorized step at a time: straight
     * to the original provider on a reverse partnership, or back to the provider
     * that handed this parcel over, on a reverse partnership or, with no link at
     * all, once an Admin has authorized this one parcel. Providers outside the
     * parcel's verified path are never used.
     *
     * @param  callable(LogisticsProviderLink): bool  $excluded
     * @return array{link: ?LogisticsProviderLink, provider_id: int, hub_id: int}|null
     */
    private function reverseTarget(SellerOrder $parcel, LogisticsProvider $origin, callable $excluded): ?array
    {
        $record = $parcel->parcelReturn;
        $direct = $this->serviceableLinks($origin, $this->routingBarangay($parcel), $this->buyerProvinceCode($parcel), lock: true)
            ->first(fn (LogisticsProviderLink $link): bool => (int) $link->destination_provider_id === (int) $record->origin_provider_id && ! $excluded($link));

        if ($direct !== null) {
            return ['link' => $direct, 'provider_id' => (int) $record->origin_provider_id, 'hub_id' => (int) $record->origin_hub_id];
        }

        $arrival = ParcelHandoff::query()
            ->where('seller_order_id', $parcel->id)
            ->where('direction', 'forward')
            ->where('destination_provider_id', $origin->id)
            ->where('status', ParcelHandoffStatus::Received->value)
            ->latest('id')
            ->first();

        if ($arrival === null) {
            return null;
        }

        $link = LogisticsProviderLink::query()
            ->where('origin_provider_id', $origin->id)
            ->where('destination_provider_id', $arrival->origin_provider_id)
            ->where('status', LogisticsProviderLink::ACTIVE)
            ->lockForUpdate()
            ->first();

        if ($link === null || $excluded($link)) {
            if (! app(ParcelReturnService::class)->reverseRouteAuthorized($record)) {
                return null;
            }

            $link = null;
        }

        return [
            'link' => $link,
            'provider_id' => (int) $arrival->origin_provider_id,
            'hub_id' => (int) $arrival->origin_provider_id === (int) $record->origin_provider_id
                ? (int) $record->origin_hub_id
                : (int) $arrival->sending_hub_id,
        ];
    }

    /**
     * Checkout resolves a cross-province destination under lock. When that
     * planned partner is still serviceable, keep it first so the handoff
     * honours the route the buyer paid for; otherwise priority decides.
     *
     * @param  Collection<int, LogisticsProviderLink>  $links
     * @return Collection<int, LogisticsProviderLink>
     */
    private function prioritizePlannedDestination(SellerOrder $parcel, Collection $links): Collection
    {
        $plannedId = $parcel->handoff_destination_provider_id;
        if ($plannedId === null) {
            return $links;
        }

        $planned = $links->first(fn (LogisticsProviderLink $link): bool => (int) $link->destination_provider_id === (int) $plannedId);
        if ($planned === null) {
            return $links;
        }

        return collect([$planned])
            ->merge($links->reject(fn (LogisticsProviderLink $link): bool => (int) $link->id === (int) $planned->id))
            ->values();
    }

    private function throwUnavailable(SellerOrder $parcel, LogisticsProvider $origin): never
    {
        $this->recordException($parcel, $origin, $this->routingBarangay($parcel));

        throw ValidationException::withMessages([
            'handoff' => 'No eligible destination provider is available. Admin has been notified of this exception.',
        ]);
    }

    private function recordException(SellerOrder $parcel, LogisticsProvider $origin, ?string $barangayCode): void
    {
        if ($parcel->hasActiveReturn()) {
            $parcel->parcelReturn->forceFill(['exception_reason' => 'No approved directed reverse partner can receive this physical return. Review the reverse route and retry.'])->save();
        }

        $existing = ParcelHandoffException::query()
            ->where('seller_order_id', $parcel->id)
            ->where('status', ParcelHandoffException::OPEN)
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            return;
        }

        ParcelHandoffException::query()->create([
            'seller_order_id' => $parcel->id,
            'origin_provider_id' => $origin->id,
            'status' => ParcelHandoffException::OPEN,
            'destination_barangay_code' => $barangayCode,
            'reason' => 'No approved active destination partner covers this parcel destination. The parcel remains safely held by '.$origin->business_name.'.',
        ]);
    }

    private function lockParcel(SellerOrder $parcel, bool $receiving = false): SellerOrder
    {
        $locked = SellerOrder::query()
            ->whereKey($parcel->id)
            ->lockForUpdate()
            ->with(['order.buyer', 'seller', 'orderItems'])
            ->firstOrFail();
        if ($receiving) {
            ParcelIncidentService::assertReceivingAllowed($locked);
        } else {
            ParcelIncidentService::assertOperationsAllowed($locked);
        }

        return $locked;
    }

    private function lockHandoff(ParcelHandoff $handoff): ParcelHandoff
    {
        return ParcelHandoff::query()->whereKey($handoff->id)->lockForUpdate()->firstOrFail();
    }

    private function assertOriginCustody(SellerOrder $parcel, LogisticsProvider $origin): void
    {
        $this->assertParcelOpen($parcel);
        $this->require(
            $parcel->currentCustodyProviderId() === (int) $origin->id,
            'seller_order_id',
            'Only the provider physically holding this parcel can hand it off.',
        );
    }

    private function assertParcelOpen(SellerOrder $parcel): void
    {
        $this->require($parcel->parcelReturn?->movementBlockedReason() === null, 'return', (string) $parcel->parcelReturn?->movementBlockedReason());
        $this->require(! $parcel->hasActiveReturn() || in_array($parcel->parcelReturn->status->value, ['RETURN_REQUESTED', 'RETURN_IN_TRANSIT'], true), 'return', 'Transport is closed after verified origin arrival.');
        $this->require(! $parcel->isClosedForHandoff(), 'seller_order_id', 'Closed parcels cannot be handed off.');
        $this->require($parcel->delivery_courier_id === null || ($parcel->hasActiveReturn() && ($parcel->status->value === 'DELIVERY_FAILED' || $parcel->parcelReturn->collectedFromBuyer())), 'seller_order_id', 'Resolve the reserved delivery Rider before handing off this parcel.');
        $this->require(! in_array($parcel->status->value, ['ASSIGNED_TO_RIDER', 'OUT_FOR_DELIVERY'], true) && ($parcel->status->value !== 'DELIVERY_FAILED' || $parcel->hasActiveReturn()), 'seller_order_id', 'Resolve the active delivery leg before handing off this parcel.');
        $this->require(! $parcel->manifestItems()->whereNull('released_at')->exists(), 'seller_order_id', 'Complete the active internal manifest before handing off this parcel.');
    }

    private function assertLinkStillServiceable(ParcelHandoff $handoff, SellerOrder $parcel): void
    {
        if ($handoff->logistics_provider_link_id === null) {
            return;
        }

        $link = LogisticsProviderLink::query()->whereKey($handoff->logistics_provider_link_id)->lockForUpdate()->first();
        $this->require($link !== null && $link->status === LogisticsProviderLink::ACTIVE, 'link', 'This partnership is no longer active.');

        if ($handoff->direction === 'return') {
            // A step back along the parcel's path was authorized when requested;
            // only the final step into the original provider checks seller coverage.
            if ((int) $handoff->destination_provider_id !== (int) $parcel->parcelReturn?->origin_provider_id) {
                return;
            }
        } elseif (($route = $parcel->parcelRoute()->first()) !== null) {
            $this->require(
                $this->savedRouteLinks($parcel, $route, $handoff->originProvider()->firstOrFail())->contains('id', $link->id),
                'destination_provider_id',
                'This partnership no longer serves the next saved leg of this parcel.',
            );

            return;
        }

        $this->require(
            $this->linkIsServiceable($link, $this->routingBarangay($parcel), $this->buyerProvinceCode($parcel)),
            'destination_provider_id',
            'The destination provider no longer covers this parcel destination.',
        );
    }

    private function linkIsServiceable(LogisticsProviderLink $link, ?string $barangayCode, ?string $provinceCode): bool
    {
        if ($link->status !== LogisticsProviderLink::ACTIVE || blank($barangayCode)) {
            return false;
        }

        $destination = $link->destinationProvider;
        if ($destination === null || ! $destination->isApproved() || $destination->archived_at !== null
            || ! $destination->hasVerifiedProvince()) {
            return false;
        }

        if (filled($provinceCode) && (string) $destination->province_code !== (string) $provinceCode) {
            return false;
        }

        return $this->hubs->areaForBarangay((int) $destination->id, $barangayCode) !== null
            && $this->hubs->hasOperationalProvinceCoverage($destination);
    }

    private function sendingHub(SellerOrder $parcel, LogisticsProvider $origin): LogisticsHub
    {
        if ($parcel->custody_hub_id !== null) {
            return $this->assertHandoffHub((int) $origin->id, (int) $parcel->custody_hub_id, 'sending_hub_id');
        }
        $candidates = [];
        if ($parcel->origin_station_hub_id !== null) {
            $candidates[] = (int) $parcel->origin_station_hub_id;
        }

        $area = $this->hubs->areaForBarangay((int) $origin->id, $parcel->sellerBarangayCode(), lock: true);
        if ($area !== null) {
            $candidates[] = (int) $area->station_hub_id;
        }

        foreach (array_unique($candidates) as $hubId) {
            $hub = LogisticsHub::query()->whereKey($hubId)
                ->where('logistics_provider_id', $origin->id)
                ->operational()->lockForUpdate()->first();
            if ($hub !== null) {
                return $hub;
            }
        }

        $province = LogisticsHub::query()
            ->where('logistics_provider_id', $origin->id)
            ->where('hub_type', LogisticsHub::PROVINCE)
            ->where('active_province_slot', 1)
            ->operational()
            ->orderBy('id')
            ->lockForUpdate()
            ->first();

        if ($province === null) {
            throw ValidationException::withMessages([
                'sending_hub_id' => 'The sending provider has no operational hub for this parcel.',
            ]);
        }

        return $province;
    }

    private function assertHandoffHub(int $providerId, int $hubId, string $field): LogisticsHub
    {
        $hub = LogisticsHub::query()
            ->whereKey($hubId)
            ->where('logistics_provider_id', $providerId)
            ->operational()
            ->first();

        if ($hub === null) {
            throw ValidationException::withMessages([
                $field => 'The selected hub is not operational or does not belong to the participating provider.',
            ]);
        }

        return $hub;
    }

    private function assertParticipant(ParcelHandoff $handoff, LogisticsProvider $provider): void
    {
        abort_unless(
            in_array((int) $provider->id, [(int) $handoff->origin_provider_id, (int) $handoff->destination_provider_id], true),
            403,
        );
    }

    private function assertOriginProvider(ParcelHandoff $handoff, LogisticsProvider $provider): void
    {
        abort_unless((int) $handoff->origin_provider_id === (int) $provider->id, 403);
    }

    private function assertDestinationProvider(ParcelHandoff $handoff, LogisticsProvider $provider): void
    {
        abort_unless((int) $handoff->destination_provider_id === (int) $provider->id, 403);
    }

    private function routingBarangay(SellerOrder $parcel): ?string
    {
        return $parcel->hasActiveReturn() ? ($parcel->parcelReturn->seller_address['barangay_code'] ?? null) : $parcel->buyerBarangayCode();
    }

    private function buyerProvinceCode(SellerOrder $parcel): ?string
    {
        if ($parcel->hasActiveReturn()) {
            return $parcel->parcelReturn->seller_address['province_code'] ?? $parcel->parcelReturn->originProvider?->province_code;
        }
        $address = $parcel->order?->shipping_address ?? [];

        return $address['province_code']
            ?? $parcel->order?->buyer?->province_code
            ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function handoffSummary(ParcelHandoff $handoff): array
    {
        return [
            'id' => $handoff->id,
            'status' => $handoff->status?->value,
            'status_label' => $handoff->statusLabel(),
            'origin' => $handoff->originProvider?->business_name,
            'destination' => $handoff->destinationProvider?->business_name,
            'sending_hub' => $handoff->sendingHub?->name,
            'receiving_hub' => $handoff->receivingHub?->name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parcelSummary(SellerOrder $order): array
    {
        $order->loadMissing(['order.buyer', 'seller', 'orderItems']);
        $address = $order->order?->shipping_address ?? [];

        return [
            'id' => $order->id,
            'tracking_number' => $order->tracking_number,
            'status' => $order->status?->value,
            'status_label' => $order->status?->label(),
            'seller_name' => $order->seller?->business_name ?? 'Seller',
            'buyer_municipality' => $address['municipality'] ?? $order->buyerMunicipality() ?? '—',
            'item_count' => (int) $order->orderItems->sum('quantity'),
            'weight' => $order->formattedParcelWeight(),
        ];
    }

    /** The handoff moved on since the page rendered: 409 for JSON, same errors for forms. */
    private function conflict(bool $condition, string $field, string $message): void
    {
        if (! $condition) {
            throw StaleStateException::field($field, $message);
        }
    }

    private function require(bool $condition, string $field, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
