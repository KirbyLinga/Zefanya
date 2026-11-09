<?php

namespace App\Http\Resources\Mobile;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LinehaulTransferResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [...\Illuminate\Support\Arr::except($this->snapshot, ['_dispatch_actor_type', '_dispatch_actor_id']), 'id' => $this->manifest_id, 'status' => 'Reassigned',
            'reassignment_reason' => $this->reason, 'reassigned_at' => $this->created_at->toIso8601String(),
            'trip_pay' => ['status' => 'ineligible', 'amount_minor' => 0],
            'can_decline' => false, 'can_report_delay' => false, 'can_report_breakdown' => false, 'needs_rescan' => false,
            'departure_location' => 'off', 'arrival_location' => 'off', 'items' => []];
    }
}
