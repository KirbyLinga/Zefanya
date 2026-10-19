<?php

namespace App\Enums;

/**
 * Logistics provider application lifecycle.
 *
 * Stored as VARCHAR in `logistics_providers.status` and cast by the model, so
 * the permitted values live in application code rather than in a native MySQL
 * ENUM (project rule #6).
 */
enum LogisticsProviderStatus: string
{
    /** Just registered, the emailed OTP has not been confirmed yet. */
    case Unverified = 'unverified';

    /** Email confirmed, waiting on an administrator's decision. */
    case Pending = 'pending';

    case Approved = 'approved';

    case Rejected = 'rejected';

    /** Admin-suspended — credentials still work for auth attempt, panel access denied. */
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::Unverified => 'Pending email verification',
            self::Pending => 'Pending approval',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Suspended => 'Suspended',
        };
    }

    public function isDecided(): bool
    {
        return $this === self::Approved || $this === self::Rejected || $this === self::Suspended;
    }
}
