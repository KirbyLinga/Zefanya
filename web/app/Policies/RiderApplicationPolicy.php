<?php

namespace App\Policies;

use App\Enums\LogisticsProviderStatus;
use App\Models\Logistics\LogisticsProvider;
use App\Models\RiderApplication;

class RiderApplicationPolicy
{
    public function view(LogisticsProvider $provider, RiderApplication $application): bool
    {
        return $provider->status === LogisticsProviderStatus::Approved
            && (int) $provider->id === (int) $application->logistics_provider_id
            && $application->email_verified_at !== null;
    }

    public function decide(LogisticsProvider $provider, RiderApplication $application): bool
    {
        return $this->view($provider, $application) && $application->status === 'pending';
    }
}
