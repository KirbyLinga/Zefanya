<?php

namespace App\Http\Resources\Mobile;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LinehaulDeclineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->manifest_id, 'status' => 'Declined', 'offer_version' => $this->offer_version,
            'can_decline' => false, 'decline_reason' => $this->reason, 'declined_at' => $this->created_at->toIso8601String(),
            'origin' => $this->snapshot['origin'], 'destination' => $this->snapshot['destination'],
            'origin_address' => $this->snapshot['origin_address'], 'destination_address' => $this->snapshot['destination_address'],
            'parcel_count' => $this->snapshot['parcel_count'], 'seal_number' => null, 'items' => []];
    }
}
