<?php

namespace App\Http\Resources\Mobile;

use App\Models\Logistics\PickupSession;
use App\Services\Logistics\PickupSessionService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One pickup stop (PickupSession) for the rider app: the parcels collected so
 * far and what is left, with the server's own earnings summary. Nothing here
 * is computed by the client.
 */
class RiderPickupStopResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var PickupSession $session */
        $session = $this->resource;
        $summary = app(PickupSessionService::class)->summary($session);

        return [
            'id' => $session->id,
            'status' => $session->status,
            'seller_name' => $session->seller?->business_name ?? $session->seller?->fullName(),
            'pickup_address' => $session->pickup_address,
            'window_starts_at' => $session->window_starts_at?->toIso8601String(),
            'window_ends_at' => $session->window_ends_at?->toIso8601String(),
            'collected' => $summary['collected'],
            'total' => $summary['total'],
            'remaining' => $summary['total'] - $summary['collected'],
            'earned_minor' => $summary['earnedMinor'],
            'potential_minor' => $summary['potentialMinor'],
            // The service refuses to finish a stop before anything is collected.
            'can_finish' => $session->status === PickupSession::IN_PROGRESS,
        ];
    }
}
