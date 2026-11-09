<?php

namespace App\Services\Logistics;

use App\Enums\ManifestStatus;
use App\Http\Resources\Mobile\LinehaulTripResource;
use App\Models\Logistics\LinehaulAssignment;
use App\Models\Logistics\LinehaulBreakdown;
use App\Models\Logistics\LinehaulDriver;
use App\Models\Logistics\LinehaulTransfer;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\Manifest;
use App\Models\Logistics\ManifestAuditLog;
use App\Policies\LinehaulPolicy;
use App\Support\RuntimeSchema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class LinehaulTransferService
{
    public static function installed(): bool
    {
        return LinehaulBreakdownService::installed() && LinehaulPayService::installed()
            && RuntimeSchema::hasTable('linehaul_transfers') && RuntimeSchema::hasColumn('linehaul_assignments', 'takeover_resumed_at');
    }

    public static function rules(): array
    {
        $rules = ['driver_id' => ['required', 'integer', 'min:1'], 'breakdown_id' => ['required', 'integer', 'min:1'],
            'offer_version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:500']];
        foreach (['snapshot', 'from_driver_id', 'logistics_provider_id', 'trip_pay_minor', 'takeover_resumed_at', 'loading_tokens'] as $field) {
            $rules[$field] = ['prohibited'];
        }

        return $rules;
    }

    public static function latest(LinehaulAssignment $assignment): ?LinehaulTransfer
    {
        if (! self::installed()) {
            return null;
        }
        $assignment->loadMissing('latestTransfer');

        return $assignment->latestTransfer;
    }

    public static function carrier(LinehaulAssignment $assignment): bool
    {
        $manifest = $assignment->manifest;
        $transfer = self::latest($assignment);
        if ($transfer === null) {
            return $manifest->dispatched_by_type === 'linehaul'
                && (int) $manifest->dispatched_by_id === (int) $assignment->linehaul_driver_id;
        }

        return (int) $transfer->to_driver_id === (int) $assignment->linehaul_driver_id
            && (int) $transfer->offer_version === (int) $assignment->offer_version
            && (int) $transfer->logistics_provider_id === (int) $manifest->logistics_provider_id
            && $manifest->dispatched_by_type === 'linehaul'
            && ($transfer->snapshot['_dispatch_actor_type'] ?? null) === $manifest->dispatched_by_type
            && (int) ($transfer->snapshot['_dispatch_actor_id'] ?? 0) === (int) $manifest->dispatched_by_id
            && $assignment->takeover_resumed_at !== null;
    }

    public static function pending(LinehaulAssignment $assignment): bool
    {
        return self::latest($assignment) !== null && ! self::carrier($assignment);
    }

    public static function eligibleBreakdown(LinehaulAssignment $assignment): ?LinehaulBreakdown
    {
        if (! self::installed() || ! self::canReassign($assignment)) {
            return null;
        }

        return self::currentEvidence($assignment)->latest('id')->first();
    }

    private static function canReassign(LinehaulAssignment $assignment): bool
    {
        $manifest = $assignment->manifest;

        return $manifest->direction !== 'return' && $assignment->seal_receipt === null
            && LinehaulDelayService::canReport($assignment)
            && ! $manifest->items()->where(fn ($query) => $query->whereNotNull('received_at')->orWhereNotNull('issue_type'))->exists();
    }

    private static function currentEvidence(LinehaulAssignment $assignment): \Illuminate\Database\Eloquent\Builder
    {
        return LinehaulBreakdown::whereHas('delay', fn ($query) => $query
            ->where('linehaul_assignment_id', $assignment->id)
            ->where('logistics_provider_id', $assignment->manifest->logistics_provider_id)
            ->where('linehaul_driver_id', $assignment->linehaul_driver_id)
            ->where('offer_version', $assignment->offer_version));
    }

    public function reassign(LogisticsProvider $provider, Manifest $manifest, array $input, Request $request): LinehaulTransfer
    {
        abort_unless((new LinehaulPolicy)->assign($provider, $manifest), 403);
        $this->require(self::installed(), 'Breakdown reassignment setup is pending.');
        $data = Validator::make($input, self::rules())->validate();
        $reason = trim($data['reason']);
        $this->require($reason !== '', 'Enter the reassignment reason.');

        // Find the driver IDs before opening a repeatable-read transaction.
        // Recheck the assignment after acquiring the driver and manifest locks.
        $peek = LinehaulAssignment::where('manifest_id', $manifest->id)->first();
        $this->require($peek !== null, 'Assign a Courier before reassigning this trip.');

        return DB::transaction(function () use ($provider, $manifest, $data, $reason, $request, $peek): LinehaulTransfer {
            $drivers = LinehaulDriver::whereIn('id', [$peek->linehaul_driver_id, $data['driver_id']])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $manifest = Manifest::whereKey($manifest->id)->lockForUpdate()->firstOrFail();
            abort_unless((new LinehaulPolicy)->assign($provider->fresh(), $manifest), 403);
            $assignment = LinehaulAssignment::where('manifest_id', $manifest->id)->lockForUpdate()->firstOrFail();
            $existing = LinehaulTransfer::where('manifest_id', $manifest->id)->where('logistics_provider_id', $provider->id)
                ->where('linehaul_breakdown_id', $data['breakdown_id'])->first();
            if ($existing !== null) {
                $this->require((int) $existing->manifest_id === (int) $manifest->id && (int) $existing->logistics_provider_id === (int) $provider->id
                    && (int) $existing->to_driver_id === (int) $data['driver_id'] && (int) $existing->offer_version === (int) $data['offer_version'] + 1
                    && $existing->reason === $reason, 'This breakdown already has a different reassignment.');

                return $existing;
            }
            $this->require((int) $assignment->linehaul_driver_id === (int) $peek->linehaul_driver_id
                && (int) $assignment->offer_version === (int) $data['offer_version'], 'The Courier offer changed. Refresh before reassigning.');
            $assignment->setRelation('manifest', $manifest);
            $this->require(self::canReassign($assignment), 'Reassign only a carried In Transit trip before arrival or receiving.');
            $report = self::currentEvidence($assignment)->find($data['breakdown_id']);
            $this->require($report !== null, 'Choose photo evidence for the current Courier offer.');
            $driver = $drivers->get((int) $data['driver_id']);
            $this->require($driver !== null && (int) $driver->id !== (int) $assignment->linehaul_driver_id
                && $driver->isOperational() && (int) $driver->logistics_provider_id === (int) $provider->id, 'Choose another active Courier from your company.');
            $this->require(! LinehaulDutyService::busy($driver), 'The replacement Courier already has an active trip.');
            app(LinehaulDutyService::class)->requireReady($driver);
            app(LinehaulService::class)->requireCapacity($driver, $manifest);
            $assignment->load(['driver', 'manifest.originHub', 'manifest.destinationHub', 'manifest.items.sellerOrder:id,parcel_weight_grams']);
            $snapshot = (new LinehaulTripResource($assignment))->resolve($request);
            $snapshot['items'] = [];
            $snapshot['_dispatch_actor_type'] = $manifest->dispatched_by_type;
            $snapshot['_dispatch_actor_id'] = $manifest->dispatched_by_id;
            $transfer = new LinehaulTransfer;
            $transfer->forceFill(['linehaul_breakdown_id' => $report->id, 'linehaul_assignment_id' => $assignment->id,
                'manifest_id' => $manifest->id, 'logistics_provider_id' => $provider->id, 'from_driver_id' => $assignment->linehaul_driver_id,
                'to_driver_id' => $driver->id, 'offer_version' => $assignment->offer_version + 1, 'reason' => $reason, 'snapshot' => $snapshot])->save();
            $assignment->forceFill(['linehaul_driver_id' => $driver->id, 'offer_version' => $transfer->offer_version,
                'accepted_at' => null, 'arrived_at' => null, 'declined_at' => null, 'loading_tokens' => [], 'takeover_resumed_at' => null,
                'trip_pay_minor' => null, 'trip_pay_vehicle_type' => null])->save();
            ManifestAuditLog::create(['manifest_id' => $manifest->id, 'action' => 'courier_reassign', 'actor_type' => 'logistics',
                'actor_id' => $provider->id, 'actor_name' => $provider->business_name, 'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(), 'verification_method' => 'manual']);

            return $transfer;
        });
    }

    public function act(LinehaulDriver $driver, Manifest $manifest, string $action, array $input, Request $request): LinehaulAssignment
    {
        $this->require(self::installed(), 'Breakdown reassignment setup is pending.');
        $rules = ['offer_version' => ['required', 'integer', 'min:1']];
        if ($action === 'takeover-scan') {
            $rules += ['tracking_number' => ['required', 'string', 'max:100'], 'method' => ['nullable', 'in:camera,manual']];
        }
        $data = Validator::make($input, $rules)->validate();

        return DB::transaction(function () use ($driver, $manifest, $action, $data, $request): LinehaulAssignment {
            $driver = LinehaulDriver::whereKey($driver->id)->lockForUpdate()->firstOrFail();
            $manifest = Manifest::whereKey($manifest->id)->lockForUpdate()->firstOrFail();
            abort_unless((new LinehaulPolicy)->access($driver, $manifest), 403);
            $assignment = LinehaulAssignment::where('manifest_id', $manifest->id)->lockForUpdate()->firstOrFail();
            $transfer = self::latest($assignment);
            $this->require($transfer !== null && (int) $transfer->to_driver_id === (int) $driver->id
                && (int) $transfer->offer_version === (int) $data['offer_version']
                && (int) $assignment->offer_version === (int) $data['offer_version'], 'This takeover offer changed. Refresh your trip.');
            if ($action === 'takeover-continue' && $assignment->takeover_resumed_at !== null) {
                return $assignment;
            }
            $this->require($manifest->status === ManifestStatus::Dispatched && $assignment->arrived_at === null, 'Rescan only before arrival on an In Transit trip.');
            $scans = $assignment->loading_tokens ?? [];
            $item = null;
            if ($action === 'takeover-scan') {
                $item = $manifest->items()->whereNull('released_at')->whereNull('received_at')->where('tracking_number', trim($data['tracking_number']))->lockForUpdate()->first();
                $this->require($item !== null, 'That active parcel is not on this trip.');
                if (isset($scans[$item->id])) {
                    return $assignment;
                }
                $this->require($assignment->takeover_resumed_at === null, 'The takeover is already complete.');
                $scans[$item->id] = true;
                $assignment->forceFill(['loading_tokens' => $scans])->save();
            } else {
                $this->require($action === 'takeover-continue', 'Unknown takeover action.');
                $ids = $manifest->items()->whereNull('released_at')->orderBy('id')->lockForUpdate()->pluck('id');
                $this->require($ids->isNotEmpty() && $ids->every(fn ($id) => isset($scans[$id])), 'Rescan every parcel before continuing.');
                app(LinehaulDutyService::class)->requireReady($driver);
                app(LinehaulService::class)->requireCapacity($driver, $manifest);
                LinehaulPayService::freeze($driver, $assignment);
                $assignment->forceFill(['accepted_at' => now(), 'takeover_resumed_at' => now()])->save();
            }
            ManifestAuditLog::create(['manifest_id' => $manifest->id, 'manifest_item_id' => $item?->id,
                'action' => 'courier_'.$action, 'actor_type' => 'linehaul', 'actor_id' => $driver->id, 'actor_name' => $driver->name,
                'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(), 'verification_method' => $data['method'] ?? 'manual']);

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
