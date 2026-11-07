<?php

namespace App\Services\Buyer;

use App\Enums\AuthRole;
use App\Enums\OrderStatus;
use App\Models\Admin\AccountActionHistory;
use App\Models\Buyer\Buyer;
use App\Models\Buyer\CartItem;
use App\Models\Buyer\CheckoutToken;
use App\Models\Buyer\StoreFollow;
use App\Models\Logistics\ParcelReturn;
use App\Models\Shared\Order;
use App\Models\Shared\SellerOrder;
use App\Services\Shared\AccountSessionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * A buyer's self-service account deletion.
 *
 * Orders must outlive the account, so the buyer row stays as an archived,
 * nameless tombstone and every order keeps its money, items, status and
 * tracking. Personal data is erased: the account fields, saved data, and the
 * recipient's name, phone and street on finished orders.
 *
 * Deletion waits until nobody still needs to reach the buyer: no parcel in
 * progress, no open return and no refund owed.
 */
class BuyerAccountDeletionService
{
    public const DISPLAY_NAME = 'Deleted buyer';

    /** A parcel in any other status still needs the buyer's contact details. */
    private const FINISHED_STATUSES = [OrderStatus::Completed, OrderStatus::Cancelled, OrderStatus::Returned];

    public function __construct(private readonly AccountSessionService $sessions) {}

    /** Why this buyer can't be deleted yet, or null when they can. */
    public function blockingReason(Buyer $buyer): ?string
    {
        if (\App\Support\RuntimeSchema::hasTable('payment_cancellation_refunds')
            && \App\Models\Shared\PaymentCancellationRefund::query()->where('buyer_id', $buyer->id)->where('status', 'pending')->exists()) {
            return 'You have a refund being processed. You can delete your account after you receive it.';
        }

        $parcels = SellerOrder::query()->whereIn('order_id', Order::query()->where('buyer_id', $buyer->id)->select('id'));

        $inProgress = (clone $parcels)
            ->whereNotIn('status', array_map(fn (OrderStatus $status) => $status->value, self::FINISHED_STATUSES))
            ->exists();
        if ($inProgress) {
            return 'You have an order in progress. You can delete your account after it is completed or cancelled.';
        }

        $returns = ParcelReturn::query()->whereIn('seller_order_id', (clone $parcels)->select('id'))->get();
        if ($returns->contains(fn (ParcelReturn $return) => $return->isActive())) {
            return 'You have an open return. You can delete your account after it is resolved.';
        }
        if ($returns->contains(fn (ParcelReturn $return) => $return->financial_review_required && $return->refunded_at === null)) {
            return 'You have a refund being processed. You can delete your account after you receive it.';
        }

        return null;
    }

    /** @throws ValidationException when the buyer still has unfinished business */
    public function delete(Buyer $buyer): void
    {
        $identityDocument = DB::transaction(function () use ($buyer): ?string {
            $current = Buyer::query()->whereKey($buyer->id)->lockForUpdate()->firstOrFail();

            $reason = $this->blockingReason($current);
            if ($reason !== null) {
                throw ValidationException::withMessages(['account' => $reason]);
            }

            $identityDocument = $current->upload_id_path ?: null;
            $this->eraseSavedData($current);
            $this->eraseOrderContactDetails($current);
            $this->anonymize($current);

            return $identityDocument;
        });

        // Sessions and the file can't be rolled back, so they go only once the erasure has committed.
        $this->sessions->revoke(AuthRole::Buyer->value, $buyer->id);
        $this->deleteIdentityDocument($identityDocument, $buyer->id);

        Log::info('Buyer account anonymized.', ['buyer_id' => $buyer->id]);
    }

    private function eraseSavedData(Buyer $buyer): void
    {
        CartItem::query()->where('buyer_id', $buyer->id)->delete();
        CheckoutToken::query()->where('buyer_id', $buyer->id)->delete();
        StoreFollow::query()->where('buyer_id', $buyer->id)->delete();
        $buyer->wishlist()->delete();
        $buyer->addresses()->delete();
        $buyer->notifications()->delete();
        $buyer->tokens()->delete();

        DB::table('delivery_otps')->where('buyer_id', $buyer->id)->delete();
        DB::table(config('auth.passwords.buyers.table'))->where('email', $buyer->email)->delete();
        DB::table('newsletter_subscribers')->where('email', $buyer->email)->delete();

        // Admin audit rows keep the action and its reason, but not who it was about.
        AccountActionHistory::query()
            ->where('account_type', 'buyer')
            ->where('account_id', $buyer->id)
            ->update(['account_name' => self::DISPLAY_NAME, 'account_email' => $this->tombstoneEmail($buyer)]);
    }

    /** The delivery area stays for shipping records. Who and exactly where does not. */
    private function eraseOrderContactDetails(Buyer $buyer): void
    {
        Order::query()->where('buyer_id', $buyer->id)->lockForUpdate()->get()->each(function (Order $order): void {
            $address = $order->shipping_address ?? [];

            $order->timestamps = false;
            $order->forceFill(['shipping_address' => array_merge($address, [
                'name' => self::DISPLAY_NAME,
                'phone' => null,
                'street' => null,
                'house_number' => null,
                'address_detail' => null,
                'postal_code' => null,
                'full' => implode(', ', array_filter([
                    $address['barangay'] ?? null,
                    $address['municipality'] ?? null,
                    $address['province'] ?? null,
                ])),
            ])])->save();
        });

        DB::table('seller_orders')
            ->whereIn('order_id', Order::query()->where('buyer_id', $buyer->id)->select('id'))
            ->update(['buyer_note' => null]);
    }

    private function anonymize(Buyer $buyer): void
    {
        // These columns are required, so they hold fixed values that say nothing about the person.
        $buyer->forceFill([
            'first_name' => 'Deleted',
            'middle_initial' => null,
            'last_name' => 'buyer',
            'sex' => 'male',
            'birthday' => '1900-01-01',
            'age' => 0,
            'upload_id_path' => '',

            'email' => $this->tombstoneEmail($buyer),
            'registration_email' => null,
            'contact_no' => null,
            'password' => null,
            'google_id' => null,
            'remember_token' => null,
            'settings' => null,

            'address_mode' => 'manual',
            'province_code' => null,
            'province_name' => null,
            'municipality_code' => null,
            'municipality_name' => null,
            'barangay_code' => null,
            'barangay_name' => null,
            'street' => null,
            'house_number' => null,
            'address_detail' => null,
            'postal_code' => null,

            'email_verification_code' => null,
            'email_verification_expires_at' => null,
            'otp_attempts' => 0,
            'pending_email' => null,
            'pending_email_code' => null,
            'pending_email_expires_at' => null,
            'pending_password' => null,
            'pending_password_code' => null,
            'pending_password_expires_at' => null,

            'admin_remarks' => null,
            'rejection_reason' => null,

            'status' => 'suspended',
            'archived_at' => now(),
        ])->save();
    }

    private function tombstoneEmail(Buyer $buyer): string
    {
        return 'deleted-'.$buyer->id.'@archive.invalid';
    }

    /** The account is already erased, so a storage failure is logged and not shown to the buyer. */
    private function deleteIdentityDocument(?string $path, int $buyerId): void
    {
        if ($path === null) {
            return;
        }

        try {
            Storage::disk('local')->delete($path);
        } catch (Throwable $exception) {
            Log::error('Could not delete an anonymized buyer\'s ID document.', ['buyer_id' => $buyerId]);
            report($exception);
        }
    }
}
