<?php

namespace App\Services\Logistics;

use App\Models\Logistics\ParcelProviderAllocation;
use App\Models\Shared\SellerOrder;

class ProviderAllocationService
{
    /**
     * The caller holds the parcel lock. Multi-provider history is evidence only.
     * Existing COD remittance allocation remains the source of monetary shares.
     */
    public function prepare(SellerOrder $parcel): void
    {
        $chain = $this->chain($parcel);
        $last = count($chain) - 1;
        if ($last < 2) {
            return;
        }

        foreach ($chain as $index => $providerId) {
            ParcelProviderAllocation::query()->firstOrCreate([
                'seller_order_id' => $parcel->id,
                'logistics_provider_id' => $providerId,
            ], [
                'sequence' => $index + 1,
                'role' => match ($index) {
                    0 => 'origin',
                    $last => 'destination',
                    default => 'intermediate',
                },
                'status' => 'pending_policy',
            ]);
        }
    }

    /** @return list<int> */
    public function chain(SellerOrder $parcel): array
    {
        $providers = collect([$parcel->carrier_provider_id]);
        $handoffs = $parcel->parcelHandoffs()->where('direction', 'forward')
            ->whereNotNull('received_at')->orderBy('received_at')->orderBy('id')
            ->get(['origin_provider_id', 'destination_provider_id']);

        foreach ($handoffs as $handoff) {
            $providers->push($handoff->origin_provider_id, $handoff->destination_provider_id);
        }

        return $providers->filter()->map(fn ($id): int => (int) $id)->unique()->values()->all();
    }
}
