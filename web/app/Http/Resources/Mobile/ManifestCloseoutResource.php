<?php

namespace App\Http\Resources\Mobile;

use App\Services\Logistics\ManifestService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ManifestCloseoutResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $counts = app(ManifestService::class)->counts($this->resource);
        $items = $this->items()->where(fn ($query) => $query->whereNull('released_at')->orWhereNotNull('received_at')->orWhereNotNull('issue_type'))->orderBy('id')->get();

        return ['id' => $this->id, 'status' => $this->status->value,
            'received_count' => $counts['received'], 'pending_count' => $counts['pending'],
            'missing_count' => $items->where('issue_type', 'missing')->count(),
            'items' => $items->map(fn ($item) => ['id' => $item->id, 'tracking_number' => $item->tracking_number,
                'issue' => $item->issue_type, 'received' => $item->received_at !== null])->all()];
    }
}
