<?php

namespace App\Http\Resources\Mobile;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LinehaulPayoutResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['amount_minor' => $this->amount_minor, 'paid_on' => $this->paid_on->toDateString()];
    }
}
