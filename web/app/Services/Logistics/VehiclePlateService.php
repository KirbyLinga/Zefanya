<?php

namespace App\Services\Logistics;

use App\Models\Logistics\LinehaulProfile;
use App\Models\Rider\Rider;
use App\Support\RuntimeSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VehiclePlateService
{
    public static function normalize(string $plate): string
    {
        return strtoupper(str_replace([' ', '-'], '', trim($plate)));
    }

    // Call inside the writer's transaction, before checking either role's plates.
    public static function reserve(string $plate, ?int $driverId = null): void
    {
        if (! RuntimeSchema::hasTable('vehicle_plate_locks') || ! RuntimeSchema::hasTable('linehaul_profiles')) {
            return;
        }
        abort_unless(DB::transactionLevel() > 0, 503, 'Vehicle updates require a transaction.');
        abort_unless(DB::table('vehicle_plate_locks')->where('id', 1)->lockForUpdate()->first(), 503, 'Vehicle setup is incomplete.');
        $plate = self::normalize($plate);
        $rider = Rider::whereRaw("UPPER(REPLACE(REPLACE(plate_number, ' ', ''), '-', '')) = ?", [$plate])->lockForUpdate()->first(['id']);
        $courier = LinehaulProfile::where('plate_number', $plate)
            ->when($driverId !== null, fn ($query) => $query->where('linehaul_driver_id', '!=', $driverId))->lockForUpdate()->first(['id']);
        if ($rider || $courier) {
            throw ValidationException::withMessages(['plate_number' => 'This plate number is already registered.']);
        }
    }
}
