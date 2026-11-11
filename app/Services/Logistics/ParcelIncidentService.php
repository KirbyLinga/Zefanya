<?php

namespace App\Services\Logistics;

use App\Enums\OrderActor;
use App\Enums\OrderStatus;
use App\Enums\ParcelIncidentStatus;
use App\Enums\ParcelIncidentType;
use App\Models\Admin\Admin;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\ManifestItem;
use App\Models\Logistics\ParcelHandoff;
use App\Models\Logistics\ParcelReturn;
use App\Models\Logistics\ParcelRouteRereoute;
use App\Models\Rider\Rider;
use App\Models\Shared\IncidentEvents;
use App\Models\Shared\Incidents;
use App\Models\Shared\SellerOrder;
use App\Models\Shared\StatusHistory;
use App\Notifications\Incident\IncidentReported;
use App\Notifications\Incident\IncidentResolved;
use App\Services\Rider\RiderOperationsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Report, review, resolve and guard parcel movements against active exceptions.
 *
 * The existing incidents/incident_events tables are the single exception
 * record. Lifecycle: OPEN -> UNDER_REVIEW -> RESOLVED. Resolving an exception
 * never fabricates receipt scans, custody transfers, deliveries, refunds or
 * stock changes; those remain owned by their existing verified services.
 *
 * @phpstan-require-immutable
 */
class ParcelIncidentService
{
    public const RESUME = 'RESUME_ORIGINAL_ROUTE';

    public const REROUTE = 'AUTHORIZE_REROUTE';

    public const HOLD = 'KEEP_ON_HOLD';

    public const ACTIVE_TYPES = [
        'lost', 'damaged', 'missing', 'wrong_hub', 'inactive_connection', 'failed_transfer',
    ];

    /**
     * Record a report under the same parcel lock used by operational writes.
     * Identity, pickup evidence and custody are always resolved server-side.
     */
    public function report(SellerOrder $parcel, Rider|LogisticsProvider $reporter, array $payload): Incidents
    {
        $photoPath = null;
        $committed = false;

        try {
            return DB::transaction(function () use ($parcel, $reporter, $payload, &$photoPath, &$committed): Incidents {
                $parcel = SellerOrder::query()->whereKey($parcel->id)->lockForUpdate()->firstOrFail();
                $reporter->refresh();
                $this->authorizeReporter($parcel, $reporter);
                $this->assertNoOpenIncident($parcel);
                self::assertOperationsAllowed($parcel);

                $pickup = $parcel->statusHistories()
                    ->where('from_status', OrderStatus::ReadyForPickup->value)
                    ->where('to_status', OrderStatus::PickedUp->value)
                    ->where('actor_type', OrderActor::Rider->value)
                    ->whereNotNull('actor_id')
                    ->reorder()->orderByDesc('id')->first();

                if ($parcel->handover_at === null || $pickup === null
                    || ! in_array($parcel->status, [
                        OrderStatus::PickedUp, OrderStatus::AtSortingCenter, OrderStatus::Sorted,
                        OrderStatus::InTransit, OrderStatus::ArrivedAtHub,
                        OrderStatus::AssignedToRider, OrderStatus::OutForDelivery, OrderStatus::DeliveryFailed,
                    ], true)) {
                    throw ValidationException::withMessages([
                        'parcel' => 'Incidents can only be reported for an active parcel after verified Rider pickup.',
                    ]);
                }

                $validated = $this->assertValidReport($payload);
                $providerId = (new ParcelReturnService)->custodyProviderId($parcel);
                $provider = $providerId !== null ? LogisticsProvider::query()->find($providerId) : null;
                $parcel->loadMissing(['order.buyer', 'seller']);
                $recipients = [$parcel->order?->buyer, $parcel->seller, $provider];
                if (in_array(null, $recipients, true)) {
                    throw ValidationException::withMessages([
                        'parcel' => 'The Buyer, Seller, or responsible Logistics provider is unavailable. Restore the missing recipient before reporting.',
                    ]);
                }

                $snapshot = $this->custodySnapshot($parcel, $pickup, $providerId);
                if (($photo = $validated['photo'] ?? null) !== null) {
                    $stored = $photo->store('incidents', ['disk' => 'local', 'visibility' => 'private']);
                    if ($stored === false) {
                        throw ValidationException::withMessages(['photo' => 'The incident photo could not be stored. Try again.']);
                    }
                    $photoPath = $stored;
                    DB::afterRollBack(function () use (&$photoPath): void {
                        if ($photoPath !== null) {
                            try {
                                Storage::disk('local')->delete($photoPath);
                            } catch (Throwable $exception) {
                                report($exception);
                            }
                            $photoPath = null;
                        }
                    });
                }

                $incident = Incidents::create([
                    'incident_seller_order_id' => $parcel->id,
                    'reporter_id' => $reporter->id,
                    'reporter_type' => $reporter instanceof Rider ? 'rider' : 'logistics_provider',
                    'type' => $validated['type'],
                    'reason' => $validated['reason'],
                    'description' => $validated['description'],
                    'happened_at' => $validated['happened_at'] ?? now(),
                    'custody_snapshot' => $snapshot,
                    'photo_disk' => $photoPath !== null ? 'local' : null,
                    'photo_path' => $photoPath,
                ]);

                $this->event($incident, 'reported', 'Incident reported', $validated['type'], $reporter instanceof Rider ? 'rider' : 'logistics_provider', $reporter->id, null, ParcelIncidentStatus::Open->value);

                DB::afterCommit(function () use ($incident, $recipients, &$committed): void {
                    $committed = true;
                    foreach ($recipients as $recipient) {
                        try {
                            $recipient->notify(new IncidentReported($incident));
                        } catch (Throwable $exception) {
                            // The report is committed. Preserve its evidence and attempt every recipient.
                            report($exception);
                        }
                    }
                });

                return $incident;
            });
        } catch (Throwable $exception) {
            if ($photoPath !== null && ! $committed) {
                try {
                    Storage::disk('local')->delete($photoPath);
                } catch (Throwable $cleanupException) {
                    report($cleanupException);
                }
            }
            throw $exception;
        }
    }

    /**
     * Record an operational exception from the logistics dashboard.
     *
     * Unlike the delivery lost/damaged report this does not require a Rider
     * pickup event, but it does require the reporting provider to currently
     * hold (or commercially own) the parcel.
     */
    public function reportOperational(SellerOrder $parcel, LogisticsProvider $provider, array $payload): Incidents
    {
        $created = false;
        $latest = DB::transaction(function () use ($parcel, $provider, $payload, &$created): Incidents {
            $parcel = SellerOrder::query()->whereKey($parcel->id)->lockForUpdate()->firstOrFail();
            $this->authorizeProviderForParcel($parcel, $provider);

            $validated = $this->assertValidOperationalReport($payload);
            $type = ParcelIncidentType::from($validated['type']);

            $existing = Incidents::query()
                ->where('incident_seller_order_id', $parcel->id)
                ->where('type', $type->value)
                ->whereIn('status', [ParcelIncidentStatus::Open->value, ParcelIncidentStatus::UnderReview->value])
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $photoPath = null;
            if (($photo = $validated['photo'] ?? null) !== null) {
                $photoPath = $photo->store('incidents', ['disk' => 'local', 'visibility' => 'private']);
                if ($photoPath === false) {
                    throw ValidationException::withMessages(['photo' => 'The incident photo could not be stored. Try again.']);
                }
                DB::afterRollBack(function () use (&$photoPath): void {
                    if ($photoPath !== null) {
                        try {
                            Storage::disk('local')->delete($photoPath);
                        } catch (Throwable $exception) {
                            report($exception);
                        }
                    }
                });
            }

            $incident = Incidents::create([
                'incident_seller_order_id' => $parcel->id,
                'reporter_id' => $provider->id,
                'reporter_type' => 'logistics_provider',
                'type' => $type->value,
                'reason' => $validated['reason'],
                'description' => $validated['description'] ?? null,
                'happened_at' => $validated['happened_at'] ?? now(),
                'custody_snapshot' => $this->currentCustodySnapshot($parcel),
                'blocked_leg_index' => $validated['blocked_leg_index'] ?? $parcel->parcelRoute()->value('current_leg_index'),
                'photo_disk' => $photoPath !== null ? 'local' : null,
                'photo_path' => $photoPath,
            ]);

            $this->event($incident, 'reported', 'Operational exception reported', $type->value, 'logistics_provider', $provider->id, null, ParcelIncidentStatus::Open->value);
            $created = true;

            return $incident;
        });

        if ($created) {
            DB::afterCommit(fn () => $this->notifyReported($latest));
        }

        return $latest;
    }

    /**
     * Detect an unavailable saved connection / failed transfer without creating
     * a duplicate row when the same exception is already active.
     */
    public function recordRoutingBlocker(
        SellerOrder $parcel,
        ParcelIncidentType $type,
        string $reason,
        ?int $providerId = null,
        ?int $legIndex = null,
    ): Incidents {
        $created = false;
        $incident = DB::transaction(function () use ($parcel, $type, $reason, $providerId, $legIndex, &$created): Incidents {
            $parcel = SellerOrder::query()->whereKey($parcel->id)->lockForUpdate()->firstOrFail();

            $existing = Incidents::query()
                ->where('incident_seller_order_id', $parcel->id)
                ->where('type', $type->value)
                ->whereIn('status', [ParcelIncidentStatus::Open->value, ParcelIncidentStatus::UnderReview->value])
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $incident = Incidents::create([
                'incident_seller_order_id' => $parcel->id,
                'reporter_id' => $providerId,
                'reporter_type' => $providerId !== null ? 'logistics_provider' : 'system',
                'type' => $type->value,
                'reason' => $reason,
                'description' => 'Detected by the saved-route execution guard.',
                'happened_at' => now(),
                'custody_snapshot' => $this->currentCustodySnapshot($parcel),
                'blocked_leg_index' => $legIndex ?? $parcel->parcelRoute()->value('current_leg_index'),
            ]);

            $this->event($incident, 'reported', 'Routing exception detected', $type->value, $providerId !== null ? 'logistics_provider' : 'system', $providerId, null, ParcelIncidentStatus::Open->value);
            $created = true;

            return $incident;
        });

        if ($created) {
            DB::afterCommit(fn () => $this->notifyReported($incident));
        }

        return $incident;
    }

    /** Persist detection before the transfer transaction so refusal does not roll back its hold. */
    public function detectUnavailableDeparture(SellerOrder $parcel, LogisticsProvider $provider): void
    {
        $blocked = DB::transaction(function () use ($parcel, $provider): bool {
            $parcel = SellerOrder::query()->whereKey($parcel->id)->lockForUpdate()->firstOrFail();
            $route = $parcel->parcelRoute()->lockForUpdate()->first();
            if (! $route || $route->isCompleted() || $parcel->hasActiveReturn()) {
                return false;
            }
            $this->authorizeProviderForParcel($parcel, $provider);
            if (! app(RoutePlannerService::class)->isRouteFeasible($route, $route->current_leg_index)) {
                $this->recordRoutingBlocker($parcel, ParcelIncidentType::InactiveConnection,
                    'The saved transport connection or directional provider partnership is unavailable.', $provider->id);

                return true;
            }

            return false;
        });
        if ($blocked) {
            throw ValidationException::withMessages(['route' => 'The next departure is held because the saved route is unavailable. Review its exception.']);
        }
    }

    /** Only a valid scan bound to the existing transfer may call this discrepancy detector. */
    public function detectReceiptDiscrepancy(SellerOrder $parcel, int $originId, int $destinationId, LogisticsProvider $provider,
        ManifestItem|ParcelHandoff $receipt): void
    {
        $blocked = DB::transaction(function () use ($parcel, $originId, $destinationId, $provider, $receipt): bool {
            $parcel = SellerOrder::query()->whereKey($parcel->id)->lockForUpdate()->firstOrFail();
            // Recheck persisted evidence after waiting for another receiver's parcel lock.
            if ($receipt->newQuery()->whereKey($receipt->id)->value('received_at') !== null) {
                return false;
            }
            $route = $parcel->parcelRoute()->lockForUpdate()->first();
            if (! $route || $route->isCompleted() || $parcel->hasActiveReturn()) {
                return false;
            }
            $hub = LogisticsHub::query()->findOrFail($destinationId);
            abort_unless((int) $hub->logistics_provider_id === (int) $provider->id, 403);
            $legs = is_array($route->legs) ? $route->legs : json_decode($route->legs ?: '[]', true);
            $expectedOrigin = $route->current_leg_index === 0 ? $route->origin_hub_id : ($legs[$route->current_leg_index - 1]['hub_id'] ?? null);
            $expectedOrigin = $route->currentLeg()['origin_hub_id'] ?? $expectedOrigin;
            if ((int) $route->currentLegDestinationHubId() !== $destinationId || (int) $expectedOrigin !== $originId) {
                if (Incidents::query()->where('incident_seller_order_id', $parcel->id)->where('type', 'wrong_hub')
                    ->where('status', ParcelIncidentStatus::UnderReview->value)->exists()) {
                    return false;
                }
                $this->recordRoutingBlocker($parcel, ParcelIncidentType::WrongHub,
                    'A verified transfer scan identified a hub outside the expected saved route leg.', $provider->id);

                return true;
            }

            return false;
        });
        if ($blocked) {
            throw ValidationException::withMessages(['route' => 'Wrong hub detected. Review the exception before recording an authorized recovery receipt.']);
        }
    }

    /** Move an active exception into review. Idempotent. */
    public function review(Incidents $incident, Admin|LogisticsProvider $actor, string $note): Incidents
    {
        $note = trim($note);
        if ($note === '' || mb_strlen($note) > 1000) {
            throw ValidationException::withMessages(['review_note' => 'Provide a review note of 1–1000 characters.']);
        }

        return DB::transaction(function () use ($incident, $actor, $note): Incidents {
            $parcel = SellerOrder::query()->whereKey($incident->incident_seller_order_id)->lockForUpdate()->firstOrFail();
            $locked = Incidents::query()->whereKey($incident->id)->lockForUpdate()->firstOrFail();

            $this->authorizeActor($locked, $parcel, $actor, false);

            if ($locked->status === ParcelIncidentStatus::Resolved && ! $locked->isHeld()) {
                return $locked;
            }

            if ($locked->status === ParcelIncidentStatus::UnderReview && $locked->reviewed_at !== null) {
                return $locked;
            }

            $from = $locked->status->value;
            $locked->forceFill([
                'status' => ParcelIncidentStatus::UnderReview,
                'reviewed_at' => now(),
                'reviewed_by_type' => $actor instanceof Admin ? 'admin' : 'logistics_provider',
                'reviewed_by_id' => $actor->id,
            ])->save();

            $this->event($locked, 'under_review', 'Exception moved under review', $note, $actor instanceof Admin ? 'admin' : 'logistics_provider', $actor->id, $from, ParcelIncidentStatus::UnderReview->value);

            return $locked;
        });
    }

    public function resolve(Incidents $incident, Admin $admin, array $payload): void
    {
        $this->resolveBy($incident, $admin, $payload);
    }

    public function resolveForProvider(Incidents $incident, LogisticsProvider $provider, array $payload): void
    {
        $this->resolveBy($incident, $provider, $payload);
    }

    private function resolveBy(Incidents $incident, Admin|LogisticsProvider $actor, array $payload): void
    {
        DB::transaction(function () use ($incident, $actor, $payload): void {
            $parcel = SellerOrder::query()->whereKey($incident->incident_seller_order_id)->lockForUpdate()->firstOrFail();
            $locked = Incidents::query()->whereKey($incident->id)->lockForUpdate()->firstOrFail();
            $this->authorizeActor($locked, $parcel, $actor, true);

            $validated = $this->assertValidResolve($payload);
            $action = $validated['event'];

            if ($actor instanceof LogisticsProvider && in_array($action, ['RETURN', 'CLOSE_LOST', 'CLOSE_DAMAGED'], true)) {
                abort(403, 'Only Admin can close or return a lost/damaged parcel.');
            }

            $lossTypes = ParcelLossService::available() ? ['lost', 'missing', 'failed_transfer'] : ['lost'];
            if (($action === 'CLOSE_LOST' && ! in_array(strtolower($locked->type), $lossTypes, true))
                || ($action === 'CLOSE_DAMAGED' && strtolower($locked->type) !== 'damaged')) {
                throw ValidationException::withMessages(['resolution' => 'The closure must match the reported incident type.']);
            }

            // Held reviews remain open to a later authorized decision.
            if ($action === self::HOLD) {
                if ($locked->resolution === self::HOLD && $locked->status === ParcelIncidentStatus::UnderReview) {
                    return;
                }
                if ($locked->status === ParcelIncidentStatus::Resolved && $locked->resolution !== self::HOLD) {
                    throw ValidationException::withMessages(['resolution' => 'This exception has already been resolved.']);
                }
                $locked = $this->review($locked, $actor, $validated['reason']);
                $locked->forceFill(['status' => ParcelIncidentStatus::UnderReview, 'resolution' => self::HOLD,
                    'resolved_at' => null, 'resolved_by_type' => null, 'resolved_by_id' => null])->save();
                $this->event($locked, 'held', 'Parcel kept on hold', $validated['reason'],
                    $actor instanceof Admin ? 'admin' : 'logistics_provider', $actor->id,
                    ParcelIncidentStatus::UnderReview->value, ParcelIncidentStatus::UnderReview->value,
                    ['reason' => $validated['reason']]);

                return;
            }
            if ($locked->status === ParcelIncidentStatus::Resolved && $locked->resolution === self::HOLD) {
                $locked->forceFill(['status' => ParcelIncidentStatus::UnderReview])->save();
            }
            // Repeating the same resolution is idempotent and never writes a second audit row.
            if ($locked->status === ParcelIncidentStatus::Resolved) {
                $already = strtoupper((string) $locked->resolution);
                if ($already === $action || ($already === 'RESUME' && $action === 'RESUME_ORIGINAL_ROUTE')) {
                    return;
                }

                throw ValidationException::withMessages(['resolution' => 'This exception has already been resolved.']);
            }

            // A parcel from a transfer cancelled after it left its hub has no
            // verified location. Its route cannot resume until a hub scans it.
            if (in_array($action, ['RESUME', self::RESUME, self::REROUTE], true)
                && (app(ManifestService::class)->awaitingRecovery($parcel) || app(ParcelHandoffService::class)->awaitingRecovery($parcel))) {
                throw ValidationException::withMessages([
                    'resolution' => 'Scan this parcel at a hub from its cancelled transfer first. Its location must be verified before its route can resume or change.',
                ]);
            }

            if (in_array($action, ['RESUME', self::RESUME], true)
                && ($action === self::RESUME || in_array($locked->type, ['missing', 'wrong_hub', 'inactive_connection', 'failed_transfer'], true))) {
                $this->assertOriginalRouteValid($parcel);
            }
            if (in_array($action, [self::RESUME, self::REROUTE], true)
                && ($locked->status === ParcelIncidentStatus::Open || $locked->reviewed_at === null)) {
                $locked = $this->review($locked, $actor, $validated['reason']);
            }

            if ($action === self::REROUTE) {
                if (! in_array(strtolower((string) $locked->type), ['missing', 'wrong_hub', 'inactive_connection', 'failed_transfer'], true)) {
                    throw ValidationException::withMessages(['resolution' => 'This exception type does not authorize a reroute.']);
                }

                app(RouteExecutionService::class)->rerouteFromCurrent(
                    $parcel->fresh(),
                    $validated['reason'],
                    $locked,
                    $actor instanceof Admin ? 'admin' : 'logistics_provider',
                    $actor->id,
                );
            }

            $from = $locked->status->value;
            $context = $validated['context'] ?? [];
            if ($action === self::REROUTE) {
                $reroute = ParcelRouteRereoute::query()->where('incident_id', $locked->id)->latest('id')->first();
                if ($reroute !== null) {
                    $context['reroute_id'] = $reroute->id;
                    $context['previous_path'] = $reroute->previous_path;
                    $context['new_path'] = $reroute->new_path;
                }
            }

            $locked->forceFill([
                'status' => ParcelIncidentStatus::Resolved,
                'resolution' => $action,
                'resolution_context' => $context ?: null,
                'resolved_at' => now(),
                'resolved_by_type' => $actor instanceof Admin ? 'admin' : 'logistics_provider',
                'resolved_by_id' => $actor->id,
            ])->save();

            $this->event(
                $locked,
                'resolved',
                'Exception resolved: '.$action,
                $action,
                $actor instanceof Admin ? 'admin' : 'logistics_provider',
                $actor->id,
                $from,
                ParcelIncidentStatus::Resolved->value,
                ['reason' => $validated['reason'], 'context' => $context],
            );

            // A parcel lost or damaged on its way back closes its return, not the
            // delivered order. It rolls back with this resolution on any refusal.
            if (in_array($action, ['CLOSE_LOST', 'CLOSE_DAMAGED'], true) && $actor instanceof Admin && $parcel->hasActiveReturn()) {
                if (! ParcelLossService::available() || ! ParcelReturn::recoveryAvailable()) {
                    throw ValidationException::withMessages(['resolution' => 'Closing a return as lost or damaged needs the pending database update.']);
                }
                app(ParcelLossService::class)->finalizeReturnLoss($parcel, $locked, $actor, $action === 'CLOSE_LOST' ? 'lost' : 'damaged',
                    $validated['reason'], isset($context['liable_provider_id']) ? (int) $context['liable_provider_id'] : null);
                $this->closeSiblingIncidents($locked, $parcel, $actor, $validated['reason'], $action);
            } elseif ($action === 'CLOSE_LOST' && $actor instanceof Admin && ParcelLossService::available()) {
                // Confirming a loss closes the parcel, fixes liability and the Seller
                // compensation, and notifies Buyer, Seller and Logistics. It rolls
                // back with this resolution if the parcel cannot be closed as lost.
                app(ParcelLossService::class)->finalize($parcel, $locked, $actor, $validated['reason'],
                    isset($context['liable_provider_id']) ? (int) $context['liable_provider_id'] : null);
                $this->closeSiblingIncidents($locked, $parcel, $actor, $validated['reason'], $action);

                return;
            }

            if (in_array($action, ['RESUME', self::RESUME], true) && ! self::movementBlockers($parcel)->exists()
                && app(ParcelLegService::class)->isReadyForNextLeg($parcel)) {
                app(ParcelLegService::class)->advanceToNextLeg($parcel);
            }

            // A failed RETURN rolls this resolution and its audit event back too.
            if ($action === 'RETURN') {
                (new ParcelReturnService)->requestForIncident($parcel, $actor, $validated['reason']);
            }

            $parcel->loadMissing(['order.buyer', 'seller']);
            $providerId = (new ParcelReturnService)->custodyProviderId($parcel);
            $provider = $providerId !== null ? LogisticsProvider::query()->find($providerId) : null;
            if ($parcel->order?->buyer === null || $parcel->seller === null || $provider === null) {
                throw ValidationException::withMessages([
                    'incident' => 'The Buyer, Seller, or responsible Logistics provider is unavailable. Restore the missing recipient before resolving this incident.',
                ]);
            }

            foreach ([$parcel->order?->buyer, $parcel->seller, $provider] as $recipient) {
                $recipient?->notify(new IncidentResolved($locked, $action));
            }
        });
    }

    private function assertOriginalRouteValid(SellerOrder $parcel): void
    {
        $hub = app(ParcelLegService::class)->verifiedLocation($parcel);
        $route = $parcel->parcelRoute()->lockForUpdate()->first();
        if (! $hub || ($route && app(RouteExecutionService::class)->isInTransit($route))) {
            throw ValidationException::withMessages(['resolution' => 'Verified stationary custody is required to resume the original route.']);
        }
        if ($route) {
            $legs = is_array($route->legs) ? $route->legs : json_decode($route->legs ?: '[]', true);
            $expectedHub = $route->currentLeg()['origin_hub_id']
                ?? ($route->current_leg_index === 0 ? $route->origin_hub_id : ($legs[$route->current_leg_index - 1]['hub_id'] ?? null));
            $receivedCurrentLeg = app(ParcelLegService::class)->isReadyForNextLeg($parcel);
            if (! $route->isCompleted() && ! $receivedCurrentLeg && (int) $expectedHub !== (int) $hub->id) {
                throw ValidationException::withMessages(['resolution' => 'Physical custody does not match the original route. Verify recovery or authorize a reroute.']);
            }
            if (! ($receivedCurrentLeg
                ? app(RoutePlannerService::class)->isRouteFeasible($route, $route->current_leg_index + 1)
                : app(RouteExecutionService::class)->validateRouteConnectivity($route))) {
                throw ValidationException::withMessages(['resolution' => 'The original route is unavailable. Keep the hold or authorize a valid alternative.']);
            }
        }
    }

    /**
     * Call under the parcel row lock before operational writes.
     * Query fresh incident state instead of a cached relationship.
     */
    public static function movementBlockers(SellerOrder $parcel): Builder
    {
        return Incidents::query()->where('incident_seller_order_id', $parcel->id)->whereIn('type', self::ACTIVE_TYPES)
            ->where(function ($query): void {
                $query->whereIn('status', [ParcelIncidentStatus::Open->value, ParcelIncidentStatus::UnderReview->value])
                    ->orWhere(function ($held): void {
                        $held->where('status', ParcelIncidentStatus::Resolved->value)
                            ->where(function ($terminal): void {
                                $terminal->whereIn('resolution', ['KEEP_ON_HOLD', 'CLOSE_LOST', 'CLOSE_DAMAGED'])
                                    ->orWhereHas('events', fn ($events) => $events->where('event', 'resolved')
                                        ->whereIn('detail', ['LOST', 'DAMAGED', 'CLOSE_LOST', 'CLOSE_DAMAGED']));
                            });
                    });
            });
    }

    public static function assertOperationsAllowed(SellerOrder $parcel): void
    {
        if (in_array(strtoupper((string) $parcel->getRawOriginal('status')), ['LOST', 'DAMAGED'], true)
            || self::movementBlockers($parcel)->exists()) {
            throw ValidationException::withMessages(['parcel' => 'Parcel operations are blocked due to an unresolved exception.']);
        }
    }

    /** A reviewed operational hold permits only an existing transfer's verified recovery receipt. */
    public static function assertReceivingAllowed(SellerOrder $parcel): void
    {
        if (in_array(strtoupper((string) $parcel->getRawOriginal('status')), ['LOST', 'DAMAGED'], true)) {
            self::assertOperationsAllowed($parcel);
        }
        $blockers = self::movementBlockers($parcel)->lockForUpdate()->get();
        if ($blockers->isNotEmpty() && $blockers->every(fn (Incidents $incident) => in_array($incident->type, ['missing', 'wrong_hub', 'inactive_connection', 'failed_transfer', 'damaged'], true)
            && $incident->status === ParcelIncidentStatus::UnderReview && $incident->reviewed_at !== null
            && ($incident->type !== 'damaged' || $incident->reviewed_by_type === 'admin'))) {
            return;
        }
        self::assertOperationsAllowed($parcel);
    }

    public function recordRecoveryReceipt(SellerOrder $parcel, ManifestItem|ParcelHandoff $receipt): bool
    {
        $blockers = self::movementBlockers($parcel)->lockForUpdate()->get();
        if ($blockers->isEmpty()) {
            return false;
        }
        self::assertReceivingAllowed($parcel);
        $verified = app(ParcelLegService::class)->verifiedLocation($parcel->fresh());
        if (! $verified || $receipt->received_at === null || (int) $receipt->seller_order_id !== (int) $parcel->id) {
            throw ValidationException::withMessages(['route' => 'Recovery requires a verified physical transfer receipt.']);
        }
        foreach ($blockers as $incident) {
            if ($incident->events()->where('event', 'recovery_received')->where('metadata->receipt_type', $receipt->getTable())
                ->where('metadata->receipt_id', $receipt->id)->exists()) {
                continue;
            }
            $this->event($incident, 'recovery_received', 'Verified recovery receipt', 'Parcel remains held for an authorized route decision.',
                'logistics_provider', $receipt->received_by_id, $incident->status->value, $incident->status->value,
                ['receipt_type' => $receipt->getTable(), 'receipt_id' => $receipt->id, 'hub_id' => $verified->id]);
        }

        return true;
    }

    /**
     * A verified recovery scan after a cancelled transfer. The exception stays
     * open: recovering proves where the parcel is, and an authorized resume or
     * reroute decision still decides where it goes next.
     */
    public function recordCancelledTransferRecovery(SellerOrder $parcel, ManifestItem $item, LogisticsHub $hub, int $providerId): void
    {
        $incidents = Incidents::query()
            ->where('incident_seller_order_id', $parcel->id)
            ->where('type', ParcelIncidentType::FailedTransfer->value)
            ->whereIn('status', [ParcelIncidentStatus::Open->value, ParcelIncidentStatus::UnderReview->value])
            ->lockForUpdate()
            ->get();

        foreach ($incidents as $incident) {
            if ($incident->events()->where('event', 'recovery_received')->where('metadata->manifest_item_id', $item->id)->exists()) {
                continue;
            }
            $this->event($incident, 'recovery_received', 'Recovered at '.$hub->name,
                'A verified scan recorded the parcel at '.$hub->name.' after its transfer batch was cancelled. It stays held for an authorized route decision.',
                'logistics_provider', $providerId, $incident->status->value, $incident->status->value,
                ['manifest_id' => $item->manifest_id, 'manifest_item_id' => $item->id, 'hub_id' => $hub->id]);
        }
    }

    /** A parcel confirmed lost or damaged has no other open exception: close them with the same decision. */
    private function closeSiblingIncidents(Incidents $resolved, SellerOrder $parcel, Admin $admin, string $reason, string $resolution): void
    {
        $resolution = in_array($resolution, ['CLOSE_LOST', 'CLOSE_DAMAGED'], true) ? $resolution : 'CLOSE_LOST';
        // Find the ids with a plain read, then lock exactly those rows by key (no secondary-index range locks).
        $ids = Incidents::query()
            ->where('incident_seller_order_id', $parcel->id)
            ->whereKeyNot($resolved->id)
            ->whereIn('status', [ParcelIncidentStatus::Open->value, ParcelIncidentStatus::UnderReview->value])
            ->pluck('id');
        Incidents::query()->whereKey($ids->all())->orderBy('id')->lockForUpdate()->get()
            ->each(function (Incidents $other) use ($resolved, $admin, $reason, $resolution): void {
                $from = $other->status->value;
                $other->forceFill([
                    'status' => ParcelIncidentStatus::Resolved, 'resolution' => $resolution,
                    'resolved_at' => now(), 'resolved_by_type' => 'admin', 'resolved_by_id' => $admin->id,
                ])->save();
                $this->event($other, 'resolved', 'Exception resolved: '.$resolution, $resolution, 'admin', $admin->id,
                    $from, ParcelIncidentStatus::Resolved->value, ['reason' => $reason, 'context' => ['closed_with_incident' => $resolved->id]]);
            });
    }

    public function recordCancelledHandoffRecovery(SellerOrder $parcel, ParcelHandoff $handoff, LogisticsHub $hub, int $providerId): void
    {
        $incidents = Incidents::query()
            ->where('incident_seller_order_id', $parcel->id)
            ->where('type', ParcelIncidentType::FailedTransfer->value)
            ->whereIn('status', [ParcelIncidentStatus::Open->value, ParcelIncidentStatus::UnderReview->value])
            ->lockForUpdate()
            ->get();

        foreach ($incidents as $incident) {
            if ($incident->events()->where('event', 'recovery_received')->where('metadata->parcel_handoff_id', $handoff->id)->exists()) {
                continue;
            }
            $this->event($incident, 'recovery_received', 'Recovered at '.$hub->name,
                'A verified scan recorded the parcel back at '.$hub->name.' after its handoff was cancelled. It stays held for an authorized route decision.',
                'logistics_provider', $providerId, $incident->status->value, $incident->status->value,
                ['parcel_handoff_id' => $handoff->id, 'hub_id' => $hub->id]);
        }
    }

    public function hasOpenIncident(SellerOrder $parcel): bool
    {
        return (bool) Incidents::query()
            ->where('incident_seller_order_id', $parcel->id)
            ->whereIn('status', [ParcelIncidentStatus::Open->value, ParcelIncidentStatus::UnderReview->value])
            ->exists();
    }

    public function assertNoOpenIncident(SellerOrder $parcel): void
    {
        if ($this->hasOpenIncident($parcel)) {
            throw ValidationException::withMessages([
                'parcel' => 'Parcel operations are blocked due to an unresolved exception.',
            ]);
        }
    }

    public static function reportRules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in([ParcelIncidentType::Lost->value, ParcelIncidentType::Damaged->value])],
            'reason' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:1000'],
            'happened_at' => ['nullable', 'date', 'before_or_equal:now'],
            'photo' => ['required_if:type,damaged', 'nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }

    public static function operationalReportRules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in([
                ParcelIncidentType::Missing->value,
                ParcelIncidentType::Damaged->value,
                ParcelIncidentType::WrongHub->value,
                ParcelIncidentType::InactiveConnection->value,
                ParcelIncidentType::FailedTransfer->value,
            ])],
            'reason' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'happened_at' => ['nullable', 'date', 'before_or_equal:now'],
            'blocked_leg_index' => ['nullable', 'integer', 'min:0'],
            'photo' => ['required_if:type,damaged', 'nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }

    private function authorizeReporter(SellerOrder $parcel, Rider|LogisticsProvider $reporter): void
    {
        if ($reporter instanceof LogisticsProvider) {
            abort_unless($reporter->isApproved() && $reporter->archived_at === null, 403);
            Gate::forUser($reporter)->authorize('advance-shipment', $parcel);

            return;
        }

        $operations = new RiderOperationsService;
        if ($parcel->status === OrderStatus::PickedUp) {
            $operations->assertPickupAssigned($reporter, $parcel);
        } elseif (in_array($parcel->status, RiderOperationsService::DELIVERY_STATUSES, true)
            && ! $parcel->hasActiveReturn()) {
            $operations->assertDeliveryAssigned($reporter, $parcel);
        } else {
            abort(403, 'This parcel is not on your active pickup or delivery leg.');
        }
    }

    private function authorizeProviderForParcel(SellerOrder $parcel, LogisticsProvider $provider): void
    {
        abort_unless($provider->isApproved() && $provider->archived_at === null, 403);
        Gate::forUser($provider)->authorize('advance-shipment', $parcel);
        abort_unless($this->providerCanManage($parcel, $provider), 403, 'This parcel is not held by your company.');
    }

    private function authorizeActor(Incidents $incident, SellerOrder $parcel, Admin|LogisticsProvider $actor, bool $resolution): void
    {
        $actor->refresh();
        if ($actor instanceof Admin) {
            abort_unless(in_array($actor->role, ['super_admin', 'admin', 'moderator'], true), 403);

            return;
        }

        abort_unless($actor->isApproved() && $actor->archived_at === null, 403);
        abort_unless($this->providerCanManage($parcel, $actor), 403, 'You cannot change another provider\'s parcel exception.');

        if ($resolution && in_array(strtolower((string) $incident->type), ['lost', 'damaged'], true)) {
            abort(403, 'Lost and damaged exceptions are resolved by Admin.');
        }

        Gate::forUser($actor)->authorize('advance-shipment', $parcel);
    }

    private function providerCanManage(SellerOrder $parcel, LogisticsProvider $provider): bool
    {
        // Historical participation never grants authority over current custody.
        $holderId = $parcel->currentCustodyProviderId();
        if ($holderId !== null) {
            return $holderId === (int) $provider->id;
        }

        return $parcel->carrier_provider_id !== null
            && (int) $parcel->carrier_provider_id === (int) $provider->id;
    }

    private function notifyReported(Incidents $incident): void
    {
        $parcel = $incident->sellerOrder()->with(['order.buyer', 'seller'])->first();
        $provider = LogisticsProvider::query()->find($incident->reporter_id);
        $recipients = [$parcel?->order?->buyer, $parcel?->seller, $provider];

        foreach (array_filter($recipients) as $recipient) {
            try {
                $recipient->notify(new IncidentReported($incident));
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    private function event(
        Incidents $incident,
        string $event,
        string $label,
        ?string $detail,
        ?string $actorType,
        ?int $actorId,
        ?string $from = null,
        ?string $to = null,
        array $metadata = [],
    ): IncidentEvents {
        return IncidentEvents::create([
            'incident_id' => $incident->id,
            'event' => $event,
            'label' => $label,
            'detail' => $detail,
            'occurred_at' => now(),
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'from_status' => $from,
            'to_status' => $to,
            'metadata' => $metadata ?: null,
            // Preserve the legacy admin-resolution audit columns used by the
            // existing incident history UI.
            'resolved_by_admin_id' => $event === 'resolved' && $actorType === 'admin' ? $actorId : null,
            'resolution_reason' => $event === 'resolved' ? ($metadata['reason'] ?? $detail) : null,
        ]);
    }

    private function custodySnapshot(SellerOrder $parcel, StatusHistory $pickup, ?int $providerId): array
    {
        $history = $parcel->statusHistories()
            ->whereIn('to_status', [
                OrderStatus::PickedUp->value, OrderStatus::AtSortingCenter->value, OrderStatus::OutForDelivery->value,
            ])->whereNotNull('actor_id')->reorder()->orderByDesc('created_at')->orderByDesc('id')->first() ?? $pickup;
        $evidence = [[
            'source' => 'status_histories', 'id' => $history->id,
            'event' => $history->to_status->value, 'verified_at' => $history->created_at?->toIso8601String(),
            'actor_type' => $history->actor_type, 'actor_id' => $history->actor_id,
        ]];
        $handoff = $parcel->parcelHandoffs()->whereNotNull('received_at')->reorder()->orderByDesc('received_at')->orderByDesc('id')->first();
        if ($handoff !== null) {
            $evidence[] = [
                'source' => 'parcel_handoffs', 'id' => $handoff->id, 'event' => 'received',
                'verified_at' => $handoff->received_at->toIso8601String(),
                'actor_type' => $handoff->received_by_type, 'actor_id' => $handoff->received_by_id,
                'provider_id' => $handoff->destination_provider_id, 'hub_id' => $handoff->receiving_hub_id,
            ];
        }
        $item = $parcel->manifestItems()->whereNotNull('received_at')->with('manifest')
            ->reorder()->orderByDesc('received_at')->orderByDesc('id')->first();
        if ($item !== null) {
            $evidence[] = [
                'source' => 'manifest_items', 'id' => $item->id, 'event' => 'received',
                'verified_at' => $item->received_at->toIso8601String(),
                'actor_type' => $item->received_by_type, 'actor_id' => $item->received_by_id,
                'provider_id' => $item->manifest?->logistics_provider_id, 'hub_id' => $item->manifest?->destination_hub_id,
            ];
        }
        $return = $parcel->parcelReturn;
        if ($return?->origin_arrived_at !== null) {
            $evidence[] = [
                'source' => 'parcel_returns', 'id' => $return->id, 'event' => 'origin_arrived',
                'verified_at' => $return->origin_arrived_at->toIso8601String(),
                'provider_id' => $return->origin_provider_id, 'hub_id' => $return->origin_hub_id,
            ];
        }

        return [
            'captured_at' => now()->toIso8601String(),
            'responsible_provider_id' => $providerId,
            'custody_provider_id' => $parcel->custody_provider_id,
            'custody_hub_id' => $parcel->custody_hub_id,
            'pickup_courier_id' => $parcel->courier_id,
            'delivery_courier_id' => $parcel->delivery_courier_id,
            'handover_at' => $parcel->handover_at?->toIso8601String(),
            'pickup_history_id' => $pickup->id,
            'last_verified_event' => collect($evidence)->sortByDesc('verified_at')->first(),
        ];
    }

    private function currentCustodySnapshot(SellerOrder $parcel): array
    {
        $parcel->loadMissing(['parcelRoute']);
        $verified = app(ParcelLegService::class)->verifiedLocation($parcel);

        return [
            'captured_at' => now()->toIso8601String(),
            'custody_provider_id' => $parcel->custody_provider_id,
            'custody_hub_id' => $parcel->custody_hub_id,
            'verified_hub_id' => $verified?->id,
            'current_leg_index' => $parcel->parcelRoute?->current_leg_index,
            'handover_at' => $parcel->handover_at?->toIso8601String(),
        ];
    }

    private function assertValidReport(array $payload): array
    {
        foreach (['type', 'reason', 'description'] as $field) {
            if (is_string($payload[$field] ?? null)) {
                $payload[$field] = trim($payload[$field]);
            }
        }
        if (is_string($payload['type'] ?? null)) {
            $payload['type'] = strtolower($payload['type']);
        }
        $validated = validator()->make($payload, self::reportRules(), ['photo.required_if' => 'Attach a photo when reporting a damaged parcel.'])->validate();
        $validated['happened_at'] = isset($validated['happened_at'])
            ? Carbon::parse($validated['happened_at'])->utc() : null;

        return $validated;
    }

    private function assertValidOperationalReport(array $payload): array
    {
        foreach (['type', 'reason', 'description'] as $field) {
            if (is_string($payload[$field] ?? null)) {
                $payload[$field] = trim($payload[$field]);
            }
        }
        if (is_string($payload['type'] ?? null)) {
            $payload['type'] = match (strtoupper($payload['type'])) {
                'MISSING_PARCEL' => 'missing', 'DAMAGED_PARCEL' => 'damaged',
                default => strtolower($payload['type']),
            };
        }
        $validated = validator()->make($payload, self::operationalReportRules(), ['photo.required_if' => 'Attach a photo when reporting a damaged parcel.'])->validate();
        $validated['happened_at'] = isset($validated['happened_at'])
            ? Carbon::parse($validated['happened_at'])->utc() : null;

        return $validated;
    }

    private function assertValidResolve(array $payload): array
    {
        $payload['reason'] = is_string($payload['reason'] ?? null) ? trim($payload['reason']) : ($payload['reason'] ?? null);

        return validator()->make($payload, [
            'event' => ['required', 'string', Rule::in([
                'RESUME', 'RESUME_ORIGINAL_ROUTE', 'RETURN', 'CLOSE_LOST', 'CLOSE_DAMAGED',
                'AUTHORIZE_REROUTE', 'KEEP_ON_HOLD',
            ])],
            'reason' => ['required', 'string', 'max:1000'],
            'context' => ['nullable', 'array'],
        ], [
            'event.required' => 'Select a resolution action.',
            'event.in' => 'Select a supported resolution action.',
            'reason.required' => 'A resolution reason is required.',
        ])->validate();
    }
}
