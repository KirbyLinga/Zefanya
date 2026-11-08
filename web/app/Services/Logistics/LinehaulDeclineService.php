<?php

namespace App\Services\Logistics;

use App\Enums\ManifestStatus;
use App\Models\Logistics\LinehaulAssignment;
use App\Models\Logistics\LinehaulDriver;
use App\Models\Logistics\LinehaulTripDecline;
use App\Models\Logistics\Manifest;
use App\Models\Logistics\ManifestAuditLog;
use App\Policies\LinehaulPolicy;
use App\Support\RuntimeSchema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class LinehaulDeclineService
{
    public static function installed(): bool
    {
        return RuntimeSchema::hasTable('linehaul_trip_declines')
            && RuntimeSchema::hasColumn('linehaul_assignments', 'offer_version')
            && RuntimeSchema::hasColumn('linehaul_assignments', 'declined_at');
    }

    public static function rules(): array
    {
        return ['offer_version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:500'],
            'driver_id' => ['prohibited'], 'status' => ['prohibited'], 'logistics_provider_id' => ['prohibited'],
            'manifest_id' => ['prohibited'], 'linehaul_driver_id' => ['prohibited'], 'snapshot' => ['prohibited'],
            'created_at' => ['prohibited'], 'declined_at' => ['prohibited']];
    }

    public static function canDecline(LinehaulAssignment $assignment): bool
    {
        return self::installed() && $assignment->declined_at === null
            && $assignment->manifest->status === ManifestStatus::Draft && $assignment->accepted_at === null
            && empty($assignment->loading_tokens) && $assignment->seal_number === null && $assignment->arrived_at === null;
    }

    public function decline(LinehaulDriver $driver, Manifest $manifest, array $input, Request $request): LinehaulTripDecline
    {
        abort_unless((new LinehaulPolicy)->decline($driver, $manifest), 403);
        $this->require(self::installed(), 'Courier decline setup is pending. Contact Logistics.');
        $data = Validator::make($input, self::rules())->validate();
        $reason = trim($data['reason']);
        $this->require($reason !== '', 'Choose or enter a reason for declining.');

        return DB::transaction(function () use ($driver, $manifest, $data, $reason, $request): LinehaulTripDecline {
            $driver = LinehaulDriver::whereKey($driver->id)->lockForUpdate()->firstOrFail();
            $manifest = Manifest::whereKey($manifest->id)->lockForUpdate()->firstOrFail();
            abort_unless((new LinehaulPolicy)->decline($driver, $manifest), 403);
            $prior = LinehaulTripDecline::where('manifest_id', $manifest->id)->where('linehaul_driver_id', $driver->id)->first();
            if ($prior !== null) {
                $this->require((int) $prior->offer_version === (int) $data['offer_version'] && $prior->reason === $reason,
                    'This offer was already declined. Its reason cannot be changed.');

                return $prior;
            }
            $assignment = LinehaulAssignment::where('manifest_id', $manifest->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $assignment->linehaul_driver_id === (int) $driver->id && $assignment->declined_at === null, 403);
            $this->require((int) $assignment->offer_version === (int) $data['offer_version'], 'The offer changed. Refresh your trips.');
            $assignment->setRelation('manifest', $manifest);
            $this->require(self::canDecline($assignment), 'Only an unaccepted, unloaded draft trip can be declined. Contact Logistics for an active trip.');
            $decline = new LinehaulTripDecline;
            $decline->forceFill(['manifest_id' => $manifest->id, 'logistics_provider_id' => $manifest->logistics_provider_id,
                'linehaul_driver_id' => $driver->id, 'offer_version' => $assignment->offer_version, 'reason' => $reason,
                'snapshot' => ['courier_name' => $driver->name, 'origin' => $manifest->originHub?->name,
                    'destination' => $manifest->destinationHub?->name, 'origin_address' => $manifest->originHub?->fullAddress() ?: null,
                    'destination_address' => $manifest->destinationHub?->fullAddress() ?: null,
                    'parcel_count' => $manifest->items()->whereNull('released_at')->count()]])->save();
            $assignment->forceFill(['declined_at' => now()])->save();
            ManifestAuditLog::create(['manifest_id' => $manifest->id, 'action' => 'courier_decline',
                'actor_type' => 'linehaul', 'actor_id' => $driver->id, 'actor_name' => $driver->name,
                'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(), 'verification_method' => 'manual']);
            $driver->provider->notify(new \App\Notifications\Logistics\CourierTripDeclined($decline));

            return $decline;
        });
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['trip' => $message]);
        }
    }
}
