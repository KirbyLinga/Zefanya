<?php

namespace App\Enums;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Enumerable;

/** Lifecycle status of an incident. Open incidents block delivery delivery. */
enum ParcelIncidentStatus: string implements Arrayable
{
    case Open = 'open';
    case Resolved = 'resolved';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Resolved => 'Resolved',
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
