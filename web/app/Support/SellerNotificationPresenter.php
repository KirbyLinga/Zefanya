<?php

namespace App\Support;

use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;

/**
 * How a stored seller notification reads on screen. Links are built here from
 * known routes, never taken from the notification's stored data.
 */
class SellerNotificationPresenter
{
    /** @var array<string, array{title: string, icon: string, tone: string}> */
    private const TYPES = [
        'seller_new_order' => ['title' => 'New order', 'icon' => 'shopping-bag', 'tone' => 'primary'],
        'low_stock' => ['title' => 'Low stock', 'icon' => 'triangle-alert', 'tone' => 'warning'],
        'buyer_order_cancelled' => ['title' => 'Order cancelled', 'icon' => 'circle-x', 'tone' => 'danger'],
        'buyer_return_requested' => ['title' => 'Return requested', 'icon' => 'undo-2', 'tone' => 'warning'],
        'return_disputed' => ['title' => 'Return disputed', 'icon' => 'scale', 'tone' => 'danger'],
        'return_dispute_resolved' => ['title' => 'Dispute resolved', 'icon' => 'scale', 'tone' => 'success'],
        'seller_delivery_failed' => ['title' => 'Delivery failed', 'icon' => 'truck', 'tone' => 'danger'],
        'pickup_failed' => ['title' => 'Pickup failed', 'icon' => 'package-x', 'tone' => 'danger'],
        'seller_parcel_returned' => ['title' => 'Parcel returned', 'icon' => 'package-check', 'tone' => 'neutral'],
        'seller_payout_paid' => ['title' => 'Payout sent', 'icon' => 'wallet', 'tone' => 'success'],
        'seller_payout_rejected' => ['title' => 'Payout rejected', 'icon' => 'wallet', 'tone' => 'danger'],
    ];

    /** @return array{title: string, icon: string, tone: string, actionLabel: ?string, actionUrl: ?string, amountMinor: ?int} */
    public static function present(DatabaseNotification $notification): array
    {
        $data = $notification->data ?? [];
        $type = is_string($data['type'] ?? null) ? $data['type'] : '';
        $known = self::TYPES[$type] ?? null;

        return [
            'title' => $data['title'] ?? $known['title'] ?? ($type !== '' ? Str::headline($type) : 'Notification'),
            'icon' => $known['icon'] ?? 'bell',
            'tone' => $known['tone'] ?? 'neutral',
            ...self::action($type, $data),
            'amountMinor' => is_int($data['amount_minor'] ?? null) ? $data['amount_minor'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{actionLabel: ?string, actionUrl: ?string}
     */
    private static function action(string $type, array $data): array
    {
        if ($type === 'low_stock') {
            return ['actionLabel' => 'Review inventory', 'actionUrl' => route('seller.products.index', ['stock' => 'low'])];
        }

        if (in_array($type, ['buyer_return_requested', 'return_disputed', 'return_dispute_resolved'], true)) {
            return ['actionLabel' => 'View returns', 'actionUrl' => route('seller.returns.index')];
        }

        if (in_array($type, ['seller_payout_paid', 'seller_payout_rejected'], true)) {
            return ['actionLabel' => 'View payouts', 'actionUrl' => route('seller.payout-requests.index')];
        }

        $orderId = $data['seller_order_id'] ?? $data['order_id'] ?? null;

        return is_int($orderId)
            ? ['actionLabel' => 'View order', 'actionUrl' => route('seller.orders.show', $orderId)]
            : ['actionLabel' => null, 'actionUrl' => null];
    }
}
