<?php

namespace App\Http\Controllers\Api\Buyer;

use App\Http\Controllers\Controller;
use App\Http\Middleware\Traits\ApiResponses;
use App\Models\Shared\SellerOrder;
use App\Support\BuyerNotificationTitle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Buyer mobile notifications: only the token owner's own database
 * notifications. A linked order id is returned only when that order belongs
 * to the buyer (same check as the web bell); raw payloads are not exposed.
 */
class NotificationController extends Controller
{
    use ApiResponses;

    public function index(Request $request): JsonResponse
    {
        $buyer = $request->user();
        $page = $buyer->notifications()->latest()->paginate(20);

        $orderIds = SellerOrder::query()
            ->whereIn('id', $page->getCollection()->pluck('data.seller_order_id')->filter())
            ->whereHas('order', fn ($query) => $query->where('buyer_id', $buyer->id))
            ->pluck('order_id', 'id');

        return $this->success('Notifications retrieved.', [
            'data' => $page->getCollection()->map(fn ($notification): array => [
                'id' => $notification->id,
                'type' => $notification->data['type'] ?? 'notification',
                'title' => BuyerNotificationTitle::for((array) $notification->data),
                'message' => BuyerNotificationTitle::message((array) $notification->data),
                'order_id' => $orderIds->get($notification->data['seller_order_id'] ?? null),
                'read' => $notification->read_at !== null,
                'created_at' => $notification->created_at?->toIso8601String(),
            ])->values(),
            'meta' => [
                'page' => $page->currentPage(),
                'has_more' => $page->hasMorePages(),
                'unread_count' => $buyer->unreadNotifications()->count(),
            ],
        ]);
    }

    public function markRead(Request $request, string $notification): JsonResponse
    {
        $buyer = $request->user();
        $record = $buyer->notifications()->find($notification);
        abort_if($record === null, 404, 'Notification not found.');
        $record->markAsRead();

        return $this->success('Notification marked as read.', ['unread_count' => $buyer->unreadNotifications()->count()]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $buyer = $request->user();
        $buyer->unreadNotifications()->update(['read_at' => now()]);

        return $this->success('All notifications marked as read.', ['unread_count' => 0]);
    }
}
