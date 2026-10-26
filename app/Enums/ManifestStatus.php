<?php

namespace App\Enums;

enum ManifestStatus: string
{
    case Draft = 'DRAFT';
    case Dispatched = 'DISPATCHED';
    case Received = 'RECEIVED';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Dispatched => 'Dispatched',
            self::Received => 'Received',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * A manifest is active from creation until it is received or cancelled.
     */
    public function isOpen(): bool
    {
        return $this === self::Draft || $this === self::Dispatched;
    }
}
