<?php

namespace App\Enums;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Enumerable;

/** The kind of lost/damaged incident reported for a delivery parcel. */
enum ParcelIncidentType: string implements Arrayable
{
    case Lost = 'lost';
    case Transported = 'transported';
    case Damaged = 'damaged';
    case Locked = 'locked';
    case Tampered = 'tampered';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Lost => 'Lost',
            self::Transported => 'Transported',
            self::Damaged => 'Damaged',
            self::Locked => 'Locked',
            self::Tampered => 'Tampered',
            self::Other => 'Other',
        };
    }

    public function toArray(): array
    {
        return array_column(self::cases(), 'label', 'value');
    }

    public static function labels(): Enumerable
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->label()]);
    }
}
