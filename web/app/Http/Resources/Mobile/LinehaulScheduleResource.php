<?php

namespace App\Http\Resources\Mobile;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LinehaulScheduleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'scheduled_departure_at' => $this->scheduled_departure_at?->toIso8601String(),
            'schedule_version' => (int) $this->schedule_version];
    }
}
