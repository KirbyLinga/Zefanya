<?php

namespace App\Services\Logistics;

use App\Enums\ManifestStatus;
use App\Models\Logistics\LinehaulAssignment;
use App\Models\Logistics\LinehaulDriver;
use App\Support\RuntimeSchema;

class LinehaulPayService
{
    private const RATES = ['Van' => 60000, '4-Wheeler Closed Van Truck' => 100000, '6-Wheeler Truck' => 150000];

    public static function installed(): bool
    {
        return RuntimeSchema::hasColumn('linehaul_assignments', 'trip_pay_minor')
            && RuntimeSchema::hasColumn('linehaul_assignments', 'trip_pay_vehicle_type');
    }

    private static function quote(LinehaulDriver $driver): ?array
    {
        $profile = LinehaulProfileService::current($driver);
        if ($profile === null || ! LinehaulProfileService::ready($driver)) {
            return null;
        }

        $rate = self::RATES[$profile->vehicle_type] ?? null;
        if ($profile->vehicle_type === '10-Wheeler Truck') {
            $rate = filter_var(config('linehaul.ten_wheeler_pay_minor'), FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1, 'max_range' => 4294967295],
            ]);
        }
        if ($rate === null || $rate === false) {
            return null;
        }

        return ['amount_minor' => $rate, 'vehicle_type' => $profile->vehicle_type];
    }

    /** Caller holds driver, manifest and assignment locks inside departure transaction. */
    public static function freeze(LinehaulDriver $driver, LinehaulAssignment $assignment): void
    {
        if (! self::installed() || $assignment->trip_pay_minor !== null) {
            return;
        }
        $quote = self::quote($driver);
        if ($quote !== null) {
            $assignment->forceFill(['trip_pay_minor' => $quote['amount_minor'], 'trip_pay_vehicle_type' => $quote['vehicle_type']])->save();
        }
    }

    public static function details(LinehaulAssignment $assignment): array
    {
        $manifest = $assignment->manifest;
        if ($assignment->declined_at !== null || $manifest->status === ManifestStatus::Cancelled) {
            return ['status' => 'ineligible', 'amount_minor' => 0];
        }
        if (! self::installed()) {
            return ['status' => 'not_configured', 'amount_minor' => null];
        }
        if ($assignment->trip_pay_minor !== null) {
            $sameDriver = LinehaulTransferService::carrier($assignment);
            if (! $sameDriver) {
                return ['status' => 'not_configured', 'amount_minor' => null];
            }
            $earned = in_array($manifest->status, [ManifestStatus::Received, ManifestStatus::ReceivedWithExceptions], true)
                && $manifest->received_at !== null && $manifest->received_by_type === 'logistics'
                && (int) $manifest->received_by_id === (int) $manifest->logistics_provider_id
                && $assignment->seal_receipt !== null;

            return ['status' => $earned ? 'earned' : 'pending', 'amount_minor' => (int) $assignment->trip_pay_minor];
        }
        if ($manifest->status === ManifestStatus::Draft && $assignment->driver !== null) {
            $quote = self::quote($assignment->driver);
            if ($quote !== null) {
                return ['status' => 'quote', 'amount_minor' => $quote['amount_minor']];
            }
        }

        return ['status' => 'not_configured', 'amount_minor' => null];
    }
}
