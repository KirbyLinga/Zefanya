<?php

namespace App\Services;

use App\Models\Rider;
use App\Models\SellerOrder;

/**
 * Rider-facing facade over ParcelHandoffVerification (F2 Stages 3–4).
 *
 * @deprecated Prefer ParcelHandoffVerification directly for new call sites.
 */
class RiderHandoffVerification
{
    public const ACTION_CONFIRM_PICKUP = ParcelHandoffVerification::ACTION_CONFIRM_PICKUP;

    public const ACTION_START_DELIVERY = ParcelHandoffVerification::ACTION_START_DELIVERY;

    public const ACTION_COMPLETE_DELIVERY = ParcelHandoffVerification::ACTION_COMPLETE_DELIVERY;

    public const ACTION_FAIL_DELIVERY = ParcelHandoffVerification::ACTION_FAIL_DELIVERY;

    public function __construct(
        private readonly ParcelHandoffVerification $inner = new ParcelHandoffVerification,
    ) {}

    public function issue(Rider $rider, SellerOrder $sellerOrder, string $action, string $method = ParcelHandoffVerification::METHOD_MANUAL): string
    {
        return $this->inner->issueForRider($rider, $sellerOrder, $action, $method);
    }

    /** @return array<string, mixed> */
    public function assertValid(Rider $rider, SellerOrder $sellerOrder, string $action, ?string $token): array
    {
        return $this->inner->assertValidForRider($rider, $sellerOrder, $action, $token);
    }

    /** @return array<string, mixed> */
    public function consume(Rider $rider, SellerOrder $sellerOrder, string $action, ?string $token): array
    {
        return $this->inner->consumeForRider($rider, $sellerOrder, $action, $token);
    }
}
