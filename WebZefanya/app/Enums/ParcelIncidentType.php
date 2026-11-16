<?php

namespace App\Enums;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Enumerable;

/** The kind of parcel exception. Lost/damaged remain the delivery incident types. */
enum ParcelIncidentType: string implements Arrayable
{
    case Lost = 'lost';
    case Damaged = 'damaged';
    case Missing = 'missing';
    case WrongHub = 'wrong_hub';
    case InactiveConnection = 'inactive_connection';
    case FailedTransfer = 'failed_transfer';
    case Transported = 'transported';
    case Locked = 'locked';
    case Tampered = 'tampered';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Lost => 'Lost',
            self::Damaged => 'Damaged',
            self::Missing => 'Missing',
            self::WrongHub => 'Wrong hub',
            self::InactiveConnection => 'Inactive connection',
            self::FailedTransfer => 'Failed transfer',
            self::Transported => 'Transported',
            self::Locked => 'Locked',
            self::Tampered => 'Tampered',
            self::Other => 'Other',
        };
    }

    /** Exception types resolved through the Phase 5 route decision workflow. */
    public function requiresRoutingDecision(): bool
    {
        return in_array($this, [
            self::Missing,
            self::WrongHub,
            self::InactiveConnection,
            self::FailedTransfer,
        ], true);
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
