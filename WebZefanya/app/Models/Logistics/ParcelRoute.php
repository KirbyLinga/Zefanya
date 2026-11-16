<?php

namespace App\Models\Logistics;

use App\Models\Shared\SellerOrder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * Planned multi-leg route for a parcel/seller order.
 *
 * Represents the complete path from origin (seller's pickup barangay) to
 * destination (buyer's delivery barangay). Tracks current leg, intermediate hubs,
 * and transport modes. Once created, the route is immutable; rerouting requires
 * explicit authorization (creates new route, invalidates old).
 *
 * Legs are ordered: index 0 is the first departure from origin_hub_id,
 * final leg arrives at destination_hub_id.
 */
class ParcelRoute extends Model
{
    use HasFactory;

    protected $fillable = [
        'seller_order_id',
        'origin_hub_id',
        'destination_hub_id',
        'current_leg_index',
        'legs',
        'route_planned_at',
        'route_completed_at',
    ];

    protected function casts(): array
    {
        return [
            'legs' => 'json',
            'current_leg_index' => 'integer',
            'route_planned_at' => 'datetime',
            'route_completed_at' => 'datetime',
        ];
    }

    /**
     * Get current leg configuration.
     *
     * @return array{hub_id: int, transport_mode: string, expected_arrival_at?: string}|null
     */
    public function currentLeg(): ?array
    {
        $legs = is_array($this->legs) ? $this->legs : ($this->legs ? json_decode($this->legs, true) : []);

        return $legs[$this->current_leg_index] ?? null;
    }

    /**
     * Get the next (not-yet-started) leg.
     *
     * @return array{hub_id: int, transport_mode: string, expected_arrival_at?: string}|null
     */
    public function nextLeg(): ?array
    {
        $legs = is_array($this->legs) ? $this->legs : ($this->legs ? json_decode($this->legs, true) : []);
        $nextIndex = $this->current_leg_index + 1;

        return $legs[$nextIndex] ?? null;
    }

    /**
     * Check if route has completed (all legs completed).
     */
    public function isCompleted(): bool
    {
        return $this->route_completed_at !== null;
    }

    /**
     * Get destination hub of current leg (next hub to arrive at).
     */
    public function currentLegDestinationHubId(): ?int
    {
        $leg = $this->currentLeg();

        return $leg ? (int) ($leg['hub_id'] ?? null) : null;
    }

    /**
     * Get transport mode for current leg.
     */
    public function currentLegTransportMode(): ?string
    {
        $leg = $this->currentLeg();

        return $leg ? ($leg['transport_mode'] ?? null) : null;
    }

    /**
     * Determine if this is the final leg (arrival at destination_hub_id).
     * A route with no legs starts and ends at the same hub, so it has no later leg.
     */
    public function isCurrentLegFinal(): bool
    {
        $legs = is_array($this->legs) ? $this->legs : ($this->legs ? json_decode($this->legs, true) : []);

        return $this->current_leg_index >= count($legs) - 1;
    }

    /**
     * Get ordered list of all hub IDs in route (including origin and destination).
     *
     * @return Collection<int, int>
     */
    public function allHubsInRoute(): Collection
    {
        $hubs = collect([$this->origin_hub_id]);
        $legs = is_array($this->legs) ? $this->legs : ($this->legs ? json_decode($this->legs, true) : []);

        foreach ($legs as $leg) {
            $hubs->push($leg['hub_id'] ?? null);
        }

        return $hubs->filter();
    }

    /**
     * Get total number of legs in route.
     */
    public function totalLegs(): int
    {
        $legs = is_array($this->legs) ? $this->legs : ($this->legs ? json_decode($this->legs, true) : []);

        return count($legs);
    }

    public function sellerOrder(): BelongsTo
    {
        return $this->belongsTo(SellerOrder::class);
    }

    public function originHub(): BelongsTo
    {
        return $this->belongsTo(LogisticsHub::class, 'origin_hub_id');
    }

    public function destinationHub(): BelongsTo
    {
        return $this->belongsTo(LogisticsHub::class, 'destination_hub_id');
    }

    /**
     * Get the provider that will handle the current leg's destination hub.
     */
    public function currentLegDestinationProvider(): ?LogisticsProvider
    {
        $leg = $this->currentLeg();
        if (! $leg) {
            return null;
        }

        $hub = LogisticsHub::query()->find($leg['hub_id']);

        return $hub?->provider;
    }
}
