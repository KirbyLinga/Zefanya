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
    case PendingVerification = 'pending_verification';

    /** Email confirmed, waiting on an administrator's decision. */
    case PendingApproval = 'pending_approval';

    case Approved = 'approved';

    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::PendingVerification => 'Pending email verification',
            self::PendingApproval => 'Pending approval',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
        };
    }

    public function isDecided(): bool
    {
        return $this === self::Approved || $this === self::Rejected;
    }
}
