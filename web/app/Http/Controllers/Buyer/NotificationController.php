<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class NotificationController extends Controller
{
    /**
     * GET /buyer/notifications — Fetch unread notifications for the buyer
     */
    public function index(): JsonResponse
    {
        $buyer = auth('buyer')->user();

        $notifications = $buyer->unreadNotifications()
            ->latest()
            ->take(10)
            ->get()
            ->map(function ($notif) {
                return [
                    'id' => $notif->id,
                    'type' => $notif->data['type'] ?? 'notification',
                    'title' => $this->getNotificationTitle($notif),
                    'message' => $notif->data['message'] ?? 'You have a new notification',
                    'created_at' => $notif->created_at->toIso8601String(),
                ];
            });

        return response()->json([
            'notifications' => $notifications,
        ]);
    }

    /**
     * GET /buyer/notifications/count — Get count of unread notifications
     */
    public function count(): JsonResponse
    {
        $buyer = auth('buyer')->user();
        $count = $buyer->unreadNotifications()->count();

        return response()->json([
            'count' => $count,
        ]);
    }

    /**
     * POST /buyer/notifications/{id}/read — Mark a notification as read
     */
    public function markRead(string $id): JsonResponse
    {
        $buyer = auth('buyer')->user();
        $notification = $buyer->unreadNotifications()->find($id);

        if (! $notification) {
            return response()->json(['error' => 'Notification not found'], 404);
        }

        $notification->markAsRead();

        return response()->json([
            'success' => true,
        ]);
    }

    /**
     * POST /buyer/notifications/mark-all-read — Mark all notifications as read
     */
    public function markAllRead(): JsonResponse
    {
        $buyer = auth('buyer')->user();
        $buyer->unreadNotifications()->update(['read_at' => now()]);

        return response()->json([
            'success' => true,
        ]);
    }

    /**
     * Get a readable title for a notification based on its type
     */
    private function getNotificationTitle($notification): string
    {
        $type = $notification->data['type'] ?? 'notification';

        return match ($type) {
            'courier_assigned' => 'Courier Assigned',
            'order_out_for_delivery' => 'Order Out for Delivery',
            'order_delivered' => 'Order Delivered',
            default => 'Notification',
        };
    }
}
