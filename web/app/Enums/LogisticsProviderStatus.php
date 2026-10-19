<?php

namespace App\Enums;

/**
 * Logistics provider application lifecycle.
 *
 * Backing values match the existing MySQL registration status enum.
 */
enum LogisticsProviderStatus: string
{
    /** Just registered, the emailed OTP has not been confirmed yet. */
    case PendingVerification = 'unverified';

    /** Email confirmed, waiting on an administrator's decision. */
    case PendingApproval = 'pending';

    case Approved = 'approved';

    case Rejected = 'rejected';

    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::PendingVerification => 'Pending email verification',
            self::PendingApproval => 'Pending approval',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Suspended => 'Suspended',
        };
    }

    public function isDecided(): bool
    {
        return $this === self::Approved || $this === self::Rejected;
    }
}
