<?php

namespace App\Http\Resources\Mobile;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LinehaulEarningsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return \Illuminate\Support\Arr::only($this->resource, ['from', 'to', 'timezone', 'earned_minor', 'earned_trips',
            'unconfirmed_trips', 'completed_trips', 'page', 'last_page', 'trips']);
    }
}
