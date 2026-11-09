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
        $delay = \App\Services\Logistics\LinehaulDelayService::latest($this->resource);
        $takeover = $manifest->status === \App\Enums\ManifestStatus::Dispatched && $this->arrived_at === null
            && \App\Services\Logistics\LinehaulTransferService::pending($this->resource);
        $items = $manifest->items->sortBy('id')->filter(fn ($item): bool => $item->released_at === null || $item->received_at !== null || $item->issue_type !== null);
        $weights = $items->map(fn ($item) => $item->sellerOrder?->parcel_weight_grams);
        $weight = $items->isNotEmpty() && $weights->every(fn ($grams): bool => is_numeric($grams) && (int) $grams > 0)
            ? (int) $weights->sum() : null;
        $stage = match ($manifest->status) {
            \App\Enums\ManifestStatus::Draft => $this->accepted_at === null ? 'Assigned' : 'Loading',
            \App\Enums\ManifestStatus::Dispatched => $this->arrived_at === null ? 'In Transit' : 'Arrived',
            \App\Enums\ManifestStatus::Received => 'Completed',
            \App\Enums\ManifestStatus::ReceivedWithExceptions => 'Received with Issues',
            default => 'Cancelled',
        };

        return [
            'id' => $manifest->id, 'status' => $stage,
            'needs_rescan' => $takeover,
            'trip_pay' => \App\Services\Logistics\LinehaulPayService::details($this->resource),
            'offer_version' => $this->offer_version ?? 1,
            'can_decline' => \App\Services\Logistics\LinehaulDeclineService::canDecline($this->resource),
            'can_report_delay' => \App\Services\Logistics\LinehaulDelayService::canReport($this->resource),
            'can_report_breakdown' => \App\Services\Logistics\LinehaulBreakdownService::installed()
                && function_exists('imagecreatefromstring') && \App\Services\Logistics\LinehaulDelayService::canReport($this->resource),
            'delay' => $delay === null ? null : (new LinehaulDelayResource($delay))->resolve($request),
            'origin' => $manifest->originHub?->name, 'destination' => $manifest->destinationHub?->name,
            'origin_address' => $this->hubAddress($manifest->originHub),
            'destination_address' => $this->hubAddress($manifest->destinationHub),
            'seal_number' => $this->seal_number,
            'parcel_count' => $items->count(),
            'recorded_weight_grams' => $weight,
            'departed_at' => $manifest->dispatched_at?->toIso8601String(),
            'scheduled_departure_at' => $manifest->scheduled_departure_at?->toIso8601String(),
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'arrived_at' => $this->arrived_at?->toIso8601String(),
            'items' => $items->map(fn ($item): array => [
                'id' => $item->id, 'tracking_number' => $item->tracking_number,
                'loaded' => (! $takeover && $manifest->dispatched_at !== null) || isset(($this->loading_tokens ?? [])[$item->id]),
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

    private function hubAddress(?\App\Models\Logistics\LogisticsHub $hub): ?string
    {
        $address = trim($hub?->fullAddress() ?? '');

        return $address === '' ? null : $address;
    }
}
