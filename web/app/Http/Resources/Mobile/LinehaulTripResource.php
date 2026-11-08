<?php

namespace App\Http\Resources\Mobile;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LinehaulTripResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $manifest = $this->manifest;
        $stage = match ($manifest->status) {
            \App\Enums\ManifestStatus::Draft => $this->accepted_at === null ? 'Assigned' : 'Loading',
            \App\Enums\ManifestStatus::Dispatched => $this->arrived_at === null ? 'In Transit' : 'Arrived',
            \App\Enums\ManifestStatus::Received => 'Completed',
            \App\Enums\ManifestStatus::ReceivedWithExceptions => 'Received with Issues',
            default => 'Cancelled',
        };

        return [
            'id' => $manifest->id, 'status' => $stage,
            'origin' => $manifest->originHub?->name, 'destination' => $manifest->destinationHub?->name,
            'seal_number' => $this->seal_number,
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'arrived_at' => $this->arrived_at?->toIso8601String(),
            'items' => $manifest->items->filter(fn ($item): bool => $item->released_at === null || $item->received_at !== null || $item->issue_type !== null)->map(fn ($item): array => [
                'id' => $item->id, 'tracking_number' => $item->tracking_number,
                'loaded' => $manifest->dispatched_at !== null || isset(($this->loading_tokens ?? [])[$item->id]),
                'received' => $item->received_at !== null,
                'issue' => $item->issue_type,
                'courier_note' => $item->issue_type === null ? null : $this->courierNote($item, $manifest->id),
            ])->values()->all(),
        ];
    }

    private function courierNote($item, int $manifestId): ?string
    {
        $event = \App\Models\Shared\IncidentEvents::where('event', 'courier_handover_note')
            ->where('actor_type', 'linehaul')->where('actor_id', $this->linehaul_driver_id)
            ->where('metadata->manifest_id', $manifestId)
            ->whereHas('incident', fn ($query) => $query->where('incident_seller_order_id', $item->seller_order_id)->where('type', $item->issue_type))
            ->first();

        return $event?->metadata['note'] ?? null;
    }
}
