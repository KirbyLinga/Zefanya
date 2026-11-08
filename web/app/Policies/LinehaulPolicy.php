<?php

namespace App\Policies;

use App\Models\Logistics\LinehaulDriver;
use App\Models\Logistics\LinehaulAssignment;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\Manifest;

class LinehaulPolicy
{
    public function access(LinehaulDriver $driver, ?Manifest $manifest = null): bool
    {
        return $driver->isOperational() && ($manifest === null || (
            (int) $manifest->logistics_provider_id === (int) $driver->logistics_provider_id
            && LinehaulAssignment::query()->where('manifest_id', $manifest->id)->where('linehaul_driver_id', $driver->id)->exists()
        ));
    }

    public function assign(LogisticsProvider $provider, Manifest $manifest): bool
    {
        return $provider->isApproved() && $provider->archived_at === null
            && (int) $manifest->logistics_provider_id === (int) $provider->id;
    }

    public function closeReceiving(LogisticsProvider $provider, Manifest $manifest): bool
    {
        return $this->assign($provider, $manifest);
    }
}
