<?php

namespace App\Enums;

/**
 * Where a store sale sits in its schedule. Never stored: StoreSale::status()
 * derives it from starts_at and ends_at.
 */
enum StoreSaleStatus: string
{
    case Upcoming = 'upcoming';
    case Ongoing = 'ongoing';
    case Ended = 'ended';

    public function label(): string
    {
        return match ($this) {
            self::Upcoming => 'Upcoming',
            self::Ongoing => 'Ongoing',
            self::Ended => 'Ended',
        };
    }
}
