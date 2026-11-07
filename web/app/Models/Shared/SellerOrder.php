<?php

namespace App\Models\Shared;

use App\Enums\OrderStatus;
use App\Models\Logistics\Area;
use App\Models\Logistics\DeliveryAssignment;
use App\Models\Logistics\DeliveryProof;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\ManifestItem;
use App\Models\Logistics\ParcelHandoff;
use App\Models\Logistics\ParcelHandoffException;
use App\Models\Logistics\ParcelReturn;
use App\Models\Logistics\ParcelRoute;
use App\Models\Logistics\PickupAttempt;
use App\Models\Logistics\PickupSession;
use App\Models\Rider\Courier;
use App\Models\Seller\Seller;
use App\Models\Seller\SellerDebt;
use App\Models\Seller\SellerSettlement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class SellerOrder extends Model
{
    /** @use HasFactory<SellerOrderFactory> */
    use HasFactory;

    /**
     * `status` is deliberately NOT mass-assignable — pipeline transitions go
     * through controlled business logic in later phases, never request data.
     */
    protected $fillable = [
        'order_id',
        'seller_id',
        'subtotal_minor',
        'parcel_weight_grams',
        'tracking_number',
        'courier_id',
        'delivery_courier_id',
    ];

    protected function casts(): array
    {
        return [
            'subtotal_minor' => 'integer',
            'parcel_weight_grams' => 'integer',
            'shipping_fee_minor' => 'integer',
            'voucher_discount_minor' => 'integer',
            'shop_voucher_discount_minor' => 'integer',
            'platform_voucher_discount_minor' => 'integer',
            'shipping_voucher_discount_minor' => 'integer',
            'shipping_discount_minor' => 'integer',
            'seller_funded_discount_minor' => 'integer',
            'marketplace_funded_discount_minor' => 'integer',
            'commission_bps' => 'integer',
            'commission_minor' => 'integer',
            'seller_net_minor' => 'integer',
            'commission_finalized_at' => 'datetime',
            'carrier_provider_id' => 'integer',
            'handoff_destination_provider_id' => 'integer',
            'custody_provider_id' => 'integer',
            'custody_hub_id' => 'integer',
            'pickup_address_snapshot' => 'array',
            'status' => OrderStatus::class,
            'handover_at' => 'datetime',
            'pickup_window_starts_at' => 'datetime',
            'pickup_window_ends_at' => 'datetime',
            'pickup_missed_windows' => 'integer',
            'delivery_attempts' => 'integer',
            'needs_reassignment' => 'boolean',
            'reassignment_flagged_at' => 'datetime',
            'returned_to_station_at' => 'datetime',
            'delivery_address_verification' => 'array',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────────────

    /**
     * The checkout-level order this fulfilment unit belongs to.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * The shop fulfilling this slice of the order.
     */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function settlement(): HasOne
    {
        return $this->hasOne(SellerSettlement::class);
    }

    /**
     * Payment is per seller_order, not per order.
     */
    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    /** Shop voucher snapshot applied to this Seller's merchandise. */
    public function shopVoucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class, 'shop_voucher_id');
    }

    /** Order-wide platform voucher portion allocated to this Seller. */
    public function platformVoucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class, 'platform_voucher_id');
    }

    /** Shipping voucher portion allocated to this Seller's quoted fee. */
    public function shippingVoucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class, 'shipping_voucher_id');
    }

    /**
     * Carrier selected at checkout; null for legacy parcels. This remains the
     * commercial owner across cross-provider custody transfers.
     */
    public function carrierProvider(): BelongsTo
    {
        return $this->belongsTo(LogisticsProvider::class, 'carrier_provider_id');
    }

    /**
     * Buyer-selected logistics provider for this parcel (alias of carrierProvider).
     */
    public function selectedLogisticsProvider(): ?LogisticsProvider
    {
        return $this->carrierProvider;
    }

    /**
     * Provider physically holding the parcel. Null means custody still sits
     * with the selected carrier.
     */
    public function custodyProvider(): BelongsTo
    {
        return $this->belongsTo(LogisticsProvider::class, 'custody_provider_id');
    }

    public function custodyHub(): BelongsTo
    {
        return $this->belongsTo(LogisticsHub::class, 'custody_hub_id');
    }

    public function currentCustodyProviderId(): ?int
    {
        return $this->custody_provider_id !== null
            ? (int) $this->custody_provider_id
            : ($this->carrier_provider_id !== null ? (int) $this->carrier_provider_id : null);
    }

    /** Current operations follow custody; fleet inference is for legacy parcels only. */
    public function scopeHeldBy(Builder $query, int $providerId): Builder
    {
        return $query->where(function (Builder $held) use ($providerId): void {
            $held->where('custody_provider_id', $providerId)
                ->orWhere(function (Builder $origin) use ($providerId): void {
                    $origin->whereNull('custody_provider_id')->where(function (Builder $owner) use ($providerId): void {
                        $owner->where('carrier_provider_id', $providerId)
                            ->orWhere(function (Builder $legacy) use ($providerId): void {
                                $legacy->whereNull('carrier_provider_id')->where(function (Builder $fleet) use ($providerId): void {
                                    $fleet->whereHas('courier', fn (Builder $q) => $q->where('logistics_provider_id', $providerId))
                                        ->orWhereHas('deliveryCourier', fn (Builder $q) => $q->where('logistics_provider_id', $providerId));
                                });
                            });
                    });
                });
        });
    }

    public function scopeVisibleToProvider(Builder $query, int $providerId): Builder
    {
        return $query->where(function (Builder $visible) use ($providerId): void {
            $visible->heldBy($providerId)
                ->orWhere('carrier_provider_id', $providerId)
                ->orWhereHas('parcelHandoffs', fn (Builder $q) => $q->where('origin_provider_id', $providerId)->whereNotNull('received_at'));
        });
    }

    public function isClosedForHandoff(): bool
    {
        // A collected buyer return is Delivered but physically back in the network.
        if ($this->parcelReturn?->collectedFromBuyer() && $this->parcelReturn->isActive()) {
            return false;
        }

        return in_array($this->status, [
            OrderStatus::Delivered,
            OrderStatus::Completed,
            OrderStatus::Returned,
            OrderStatus::Cancelled,
        ], true);
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    /** The rider's visit to the seller that this parcel is collected in. */
    public function pickupSession(): BelongsTo
    {
        return $this->belongsTo(PickupSession::class, 'pickup_session_id');
    }

    /**
     * The delivery courier assigned at the sorting center (the pickup courier
     * may be a different rider).
     */
    public function deliveryCourier(): BelongsTo
    {
        return $this->belongsTo(Courier::class, 'delivery_courier_id');
    }

    /**
     * Full status-change timeline (append-only audit log).
     */
    public function statusHistories(): HasMany
    {
        return $this->hasMany(StatusHistory::class)->orderBy('created_at');
    }

    /** Completion comes from the trusted transition audit, never updated_at. */
    public function completionTime(): ?\Illuminate\Support\Carbon
    {
        return $this->statusHistories()
            ->where('to_status', OrderStatus::Completed->value)
            ->first()?->created_at;
    }

    /**
     * Lost/damaged incidents reported for this delivery parcel.
     */
    public function incidentSet()
    {
        return $this->hasMany(Incidents::class, 'incident_seller_order_id');
    }

    /**
     * Rider pickup attempts (one row per failed attempt, max two).
     */
    public function pickupAttempts(): HasMany
    {
        return $this->hasMany(PickupAttempt::class)->orderBy('attempt_number');
    }

    /**
     * Total parcel weight in grams. This is the checkout snapshot when one
     * exists; legacy rows fall back to the line items until the backfill runs.
     * A later catalog weight edit must not change an in-transit parcel.
     */
    public function parcelWeightGrams(): int
    {
        if ($this->parcel_weight_grams !== null) {
            return (int) $this->parcel_weight_grams;
        }

        return $this->calculateParcelWeightGrams();
    }

    /**
     * Compute the parcel weight from the current line items, ignoring the
     * snapshot. Used when creating or repairing the snapshot only.
     */
    public function calculateParcelWeightGrams(): int
    {
        return (int) $this->orderItems()
            ->with(['variant.product', 'product'])
            ->get()
            ->sum(function (OrderItem $item): int {
                $weight = $item->variant?->effectiveWeightGrams()
                    ?? (int) ($item->product?->weight_grams ?? 0);

                return $item->quantity * $weight;
            });
    }

    /**
     * Freeze the current line-item weight onto the parcel.
     */
    public function refreshParcelWeight(): void
    {
        $weight = $this->calculateParcelWeightGrams();

        if ($this->parcel_weight_grams === null || (int) $this->parcel_weight_grams !== $weight) {
            $this->forceFill(['parcel_weight_grams' => $weight])->save();
        }
    }

    /**
     * The buyer's destination municipality, read from the order's immutable
     * shipping-address snapshot. Used for service-area matching.
     */
    public function buyerMunicipality(): ?string
    {
        return $this->order?->shipping_address['municipality']
            ?? $this->order?->shipping_address['municipality_name']
            ?? null;
    }

    /**
     * PSGC city/municipality code for the buyer's destination. New checkout
     * snapshots store it; older orders fall back to the buyer's profile. A new
     * snapshot with no code stays empty, since the profile may be another address.
     */
    public function buyerMunicipalityCode(): ?string
    {
        $address = $this->order?->shipping_address ?? [];

        if (array_key_exists('municipality_code', $address) && blank($address['municipality_code'])) {
            return null;
        }

        return $address['municipality_code']
            ?? $address['city_municipality_code']
            ?? $this->order?->buyer?->municipality_code
            ?? null;
    }

    /**
     * The seller's (pickup) municipality, read from the registered shop
     * address. Pickup riders are matched against this — the parcel is
     * collected at the shop, not at the destination.
     */
    public function sellerMunicipality(): ?string
    {
        return $this->seller?->municipality_name;
    }

    /**
     * PSGC city/municipality code of the seller's pickup address.
     */
    public function sellerMunicipalityCode(): ?string
    {
        return $this->seller?->municipality_code;
    }

    public function buyerBarangayCode(): ?string
    {
        // A legacy shipping snapshot must not silently acquire a changed profile address.
        $address = $this->order?->shipping_address ?? [];
        if (filled($address['barangay_code'] ?? null)) {
            return $address['barangay_code'];
        }
        $verification = $this->delivery_address_verification;
        if (is_array($verification) && hash_equals($this->shippingAddressFingerprint(), (string) ($verification['snapshot_hash'] ?? ''))) {
            return $verification['barangay_code'] ?? null;
        }

        return null;
    }

    public function shippingAddressFingerprint(): string
    {
        return hash('sha256', json_encode($this->order?->shipping_address ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public function sellerBarangayCode(): ?string
    {
        return $this->seller?->barangay_code;
    }

    /**
     * Mark this parcel as needing Logistics reassignment without touching the
     * status graph. Used when its rider is suspended/deactivated.
     */
    public function flagForReassignment(string $reason): void
    {
        $this->forceFill([
            'needs_reassignment' => true,
            'reassignment_reason' => $reason,
            'reassignment_flagged_at' => now(),
        ])->save();
    }

    /**
     * The delivery area this parcel is bound for (set when the parcel is
     * sorted at the center).
     */
    public function destinationArea(): BelongsTo
    {
        return $this->belongsTo(Area::class, 'destination_area_id');
    }

    /**
     * Origin Station Hub frozen when pickup routing was established.
     */
    public function originStation(): BelongsTo
    {
        return $this->belongsTo(LogisticsHub::class, 'origin_station_hub_id');
    }

    /**
     * Proof-of-delivery evidence captured by the Rider at the door.
     */
    public function deliveryProof(): HasOne
    {
        return $this->hasOne(DeliveryProof::class);
    }

    /**
     * The courier assignment trail (pickup + delivery, incl. re-assignments).
     */
    public function deliveryAssignments(): HasMany
    {
        return $this->hasMany(DeliveryAssignment::class)->orderBy('created_at');
    }

    /**
     * Hub-to-hub manifest history for this parcel (append-only for audit).
     */
    public function manifestItems(): HasMany
    {
        return $this->hasMany(ManifestItem::class)->orderBy('created_at');
    }

    /** What the Seller owes back when this order was returned after they were paid. */
    public function sellerDebt(): HasOne
    {
        return $this->hasOne(SellerDebt::class);
    }

    public function parcelReturn(): HasOne
    {
        return $this->hasOne(ParcelReturn::class);
    }

    /**
     * True when, after the latest failed delivery attempt, the Station Hub
     * scanned the parcel back in. Until then the parcel is still physically
     * with the Rider who attempted it, so it must not change hands.
     */
    public function isBackAtStationAfterFailure(): bool
    {
        if ($this->returned_to_station_at === null) {
            return false;
        }
        $lastFailure = $this->statusHistories()
            ->where('to_status', OrderStatus::DeliveryFailed->value)
            ->reorder()->orderByDesc('id')->value('created_at');

        return $lastFailure === null || $this->returned_to_station_at->gte($lastFailure);
    }

    public function hasActiveReturn(): bool
    {
        return $this->parcelReturn?->isActive() ?? false;
    }

    public function parcelHandoffs(): HasMany
    {
        return $this->hasMany(ParcelHandoff::class)->orderBy('created_at')->orderBy('id');
    }

    public function parcelHandoffExceptions(): HasMany
    {
        return $this->hasMany(ParcelHandoffException::class)->orderBy('created_at');
    }

    /**
     * The planned multi-leg route for this parcel.
     */
    public function parcelRoute(): HasOne
    {
        return $this->hasOne(ParcelRoute::class);
    }

    /**
     * Movement events derived from internal manifests. These are deliberately
     * separate from status_histories: a hub transfer records physical custody,
     * never the parcel's canonical fulfilment status.
     *
     * @return list<array<string, mixed>>
     */
    public function manifestMovementEvents(): array
    {
        $this->loadMissing([
            'manifestItems.manifest.originHub',
            'manifestItems.manifest.destinationHub',
            'manifestItems.manifest.provider',
        ]);

        $events = [];

        foreach ($this->manifestItems as $item) {
            $manifest = $item->manifest;

            if ($manifest === null || $manifest->direction === 'return' || $manifest->dispatched_at === null) {
                continue;
            }

            $origin = $manifest->originHub?->name ?? 'origin hub';
            $destination = $manifest->destinationHub?->name ?? 'destination hub';
            $provider = $manifest->provider?->business_name ?? 'Logistics provider';

            $events[] = [
                'type' => 'dispatch',
                'title' => 'Dispatched from '.$origin,
                'detail' => 'Manifest #'.$manifest->id.' · '.$provider,
                'custody' => $item->received_at === null && $item->released_at === null
                    ? 'In transit — the origin hub remains accountable until the destination receiving scan.'
                    : null,
                'at' => $manifest->dispatched_at,
            ];

            if ($item->received_at !== null) {
                $events[] = [
                    'type' => 'receipt',
                    'title' => 'Arrived / received at '.$destination,
                    'detail' => 'Hub arrival and receiving scan confirmed by '.$provider.' · Manifest #'.$manifest->id,
                    'custody' => 'Custody transferred to the destination hub.',
                    'at' => $item->received_at,
                ];
            }
        }

        usort($events, fn (array $a, array $b): int => $a['at'] <=> $b['at']);

        return $events;
    }

    /** Customer-safe tracking: handoffs remain events, never delivery statuses. */
    public function trackingEvents(bool $buyerFacing = false): array
    {
        $this->loadMissing(['statusHistories', 'parcelHandoffs.sendingHub', 'parcelHandoffs.receivingHub', 'originStation', 'custodyHub', 'manifestItems.manifest.destinationHub']);
        $events = [[
            'key' => 'created', 'type' => 'status', 'title' => 'Order created',
            'at' => $this->created_at, 'sequence' => 0,
        ]];
        $failedAttempt = 0;
        foreach ($this->statusHistories as $history) {
            $events[] = [
                'key' => 'status:'.$history->id,
                'type' => 'status',
                // One entry per transition with its exact step ("Ready for pickup",
                // "Picked up"). The buyer's simplified stage (buyerLabel) belongs
                // on the status pill and milestones, where it would otherwise
                // repeat here (Confirmed/Preparing/Ready all read "Preparing").
                'title' => $history->to_status === OrderStatus::Cancelled && $history->actor_type === 'buyer'
                    ? 'Cancelled by Buyer' : $history->to_status->label(),
                'detail' => match (true) {
                    $history->to_status === OrderStatus::DeliveryFailed => 'Failed delivery attempt '.(++$failedAttempt).' of 2.',
                    $history->to_status === OrderStatus::Cancelled && $history->actor_type === 'buyer' => Str::after((string) $history->note, 'Buyer cancelled: '),
                    $history->to_status === OrderStatus::AtSortingCenter && $this->originStation !== null => 'Arrived at '.$this->originStation->name.'.',
                    $history->to_status === OrderStatus::Sorted && $this->custodyHub !== null => ($buyerFacing ? 'Preparing the next delivery leg at ' : 'Sorted at ').$this->custodyHub->name.'.',
                    default => null,
                },
                'at' => $history->created_at,
                'sequence' => $history->to_status === OrderStatus::Returned ? 5 : (in_array($history->to_status, [OrderStatus::Placed, OrderStatus::Confirmed, OrderStatus::Preparing, OrderStatus::ReadyForPickup, OrderStatus::PickedUp, OrderStatus::AtSortingCenter], true) ? 1 : 3),
            ];
        }
        // Rider assignment updates from the delivery_assignments history, never
        // naming the Rider. A pickup reservation changes no status, so its first
        // assignment is shown; the first delivery assignment is already the
        // "Assigned to rider" status. A later row with a different Courier is a
        // reassignment (a same-Rider reschedule is not).
        $this->loadMissing('deliveryAssignments');
        foreach ($this->deliveryAssignments->sortBy('id')->groupBy('role') as $role => $rows) {
            $previous = null;
            foreach ($rows->values() as $index => $row) {
                $changed = $previous !== null && (int) $previous->courier_id !== (int) $row->courier_id;
                $previous = $row;
                [$title, $detail] = match (true) {
                    $index === 0 && $role === 'pickup' => ['Pickup rider assigned', 'A rider will collect the parcel from the seller.'],
                    $changed && $role === 'pickup' => ['Pickup rider changed', 'Another rider will now collect the parcel from the seller.'],
                    $changed && $role === 'delivery' => ['Delivery rider changed', $buyerFacing ? 'Another rider will now deliver your parcel.' : 'Another rider will now deliver this parcel.'],
                    default => [null, null],
                };
                if ($title !== null) {
                    $events[] = [
                        'key' => 'assignment:'.$row->id, 'type' => 'assignment', 'title' => $title, 'detail' => $detail,
                        // Pickup: after a same-second "Ready for pickup" (stable
                        // sort). Delivery: before same-second delivery statuses,
                        // so "Delivered" stays the last entry.
                        'at' => $row->created_at, 'sequence' => $role === 'pickup' ? 1 : 2.9,
                    ];
                }
            }
        }
        foreach ($this->parcelHandoffs as $handoff) {
            foreach ([
                'requested_at' => 'Handoff requested',
                'accepted_at' => 'Handoff accepted',
                'dispatched_at' => 'Dispatched to destination provider',
                'received_at' => 'Received by destination provider',
            ] as $field => $title) {
                if ($handoff->{$field} !== null) {
                    $hubName = match ($field) {
                        'dispatched_at' => $handoff->sendingHub?->name,
                        'received_at' => $handoff->receivingHub?->name,
                        default => null,
                    };
                    $events[] = [
                        'key' => 'handoff:'.$handoff->id.':'.$field,
                        'detail' => $hubName === null ? null : ($field === 'received_at' ? 'Arrived at ' : 'Left ').$hubName.'.',
                        'type' => 'handoff', 'title' => $handoff->direction === 'return' ? 'Return: '.$title : $title, 'at' => $handoff->{$field},
                        'sequence' => $handoff->direction === 'return' ? ['requested_at' => 4.1, 'accepted_at' => 4.2, 'dispatched_at' => 4.3, 'received_at' => 4.5][$field] : 2,
                    ];
                }
            }
        }

        foreach ($this->manifestItems as $item) {
            $manifest = $item->manifest;
            if ($item->received_at !== null && $manifest !== null && $manifest->direction !== 'return' && $manifest->destinationHub !== null) {
                $events[] = [
                    'key' => 'manifest-arrival:'.$item->id, 'type' => 'status',
                    'title' => 'Arrived at '.$manifest->destinationHub->name,
                    'at' => $item->received_at, 'sequence' => 2,
                ];
            }
        }

        // The schema stores seconds. Equal timestamps follow lifecycle order,
        // keeping destination receipt before subsequent delivery events.
        $this->loadMissing('parcelReturn.events');
        foreach ($this->parcelReturn?->events ?? [] as $event) {
            $title = match ($event->event_key) {
                'requested' => 'Physical return requested',
                'collected_from_buyer' => 'Return collected from buyer',
                'in_transit' => 'Return in transit to Seller',
                'at_origin' => 'Return received at origin station',
                'awaiting_seller' => 'Awaiting Seller physical receipt',
                'completed' => 'Physical return received by Seller',
                default => null,
            };
            if ($title !== null) {
                $events[] = ['key' => 'return:'.$event->id, 'type' => 'return', 'title' => $title,
                    'at' => $event->occurred_at, 'sequence' => ['requested' => 4, 'collected_from_buyer' => 4.2, 'in_transit' => 4.4, 'at_origin' => 4.6, 'awaiting_seller' => 4.7, 'completed' => 4.8][$event->event_key]];
            }
        }

        return collect($events)->unique('key')->sort(fn (array $a, array $b): int => ($a['at'] <=> $b['at']) ?: ($a['sequence'] <=> $b['sequence'])
        )->values()->all();
    }

    /**
     * True when this parcel has been claimed by the given provider's fleet
     * (its pickup courier belongs to the provider).
     */
    public function isClaimedBy(LogisticsProvider $provider): bool
    {
        return $this->courier !== null
            && (int) $this->courier->logistics_provider_id === (int) $provider->getKey();
    }

    /**
     * Human-readable parcel weight for display, e.g. "1.2 kg" or "350 g".
     */
    public function formattedParcelWeight(): string
    {
        $grams = $this->parcelWeightGrams();

        if ($grams >= 1000) {
            $kg = $grams / 1000;

            return rtrim(rtrim(number_format($kg, 2, '.', ''), '0'), '.').' kg';
        }

        return $grams.' g';
    }

    /**
     * Generate a unique, human-readable tracking number.
     * Format: TRK-YYYYMMDD-XXXXXX (6 random chars, collision-safe loop).
     */
    public static function generateUniqueTrackingNumber(): string
    {
        do {
            $candidate = 'TRK-'.now()->format('Ymd').'-'.strtoupper(Str::random(6));
        } while (self::query()->where('tracking_number', $candidate)->exists());

        return $candidate;
    }

    // ── Formatting helpers ───────────────────────────────────────────────────

    /** What to pack at a glance: "Tulfone", or "Tulfone +2 more" for several products. */
    public function itemsSummary(): string
    {
        $names = $this->orderItems->pluck('product_name')->unique()->values();

        return match (true) {
            $names->isEmpty() => '—',
            $names->count() === 1 => (string) $names->first(),
            default => $names->first().' +'.($names->count() - 1).' more',
        };
    }

    public function formattedSubtotal(): string
    {
        return '₱'.number_format($this->subtotal_minor / 100, 2);
    }

    /**
     * Human-readable state for the pickup phase.
     * Derived from existing fields: status, courier_id, needs_reassignment.
     */
    public function pickupStateLabel(): string
    {
        if ($this->needs_reassignment) {
            return 'Needs reassignment';
        }

        if ($this->status === OrderStatus::PickedUp) {
            return 'Picked up';
        }

        if ($this->courier_id !== null) {
            return 'Awaiting pickup';
        }

        return 'Unassigned';
    }

    /**
     * Origin Station Hub name from persisted routing only.
     */
    public function originStationName(): ?string
    {
        return $this->originStation?->name;
    }

    /**
     * Pickup address from the seller's registered shop address.
     */
    public function pickupAddress(): ?string
    {
        if (filled($this->pickup_address_snapshot['full'] ?? null)) {
            return $this->pickup_address_snapshot['full'];
        }

        if (! $this->seller) {
            return null;
        }

        $parts = collect([
            $this->seller->house_number,
            $this->seller->street,
            $this->seller->barangay_name,
            $this->seller->municipality_name,
            $this->seller->province_name,
        ])->filter(fn ($v) => filled($v))->values();

        return $parts->isNotEmpty() ? $parts->implode(', ') : null;
    }
}
