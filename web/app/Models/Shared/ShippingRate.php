<?php

namespace App\Models\Shared;

use Illuminate\Database\Eloquent\Model;

class ShippingRate extends Model
{
    protected $fillable = ['origin_zone', 'destination_zone', 'first_kg_minor', 'additional_kg_minor'];

    protected function casts(): array
    {
        return ['first_kg_minor' => 'integer', 'additional_kg_minor' => 'integer'];
    }

    public static function zoneFor(string $provinceCode): ?string
    {
        return match (substr($provinceCode, 0, 2)) {
            '13' => 'ncr',
            '06', '07', '08', '18' => 'visayas',
            default => null,
        };
    }

    /** @return array<string, array{first_kg_minor: int, additional_kg_minor: int}> */
    public static function cards(): array
    {
        if (! \App\Support\RuntimeSchema::hasTable('shipping_rates')) {
            return [];
        }

        return static::query()->get()->mapWithKeys(fn (self $rate): array => [
            $rate->origin_zone.':'.$rate->destination_zone => [
                'first_kg_minor' => $rate->first_kg_minor,
                'additional_kg_minor' => $rate->additional_kg_minor,
            ],
        ])->all();
    }
}
