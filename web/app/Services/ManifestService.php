<?php

namespace App\Services;

use App\Enums\ManifestStatus;
use App\Enums\OrderStatus;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\Manifest;
use App\Models\Logistics\ManifestItem;
use App\Models\SellerOrder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Internal hub-to-hub manifest lifecycle.
 *
 * Dispatch and receiving are deliberately separate from SellerOrderStatusService:
 * a manifest records physical hub custody and never changes a parcel's canonical
 * fulfilment status. Every transfer mutation requires a single-use F2 scan
 * token bound to the manifest item and the expected hub.
 */
class ManifestService
{
    public const PHASE_DISPATCH = 'dispatch';

    public const PHASE_RECEIVE = 'receive';

    public function __construct(
        private readonly ParcelHandoffVerification $handoff = new ParcelHandoffVerification,
    ) {}

    /**
     * Same-station deliveries do not need an internal transfer manifest.
     */
    public function requiresManifest(int $originHubId, int $destinationHubId): bool
    {
        return $originHubId !== $destinationHubId;
    }

    public function create(LogisticsProvider $provider, int $originHubId, int $destinationHubId, string $direction = 'forward'): Manifest
    {
        if (! in_array($direction, ['forward', 'return'], true)) {
            throw ValidationException::withMessages(['direction' => 'Choose a valid transport direction.']);
        }
        if (! $this->requiresManifest($originHubId, $destinationHubId)) {
            throw ValidationException::withMessages([
                'destination_hub_id' => 'Origin and destination must be different. Same-station deliveries do not require a manifest.',
            ]);
        }

        $origin = $this->operationalHub($provider, $originHubId, 'origin_hub_id');
        $destination = $this->operationalHub($provider, $destinationHubId, 'destination_hub_id');

        $manifest = new Manifest;
        $manifest->forceFill([
            'logistics_provider_id' => $provider->id,
            'origin_hub_id' => $origin->id,
            'destination_hub_id' => $destination->id,
            'direction' => $direction,
        ])->save();

        return $manifest;
    }

    /**
     * Parcels physically at this manifest's origin hub, inside the provider's
     * own network, that are not already on an active manifest.
     *
     * @return Collection<int, SellerOrder>
     */
    public function eligibleParcels(Manifest $manifest, ?string $search = null): Collection
    {
        if ($manifest->status !== ManifestStatus::Draft) {
            return collect();
        }

        $originHubId = (int) $manifest->origin_hub_id;
        $providerId = (int) $manifest->logistics_provider_id;

        $query = SellerOrder::query()
            ->whereNotIn('status', self::terminalStatuses())
            ->when($manifest->direction === 'return', fn ($q) => $q->whereHas('parcelReturn', fn ($r) => $r->whereIn('status', ['RETURN_REQUESTED', 'RETURN_IN_TRANSIT'])), fn ($q) => $q->whereDoesntHave('parcelReturn'))
            ->where(function ($q) use ($providerId): void {
                $q->heldBy($providerId)->orWhere(function ($legacy): void {
                    $legacy->whereNull('custody_provider_id')->whereNull('carrier_provider_id');
                });
            })
            ->whereDoesntHave('parcelHandoffs', fn ($q) => $q->whereIn('status', ParcelHandoffService::ACTIVE_STATUSES))
            ->whereDoesntHave('manifestItems', fn ($item) => $item->whereNull('released_at'))
            ->where(function ($q) use ($originHubId, $providerId): void {
                $q->where('custody_hub_id', $originHubId)->orWhere('origin_station_hub_id', $originHubId)
                    ->orWhereHas('manifestItems.manifest', fn ($m) => $m
                        ->where('destination_hub_id', $originHubId)
                        ->where('logistics_provider_id', $providerId));
            })
            ->with([
                'order.buyer',
                'seller',
                'orderItems',
                'originStation',
                'manifestItems.manifest.originHub',
                'manifestItems.manifest.destinationHub',
            ])
            ->orderBy('created_at');

        if (filled($search)) {
            $term = '%'.trim((string) $search).'%';
            $query->where(function ($q) use ($term): void {
                $q->where('tracking_number', 'like', $term)
                    ->orWhereHas('order.buyer', fn ($buyer) => $buyer
                        ->where('first_name', 'like', $term)
                        ->orWhere('last_name', 'like', $term))
                    ->orWhereHas('seller', fn ($seller) => $seller->where('business_name', 'like', $term));
            });
        }

        return $query->get()
            ->filter(fn (SellerOrder $parcel): bool => $this->currentHubId($parcel) === $originHubId)
            ->values();
    }

    /**
     * Current physical hub derived from immutable manifest history.
     *
     * While a dispatched manifest is still unreceived, the origin hub remains
     * accountable for custody. A receiving scan is the only event that moves
     * custody to the destination hub.
     */
    public function currentHubId(SellerOrder $parcel): ?int
    {
        $parcel->loadMissing('manifestItems.manifest');

        $items = $parcel->manifestItems;

        $active = $items
            ->filter(fn (ManifestItem $item): bool => $item->released_at === null)
            ->sortByDesc('id')
            ->first();

        if ($active?->manifest !== null) {
            return (int) $active->manifest->origin_hub_id;
        }

        if ($parcel->custody_hub_id !== null) {
            return (int) $parcel->custody_hub_id;
        }

        $received = $items
            ->filter(fn (ManifestItem $item): bool => $item->received_at !== null)
            ->sortByDesc(fn (ManifestItem $item): int => ($item->received_at?->getTimestamp() ?? 0) * 1000 + (int) $item->id)
            ->first();

        if ($received?->manifest !== null) {
            return (int) $received->manifest->destination_hub_id;
        }

        return $parcel->origin_station_hub_id !== null
            ? (int) $parcel->origin_station_hub_id
            : null;
    }

    public function addParcel(Manifest $manifest, SellerOrder $parcel): ManifestItem
    {
        try {
            return DB::transaction(function () use ($manifest, $parcel): ManifestItem {
                $locked = $this->lockManifest($manifest);

                $this->assertDraft($locked, 'Parcels can only be added to a draft manifest.');

                $parcel = SellerOrder::query()
                    ->whereKey($parcel->id)
                    ->lockForUpdate()
                    ->with([
                        'order.buyer',
                        'seller',
                        'orderItems',
                        'originStation',
                        'manifestItems.manifest',
                    ])
                    ->firstOrFail();
                ParcelIncidentService::assertOperationsAllowed($parcel);

                if (in_array($parcel->status, self::terminalStatuses(), true)) {
                    throw ValidationException::withMessages([
                        'seller_order_id' => 'This parcel is already closed and cannot be manifested.',
                    ]);
                }

                if ($parcel->currentCustodyProviderId() !== null
                    && $parcel->currentCustodyProviderId() !== (int) $locked->logistics_provider_id) {
                    throw ValidationException::withMessages([
                        'seller_order_id' => "This parcel is in another Logistics provider's custody.",
                    ]);
                }

                if ($parcel->parcelHandoffs()->whereIn('status', ParcelHandoffService::ACTIVE_STATUSES)->exists()) {
                    throw ValidationException::withMessages(['seller_order_id' => 'Complete the cross-provider handoff before creating an internal transfer.']);
                }
                if ($parcel->hasActiveReturn() !== ($locked->direction === 'return')) {
                    throw ValidationException::withMessages(['seller_order_id' => 'Return parcels require a reverse manifest; normal outgoing and return parcels cannot be mixed.']);
                }
                if ($parcel->hasActiveReturn() && ! in_array($parcel->parcelReturn->status->value, ['RETURN_REQUESTED', 'RETURN_IN_TRANSIT'], true)) {
                    throw ValidationException::withMessages(['return' => 'Transport is closed after verified origin arrival.']);
                }
                if ($parcel->delivery_courier_id !== null && ! $parcel->hasActiveReturn()) {
                    throw ValidationException::withMessages(['seller_order_id' => 'Resolve the delivery assignment before creating an internal transfer.']);
                }

                if (ManifestItem::query()
                    ->where('seller_order_id', $parcel->id)
                    ->whereNull('released_at')
                    ->exists()) {
                    throw ValidationException::withMessages([
                        'seller_order_id' => 'This parcel is already on an active manifest.',
                    ]);
                }

                $currentHubId = $this->currentHubId($parcel);

                if ($currentHubId === null) {
                    throw ValidationException::withMessages([
                        'seller_order_id' => 'This parcel has no established hub custody yet.',
                    ]);
                }

                if ($currentHubId === (int) $locked->destination_hub_id) {
                    throw ValidationException::withMessages([
                        'seller_order_id' => 'This parcel is already at the destination hub.',
                    ]);
                }

                if ($currentHubId !== (int) $locked->origin_hub_id) {
                    $locked->loadMissing('originHub');
                    throw ValidationException::withMessages([
                        'seller_order_id' => 'This parcel is not currently at '.($locked->originHub?->name ?? 'the origin hub').'.',
                    ]);
                }

                return ManifestItem::query()->create([
                    'manifest_id' => $locked->id,
                    'seller_order_id' => $parcel->id,
                    'tracking_number' => $parcel->tracking_number,
                ]);
            });
        } catch (QueryException $e) {
            if ($this->isActiveMembershipViolation($e)) {
                throw ValidationException::withMessages([
                    'seller_order_id' => 'This parcel is already on another active manifest.',
                ]);
            }

            throw $e;
        }
    }

    public function removeParcel(Manifest $manifest, ManifestItem $item): void
    {
        DB::transaction(function () use ($manifest, $item): void {
            $locked = $this->lockManifest($manifest);
            $this->assertDraft($locked, 'Parcels can only be removed from a draft manifest.');

            $lockedItem = ManifestItem::query()
                ->whereKey($item->id)
                ->where('manifest_id', $locked->id)
                ->lockForUpdate()
                ->firstOrFail();

            ParcelIncidentService::assertOperationsAllowed(
                SellerOrder::query()->whereKey($lockedItem->seller_order_id)->lockForUpdate()->firstOrFail(),
            );

            if ($lockedItem->received_at !== null) {
                throw ValidationException::withMessages([
                    'manifest' => 'A received parcel cannot be removed from the manifest.',
                ]);
            }

            $lockedItem->release();
        });
    }

    /**
     * Dispatch every parcel on the manifest. Each parcel needs its own scan
     * token issued for this manifest item at the origin hub.
     *
     * @param  array<int|string, mixed>  $tokens  item id => verification token
     */
    public function dispatch(Manifest $manifest, LogisticsProvider $provider, array $tokens): Manifest
    {
        $this->assertManifestOwner($manifest, $provider);

        $items = new EloquentCollection;
        $consumed = [];

        DB::transaction(function () use ($manifest, $provider, $tokens, &$items, &$consumed): void {
            $locked = $this->lockManifest($manifest);
            $this->assertManifestOwner($locked, $provider);
            $this->assertDraft($locked, 'This manifest has already been dispatched.');

            $items = $locked->items()
                ->whereNull('released_at')
                ->with('sellerOrder')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($items->isEmpty()) {
                throw ValidationException::withMessages([
                    'manifest' => 'Add at least one parcel before dispatching this manifest.',
                ]);
            }

            foreach ($items as $item) {
                $item->setRelation('sellerOrder', SellerOrder::query()->whereKey($item->seller_order_id)->lockForUpdate()->firstOrFail());
                ParcelIncidentService::assertOperationsAllowed($item->sellerOrder);
                if ($item->received_at !== null || $item->released_at !== null) {
                    throw ValidationException::withMessages([
                        'manifest' => 'Every parcel on this manifest must still be active and unreceived before dispatch.',
                    ]);
                }

                $token = $tokens[$item->id] ?? $tokens[(string) $item->id] ?? null;

                if ($this->currentHubId($item->sellerOrder) !== (int) $locked->origin_hub_id) {
                    throw ValidationException::withMessages([
                        'verification_token' => 'A parcel is no longer in the custody of the manifest origin hub. Refresh the manifest and scan again.',
                    ]);
                }

                $this->handoff->assertValidForManifestItem(
                    $provider,
                    $item,
                    ParcelHandoffVerification::ACTION_MANIFEST_DISPATCH,
                    is_string($token) ? $token : null,
                    (int) $locked->origin_hub_id,
                );

                $consumed[] = [$item, is_string($token) ? $token : null];
            }

            $locked->forceFill([
                'status' => ManifestStatus::Dispatched->value,
                'dispatched_at' => now(),
                'dispatched_by_type' => ParcelHandoffVerification::ACTOR_LOGISTICS,
                'dispatched_by_id' => $provider->id,
            ])->save();
            foreach ($items as $returnItem) {
                app(ParcelReturnService::class)->movement($returnItem->sellerOrder, $returnItem);
            }

        });

        foreach ($consumed as [$item, $token]) {
            $this->handoff->consumeAfterSuccess(fn () => $this->handoff->consumeForManifestItem(
                $provider,
                $item,
                ParcelHandoffVerification::ACTION_MANIFEST_DISPATCH,
                $token,
                (int) $manifest->origin_hub_id,
            ));
        }

        return $manifest->fresh();
    }

    /**
     * Verify a scanned tracking number against this manifest without changing
     * anything. A valid, short-lived single-use token is returned for the UI.
     *
     * @return array<string, mixed>
     */
    public function verifyScan(
        Manifest $manifest,
        LogisticsProvider $provider,
        string $trackingNumber,
        string $phase,
        string $method = ParcelHandoffVerification::METHOD_MANUAL,
    ): array {
        $this->assertManifestOwner($manifest, $provider);

        if (! in_array($phase, [self::PHASE_DISPATCH, self::PHASE_RECEIVE], true)) {
            throw ValidationException::withMessages([
                'phase' => 'Choose whether this scan is for dispatch or receiving.',
            ]);
        }

        $manifest = $manifest->fresh(['originHub', 'destinationHub', 'provider']);
        $trackingNumber = trim($trackingNumber);

        if ($trackingNumber === '') {
            throw ValidationException::withMessages([
                'tracking_number' => 'Scan the waybill QR or enter the tracking number.',
            ]);
        }

        $item = $manifest->items()
            ->where(function ($q) use ($trackingNumber): void {
                $q->where('tracking_number', $trackingNumber)
                    ->orWhere(function ($fallback) use ($trackingNumber): void {
                        $fallback->whereNull('tracking_number')
                            ->whereHas('sellerOrder', fn ($order) => $order->where('tracking_number', $trackingNumber));
                    });
            })
            ->with([
                'sellerOrder.order.buyer',
                'sellerOrder.seller',
                'sellerOrder.orderItems',
                'sellerOrder.payment',
            ])
            ->first();

        if ($item === null) {
            throw ValidationException::withMessages([
                'tracking_number' => 'That parcel is not on this manifest.',
            ]);
        }

        if ($item->received_at !== null) {
            throw ValidationException::withMessages([
                'tracking_number' => 'This parcel has already been received at the destination hub.',
            ]);
        }

        if ($item->released_at !== null) {
            throw ValidationException::withMessages([
                'tracking_number' => 'This parcel is no longer active on this manifest.',
            ]);
        }

        if ($phase === self::PHASE_DISPATCH) {
            if ($manifest->status !== ManifestStatus::Draft) {
                throw ValidationException::withMessages([
                    'tracking_number' => 'This manifest has already been dispatched.',
                ]);
            }

            $expectedHubId = (int) $manifest->origin_hub_id;
            $action = ParcelHandoffVerification::ACTION_MANIFEST_DISPATCH;
            $label = 'Dispatch Manifest';
            $url = route('logistics.manifests.dispatch', $manifest);
            $confirm = 'Confirm this parcel is physically on the manifest at the origin hub?';
            $context = 'manifest_dispatch';
        } else {
            if ($manifest->status !== ManifestStatus::Dispatched) {
                throw ValidationException::withMessages([
                    'tracking_number' => 'Only a dispatched manifest can be received.',
                ]);
            }

            $expectedHubId = (int) $manifest->destination_hub_id;
            $action = ParcelHandoffVerification::ACTION_MANIFEST_RECEIVE;
            $label = 'Receive at '.($manifest->destinationHub?->name ?? 'destination hub');
            $url = route('logistics.manifests.receive.store', [$manifest, $item]);
            $confirm = 'Confirm this parcel was physically received at the destination hub?';
            $context = 'manifest_receive';
        }

        if ($this->currentHubId($item->sellerOrder) !== (int) $manifest->origin_hub_id) {
            throw ValidationException::withMessages([
                'tracking_number' => 'This parcel is not currently in the custody of the manifest origin hub.',
            ]);
        }

        $token = $this->handoff->issueForManifestItem(
            $provider,
            $item,
            $action,
            $expectedHubId,
            $method,
        );

        return [
            'context' => $context,
            'manifest' => $this->manifestSummary($manifest),
            'item' => [
                'id' => $item->id,
                'tracking_number' => $item->tracking_number,
                'received_at' => $item->received_at?->toIso8601String(),
            ],
            'parcel' => $this->parcelSummary($item->sellerOrder),
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
     * Receive one parcel. The manifest closes only after its final unreceived
     * parcel is scanned; partial receipt leaves it DISPATCHED.
     *
     * @return array{manifest: Manifest, item: ManifestItem, completed: bool}
     */
    public function receive(
        Manifest $manifest,
        LogisticsProvider $provider,
        ManifestItem $item,
        ?string $token,
    ): array {
        $this->assertManifestOwner($manifest, $provider);

        if ((int) $item->manifest_id !== (int) $manifest->id) {
            abort(404);
        }

        $completed = false;
        $receivedItem = null;
        $method = ParcelHandoffVerification::METHOD_MANUAL;

        DB::transaction(function () use ($manifest, $provider, $item, $token, &$completed, &$receivedItem, &$method): void {
            $locked = $this->lockManifest($manifest);
            $this->assertManifestOwner($locked, $provider);

            if ($locked->status !== ManifestStatus::Dispatched) {
                throw ValidationException::withMessages([
                    'manifest' => 'Only a dispatched manifest can be received.',
                ]);
            }

            $lockedItem = ManifestItem::query()
                ->whereKey($item->id)
                ->where('manifest_id', $locked->id)
                ->with('sellerOrder')
                ->lockForUpdate()
                ->firstOrFail();

            ParcelIncidentService::assertOperationsAllowed(
                SellerOrder::query()->whereKey($lockedItem->seller_order_id)->lockForUpdate()->firstOrFail(),
            );

            if ($lockedItem->received_at !== null) {
                throw ValidationException::withMessages([
                    'verification_token' => 'This parcel has already been received at the destination hub.',
                ]);
            }

            if ($lockedItem->released_at !== null) {
                throw ValidationException::withMessages([
                    'verification_token' => 'This parcel is no longer active on this manifest.',
                ]);
            }

            $payload = $this->handoff->assertValidForManifestItem(
                $provider,
                $lockedItem,
                ParcelHandoffVerification::ACTION_MANIFEST_RECEIVE,
                $token,
                (int) $locked->destination_hub_id,
            );
            $method = $payload['method'] ?? ParcelHandoffVerification::METHOD_MANUAL;

            $lockedItem->markReceived(
                ParcelHandoffVerification::ACTOR_LOGISTICS,
                (int) $provider->id,
            );
            $lockedItem->sellerOrder->forceFill([
                'custody_provider_id' => $provider->id,
                'custody_hub_id' => $locked->destination_hub_id,
            ])->save();
            app(ParcelReturnService::class)->movement($lockedItem->sellerOrder->fresh(), $lockedItem);
            $receivedItem = $lockedItem;

            $pending = ManifestItem::query()
                ->where('manifest_id', $locked->id)
                ->whereNull('received_at')
                ->whereNull('released_at')
                ->exists();

            if (! $pending) {
                $locked->forceFill([
                    'status' => ManifestStatus::Received->value,
                    'received_at' => now(),
                    'received_by_type' => ParcelHandoffVerification::ACTOR_LOGISTICS,
                    'received_by_id' => $provider->id,
                ])->save();
                $completed = true;
            }
        });

        $this->handoff->consumeAfterSuccess(fn () => $this->handoff->consumeForManifestItem(
            $provider,
            $receivedItem,
            ParcelHandoffVerification::ACTION_MANIFEST_RECEIVE,
            $token,
            (int) $manifest->destination_hub_id,
        ));

        app(CourierAssignmentService::class)->dispatchAfterCommit($receivedItem->sellerOrder);

        return [
            'manifest' => $manifest->fresh(),
            'item' => $receivedItem->fresh(),
            'completed' => $completed,
            'method' => $method,
        ];
    }

    /**
     * @return array{total: int, received: int, pending: int}
     */
    public function counts(Manifest $manifest): array
    {
        $items = $manifest->items()
            ->where(function ($q): void {
                $q->whereNull('released_at')->orWhereNotNull('received_at');
            });

        $total = (clone $items)->count();
        $received = (clone $items)->whereNotNull('received_at')->count();

        return [
            'total' => $total,
            'received' => $received,
            'pending' => max(0, $total - $received),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function manifestSummary(Manifest $manifest): array
    {
        return [
            'id' => $manifest->id,
            'status' => $manifest->status?->value,
            'status_label' => $manifest->status?->label(),
            'origin_hub' => $manifest->originHub?->name,
            'destination_hub' => $manifest->destinationHub?->name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parcelSummary(SellerOrder $order): array
    {
        $order->loadMissing(['order.buyer', 'seller', 'orderItems', 'payment']);
        $address = $order->order?->shipping_address ?? [];

        return [
            'id' => $order->id,
            'tracking_number' => $order->tracking_number,
            'status' => $order->status?->value,
            'status_label' => $order->status?->label(),
            'seller_name' => $order->seller?->business_name ?? $order->seller?->fullName() ?? 'Seller',
            'buyer_municipality' => $address['municipality'] ?? $order->buyerMunicipality() ?? '—',
            'item_count' => (int) $order->orderItems->sum('quantity'),
            'weight' => $order->formattedParcelWeight(),
            'payment_method' => $order->payment?->method?->label(),
        ];
    }

    private function operationalHub(LogisticsProvider $provider, int $hubId, string $field): LogisticsHub
    {
        $hub = LogisticsHub::query()
            ->whereKey($hubId)
            ->where('logistics_provider_id', $provider->id)
            ->operational()
            ->first();

        if ($hub === null) {
            throw ValidationException::withMessages([
                $field => 'Select an approved, active hub owned by your Logistics company.',
            ]);
        }

        return $hub;
    }

    private function lockManifest(Manifest $manifest): Manifest
    {
        $locked = Manifest::query()
            ->whereKey($manifest->id)
            ->lockForUpdate()
            ->firstOrFail();

        if ((int) $locked->logistics_provider_id !== (int) $manifest->logistics_provider_id) {
            abort(404);
        }

        return $locked;
    }

    private function assertManifestOwner(Manifest $manifest, LogisticsProvider $provider): void
    {
        // 404 rather than 403: never disclose another provider's manifest.
        abort_unless((int) $manifest->logistics_provider_id === (int) $provider->id, 404);
    }

    private function assertDraft(Manifest $manifest, string $message): void
    {
        if ($manifest->status !== ManifestStatus::Draft) {
            throw ValidationException::withMessages(['manifest' => $message]);
        }
    }

    /**
     * @return list<OrderStatus>
     */
    private static function terminalStatuses(): array
    {
        return [
            OrderStatus::Delivered,
            OrderStatus::Completed,
            OrderStatus::Returned,
            OrderStatus::Cancelled,
        ];
    }

    private function isActiveMembershipViolation(QueryException $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'manifest_items_active_parcel_unique')
            || str_contains($message, 'unique constraint')
            || str_contains($message, 'duplicate entry');
    }
}
