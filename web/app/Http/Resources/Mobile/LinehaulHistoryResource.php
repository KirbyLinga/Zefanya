<?php

namespace App\Http\Resources\Mobile;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LinehaulHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return \Illuminate\Support\Arr::only($this->resource, ['from', 'to', 'timezone', 'page', 'last_page', 'total', 'trips']);
    }
}
