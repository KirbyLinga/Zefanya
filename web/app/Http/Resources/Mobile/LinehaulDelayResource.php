<?php

namespace App\Http\Resources\Mobile;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LinehaulDelayResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'reason' => $this->reason, 'new_eta' => $this->new_eta->toIso8601String(),
            'reported_at' => $this->created_at->toIso8601String()];
    }
}
