<?php

namespace App\Services\Logistics;

use App\Enums\ManifestStatus;
use App\Models\Logistics\LinehaulAssignment;
use App\Models\Logistics\LinehaulDriver;
use App\Models\Logistics\LinehaulDutyCheck;
use App\Models\Logistics\Manifest;
use App\Policies\LinehaulPolicy;
use App\Support\RuntimeSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class LinehaulDutyService
{
    public const ITEMS = ['tires', 'lights', 'brakes', 'fuel', 'cargo_lock'];

    public static function installed(): bool
    {
        return RuntimeSchema::hasColumn('linehaul_drivers', 'on_duty')
            && RuntimeSchema::hasColumn('linehaul_drivers', 'duty_checked_at')
            && RuntimeSchema::hasColumn('linehaul_drivers', 'last_seen_at')
            && RuntimeSchema::hasColumn('linehaul_drivers', 'duty_location')
            && RuntimeSchema::hasTable('linehaul_duty_checks');
    }

    public static function rules(?string $action): array
    {
        $rules = [];
        if (in_array($action, ['on', 'heartbeat'], true)) {
            $rules = ['latitude' => ['required', 'numeric', 'between:-90,90'],
                'longitude' => ['required', 'numeric', 'between:-180,180'],
                'accuracy' => ['required', 'numeric', 'between:0,100']];
        }
        if ($action === 'on') {
            $rules['checklist'] = ['required', 'array:'.implode(',', self::ITEMS)];
            foreach (self::ITEMS as $item) {
                $rules['checklist.'.$item] = ['required', 'boolean'];
            }
            $rules['note'] = ['nullable', 'string', 'max:500'];
        }

        return $rules;
    }

    public static function available(LinehaulDriver $driver): bool
    {
        return self::installed() && $driver->isOperational() && LinehaulProfileService::ready($driver) && $driver->on_duty
            && $driver->duty_checked_at !== null && $driver->last_seen_at !== null
            && $driver->last_seen_at->gt(now()->subMinutes(5)) && $driver->duty_location !== null;
    }

    public static function busy(LinehaulDriver $driver): bool
    {
        return LinehaulAssignment::currentOffer()->where('linehaul_driver_id', $driver->id)
            ->whereHas('manifest', fn ($query) => $query->whereIn('status', ['DRAFT', 'DISPATCHED']))->exists();
    }

    /** @return array{latitude: float, longitude: float, reported_at: string, accuracy: float, stale: bool}|null */
    public static function tripLocation(Manifest $manifest, ?LinehaulAssignment $assignment): ?array
    {
        if (! self::installed() || $manifest->status !== ManifestStatus::Dispatched
            || $manifest->dispatched_at === null || $assignment === null || $assignment->arrived_at !== null) {
            return null;
        }

        $assignment->setRelation('manifest', $manifest);
        $driver = $assignment->driver;
        $since = $assignment->takeover_resumed_at ?? $manifest->dispatched_at;
        $seen = $driver?->last_seen_at;
        if ($driver === null || (int) $driver->logistics_provider_id !== (int) $manifest->logistics_provider_id
            || ! LinehaulTransferService::carrier($assignment)
            || $seen === null || $seen->lt($since) || $seen->isFuture()) {
            return null;
        }

        $point = $driver->duty_location ?? [];
        $latitude = $point['latitude'] ?? null;
        $longitude = $point['longitude'] ?? null;
        $accuracy = $point['accuracy'] ?? null;
        if (! is_numeric($latitude) || ! is_finite((float) $latitude) || $latitude < -90 || $latitude > 90
            || ! is_numeric($longitude) || ! is_finite((float) $longitude) || $longitude < -180 || $longitude > 180
            || ! is_numeric($accuracy) || ! is_finite((float) $accuracy) || $accuracy < 0 || $accuracy > 100) {
            return null;
        }

        return [
            'latitude' => (float) $latitude,
            'longitude' => (float) $longitude,
            'reported_at' => $seen->copy()->timezone('Asia/Manila')->format('M j, Y H:i:s'),
            'accuracy' => (float) $accuracy,
            'stale' => ! $driver->on_duty || ! $seen->gt(now()->subMinutes(5)),
        ];
    }

    public function requireReady(LinehaulDriver $driver): void
    {
        $this->require(self::installed(), 'Courier duty setup is pending. Contact the administrator.');
        $this->require(self::available($driver), 'Courier must be On Duty with a passed checklist and a location update within five minutes.');
    }

    public function change(LinehaulDriver $driver, string $action, array $input): LinehaulDriver
    {
        abort_unless((new LinehaulPolicy)->access($driver), 403);
        $this->require(self::installed(), 'Courier duty setup is pending. Contact the administrator.');
        $this->require(in_array($action, ['on', 'off', 'heartbeat'], true), 'Unknown duty action.');
        $data = Validator::make($input, self::rules($action))->validate();

        return DB::transaction(function () use ($driver, $action, $data): LinehaulDriver {
            $driver = LinehaulDriver::whereKey($driver->id)->lockForUpdate()->firstOrFail();
            abort_unless((new LinehaulPolicy)->access($driver), 403);
            if ($action === 'off') {
                $this->require(! self::busy($driver), 'Hand over your active trip before going Off Duty.');
                if (! $driver->on_duty) {
                    return $driver;
                }
                $driver->forceFill(['on_duty' => false, 'last_seen_at' => null, 'duty_location' => null])->save();
                $check = new LinehaulDutyCheck;
                $check->forceFill(['linehaul_driver_id' => $driver->id, 'action' => 'off', 'created_at' => now()])->save();

                return $driver;
            }
            $point = ['latitude' => (float) $data['latitude'], 'longitude' => (float) $data['longitude'], 'accuracy' => (float) $data['accuracy']];
            if ($action === 'heartbeat') {
                $this->require($driver->on_duty, 'Go On Duty before sending a location update.');
                $driver->forceFill(['last_seen_at' => now(), 'duty_location' => $point])->save();

                return $driver;
            }
            $this->require(! $driver->on_duty || ! self::busy($driver), 'Finish your active trip before submitting another checklist.');
            $this->require(LinehaulProfileService::ready($driver), 'Your vehicle records need company approval and a valid professional license before going On Duty.');
            $failed = array_keys(array_filter($data['checklist'], fn ($value) => ! (bool) $value));
            $note = trim($data['note'] ?? '');
            $this->require($failed === [] || $note !== '', 'Add a note explaining each Not OK item.');
            $passed = $failed === [];
            $check = new LinehaulDutyCheck;
            $check->forceFill(['linehaul_driver_id' => $driver->id, 'action' => 'on', 'passed' => $passed,
                'checklist' => $data['checklist'], 'note' => $note, 'created_at' => now()])->save();
            $driver->forceFill(['on_duty' => $passed, 'duty_checked_at' => $passed ? now() : null,
                'last_seen_at' => $passed ? now() : null, 'duty_location' => $passed ? $point : null])->save();
            if (! $passed) {
                $driver->provider->notify(new \App\Notifications\Logistics\CourierChecklistFailed($driver->name, $failed, $note));
            }

            return $driver;
        });
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['duty' => $message]);
        }
    }
}
