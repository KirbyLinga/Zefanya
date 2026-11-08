<?php

namespace App\Services\Logistics;

use App\Enums\ManifestStatus;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\Manifest;
use App\Models\Logistics\ManifestAuditLog;
use App\Policies\LinehaulPolicy;
use App\Support\RuntimeSchema;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class LinehaulScheduleService
{
    public static function installed(): bool
    {
        return RuntimeSchema::hasColumn('manifests', 'scheduled_departure_at')
            && RuntimeSchema::hasColumn('manifests', 'schedule_version')
            && RuntimeSchema::hasColumn('manifest_audit_logs', 'schedule_change');
    }

    public static function rules(): array
    {
        return ['scheduled_departure' => ['present', 'nullable', 'date_format:Y-m-d\TH:i'],
            'schedule_version' => ['required', 'integer', 'min:0'],
            'status' => ['prohibited'], 'logistics_provider_id' => ['prohibited'], 'dispatched_at' => ['prohibited']];
    }

    public function save(LogisticsProvider $provider, Manifest $manifest, array $input, Request $request): Manifest
    {
        abort_unless((new LinehaulPolicy)->assign($provider, $manifest), 403);
        $this->require(self::installed(), 'Departure scheduling setup is pending.');
        $data = Validator::make($input, self::rules())->validate();
        $planned = empty($data['scheduled_departure']) ? null
            : Carbon::createFromFormat('!Y-m-d\TH:i', $data['scheduled_departure'], 'Asia/Manila')->setTimezone(config('app.timezone'));

        return DB::transaction(function () use ($provider, $manifest, $data, $planned, $request): Manifest {
            $manifest = Manifest::whereKey($manifest->id)->lockForUpdate()->firstOrFail();
            abort_unless((new LinehaulPolicy)->assign($provider, $manifest), 403);
            $same = $manifest->scheduled_departure_at?->toDateTimeString() === $planned?->toDateTimeString();
            if ($same && (int) $manifest->schedule_version === (int) $data['schedule_version'] + 1) {
                return $manifest;
            }
            $this->require((int) $manifest->schedule_version === (int) $data['schedule_version'], 'The departure schedule changed. Refresh this batch before saving.');
            $this->require($manifest->status === ManifestStatus::Draft && $manifest->direction !== 'return', 'Change the departure schedule only before departure on a forward batch.');
            if ($same) {
                return $manifest;
            }
            $this->require($planned === null || $planned->isFuture(), 'Choose a future planned departure time.');
            $before = $manifest->scheduled_departure_at?->toIso8601String();
            $manifest->forceFill(['scheduled_departure_at' => $planned, 'schedule_version' => $manifest->schedule_version + 1])->save();
            $audit = new ManifestAuditLog;
            $audit->forceFill(['manifest_id' => $manifest->id, 'action' => 'courier_schedule', 'actor_type' => 'logistics',
                'actor_id' => $provider->id, 'actor_name' => $provider->business_name ?: 'Logistics #'.$provider->id,
                'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(), 'verification_method' => 'manual',
                'schedule_change' => ['before' => $before, 'after' => $planned?->toIso8601String(), 'version' => $manifest->schedule_version]])->save();

            return $manifest;
        });
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['scheduled_departure' => $message]);
        }
    }
}
