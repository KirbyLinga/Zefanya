<?php

namespace App\Support;

/**
 * Display title for a buyer notification type, shared by the buyer web bell
 * and the Buyer mobile API (moved from Buyer\NotificationController).
 */
final class BuyerNotificationTitle
{
    /** @param  array<string, mixed>  $data  the notification's data payload */
    public static function for(array $data): string
    {
        return match ($data['type'] ?? 'notification') {
            'consumer_claim' => 'Warranty / Consumer Claim',
            'courier_assigned' => 'Courier Assigned',
            'out_for_delivery', 'order_out_for_delivery' => 'Order Out for Delivery',
            'delivered', 'order_delivered' => 'Order Delivered',
            'delivery_failed' => $data['title'] ?? 'Unsuccessful delivery attempt',
            'seller_order_cancelled' => 'Shipment Cancelled',
            'return_request_decided' => 'Return Request Updated',
            'return_dispute_resolved' => 'Return Dispute Resolved',
            'conduct_review_decision' => $data['title'] ?? 'Account Notice',
            'address_check_requested', 'address_check_confirmed', 'address_check_not_confirmed' => $data['title'] ?? 'Delivery Address Check',
            default => 'Notification',
        };
    }
}
