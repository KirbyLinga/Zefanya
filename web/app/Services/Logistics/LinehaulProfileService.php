<?php

namespace App\Services\Logistics;

use App\Models\Logistics\LinehaulDriver;
use App\Models\Logistics\LinehaulProfile;
use App\Models\Logistics\LogisticsProvider;
use App\Policies\LinehaulPolicy;
use App\Support\RiderValidation;
use App\Support\RuntimeSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LinehaulProfileService
{
    public const DOCUMENTS = ['drivers_license', 'or_cr', 'nbi_clearance'];

    public const VEHICLES = ['Van', '4-Wheeler Closed Van Truck', '6-Wheeler Truck', '10-Wheeler Truck'];

    public static function installed(): bool
    {
        return RuntimeSchema::hasTable('linehaul_profiles') && RuntimeSchema::hasTable('vehicle_plate_locks');
    }

    public static function rules(): array
    {
        $rules = [
            'version' => ['required', 'integer', 'min:0'],
            'plate_number' => ['required', 'string', 'regex:/\A[A-Z0-9]{4,20}\z/'],
            'license_no' => ['required', 'string', 'regex:'.RiderValidation::LICENSE_REGEX],
            'license_expiry' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.RiderValidation::today()->toDateString()],
            'license_codes' => ['required', 'array', 'min:1', 'max:8'],
            'license_codes.*' => ['required', 'string', 'distinct', Rule::in(['B', 'B1', 'B2', 'C', 'D', 'BE', 'CE'])],
            'vehicle_type' => ['required', Rule::in(self::VEHICLES)],
            'capacity_kg' => ['required', 'numeric', 'min:1', 'max:50000', 'decimal:0,2'],
        ];
        foreach (self::DOCUMENTS as $field) {
            $rules[$field] = ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'];
        }
        foreach (['driver_id', 'id', 'status', 'review_status', 'reviewed_by', 'reviewed_at', 'documents', 'logistics_provider_id', 'home_hub_id', 'on_duty', 'email_verified_at'] as $field) {
            $rules[$field] = ['prohibited'];
        }

        return $rules;
    }

    public static function current(LinehaulDriver $driver): ?LinehaulProfile
    {
        return self::installed() ? LinehaulProfile::where('linehaul_driver_id', $driver->id)->first() : null;
    }

    public static function ready(LinehaulDriver $driver): bool
    {
        $profile = self::current($driver);

        if ($profile === null) {
            return ! config('linehaul.require_reviewed_vehicle');
        }

        return $profile->review_status === 'approved'
            && RiderValidation::isExpiryValid($profile->license_expiry->toDateString());
    }

    public function submit(LinehaulDriver $driver, array $input): LinehaulProfile
    {
        abort_unless((new LinehaulPolicy)->access($driver), 403);
        $this->require(self::installed(), 'Courier vehicle setup is pending.');
        $data = Validator::make($input, self::rules())->validate();
        $newPaths = [];
        try {
            return DB::transaction(function () use ($driver, $data, &$newPaths): LinehaulProfile {
                $driver = LinehaulDriver::whereKey($driver->id)->lockForUpdate()->firstOrFail();
                abort_unless((new LinehaulPolicy)->access($driver), 403);
                $this->require(! $driver->on_duty && ! LinehaulDutyService::busy($driver), 'Go Off Duty and finish any active trip before editing vehicle records.');
                VehiclePlateService::reserve($data['plate_number'], $driver->id);
                $profile = self::current($driver) ?? new LinehaulProfile;
                $this->require((int) $data['version'] === (int) ($profile->version ?? 0), 'These records changed. Refresh before saving.');
                $documents = $profile->documents ?? [];
                $oldPaths = [];
                foreach (self::DOCUMENTS as $field) {
                    if (isset($data[$field])) {
                        $file = $data[$field];
                        if ($file->getMimeType() !== 'application/pdf') {
                            $this->require(@getimagesize($file->getRealPath()) !== false, 'Select a valid JPG, PNG or PDF document.');
                        }
                        $path = $file->store('courier-documents', 'local');
                        abort_if($path === false, 503, 'Could not store the document. Please retry.');
                        $newPaths[] = $path;
                        if (isset($documents[$field]['path'])) {
                            $oldPaths[] = $documents[$field]['path'];
                        }
                        $documents[$field] = ['path' => $path, 'mime' => $file->getMimeType(), 'size' => $file->getSize()];
                    }
                    $this->require(isset($documents[$field]), 'Upload the professional license, OR/CR and NBI clearance.');
                }
                $profile->forceFill(\Illuminate\Support\Arr::only($data, ['plate_number', 'license_no', 'license_expiry', 'license_codes', 'vehicle_type', 'capacity_kg']));
                $profile->forceFill(['linehaul_driver_id' => $driver->id, 'documents' => $documents,
                    'version' => ($profile->version ?? 0) + 1, 'review_status' => 'pending',
                    'reviewed_by' => null, 'reviewed_at' => null, 'review_note' => null])->save();
                DB::afterCommit(function () use ($oldPaths): void {
                    try {
                        Storage::disk('local')->delete($oldPaths);
                    } catch (\Throwable $exception) {
                        report($exception);
                    }
                });

                return $profile;
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($newPaths);
            throw $exception;
        }
    }

    public function review(LogisticsProvider $provider, LinehaulDriver $driver, array $input): LinehaulProfile
    {
        abort_unless((new LinehaulPolicy)->reviewProfile($provider, $driver), 403);
        $this->require(self::installed(), 'Courier vehicle setup is pending.');
        $data = Validator::make($input, ['version' => ['required', 'integer', 'min:1'],
            'decision' => ['required', 'in:approved,rejected'], 'note' => ['required', 'string', 'max:500'],
            'capacity_kg' => ['nullable', 'numeric', 'min:1', 'max:50000', 'decimal:0,2']])->validate();

        return DB::transaction(function () use ($provider, $driver, $data): LinehaulProfile {
            $provider = LogisticsProvider::whereKey($provider->id)->lockForUpdate()->firstOrFail();
            $driver = LinehaulDriver::whereKey($driver->id)->lockForUpdate()->firstOrFail();
            abort_unless((new LinehaulPolicy)->reviewProfile($provider, $driver), 403);
            $this->require(! $driver->on_duty && ! LinehaulDutyService::busy($driver), 'Finish the active trip and go Off Duty before review.');
            $profile = self::current($driver);
            $this->require($profile !== null && (int) $profile->version === (int) $data['version'], 'The submission changed. Refresh before reviewing.');
            $this->require($profile->review_status === 'pending', 'This version has already been reviewed.');
            $this->require(trim($data['note']) !== '', 'Add your review note.');
            if ($data['decision'] === 'approved') {
                $this->require(RiderValidation::isExpiryValid($profile->license_expiry->toDateString()), 'The professional license has expired.');
                foreach ($profile->documents as $document) {
                    $this->require(Storage::disk('local')->exists($document['path']), 'A document is unavailable. Request a replacement.');
                }
            }
            if (isset($data['capacity_kg']) && (float) $data['capacity_kg'] !== (float) $profile->capacity_kg) {
                $profile->forceFill(['capacity_kg' => $data['capacity_kg'], 'version' => $profile->version + 1]);
            }
            $profile->forceFill(['review_status' => $data['decision'], 'review_note' => trim($data['note']),
                'reviewed_by' => $provider->id, 'reviewed_at' => now()])->save();

            return $profile;
        });
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['vehicle' => $message]);
        }
    }
}
