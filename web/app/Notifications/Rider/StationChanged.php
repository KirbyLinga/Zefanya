<?php

namespace App\Notifications\Rider;

use App\Models\Logistics\LogisticsHub;
use App\Models\Rider;

/**
 * Logistics moved this rider to another Station Hub.
 */
class StationChanged extends RiderNotification
{
    public function __construct(
        public Rider $rider,
        public LogisticsHub $station,
        public ?string $previousStationName = null,
    ) {}

    public function dedupeKey(): string
    {
        return 'station-changed:'.$this->rider->id.':'.$this->station->id.':'
            .($this->rider->updated_at?->getTimestamp() ?? now()->getTimestamp());
    }

    /** @return array<string, mixed> */
    protected function payload(): array
    {
        $message = 'Your Station Hub is now '.$this->station->name.'.';

        if (filled($this->previousStationName) && $this->previousStationName !== $this->station->name) {
            $message = 'Your Station Hub changed from '.$this->previousStationName.' to '.$this->station->name.'.';
        }

        return [
            'type' => 'rider_station_changed',
            'title' => 'Station Hub updated',
            'message' => $message,
            'url' => route('rider.dashboard'),
        ];
    }
}
