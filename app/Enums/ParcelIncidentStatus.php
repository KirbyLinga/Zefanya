<?php

namespace App\Enums;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Enumerable;

/** Lifecycle status of a parcel exception. Active exceptions block movement. */
enum ParcelIncidentStatus: string implements Arrayable
{
    case Open = 'open';
    case UnderReview = 'under_review';
    case Resolved = 'resolved';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::UnderReview => 'Under review',
            self::Resolved => 'Resolved',
        };
    }

    public function isActive(): bool
    {
        return in_array($this, [self::Open, self::UnderReview], true);
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
