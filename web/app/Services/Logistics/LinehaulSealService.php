<?php

namespace App\Services\Logistics;

use App\Models\Logistics\LinehaulAssignment;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\Manifest;
use App\Models\Logistics\ManifestAuditLog;
use App\Support\RuntimeSchema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class LinehaulSealService
{
    public function requiresConfirmation(Manifest $manifest): bool
    {
        if ($manifest->dispatched_by_type !== 'linehaul') {
            return false;
        }

        return ! RuntimeSchema::hasColumn('linehaul_assignments', 'seal_receipt')
            || LinehaulAssignment::where('manifest_id', $manifest->id)->value('seal_receipt') === null;
    }

    /** Called inside the manifest's receiving transaction, with its row locked. */
    public function confirm(Manifest $manifest, LogisticsProvider $provider, ?Request $request): void
    {
        abort_unless((int) $manifest->logistics_provider_id === (int) $provider->id, 403);
        if ($manifest->dispatched_by_type !== 'linehaul') {
            return;
        }
        if (! RuntimeSchema::hasColumn('linehaul_assignments', 'seal_receipt')) {
            throw ValidationException::withMessages(['observed_seal' => 'Courier seal receiving setup is pending. Contact the administrator before receiving this trip.']);
        }
        $assignment = LinehaulAssignment::where('manifest_id', $manifest->id)->lockForUpdate()->firstOrFail();
        if (LinehaulTransferService::pending($assignment)) {
            throw ValidationException::withMessages(['observed_seal' => 'The replacement Courier must rescan every parcel and continue before handover.']);
        }
        if ($assignment->seal_receipt !== null) {
            return;
        }
        if (blank($assignment->seal_number)) {
            throw ValidationException::withMessages(['observed_seal' => 'This Courier trip has no recorded departure seal.']);
        }
        $data = Validator::make($request?->only(['observed_seal', 'seal_note']) ?? [], [
            'observed_seal' => ['required', 'string', 'regex:/\ABH-\d{7}\z/'],
            'seal_note' => ['nullable', 'string', 'max:500'],
        ])->validate();
        $mismatch = $data['observed_seal'] !== $assignment->seal_number;
        $note = trim($data['seal_note'] ?? '');
        if ($mismatch && $note === '') {
            throw ValidationException::withMessages(['seal_note' => 'Seal discrepancy: the seal does not match departure. Enter a note before receiving.']);
        }
        $assignment->forceFill(['seal_receipt' => [
            'expected' => $assignment->seal_number, 'observed' => $data['observed_seal'],
            'mismatch' => $mismatch, 'note' => $note, 'actor_id' => $provider->id,
            'actor_name' => $provider->business_name, 'confirmed_at' => now()->toIso8601String(),
        ]])->save();
        ManifestAuditLog::record($provider, $mismatch ? 'seal_discrepancy' : 'seal_confirmed', $manifest, null, $request);
        if ($mismatch) {
            $provider->notify(new \App\Notifications\Logistics\CourierSealDiscrepancy($manifest->id));
        }
    }

    public function hasDiscrepancy(Manifest $manifest): bool
    {
        if ($manifest->dispatched_by_type !== 'linehaul'
            || ! RuntimeSchema::hasColumn('linehaul_assignments', 'seal_receipt')) {
            return false;
        }

        return LinehaulAssignment::where('manifest_id', $manifest->id)->first()?->seal_receipt['mismatch'] ?? false;
    }
}
