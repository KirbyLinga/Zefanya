<?php

namespace App\Services\Logistics;

use App\Enums\ManifestStatus;
use App\Models\Logistics\LinehaulAssignment;
use App\Models\Logistics\LinehaulDriver;
use App\Models\Logistics\LinehaulTripDelay;
use App\Models\Logistics\Manifest;
use App\Models\Logistics\ManifestAuditLog;
use App\Policies\LinehaulPolicy;
use App\Support\RuntimeSchema;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class LinehaulDelayService
{
    public static function installed(): bool
    {
        return RuntimeSchema::hasTable('linehaul_trip_delays') && RuntimeSchema::hasColumn('linehaul_assignments', 'offer_version');
    }

    public static function rules(): array
    {
        $rules = ['reason' => ['required', 'string', 'max:500'], 'offer_version' => ['required', 'integer', 'min:1'],
            'new_eta' => ['required', 'string', 'max:40', 'date', 'regex:/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})\z/']];
        foreach (['id', 'driver_id', 'linehaul_driver_id', 'logistics_provider_id', 'linehaul_assignment_id', 'status', 'created_at', 'fingerprint'] as $field) {
            $rules[$field] = ['prohibited'];
        }

        return $rules;
    }

    public static function canReport(LinehaulAssignment $assignment): bool
    {
        $manifest = $assignment->manifest;

        return self::installed() && $assignment->declined_at === null && $assignment->accepted_at !== null
            && $assignment->arrived_at === null && $manifest->status === ManifestStatus::Dispatched
            && $manifest->dispatched_by_type === 'linehaul' && (int) $manifest->dispatched_by_id === (int) $assignment->linehaul_driver_id;
    }

    public static function latest(?LinehaulAssignment $assignment): ?LinehaulTripDelay
    {
        if ($assignment === null || ! self::installed()) {
            return null;
        }
        $delay = $assignment->latestDelay;

        return $delay !== null && (int) $delay->linehaul_driver_id === (int) $assignment->linehaul_driver_id
            && (int) $delay->logistics_provider_id === (int) $assignment->manifest->logistics_provider_id
            && (int) $delay->offer_version === (int) ($assignment->offer_version ?? 1) ? $delay : null;
    }

    public function report(LinehaulDriver $driver, Manifest $manifest, array $input, Request $request): LinehaulAssignment
    {
        abort_unless((new LinehaulPolicy)->access($driver, $manifest), 403);
        $this->require(self::installed(), 'Courier delay setup is pending. Contact Logistics.');
        $data = Validator::make($input, self::rules())->validate();
        $reason = trim($data['reason']);
        $this->require($reason !== '', 'Choose or enter a delay reason.');
        $eta = Carbon::parse($data['new_eta'])->utc()->startOfSecond();

        return DB::transaction(function () use ($driver, $manifest, $data, $reason, $eta, $request): LinehaulAssignment {
            $driver = LinehaulDriver::whereKey($driver->id)->lockForUpdate()->firstOrFail();
            $manifest = Manifest::whereKey($manifest->id)->lockForUpdate()->firstOrFail();
            abort_unless((new LinehaulPolicy)->access($driver, $manifest), 403);
            $assignment = LinehaulAssignment::currentOffer()->where('manifest_id', $manifest->id)
                ->where('linehaul_driver_id', $driver->id)->lockForUpdate()->firstOrFail();
            $this->require((int) $assignment->offer_version === (int) $data['offer_version'], 'The offer changed. Refresh your trip.');
            $fingerprint = hash('sha256', json_encode([$assignment->id, $driver->id, $driver->logistics_provider_id, (int) $assignment->offer_version,
                $reason, $eta->toDateTimeString()], JSON_THROW_ON_ERROR));
            if (LinehaulTripDelay::where('fingerprint', $fingerprint)->lockForUpdate()->first() !== null) {
                return $assignment;
            }
            $assignment->setRelation('manifest', $manifest);
            $this->require(self::canReport($assignment), 'Report delays only while your Courier trip is In Transit and before arrival.');
            $this->require($eta->isFuture(), 'The new ETA must be in the future.');
            $delay = new LinehaulTripDelay;
            $delay->forceFill(['linehaul_assignment_id' => $assignment->id, 'logistics_provider_id' => $driver->logistics_provider_id,
                'linehaul_driver_id' => $driver->id, 'offer_version' => $assignment->offer_version,
                'fingerprint' => $fingerprint, 'reason' => $reason, 'new_eta' => $eta])->save();
            ManifestAuditLog::create(['manifest_id' => $manifest->id, 'action' => 'courier_delay', 'actor_type' => 'linehaul',
                'actor_id' => $driver->id, 'actor_name' => $driver->name, 'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(), 'verification_method' => 'manual']);
            $driver->provider->notify(new \App\Notifications\Logistics\CourierTripDelayed($driver->name, $manifest->id, $delay));

            return $assignment;
        });
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['trip' => $message]);
        }
    }
}
