<?php

namespace App\Http\Resources\Mobile;

use App\Services\Logistics\LinehaulDutyService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LinehaulDutyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $installed = LinehaulDutyService::installed();

        return ['setup_required' => ! $installed, 'on_duty' => $installed && (bool) $this->on_duty,
            'trip_location_sharing' => $installed,
            'push_available' => \App\Services\Logistics\LinehaulPushService::available(),
            'available' => $installed && LinehaulDutyService::available($this->resource),
            'status' => ! $installed ? 'Setup pending' : (! $this->on_duty ? 'Off Duty'
                : (LinehaulDutyService::available($this->resource) ? 'On Duty' : 'Location unknown')),
            'checked_at' => $this->duty_checked_at?->toIso8601String(),
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'last_check_passed' => $installed ? $this->latestDutyCheck?->passed : null];
    }
}
