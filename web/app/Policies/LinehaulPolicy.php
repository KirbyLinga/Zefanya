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
            && LinehaulAssignment::query()->currentOffer()->where('manifest_id', $manifest->id)->where('linehaul_driver_id', $driver->id)->exists()
        ));
    }

    public function assign(LogisticsProvider $provider, Manifest $manifest): bool
    {
        return $provider->isApproved() && $provider->archived_at === null
            && (int) $manifest->logistics_provider_id === (int) $provider->id;
    }

    public function decline(LinehaulDriver $driver, Manifest $manifest): bool
    {
        return $driver->isOperational() && (int) $driver->logistics_provider_id === (int) $manifest->logistics_provider_id
            && ($this->access($driver, $manifest) || (\App\Services\Logistics\LinehaulDeclineService::installed()
                && \App\Models\Logistics\LinehaulTripDecline::where('manifest_id', $manifest->id)
                    ->where('logistics_provider_id', $driver->logistics_provider_id)->where('linehaul_driver_id', $driver->id)->exists()));
    }

    public function closeReceiving(LogisticsProvider $provider, Manifest $manifest): bool
    {
        return $this->assign($provider, $manifest);
    }

    public function viewBreakdown(LogisticsProvider $provider, \App\Models\Logistics\LinehaulBreakdown $breakdown): bool
    {
        return $provider->isApproved() && $provider->archived_at === null
            && (int) $breakdown->delay->logistics_provider_id === (int) $provider->id;
    }

    public function reviewProfile(LogisticsProvider $provider, ?LinehaulDriver $driver = null): bool
    {
        return $provider->isApproved() && $provider->archived_at === null
            && ($driver === null || (int) $driver->logistics_provider_id === (int) $provider->id);
    }
}
