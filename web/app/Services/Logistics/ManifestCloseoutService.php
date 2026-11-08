<?php

namespace App\Services\Logistics;

use App\Enums\ManifestStatus;
use App\Models\Logistics\LinehaulAssignment;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\Manifest;
use App\Models\Logistics\ManifestAuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ManifestCloseoutService
{
    public static function rules(): array
    {
        return ['close_confirmed' => ['required', 'accepted'],
            'pending_item_ids' => ['required', 'array', 'min:1', 'max:500'],
            'pending_item_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'issue_note' => ['required', 'string', 'max:500'],
            'observed_seal' => ['nullable', 'string', 'max:10'],
            'seal_note' => ['nullable', 'string', 'max:500']];
    }

    public function close(Manifest $manifest, LogisticsProvider $provider, array $input, Request $request): Manifest
    {
        Gate::forUser($provider)->authorize('linehaul-close-receiving', $manifest);
        $data = Validator::make($input, self::rules())->validate();
        $this->require(trim($data['issue_note']) !== '', 'issue_note', 'Explain why the listed parcels are missing.');

        return DB::transaction(function () use ($manifest, $provider, $data, $request): Manifest {
            $locked = Manifest::whereKey($manifest->id)->lockForUpdate()->firstOrFail();
            $provider = LogisticsProvider::whereKey($provider->id)->firstOrFail();
            Gate::forUser($provider)->authorize('linehaul-close-receiving', $locked);
            if ($locked->status === ManifestStatus::ReceivedWithExceptions
                && ManifestAuditLog::where('manifest_id', $locked->id)->where('action', 'receiving_closed')->exists()) {
                return $locked;
            }
            $this->require($locked->status === ManifestStatus::Dispatched && $locked->dispatched_by_type === 'linehaul',
                'manifest', 'Only a dispatched Courier batch can close receiving.');
            $this->require(LinehaulAssignment::where('manifest_id', $locked->id)->whereNotNull('arrived_at')->exists(),
                'manifest', 'The assigned Courier must report arrival before receiving can be closed.');
            $pending = $locked->items()->whereNull('received_at')->whereNull('released_at')->orderBy('id')->lockForUpdate()->get();
            $expected = $pending->pluck('id')->map(fn ($id) => (int) $id)->all();
            $submitted = array_map('intval', $data['pending_item_ids']);
            sort($submitted);
            $this->require($expected !== [] && $submitted === $expected, 'pending_item_ids',
                'The unreceived parcel list changed. Refresh and review every tracking number before closing.');
            app(LinehaulSealService::class)->confirm($locked, $provider, $request);
            $issueRequest = clone $request;
            // Derived from the validated bulk confirmation, never from an unchecked client flag.
            $issueRequest->merge(['missing_confirmed' => true]);
            foreach ($pending as $item) {
                app(ManifestService::class)->reportIssue($locked, $item, 'missing', trim($data['issue_note']),
                    'logistics', (int) $provider->id, null, $issueRequest);
            }
            ManifestAuditLog::record($provider, 'receiving_closed', $locked, null, $request);

            return $locked->fresh();
        });
    }

    private function require(bool $condition, string $field, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
