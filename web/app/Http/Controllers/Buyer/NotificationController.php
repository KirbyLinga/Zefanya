<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Shared\SellerOrder;
use App\Support\BuyerNotificationTitle;
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
            ->get();
        $orderIds = SellerOrder::query()
            ->whereIn('id', $notifications->pluck('data.seller_order_id')->filter())
            ->whereHas('order', fn ($query) => $query->where('buyer_id', $buyer->id))
            ->pluck('order_id', 'id');
        $notifications = $notifications->map(function ($notif) use ($orderIds) {
            $orderId = $orderIds->get($notif->data['seller_order_id'] ?? null);

            return [
                'id' => $notif->id,
                'type' => $notif->data['type'] ?? 'notification',
                'title' => $this->getNotificationTitle($notif),
                'message' => BuyerNotificationTitle::message((array) $notif->data),
                'url' => $orderId ? route('buyer.orders.show', $orderId) : null,
                'created_at' => $notif->created_at->toIso8601String(),
            ];
        });

        return response()->json([
            'notifications' => $notifications,
            'unread_count' => $buyer->unreadNotifications()->count(),
        ])->header('Cache-Control', 'no-store, private');
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
        $notification = $buyer->notifications()->find($id);

        if (! $notification) {
            return response()->json(['error' => 'Notification not found'], 404);
        }

        $notification->markAsRead();
        $orderId = SellerOrder::query()
            ->whereKey($notification->data['seller_order_id'] ?? null)
            ->whereHas('order', fn ($query) => $query->where('buyer_id', $buyer->id))
            ->value('order_id');

        return response()->json([
            'success' => true,
            'count' => $buyer->unreadNotifications()->count(),
            'url' => $orderId ? route('buyer.orders.show', $orderId) : null,
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
            'count' => $buyer->unreadNotifications()->count(),
        ]);
    }

    /**
     * Get a readable title for a notification based on its type
     */
    private function getNotificationTitle($notification): string
    {
        return BuyerNotificationTitle::for((array) $notification->data);
    }
}
