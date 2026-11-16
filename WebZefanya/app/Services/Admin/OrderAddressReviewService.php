<?php

namespace App\Services\Admin;

use App\Models\Admin\Admin;
use App\Models\Admin\OperationsAuditLog;
use App\Models\Shared\Order;
use App\Models\Shared\OrderAddressReview;
use App\Models\Shared\SellerOrder;
use App\Notifications\Admin\AddressReviewNeeded;
use App\Notifications\Buyer\AddressCheckUpdate;
use App\Notifications\Seller\AddressCheckHold;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Cross-region delivery address check.
 *
 * At checkout, a shipment whose delivery region is neither the Seller's region
 * nor a neighbouring one gets a pending review. The Seller can still accept and
 * pack, but cannot request pickup (and no Rider can collect it) until Admin
 * calls the Buyer and confirms the address. If the address cannot be confirmed,
 * the hold stays and the Seller may decline the order through the existing
 * decline action, which returns stock and vouchers. No order is cancelled,
 * charged or refunded here.
 */
class OrderAddressReviewService
{
    public const STAFF_ROLES = ['super_admin', 'admin', 'moderator'];

    public const HOLD_MESSAGE = 'This order is on hold while Zefanya confirms the Buyer\'s delivery address by phone. You can request pickup after Admin confirms it.';

    // ── Policy ────────────────────────────────────────────────────────────

    public static function region(?string $provinceCode): ?string
    {
        $code = preg_replace('/\D/', '', (string) $provinceCode);
        if ($code === null || strlen($code) < 9) {
            return null;
        }
        $region = substr($code, 0, 2);

        return config('address_review.region_aliases.'.$region, $region);
    }

    /** True when the two regions are the same or listed as neighbours. */
    public static function nearby(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }
        $neighbours = (array) config('address_review.neighbours', []);

        return in_array($b, $neighbours[$a] ?? [], true) || in_array($a, $neighbours[$b] ?? [], true);
    }

    /**
     * [seller region, buyer region] when this shipment needs a phone check,
     * otherwise null. Unknown regions are not held: there is nothing to compare.
     *
     * @return array{0: string, 1: string}|null
     */
    public function regionsNeedingCheck(SellerOrder $parcel): ?array
    {
        if (! config('address_review.enabled', true)) {
            return null;
        }
        $sellerRegion = self::region($parcel->seller?->province_code);
        $buyerRegion = self::region($parcel->order?->shipping_address['province_code'] ?? null);
        if ($sellerRegion === null || $buyerRegion === null || self::nearby($sellerRegion, $buyerRegion)) {
            return null;
        }

        return [$sellerRegion, $buyerRegion];
    }

    // ── Checkout ──────────────────────────────────────────────────────────

    /** Called inside the checkout transaction. Notifications go out only after it commits. */
    public function flagOrder(Order $order): void
    {
        if (! OrderAddressReview::available()) {
            return;
        }
        $order->loadMissing('sellerOrders.seller');
        foreach ($order->sellerOrders as $parcel) {
            $parcel->setRelation('order', $order);
            $regions = $this->regionsNeedingCheck($parcel);
            if ($regions === null || OrderAddressReview::query()->where('seller_order_id', $parcel->id)->exists()) {
                continue;
            }
            $review = new OrderAddressReview;
            $review->forceFill([
                'seller_order_id' => $parcel->id, 'order_id' => $order->id,
                'buyer_id' => $order->buyer_id, 'seller_id' => $parcel->seller_id,
                'seller_region' => $regions[0], 'buyer_region' => $regions[1],
                'seller_province' => $parcel->seller?->province_name,
                'buyer_province' => $order->shipping_address['province'] ?? null,
                'contact_no' => $order->shipping_address['phone'] ?? null,
                'status' => 'pending',
            ])->save();

            DB::afterCommit(fn () => $this->notifyCreated($review->fresh()));
        }
    }

    // ── Hold ──────────────────────────────────────────────────────────────

    /** The review holding this shipment, if any. Safe before the table exists. */
    public function holdFor(SellerOrder|int $parcel): ?OrderAddressReview
    {
        if (! OrderAddressReview::available()) {
            return null;
        }
        $id = $parcel instanceof SellerOrder ? $parcel->id : $parcel;
        $review = OrderAddressReview::query()->where('seller_order_id', $id)->first();

        return $review?->holdsShipment() ? $review : null;
    }

    // ── Admin decisions ───────────────────────────────────────────────────

    /**
     * Record the phone check. "confirmed" releases the hold; "not_confirmed"
     * keeps it. A confirmed review is final. A not-confirmed review may later be
     * confirmed (for example, the Buyer calls back). Returns false when nothing
     * changed, so a replay sends nothing.
     */
    public function decide(OrderAddressReview $review, Admin $admin, string $decision, string $callOutcome, ?string $note): bool
    {
        abort_unless(in_array($admin->role, self::STAFF_ROLES, true), 403);
        abort_unless(OrderAddressReview::available(), 503, 'Address review is awaiting the database update.');
        $note = trim((string) $note);
        if (! in_array($decision, ['confirmed', 'not_confirmed'], true)) {
            throw ValidationException::withMessages(['decision' => 'Choose whether the address was confirmed.']);
        }
        if (! array_key_exists($callOutcome, OrderAddressReview::CALL_OUTCOMES)) {
            throw ValidationException::withMessages(['call_outcome' => 'Record the result of the phone call.']);
        }
        if ($decision === 'confirmed' && $callOutcome !== 'reached_confirmed') {
            throw ValidationException::withMessages(['call_outcome' => 'An address can be confirmed only after reaching the Buyer and confirming it by phone.']);
        }
        if ($decision === 'not_confirmed' && mb_strlen($note) < 10) {
            throw ValidationException::withMessages(['decision_note' => 'Explain why the address could not be confirmed (at least 10 characters).']);
        }
        if (mb_strlen($note) > 1000) {
            throw ValidationException::withMessages(['decision_note' => 'Keep the note within 1000 characters.']);
        }

        $changed = DB::transaction(function () use ($review, $admin, $decision, $callOutcome, $note): bool {
            $locked = OrderAddressReview::query()->whereKey($review->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'confirmed' || $locked->status === $decision) {
                return false;
            }
            $old = ['status' => $locked->status];
            $locked->forceFill([
                'status' => $decision, 'call_outcome' => $callOutcome, 'called_at' => now(),
                'decision_note' => $note !== '' ? $note : null, 'decided_by' => $admin->id, 'decided_at' => now(),
            ])->save();
            OperationsAuditLog::record($admin, 'order_address_review_decided', $locked, 'Address check '.$locked->reference(),
                $old, ['status' => $decision, 'call' => $callOutcome], $note !== '' ? $note : null);

            return true;
        });

        if ($changed) {
            $this->notifyDecision($review->fresh());
        }

        return $changed;
    }

    // ── Notifications (after commit, once per state change) ───────────────

    private function notifyCreated(OrderAddressReview $review): void
    {
        $review->loadMissing(['buyer', 'seller', 'sellerOrder.order']);
        $this->send(fn () => $review->buyer?->notify(new AddressCheckUpdate($review, 'requested')));
        $this->send(fn () => $review->seller?->notify(new AddressCheckHold($review, 'held')));
        $this->send(fn () => Admin::query()->whereIn('role', self::STAFF_ROLES)->each(fn (Admin $admin) => $admin->notify(new AddressReviewNeeded($review))));
    }

    private function notifyDecision(OrderAddressReview $review): void
    {
        $review->loadMissing(['buyer', 'seller', 'sellerOrder.order']);
        $this->send(fn () => $review->buyer?->notify(new AddressCheckUpdate($review, $review->status)));
        $this->send(fn () => $review->seller?->notify(new AddressCheckHold($review, $review->status)));
    }

    /** A failed notification is reported; it never undoes a saved order or decision. */
    private function send(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
