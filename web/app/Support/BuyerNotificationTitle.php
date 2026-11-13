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
            'hub_delay' => 'Parcel delayed at a hub',
            'courier_assigned' => 'Courier Assigned',
            'out_for_delivery', 'order_out_for_delivery' => 'Order Out for Delivery',
            'delivery_code' => 'Delivery update',
            'delivered', 'order_delivered' => 'Order Delivered',
            'delivery_failed' => 'Delivery Attempt Failed',
            'seller_order_cancelled' => 'Shipment Cancelled',
            'return_request_decided' => 'Return Request Updated',
            'return_dispute_resolved' => 'Return Dispute Resolved',
            default => 'Notification',
        };
    }

    public static function message(array $data): string
    {
        return ($data['type'] ?? null) === 'delivery_code'
            ? 'Open your order to view its delivery status.'
            : ($data['message'] ?? 'You have a new notification');
    }
}
