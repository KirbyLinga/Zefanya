<?php

namespace App\Services;

use App\Enums\LogisticsProviderStatus;
use App\Models\Logistics\LogisticsProvider;
use App\Models\RiderApplication;
use App\Models\RiderApplicationDecision;
use App\Notifications\Rider\RiderApplicationDecisionNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RiderApplicationDecisionService
{
    public function decide(LogisticsProvider $actor, RiderApplication $application, string $decision, ?string $reason): RiderApplication
    {
        if (! in_array($decision, ['approved', 'rejected'], true) || ($decision === 'rejected' && trim($reason ?? '') === '') || mb_strlen($reason ?? '') > 1000) {
            throw ValidationException::withMessages(['decision' => 'Choose approval or rejection and provide a rejection reason.']);
        }

        return DB::transaction(function () use ($actor, $application, $decision, $reason): RiderApplication {
            $provider = LogisticsProvider::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_unless($provider->status === LogisticsProviderStatus::Approved, 403);
            $row = RiderApplication::whereKey($application->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $row->logistics_provider_id === (int) $provider->id, 404);
            abort_unless($row->email_verified_at !== null, 409, 'Verify the Rider email before review.');
            abort_unless($row->status === 'pending', 409, 'Only pending Rider applications can be decided.');
            $row->forceFill(['status' => $decision])->save();
            $audit = new RiderApplicationDecision;
            $audit->forceFill([
                'rider_application_id' => $row->id, 'logistics_provider_id' => $provider->id,
                'from_status' => 'pending', 'to_status' => $decision,
                'reason' => $decision === 'rejected' ? trim($reason) : null,
            ])->save();
            // Match synchronous registration delivery: failure rolls back status and audit together.
            $row->notify(new RiderApplicationDecisionNotification($decision, $audit->reason, $provider->business_name));

            return $row;
        });
    }
}
