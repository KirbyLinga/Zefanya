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
            $existing = LinehaulAssignment::query()->where('manifest_id', $manifest->id)->first();
            if ($existing !== null) {
                $this->require((int) $existing->linehaul_driver_id === (int) $driver->id, 'This manifest is already assigned.');

                return $existing;
            }
            $busy = LinehaulAssignment::query()->where('linehaul_driver_id', $driver->id)
                ->whereHas('manifest', fn ($query) => $query->whereIn('status', [ManifestStatus::Draft->value, ManifestStatus::Dispatched->value]))->exists();
            $this->require(! $busy, 'This Courier already has an active trip.');
            $assignment = new LinehaulAssignment;
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
            if ($action === 'arrive') {
                $this->require($manifest->status === ManifestStatus::Dispatched, 'Only an in-transit trip can arrive.');
                if ($assignment->arrived_at !== null) {
                    return $assignment;
                }
                $assignment->forceFill(['arrived_at' => now()])->save();
            } else {
                $this->require($manifest->status === ManifestStatus::Draft, 'This trip can no longer be loaded or departed.');
                if ($action === 'accept') {
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

    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['trip' => $message]);
        }
    }
}
