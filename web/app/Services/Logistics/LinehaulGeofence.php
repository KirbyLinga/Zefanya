<?php

namespace App\Services\Logistics;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class LinehaulGeofence
{
    private const RADIUS_METERS = 500;

    public static function locationRules(): array
    {
        return [
            'location' => ['sometimes', 'required', 'array:latitude,longitude,accuracy'],
            'location.latitude' => ['required_with:location', 'numeric', 'between:-90,90'],
            'location.longitude' => ['required_with:location', 'numeric', 'between:-180,180'],
            'location.accuracy' => ['required_with:location', 'numeric', 'between:0,100'],
        ];
    }

    public static function requirement(int $hubId): string
    {
        $hubs = config('linehaul.hub_coordinates');
        if (! is_array($hubs)) {
            return 'setup_required';
        }

        if (! array_key_exists($hubId, $hubs)) {
            return config('linehaul.require_hub_geofence') ? 'setup_required' : 'off';
        }

        $point = $hubs[$hubId];

        return is_array($point)
            && self::validNumber($point['latitude'] ?? null, -90, 90)
            && self::validNumber($point['longitude'] ?? null, -180, 180)
            ? 'required' : 'setup_required';
    }

    public static function verify(int $hubId, array $data, string $hubLabel): bool
    {
        $requirement = self::requirement($hubId);
        if ($requirement === 'off') {
            return false;
        }
        if ($requirement === 'setup_required') {
            throw ValidationException::withMessages(['location' => "The {$hubLabel} hub needs verified coordinates. Contact Logistics."]);
        }

        $rules = self::locationRules();
        $rules['location'] = ['required', 'array:latitude,longitude,accuracy'];
        $point = Validator::make($data, $rules, [
            'location.required' => 'Refresh your location and try again.',
            'location.accuracy.between' => 'Wait for a location accurate to 100 metres or better.',
        ])->validate()['location'];
        $hub = config('linehaul.hub_coordinates')[$hubId];
        $lat1 = deg2rad((float) $hub['latitude']);
        $lat2 = deg2rad((float) $point['latitude']);
        $deltaLon = deg2rad((float) $point['longitude'] - (float) $hub['longitude']);
        $a = sin(($lat2 - $lat1) / 2) ** 2 + cos($lat1) * cos($lat2) * sin($deltaLon / 2) ** 2;
        $distance = 2 * 6371000 * asin(sqrt(max(0.0, min(1.0, $a))));
        if ($distance > self::RADIUS_METERS) {
            throw ValidationException::withMessages(['location' => "You must be within 500 metres of the {$hubLabel} hub."]);
        }

        return true;
    }

    private static function validNumber(mixed $value, int $min, int $max): bool
    {
        return is_numeric($value) && is_finite((float) $value) && $value >= $min && $value <= $max;
    }
}
