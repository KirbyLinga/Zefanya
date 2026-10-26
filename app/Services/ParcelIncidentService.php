<?php

namespace App\Services;

use App\Enums\OrderActor;
use App\Enums\OrderStatus;
use App\Enums\ParcelIncidentStatus;
use App\Enums\ParcelIncidentType;
use App\Models\Admin\Admin;
use App\Models\IncidentEvents;
use App\Models\Incidents;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Rider;
use App\Models\SellerOrder;
use App\Models\StatusHistory;
use App\Notifications\Incident\IncidentReported;
use App\Notifications\Incident\IncidentResolved;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Report, resolve and guard delivery transitions against open incidents.
 *
 * A delivery is lockable only when its parcel holds "open" status. The guard
 * reads the incident set via the shared parcel key and never touches
 * `status` or `courier_id` directly — those stay under controlled writes.
 *
 * @phpstan-require-immutable
 */
class ParcelIncidentService
{
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

                IncidentEvents::create([
                    'incident_id' => $incident->id,
                    'event' => 'reported',
                    'label' => 'Incident reported',
                    'detail' => $validated['type'],
                    'occurred_at' => now(),
                ]);

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

    private function custodySnapshot(SellerOrder $parcel, StatusHistory $pickup, int $providerId): array
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
            'handover_at' => $parcel->handover_at->toIso8601String(),
            'pickup_history_id' => $pickup->id,
            'last_verified_event' => collect($evidence)->sortByDesc('verified_at')->first(),
        ];
    }

    public static function reportRules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in([ParcelIncidentType::Lost->value, ParcelIncidentType::Damaged->value])],
            'reason' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:1000'],
            'happened_at' => ['nullable', 'date', 'before_or_equal:now'],
            'photo' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }

    public function resolve(Incidents $incident, Admin $admin, array $payload): void
    {
        DB::transaction(function () use ($incident, $admin, $payload): void {
            $parcel = SellerOrder::query()->whereKey($incident->incident_seller_order_id)->lockForUpdate()->firstOrFail();
            $incident = Incidents::query()->whereKey($incident->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($admin)->authorize('resolve', $incident);
            $validated = $this->assertValidResolve($payload);
            $action = $validated['event'];

            if (($action === 'CLOSE_LOST' && strtolower($incident->type) !== 'lost')
                || ($action === 'CLOSE_DAMAGED' && strtolower($incident->type) !== 'damaged')) {
                throw ValidationException::withMessages(['resolution' => 'The closure must match the reported incident type.']);
            }

            $incident->forceFill(['status' => ParcelIncidentStatus::Resolved])->save();
            IncidentEvents::create([
                'incident_id' => $incident->id,
                'event' => 'resolved',
                'label' => 'Admin resolution: '.$action,
                'detail' => $action,
                'resolved_by_admin_id' => $admin->id,
                'resolution_reason' => $validated['reason'],
                'occurred_at' => now(),
            ]);

            // A failed RETURN rolls this resolution and its audit event back too.
            if ($action === 'RETURN') {
                (new ParcelReturnService)->requestForIncident($parcel, $admin, $validated['reason']);
            }

            $parcel->loadMissing(['order.buyer', 'seller']);
            $providerId = (new ParcelReturnService)->custodyProviderId($parcel);
            $provider = $providerId !== null ? LogisticsProvider::query()->find($providerId) : null;
            if ($parcel->order?->buyer === null || $parcel->seller === null || $provider === null) {
                throw ValidationException::withMessages([
                    'incident' => 'The Buyer, Seller, or responsible Logistics provider is unavailable. Restore the missing recipient before resolving this incident.',
                ]);
            }

            // Database notifications commit atomically with the resolution.
            foreach ([$parcel->order?->buyer, $parcel->seller, $provider] as $recipient) {
                $recipient?->notify(new IncidentResolved($incident, $action));
            }
        });
    }

    /**
     * Call under the parcel row lock before operational writes.
     * Query fresh incident state instead of a cached relationship.
     */
    public static function assertOperationsAllowed(SellerOrder $parcel): void
    {
        $blocked = in_array(strtoupper((string) $parcel->getRawOriginal('status')), ['LOST', 'DAMAGED'], true)
            || Incidents::query()
                ->where('incident_seller_order_id', $parcel->id)
                ->whereIn('type', ['lost', 'damaged', 'LOST', 'DAMAGED'])
                ->where(function ($query): void {
                    $query->where('status', ParcelIncidentStatus::Open->value)
                        ->orWhereHas('events', fn ($events) => $events
                            ->where('event', 'resolved')
                            ->whereIn('detail', ['LOST', 'DAMAGED', 'CLOSE_LOST', 'CLOSE_DAMAGED']));
                })->exists();

        if ($blocked) {
            throw ValidationException::withMessages([
                'parcel' => 'Parcel operations are blocked due to an unresolved incident.',
            ]);
        }
    }

    public function hasOpenIncident(SellerOrder $parcel): bool
    {
        return (bool) Incidents::query()
            ->where('incident_seller_order_id', $parcel->id)
            ->where('status', ParcelIncidentStatus::Open->value)
            ->exists();
    }

    public function assertNoOpenIncident(SellerOrder $parcel): void
    {
        if ($this->hasOpenIncident($parcel)) {
            throw ValidationException::withMessages([
                'parcel' => 'Parcel operations are blocked due to an unresolved incident.',
            ]);
        }
    }

    public function parcelKey(SellerOrder $parcel): string
    {
        return 'seller_order:'.$parcel->id;
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
        $validated = validator()->make($payload, self::reportRules())->validate();
        $validated['happened_at'] = isset($validated['happened_at'])
            ? Carbon::parse($validated['happened_at'])->utc() : null;

        return $validated;
    }

    private function assertValidResolve(array $payload): array
    {
        $payload['reason'] = is_string($payload['reason'] ?? null) ? trim($payload['reason']) : ($payload['reason'] ?? null);

        return validator()->make($payload, [
            'event' => ['required', 'string', Rule::in(['RESUME', 'RETURN', 'CLOSE_LOST', 'CLOSE_DAMAGED'])],
            'reason' => ['required', 'string', 'max:1000'],
        ], [
            'event.required' => 'Select a resolution action.',
            'event.in' => 'Select a supported resolution action.',
            'reason.required' => 'A resolution reason is required.',
        ])->validate();
    }
}
