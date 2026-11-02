<?php

namespace App\Services\Logistics;

use App\Enums\LogisticsProviderStatus;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\LogisticsProviderLink;
use App\Models\Logistics\ParcelRoute;
use App\Models\Logistics\TransportConnection;
use App\Models\Seller\Seller;
use App\Models\Shared\SellerOrder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Multi-leg route planning for parcels across hubs and providers.
 *
 * Finds optimal paths from origin (seller pickup barangay) to destination
 * (buyer delivery barangay) using configured TransportConnections and
 * existing LogisticsProviderLinks. Supports same-provider multi-hub transfers,
 * cross-provider handoffs, and inter-island routing.
 *
 * Uses deterministic breadth-first search (BFS) with bounded depth to avoid
 * circular routes and handle network complexity.
 */
class RoutePlannerService
{
    private const MAX_ROUTE_DEPTH = 10;  // Prevent infinite loops in cyclic graphs

    private LogisticsHubService $hubService;

    public function __construct(LogisticsHubService $hubService)
    {
        $this->hubService = $hubService;
    }

    /**
     * Plan a complete route from seller pickup to buyer delivery.
     *
     * Resolves origin/destination hubs from barangay coverage, then finds
     * a valid path through configured transport connections and provider links.
     *
     * @throws ValidationException if route cannot be planned
     */
    public function planRoute(
        Seller $seller,
        LogisticsProvider $originProvider,
        string $buyerBarangayCode,
        string $buyerProvinceCode,
    ): ParcelRoute {
        // Resolve origin hub (pickup location)
        $originArea = $this->hubService->areaForBarangay(
            (int) $originProvider->id,
            $seller->barangay_code
        );

        if (! $originArea || ! $originArea->station) {
            throw ValidationException::withMessages([
                'route' => 'Origin Station Hub not found or not operational for seller location.',
            ]);
        }

        $originHub = $originArea->station;

        if ((string) $originProvider->province_code === (string) $buyerProvinceCode && filled($originProvider->province_code)) {
            // Same-province: the origin provider must cover the destination itself.
            $destinationHub = $this->hubService->areaForBarangay((int) $originProvider->id, $buyerBarangayCode)?->station;

            if (! $destinationHub) {
                throw ValidationException::withMessages([
                    'route' => 'No destination hub found or operational for delivery location.',
                ]);
            }

            // Origin and destination share a Station Hub: valid route with no transfer legs.
            $legs = (int) $originHub->id === (int) $destinationHub->id ? [] : $this->findPath($originHub, $destinationHub, $originProvider);

            if ((int) $originHub->id !== (int) $destinationHub->id && $legs === []) {
                throw ValidationException::withMessages([
                    'route' => 'No connected route exists from origin to destination.',
                ]);
            }
        } else {
            $destination = $this->crossProvinceDestination($originProvider, $originHub, $buyerBarangayCode, $buyerProvinceCode);

            if ($destination === null) {
                throw ValidationException::withMessages([
                    'route' => $this->destinationCandidates($originProvider, $buyerBarangayCode, $buyerProvinceCode)->isEmpty()
                        ? 'No destination hub found or operational for delivery location.'
                        : 'No connected route exists from origin to destination.',
                ]);
            }

            $destinationHub = $destination['hub'];
            $legs = $destination['legs'];
        }

        // Validated route - caller must save with seller_order_id
        $route = new ParcelRoute;
        $route->forceFill([
            'origin_hub_id' => $originHub->id,
            'destination_hub_id' => $destinationHub->id,
            'legs' => $legs,
            'current_leg_index' => 0,
        ]);

        return $route;
    }

    /**
     * The final provider, hub and legs for a cross-province parcel.
     *
     * Direct partners are tried first in Admin priority order, then any other
     * provider covering the barangay. A path only counts through usable
     * connections, and each provider change on one needs its own active
     * directed partnership, so every transition is validated here at checkout.
     *
     * @return array{provider: LogisticsProvider, hub: LogisticsHub, legs: list<array<string, mixed>>}|null
     */
    public function crossProvinceDestination(
        LogisticsProvider $originProvider,
        LogisticsHub $originHub,
        string $buyerBarangayCode,
        string $buyerProvinceCode,
    ): ?array {
        foreach ($this->destinationCandidates($originProvider, $buyerBarangayCode, $buyerProvinceCode) as $provider) {
            $hub = $this->hubService->areaForBarangay((int) $provider->id, $buyerBarangayCode)?->station;
            $legs = $hub === null ? [] : $this->findPath($originHub, $hub, $originProvider);

            if ($legs !== []) {
                return ['provider' => $provider, 'hub' => $hub, 'legs' => $legs];
            }
        }

        return null;
    }

    /**
     * @return Collection<int, LogisticsProvider>
     */
    private function destinationCandidates(LogisticsProvider $originProvider, string $buyerBarangayCode, string $buyerProvinceCode): Collection
    {
        $partners = app(ParcelHandoffService::class)
            ->serviceableLinks($originProvider, $buyerBarangayCode, $buyerProvinceCode)
            ->map(fn (LogisticsProviderLink $link): ?LogisticsProvider => $link->destinationProvider)
            ->filter()
            ->values();

        $others = LogisticsProvider::query()
            ->where('status', LogisticsProviderStatus::Approved->value)
            ->whereNull('archived_at')
            ->whereNotNull('province_verified_at')
            ->where('province_code', $buyerProvinceCode)
            ->whereKeyNot([$originProvider->id, ...$partners->modelKeys()])
            ->orderBy('id')
            ->get()
            ->filter(fn (LogisticsProvider $provider): bool => $this->hubService->areaForBarangay((int) $provider->id, $buyerBarangayCode) !== null
                && $this->hubService->hasOperationalProvinceCoverage($provider));

        return $partners->concat($others)->values();
    }

    /**
     * Find a valid path from origin to destination hub using breadth-first search.
     *
     * Returns ordered list of route legs, or empty array if no path found.
     *
     * @return array[] array of legs: {hub_id, transport_mode, expected_arrival_at?}
     */
    public function findPath(
        LogisticsHub $originHub,
        LogisticsHub $destinationHub,
        LogisticsProvider $originProvider,
    ): array {
        if (! app(TransportConnectionService::class)->eligibleHub($originHub)
            || ! app(TransportConnectionService::class)->eligibleHub($destinationHub)
            || (int) $originHub->logistics_provider_id !== (int) $originProvider->id) {
            return [];
        }

        // BFS to find shortest path
        $queue = [
            [
                'currentHub' => $originHub,
                'path' => [],
                'visited' => [(int) $originHub->id],
                'currentProvider' => $originProvider,
            ],
        ];

        while (! empty($queue)) {
            $state = array_shift($queue);
            $currentHub = $state['currentHub'];
            $path = $state['path'];
            $visited = $state['visited'];
            $currentProvider = $state['currentProvider'];

            // Prevent infinite loops
            if (count($visited) > self::MAX_ROUTE_DEPTH) {
                continue;
            }

            // Check if we reached destination
            if ((int) $currentHub->id === (int) $destinationHub->id) {
                return $path;
            }

            // Explore neighbors via active transport connections
            $connections = TransportConnection::query()
                ->where('origin_hub_id', $currentHub->id)
                ->where('is_active', true)
                ->with(['originHub', 'destinationHub'])
                ->orderBy('id')
                ->get();

            foreach ($connections as $connection) {
                $nextHub = $connection->destinationHub;

                // A hub that stopped operating cannot carry a route
                if (! $connection->isActive()) {
                    continue;
                }

                // Skip if already visited (prevent cycles)
                if (in_array((int) $nextHub->id, $visited, true)) {
                    continue;
                }

                // Add leg to path
                $newPath = $path;
                $newPath[] = [
                    'hub_id' => (int) $nextHub->id,
                    'transport_mode' => $connection->transport_mode,
                    'transport_connection_id' => (int) $connection->id,
                    'operating_provider_id' => (int) $connection->operating_provider_id,
                ];

                $nextProvider = $nextHub->provider;

                // Add to queue for further exploration
                $newVisited = $visited;
                $newVisited[] = (int) $nextHub->id;

                $queue[] = [
                    'currentHub' => $nextHub,
                    'path' => $newPath,
                    'visited' => $newVisited,
                    'currentProvider' => $nextProvider,
                ];
            }
        }

        return [];  // No path found
    }

    /**
     * Validate that a planned route is still feasible (all hubs and connections active).
     */
    public function isRouteFeasible(ParcelRoute $route, int $fromLeg = 0): bool
    {
        $legs = is_array($route->legs) ? $route->legs : ($route->legs ? json_decode($route->legs, true) : []);
        if ($legs === []) {
            return (int) $route->origin_hub_id === (int) $route->destination_hub_id
                && (bool) $route->originHub?->isOperational();
        }
        $previousHubId = $route->origin_hub_id;
        foreach ($legs as $index => $leg) {
            $currentHubId = $leg['hub_id'] ?? null;
            if (! $currentHubId) {
                return false;
            }
            if ($index >= $fromLeg) {
                $previousHubId = $leg['origin_hub_id'] ?? $previousHubId;
                $connections = TransportConnection::query()
                    ->where('origin_hub_id', $previousHubId)
                    ->where('destination_hub_id', $currentHubId)
                    ->where('transport_mode', $leg['transport_mode'] ?? '')
                    ->where('is_active', true)
                    ->when(isset($leg['transport_connection_id']), fn ($query) => $query->whereKey($leg['transport_connection_id']))
                    ->when(isset($leg['operating_provider_id']), fn ($query) => $query->where('operating_provider_id', $leg['operating_provider_id']))
                    ->get();
                if (! $connections->contains(fn (TransportConnection $connection) => $connection->isActive())) {
                    return false;
                }
            }
            $previousHubId = $currentHubId;
        }

        return (int) $previousHubId === (int) $route->destination_hub_id;
    }

    public function requireAvailableDeparture(SellerOrder $order): void
    {
        // Physical returns have their own reverse movement authorization.
        $route = $order->parcelRoute;
        if ($route && ! $route->isCompleted() && ! $order->hasActiveReturn()) {
            $this->requireAvailableFutureLegs($route, $route->current_leg_index);
        }
    }

    public function requireAvailableFutureLegs(ParcelRoute $route, int $fromLeg): void
    {
        if (! $this->isRouteFeasible($route, $fromLeg)) {
            throw ValidationException::withMessages([
                'route' => 'A saved transport leg is unavailable. Keep the saved route and request authorized routing review before continuing.',
            ]);
        }
    }

    /**
     * Get human-readable route description.
     */
    public function describeRoute(ParcelRoute $route): string
    {
        $origin = $route->originHub?->name ?? 'Unknown Origin';
        $destination = $route->destinationHub?->name ?? 'Unknown Destination';
        $legs = is_array($route->legs) ? $route->legs : ($route->legs ? json_decode($route->legs, true) : []);

        if (empty($legs)) {
            return "{$origin} → {$destination} (Direct)";
        }

        $hubs = collect([$origin]);
        foreach ($legs as $leg) {
            $hub = LogisticsHub::query()->find($leg['hub_id'])->name ?? "Hub {$leg['hub_id']}";
            $mode = $leg['transport_mode'] ?? 'Unknown';
            $hubs->push("{$hub} ({$mode})");
        }

        return $hubs->join(' → ');
    }
}
