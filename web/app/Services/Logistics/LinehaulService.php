<?php

namespace App\Services\Logistics;

use App\Enums\ManifestStatus;
use App\Models\Logistics\LinehaulAssignment;
use App\Models\Logistics\LinehaulDriver;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\Manifest;
use App\Models\Logistics\ManifestAuditLog;
use App\Policies\LinehaulPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LinehaulService
{
    public function __construct(private readonly ManifestService $manifests) {}

    public function assign(LogisticsProvider $provider, Manifest $manifest, LinehaulDriver $driver): LinehaulAssignment
    {
        abort_unless((new LinehaulPolicy)->assign($provider, $manifest), 403);

        return DB::transaction(function () use ($manifest, $driver, $provider): LinehaulAssignment {
            $driver = LinehaulDriver::query()->whereKey($driver->id)->lockForUpdate()->firstOrFail();
            $manifest = Manifest::query()->whereKey($manifest->id)->lockForUpdate()->firstOrFail();
            abort_unless((new LinehaulPolicy)->assign($provider, $manifest), 403);
            $this->require($driver->isOperational() && (int) $driver->logistics_provider_id === (int) $provider->id, 'Choose an active Courier from your company.');
            $this->require($manifest->status === ManifestStatus::Draft, 'Only a draft manifest can be assigned.');
            $this->require($manifest->direction !== 'return', 'The Courier demo supports forward transfers only.');
            $existing = LinehaulAssignment::query()->where('manifest_id', $manifest->id)->lockForUpdate()->first();
            if ($existing !== null && $existing->declined_at === null) {
                $this->require((int) $existing->linehaul_driver_id === (int) $driver->id, 'This manifest is already assigned.');

                return $existing;
            }
            if (LinehaulDeclineService::installed()) {
                $this->require(! \App\Models\Logistics\LinehaulTripDecline::where('manifest_id', $manifest->id)->where('linehaul_driver_id', $driver->id)->exists(),
                    'This Courier already declined this trip. Choose another Courier.');
            }
            $busy = LinehaulAssignment::query()->currentOffer()->where('linehaul_driver_id', $driver->id)
                ->whereHas('manifest', fn ($query) => $query->whereIn('status', [ManifestStatus::Draft->value, ManifestStatus::Dispatched->value]))->exists();
            $this->require(! $busy, 'This Courier already has an active trip.');
            app(LinehaulDutyService::class)->requireReady($driver);
            $this->requireCapacity($driver, $manifest);
            $assignment = $existing ?? new LinehaulAssignment;
            if ($existing !== null) {
                $this->require(LinehaulDeclineService::installed() && $existing->accepted_at === null
                    && empty($existing->loading_tokens) && $existing->seal_number === null && $existing->arrived_at === null,
                    'This trip is not available for reassignment.');
                $assignment->forceFill(['offer_version' => $existing->offer_version + 1, 'declined_at' => null]);
            }
            $assignment->forceFill(['manifest_id' => $manifest->id, 'linehaul_driver_id' => $driver->id])->save();
            ManifestAuditLog::query()->create([
                'manifest_id' => $manifest->id, 'action' => 'courier_assign', 'actor_type' => 'logistics',
                'actor_id' => $provider->id, 'actor_name' => $provider->business_name ?: 'Logistics #'.$provider->id,
                'verification_method' => 'manual',
            ]);

            return $assignment;
        });
    }

    public function act(LinehaulDriver $driver, Manifest $manifest, string $action, array $data, Request $request): LinehaulAssignment
    {
        return DB::transaction(function () use ($driver, $manifest, $action, $data, $request): LinehaulAssignment {
            $driver = LinehaulDriver::query()->whereKey($driver->id)->lockForUpdate()->firstOrFail();
            $manifest = Manifest::query()->whereKey($manifest->id)->lockForUpdate()->firstOrFail();
            abort_unless((new LinehaulPolicy)->access($driver, $manifest), 403);
            $assignment = LinehaulAssignment::query()->where('manifest_id', $manifest->id)->lockForUpdate()->firstOrFail();
            if ($action === 'exception-note') {
                $this->require(in_array($manifest->status, [ManifestStatus::Dispatched, ManifestStatus::ReceivedWithExceptions], true), 'This trip has no handover exception to annotate.');
                $data = \Illuminate\Support\Facades\Validator::make($data, [
                    'tracking_number' => ['required', 'string', 'max:100'], 'note' => ['required', 'string', 'max:500'],
                ])->validate();
                $this->require(trim($data['note']) !== '', 'Enter your handover note.');
                $item = $manifest->items()->where('tracking_number', $data['tracking_number'])->whereNotNull('issue_type')->first();
                $this->require($item !== null, 'Choose a parcel with a recorded handover issue on this trip.');
                $incident = \App\Models\Shared\Incidents::where('incident_seller_order_id', $item->seller_order_id)->where('type', $item->issue_type)
                    ->whereHas('events', fn ($query) => $query->where('event', 'manifest_handover')
                        ->where('metadata->manifest_id', $manifest->id)->where('metadata->manifest_item_id', $item->id))->first();
                $this->require($incident !== null, 'The handover incident is not available.');
                $existing = $incident->events()->where('event', 'courier_handover_note')->first();
                if ($existing !== null) {
                    $this->require((int) $existing->actor_id === (int) $driver->id
                        && (int) ($existing->metadata['manifest_id'] ?? 0) === (int) $manifest->id
                        && ($existing->metadata['note'] ?? '') === trim($data['note']), 'A handover note is already recorded. It cannot be overwritten.');

                    return $assignment;
                }
                $incident->events()->create([
                    'event' => 'courier_handover_note', 'label' => 'Courier handover note',
                    'detail' => mb_substr(trim($data['note']), 0, 191), 'occurred_at' => now(),
                    'actor_type' => 'linehaul', 'actor_id' => $driver->id,
                    'metadata' => ['manifest_id' => $manifest->id, 'note' => trim($data['note'])],
                ]);
                ManifestAuditLog::create([
                    'manifest_id' => $manifest->id, 'manifest_item_id' => $item->id, 'action' => 'courier_note',
                    'actor_type' => 'linehaul', 'actor_id' => $driver->id, 'actor_name' => $driver->name,
                    'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(), 'verification_method' => 'manual',
                ]);

                return $assignment;
            }
            if ($action === 'arrive') {
                $this->require($manifest->status === ManifestStatus::Dispatched, 'Only an in-transit trip can arrive.');
                if ($assignment->arrived_at !== null) {
                    return $assignment;
                }
                $assignment->forceFill(['arrived_at' => now()])->save();
            } else {
                $this->require($manifest->status === ManifestStatus::Draft, 'This trip can no longer be loaded or departed.');
                if ($action === 'accept') {
                    app(LinehaulDutyService::class)->requireReady($driver);
                    $this->requireCapacity($driver, $manifest);
                    if ($assignment->accepted_at !== null) {
                        return $assignment;
                    }
                    $assignment->forceFill(['accepted_at' => now()])->save();
                } else {
                    $this->require($assignment->accepted_at !== null, 'Accept this trip before loading.');
                    if ($action === 'scan') {
                        $scan = $this->manifests->verifyScan($manifest, $driver->provider, $data['tracking_number'], ManifestService::PHASE_DISPATCH, $data['method'] ?? 'manual');
                        $tokens = $assignment->loading_tokens ?? [];
                        $tokens[$scan['item']['id']] = $scan['verification_token'];
                        $assignment->forceFill(['loading_tokens' => $tokens])->save();
                    } elseif ($action === 'depart') {
                        app(LinehaulDutyService::class)->requireReady($driver);
                        $this->requireCapacity($driver, $manifest);
                        $this->manifests->dispatch($manifest, $driver->provider, $assignment->loading_tokens ?? []);
                        $manifest->refresh()->forceFill(['dispatched_by_type' => 'linehaul', 'dispatched_by_id' => $driver->id])->save();
                        $assignment->forceFill(['seal_number' => $data['seal_number'], 'loading_tokens' => null])->save();
                    } else {
                        abort(404);
                    }
                }
            }
            ManifestAuditLog::query()->create([
                'manifest_id' => $manifest->id, 'action' => 'courier_'.$action,
                'actor_type' => 'linehaul', 'actor_id' => $driver->id, 'actor_name' => $driver->name,
                'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
                'verification_method' => $data['method'] ?? 'manual',
            ]);

            return $assignment;
        });
    }

    private function requireCapacity(LinehaulDriver $driver, Manifest $manifest): void
    {
        $profile = LinehaulProfileService::current($driver);
        // Retain the explicitly agreed presentation exception for unsubmitted accounts.
        if ($profile === null) {
            return;
        }
        $weights = \App\Models\Shared\SellerOrder::whereIn('id', $manifest->items()->whereNull('released_at')->select('seller_order_id'))
            ->orderBy('id')->lockForUpdate()->pluck('parcel_weight_grams');
        $this->require($weights->isNotEmpty() && $weights->every(fn ($grams): bool => $grams !== null && (int) $grams > 0),
            'Record a positive weight for every parcel before assigning, accepting or departing this trip.');
        [$whole, $fraction] = explode('.', $profile->capacity_kg);
        $capacityGrams = (int) $whole * 1000 + (int) $fraction * 10;
        $this->require((int) $weights->sum() <= $capacityGrams, 'This trip exceeds the approved vehicle load capacity. Contact Logistics.');
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['trip' => $message]);
        }
    }
}
