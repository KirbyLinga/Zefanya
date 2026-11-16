<?php

namespace App\Services\Buyer;

use App\Enums\AdminRole;
use App\Enums\DeliveryFailureReason;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\CheckoutException;
use App\Models\Admin\Admin;
use App\Models\Admin\OperationsAuditLog;
use App\Models\Buyer\Buyer;
use App\Models\Buyer\CodDeliveryStrike;
use App\Models\Buyer\CodRestrictionLift;
use App\Models\Shared\SellerOrder;
use App\Models\Shared\StatusHistory;
use App\Services\Seller\SellerOrderStatusService;
use App\Support\RuntimeSchema;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * COD abuse prevention. Cash on delivery is the only payment method, so a buyer
 * who keeps refusing parcels costs Sellers the failed-delivery shipping and ties
 * up stock. A strike is one COD parcel whose delivery finally failed for a
 * buyer-caused reason. Three strikes within 90 days, counted across the account
 * and the phone number the orders were placed with, stop new checkouts until an
 * Admin lifts the restriction. Damaged parcels and "other" never count.
 *
 * The existing failed-delivery charges and Seller debts are unchanged.
 */
class CodRiskService
{
    public const STRIKE_LIMIT = 3;

    /** Set while the migration backfills, when the schema memo may predate the new table. */
    private bool $backfilling = false;

    public const WINDOW_DAYS = 90;

    public const BUYER_FAULT_REASONS = [
        DeliveryFailureReason::RefusedByRecipient->value,
        DeliveryFailureReason::UnreachableByPhone->value,
        DeliveryFailureReason::RecipientUnavailable->value,
        DeliveryFailureReason::WrongAddress->value,
    ];

    public const BLOCKED_MESSAGE = 'Cash on delivery is paused for this account because several recent parcels could not be delivered (refused, unreachable or wrong address). Please contact Zefanya support to restore checkout.';

    public static function available(): bool
    {
        return RuntimeSchema::hasTable('cod_delivery_strikes');
    }

    /** The last 10 digits of a Philippine mobile number, so 0917…, 63917… and +63 917… match. */
    public static function phoneKey(?string $phone): ?string
    {
        $digits = (string) preg_replace('/\D/', '', (string) $phone);

        return strlen($digits) >= 10 ? substr($digits, -10) : null;
    }

    /**
     * Called by the status service under the parcel lock when a delivery ends without
     * handing over the parcel: the last allowed attempt failed, or it is sent back.
     * Records at most one strike per parcel, and only for a buyer-caused COD failure.
     */
    public function recordFinalFailure(SellerOrder $parcel): bool
    {
        if ($parcel->status === OrderStatus::DeliveryFailed
            && (int) $parcel->delivery_attempts < SellerOrderStatusService::MAX_DELIVERY_ATTEMPTS) {
            return false;
        }
        if ((! $this->backfilling && ! self::available()) || ! in_array($parcel->delivery_failure_reason, self::BUYER_FAULT_REASONS, true)) {
            return false;
        }
        if ($parcel->status === OrderStatus::Returned && $parcel->parcelReturn?->isBuyerRequest()) {
            return false;
        }
        $order = $parcel->order()->first(['id', 'buyer_id', 'shipping_address']);
        $method = $parcel->payment()->value('method');
        if ($order === null || ($method instanceof PaymentMethod ? $method : PaymentMethod::tryFrom((string) $method)) !== PaymentMethod::Cod) {
            return false;
        }
        if (CodDeliveryStrike::query()->where('seller_order_id', $parcel->getKey())->exists()) {
            return false;
        }

        try {
            (new CodDeliveryStrike)->forceFill([
                'seller_order_id' => $parcel->getKey(),
                'buyer_id' => $order->buyer_id,
                'phone_key' => self::phoneKey($order->shipping_address['phone'] ?? Buyer::query()->whereKey($order->buyer_id)->value('contact_no')),
                'reason' => $parcel->delivery_failure_reason,
                'failed_at' => now(),
            ])->save();
        } catch (UniqueConstraintViolationException) {
            // Already recorded for this parcel.
            return false;
        }

        return true;
    }

    /** Strikes that still count: inside the window and after the latest Admin lift. */
    public function activeStrikes(Buyer $buyer): Builder
    {
        $phone = self::phoneKey($buyer->contact_no);
        $since = now()->subDays(self::WINDOW_DAYS);
        $lift = $this->latestLift($buyer, $phone);
        if ($lift !== null && $lift->greaterThan($since)) {
            $since = $lift;
        }

        return CodDeliveryStrike::query()
            ->where(fn (Builder $query) => $query->where('buyer_id', $buyer->getKey())
                ->when($phone !== null, fn (Builder $q) => $q->orWhere('phone_key', $phone)))
            ->where('failed_at', '>', $since);
    }

    public function strikeCount(Buyer $buyer): int
    {
        return self::available() ? $this->activeStrikes($buyer)->count() : 0;
    }

    public function isBlocked(Buyer $buyer): bool
    {
        return $this->strikeCount($buyer) >= self::STRIKE_LIMIT;
    }

    /** Checkout guard for web and app. */
    public function assertCanCheckout(Buyer $buyer): void
    {
        if ($this->isBlocked($buyer)) {
            throw new CheckoutException(self::BLOCKED_MESSAGE);
        }
    }

    /**
     * Admin restores COD after reviewing the buyer (for example, a courier error or a
     * verified new address). Strikes up to now stop counting for this account and phone;
     * new failures count again from zero.
     */
    public function lift(Buyer $buyer, Admin $admin, string $reason): void
    {
        abort_unless(in_array($admin->role, [AdminRole::SuperAdmin->value, AdminRole::Admin->value, AdminRole::Moderator->value], true), 403);
        $reason = trim($reason);
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 1000) {
            throw ValidationException::withMessages(['reason' => 'Explain why COD is being restored, in 10 to 1000 characters.']);
        }

        DB::transaction(function () use ($buyer, $admin, $reason): void {
            $locked = Buyer::query()->whereKey($buyer->getKey())->lockForUpdate()->firstOrFail();
            $count = $this->strikeCount($locked);
            if ($count < self::STRIKE_LIMIT) {
                throw ValidationException::withMessages(['reason' => 'This buyer is not restricted from cash on delivery.']);
            }

            (new CodRestrictionLift)->forceFill([
                'buyer_id' => $locked->getKey(),
                'phone_key' => self::phoneKey($locked->contact_no),
                'admin_id' => $admin->getKey(),
                'reason' => $reason,
                'strikes_cleared' => $count,
                'lifted_at' => now(),
            ])->save();

            OperationsAuditLog::record($admin, 'buyer_cod_restriction_lifted', $locked, 'Buyer #'.$locked->getKey(),
                ['cod' => 'Restricted', 'strikes' => $count], ['cod' => 'Restored', 'strikes' => 0], $reason);
        }, 3);
    }

    private function latestLift(Buyer $buyer, ?string $phone): ?CarbonInterface
    {
        $lifted = CodRestrictionLift::query()
            ->where(fn (Builder $query) => $query->where('buyer_id', $buyer->getKey())
                ->when($phone !== null, fn (Builder $q) => $q->orWhere('phone_key', $phone)))
            ->max('lifted_at');

        return $lifted === null ? null : Carbon::parse($lifted);
    }

    /** Record existing finally-failed COD parcels as strikes, dated at their last failed attempt. */
    public function backfill(): void
    {
        $this->backfilling = true;
        SellerOrder::query()
            ->where(fn (Builder $query) => $query->where('status', OrderStatus::Returned->value)
                ->orWhere(fn (Builder $q) => $q->where('status', OrderStatus::DeliveryFailed->value)
                    ->where('delivery_attempts', '>=', SellerOrderStatusService::MAX_DELIVERY_ATTEMPTS)))
            ->whereIn('delivery_failure_reason', self::BUYER_FAULT_REASONS)
            ->whereNotIn('id', CodDeliveryStrike::query()->select('seller_order_id'))
            ->orderBy('id')
            ->each(function (SellerOrder $parcel): void {
                if (! $this->recordFinalFailure($parcel)) {
                    return;
                }
                $failedAt = StatusHistory::query()->where('seller_order_id', $parcel->getKey())
                    ->where('to_status', OrderStatus::DeliveryFailed->value)->max('created_at');
                CodDeliveryStrike::query()->where('seller_order_id', $parcel->getKey())
                    ->update(['failed_at' => $failedAt ?? $parcel->updated_at ?? now()]);
            });
    }
}
