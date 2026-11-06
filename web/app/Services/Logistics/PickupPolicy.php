<?php

namespace App\Services\Logistics;

use App\Models\Admin\PlatformSetting;
use App\Support\RuntimeSchema;

/**
 * Pickup windows and rider pay as Admin set them in Platform Settings, with the
 * config defaults for anything missing or unreadable. A broken saved value can
 * never stop a seller from marking a parcel ready or a rider from being paid.
 */
class PickupPolicy
{
    public const SETTING_MORNING = 'pickup_window_morning';

    public const SETTING_AFTERNOON = 'pickup_window_afternoon';

    public const SETTING_FIRST_RATE = 'rider_pickup_first_rate';

    public const SETTING_ADDITIONAL_RATE = 'rider_pickup_additional_rate';

    public const SETTING_DELIVERY_RATE = 'rider_delivery_rate';

    /** Pickup sessions need the new tables and columns; until they exist the old flat pay applies. */
    public static function sessionsAvailable(): bool
    {
        return RuntimeSchema::hasTable('pickup_sessions')
            && RuntimeSchema::hasColumn('seller_orders', 'pickup_session_id')
            && RuntimeSchema::hasColumn('seller_orders', 'pickup_window_starts_at')
            && RuntimeSchema::hasColumn('rider_earnings', 'pickup_session_id');
    }

    /**
     * Windows in the order they happen in a day.
     *
     * @return list<array{key: string, label: string, start: int, end: int}> start/end are minutes after midnight, Philippine time
     */
    public function windows(): array
    {
        $windows = [];
        foreach (['morning' => self::SETTING_MORNING, 'afternoon' => self::SETTING_AFTERNOON] as $key => $setting) {
            $parsed = self::parseWindow($this->stored($setting)) ?? self::parseWindow((string) config('pickup.windows.'.$key));
            if ($parsed !== null) {
                $windows[] = ['key' => $key, 'label' => ucfirst($key), 'start' => $parsed[0], 'end' => $parsed[1]];
            }
        }
        usort($windows, fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        return $windows;
    }

    /** @return array{first_minor: int, additional_minor: int, delivery_minor: int} */
    public function rates(): array
    {
        $rate = fn (string $setting, string $default): int => self::parseRate($this->stored($setting))
            ?? (int) config('pickup.rates.'.$default);

        return [
            'first_minor' => $rate(self::SETTING_FIRST_RATE, 'first_minor'),
            'additional_minor' => $rate(self::SETTING_ADDITIONAL_RATE, 'additional_minor'),
            'delivery_minor' => $rate(self::SETTING_DELIVERY_RATE, 'delivery_minor'),
        ];
    }

    /** "08:00-12:00" to [480, 720]; null when it is not a valid start before end on one day. */
    public static function parseWindow(?string $value): ?array
    {
        if ($value === null || ! preg_match('/^\s*([01]\d|2[0-3]):([0-5]\d)\s*-\s*([01]\d|2[0-3]):([0-5]\d)\s*$/', $value, $m)) {
            return null;
        }
        $start = (int) $m[1] * 60 + (int) $m[2];
        $end = (int) $m[3] * 60 + (int) $m[4];

        return $start < $end ? [$start, $end] : null;
    }

    /** "30.00" pesos to centavos without floating point; null when it is not a money amount. */
    public static function parseRate(?string $value): ?int
    {
        if ($value === null || ! preg_match('/^\s*\d{1,6}(\.\d{1,2})?\s*$/', $value)) {
            return null;
        }

        return (int) bcmul(trim($value), '100', 0);
    }

    public static function formatMinutes(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    private function stored(string $key): ?string
    {
        if (! RuntimeSchema::hasTable('platform_settings')) {
            return null;
        }

        return PlatformSetting::query()->where('key', $key)->value('value');
    }
}
