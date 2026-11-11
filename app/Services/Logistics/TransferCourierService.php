<?php

namespace App\Services\Logistics;

use App\Enums\ManifestStatus;
use App\Enums\ParcelHandoffStatus;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\Manifest;
use App\Models\Logistics\ManifestAuditLog;
use App\Models\Logistics\ParcelHandoff;
use App\Models\Logistics\TransferCourier;
use App\Models\Logistics\TransferCourierAssignment;
use App\Support\PhilippineMobile;
use App\Support\RiderValidation;
use App\Support\RuntimeSchema;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Couriers: the transport personnel a Logistics company records (Courier
 * Management) and assigns to a transfer batch or a cross-provider handoff
 * before dispatch. A Courier is a record, not an account: it never logs in,
 * never changes parcel status, and is never paid through Zefanya. Custody
 * still moves only on the hubs' verified scans.
 */
class TransferCourierService
{
    public static function available(): bool
    {
        return RuntimeSchema::hasTable('transfer_couriers')
            && RuntimeSchema::hasColumn('manifests', 'transfer_courier_id')
            && RuntimeSchema::hasColumn('parcel_handoffs', 'transfer_courier_id');
    }

    // ── Courier Management ─────────────────────────────────────────────────

    /** @return Collection<int, TransferCourier> */
    public function couriersFor(LogisticsProvider $provider, bool $activeOnly = false): Collection
    {
        if (! self::available()) {
            return new Collection;
        }

        return TransferCourier::query()->where('logistics_provider_id', $provider->id)
            ->when($activeOnly, fn ($q) => $q->active())
            ->orderByRaw('status = ? desc', [TransferCourier::ACTIVE])->orderBy('name')->get();
    }

    public function save(LogisticsProvider $provider, array $input, ?TransferCourier $courier = null): TransferCourier
    {
        abort_unless(self::available(), 404);
        if ($courier !== null) {
            abort_unless((int) $courier->logistics_provider_id === (int) $provider->id, 404);
        }

        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:100'],
            'contact_no' => ['required', 'string', 'max:20', ...PhilippineMobile::rules()],
            'vehicle_type' => ['required', Rule::in(RiderValidation::VEHICLES)],
            'plate_number' => ['nullable', 'string', 'max:20'],
        ], [], ['contact_no' => 'contact number', 'vehicle_type' => 'vehicle'])->validate();

        $courier ??= (new TransferCourier)->forceFill(['logistics_provider_id' => $provider->id, 'status' => TransferCourier::ACTIVE]);
        $courier->forceFill([
            'name' => trim($data['name']),
            'contact_no' => PhilippineMobile::normalize($data['contact_no']),
            'vehicle_type' => $data['vehicle_type'],
            'plate_number' => $this->normalizePlate($data['plate_number'] ?? null, 'plate_number'),
        ])->save();

        return $courier;
    }

    public function setActive(LogisticsProvider $provider, TransferCourier $courier, bool $active): TransferCourier
    {
        abort_unless((int) $courier->logistics_provider_id === (int) $provider->id, 404);

        return DB::transaction(function () use ($courier, $active): TransferCourier {
            $locked = TransferCourier::query()->whereKey($courier->id)->lockForUpdate()->firstOrFail();
            if (! $active && $this->onTheRoad($locked->id) !== null) {
                throw ValidationException::withMessages(['status' => $locked->name.' is carrying a transfer right now. Deactivate after the receiving hub scans it in.']);
            }
            $locked->forceFill(['status' => $active ? TransferCourier::ACTIVE : TransferCourier::INACTIVE])->save();

            return $locked;
        });
    }

    // ── Assignment ─────────────────────────────────────────────────────────

    /** Assign (or change) the Courier of a draft transfer batch. */
    public function assignToManifest(Manifest $manifest, LogisticsProvider $provider, int $courierId, ?string $vehicleType, ?string $plate, ?Request $request = null): Manifest
    {
        return $this->assign(Manifest::class, $manifest->getKey(), $provider, $courierId, $vehicleType, $plate, $request);
    }

    /** Assign (or change) the Courier of a handoff the sending company has not dispatched yet. */
    public function assignToHandoff(ParcelHandoff $handoff, LogisticsProvider $provider, int $courierId, ?string $vehicleType, ?string $plate): ParcelHandoff
    {
        return $this->assign(ParcelHandoff::class, $handoff->getKey(), $provider, $courierId, $vehicleType, $plate, null);
    }

    /**
     * Called by dispatch with the transfer row locked. Transfers created
     * before this rule (transport_required = false) dispatch as they did.
     */
    public function assertReadyForDispatch(Manifest|ParcelHandoff $locked, LogisticsProvider $provider): void
    {
        if (! self::available() || ! $locked->transport_required) {
            return;
        }
        if ($locked->transfer_courier_id === null) {
            throw ValidationException::withMessages(['transfer_courier_id' => 'Assign the Courier who carries this '.$this->noun($locked).' before dispatching it.']);
        }
        $courier = TransferCourier::query()->whereKey($locked->transfer_courier_id)->lockForUpdate()->first();
        $this->assertCanCarry($locked, $courier, $provider);
    }

    /** @return Collection<int, TransferCourierAssignment> */
    public function historyFor(Manifest|ParcelHandoff $transfer): Collection
    {
        if (! self::available()) {
            return new Collection;
        }

        return TransferCourierAssignment::query()
            ->where($transfer instanceof Manifest ? 'manifest_id' : 'parcel_handoff_id', $transfer->getKey())
            ->with('courier')->latest('assigned_at')->latest('id')->get();
    }

    /**
     * @param  class-string<Manifest|ParcelHandoff>  $class
     */
    private function assign(string $class, int $id, LogisticsProvider $provider, int $courierId, ?string $vehicleType, ?string $plate, ?Request $request): Model
    {
        abort_unless(self::available(), 404);

        return DB::transaction(function () use ($class, $id, $provider, $courierId, $vehicleType, $plate, $request): Model {
            /** @var Manifest|ParcelHandoff $locked */
            $locked = $class::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            $owner = $locked instanceof Manifest ? $locked->logistics_provider_id : $locked->origin_provider_id;
            abort_unless((int) $owner === (int) $provider->id, 404);

            $open = $locked instanceof Manifest
                ? $locked->status === ManifestStatus::Draft
                : in_array($locked->status, [ParcelHandoffStatus::Requested, ParcelHandoffStatus::Accepted], true);
            if (! $open) {
                throw ValidationException::withMessages(['transfer_courier_id' => 'The Courier can only be set before the '.$this->noun($locked).' is dispatched.']);
            }

            /** @var TransferCourier|null $courier */
            $courier = TransferCourier::query()->whereKey($courierId)->lockForUpdate()->first();
            $this->assertCanCarry($locked, $courier, $provider);

            $vehicle = $vehicleType !== null && $vehicleType !== '' ? $vehicleType : $courier->vehicle_type;
            if (! in_array($vehicle, RiderValidation::VEHICLES, true)) {
                throw ValidationException::withMessages(['transport_vehicle_type' => 'Choose a vehicle: '.implode(', ', RiderValidation::VEHICLES).'.']);
            }
            $plate = $plate !== null && trim($plate) !== '' ? $this->normalizePlate($plate, 'transport_plate_number') : $courier->plate_number;

            if ((int) $locked->transfer_courier_id === (int) $courier->id
                && $locked->transport_vehicle_type === $vehicle && $locked->transport_plate_number === $plate) {
                return $locked; // Re-submitting the same assignment changes nothing.
            }

            $column = $locked instanceof Manifest ? 'manifest_id' : 'parcel_handoff_id';
            TransferCourierAssignment::query()->where($column, $locked->id)->whereNull('replaced_at')->update(['replaced_at' => now()]);
            (new TransferCourierAssignment)->forceFill([
                'transfer_courier_id' => $courier->id,
                'logistics_provider_id' => $provider->id,
                $column => $locked->id,
                'vehicle_type' => $vehicle,
                'plate_number' => $plate,
                'assigned_at' => now(),
            ])->save();

            $locked->forceFill([
                'transfer_courier_id' => $courier->id,
                'transport_vehicle_type' => $vehicle,
                'transport_plate_number' => $plate,
                'transport_assigned_at' => now(),
                'transport_assigned_by_id' => $provider->id,
            ])->save();

            if ($locked instanceof Manifest && $request !== null) {
                ManifestAuditLog::record($provider, 'transport_assigned', $locked, null, $request, 'manual');
            }

            return $locked;
        });
    }

    private function assertCanCarry(Manifest|ParcelHandoff $transfer, ?TransferCourier $courier, LogisticsProvider $provider): void
    {
        if ($courier === null || (int) $courier->logistics_provider_id !== (int) $provider->id) {
            throw ValidationException::withMessages(['transfer_courier_id' => 'Choose a Courier from your own Courier list.']);
        }
        if (! $courier->isActive()) {
            throw ValidationException::withMessages(['transfer_courier_id' => $courier->name.' is inactive. Reactivate the Courier in Couriers first.']);
        }
        $busy = $this->onTheRoad($courier->id, $transfer);
        if ($busy !== null) {
            throw ValidationException::withMessages(['transfer_courier_id' => $courier->name.' is still carrying '.$busy.'. Choose another Courier or wait for its receipt scan.']);
        }
    }

    /** What a Courier is carrying right now (dispatched, not yet received), if anything. */
    private function onTheRoad(int $courierId, Manifest|ParcelHandoff|null $except = null): ?string
    {
        $batch = Manifest::query()->where('transfer_courier_id', $courierId)
            ->where('status', ManifestStatus::Dispatched->value)
            ->when($except instanceof Manifest, fn ($q) => $q->whereKeyNot($except->getKey()))
            ->value('id');
        if ($batch !== null) {
            return 'transfer batch #'.$batch;
        }
        $handoff = ParcelHandoff::query()->where('transfer_courier_id', $courierId)
            ->where('status', ParcelHandoffStatus::InTransit->value)
            ->when($except instanceof ParcelHandoff, fn ($q) => $q->whereKeyNot($except->getKey()))
            ->value('id');

        return $handoff !== null ? 'handoff #'.$handoff : null;
    }

    private function noun(Manifest|ParcelHandoff $transfer): string
    {
        return $transfer instanceof Manifest ? 'batch' : 'handoff';
    }

    private function normalizePlate(?string $plate, string $field): ?string
    {
        $plate = strtoupper(trim((string) $plate));
        if ($plate === '') {
            return null;
        }
        $plate = (string) preg_replace('/\s+/', ' ', $plate);
        if (! preg_match('/^[A-Z0-9][A-Z0-9 -]{1,14}$/', $plate)) {
            throw ValidationException::withMessages([$field => 'Enter a plate number of up to 15 letters, numbers, spaces or dashes, like ABC 1234.']);
        }

        return $plate;
    }
}
