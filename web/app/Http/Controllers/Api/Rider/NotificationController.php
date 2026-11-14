<?php

namespace App\Http\Controllers\Api\Rider;

use App\Http\Controllers\Controller;
use App\Http\Middleware\Traits\ApiResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Rider mobile notifications.
 *
 * Bearer twin of Rider\NotificationController: every query goes through the
 * token owner's own `notifications()` relation, so a rider can never read or
 * mark another rider's rows. The stored web URL is not sent; the app gets the
 * parcel id, when the payload names one, and opens its own screen.
 */
class NotificationController extends Controller
{
    use ApiResponses;

    /** GET /api/rider/notifications */
    public function index(Request $request): JsonResponse
    {
        $rider = $request->user();
        $page = $rider->notifications()->latest()->paginate(20);

        return $this->success('Notifications retrieved.', [
            'data' => $page->getCollection()
                ->map(fn (DatabaseNotification $notification): array => $this->present($notification))
                ->values(),
            'meta' => [
                'page' => $page->currentPage(),
                'has_more' => $page->hasMorePages(),
                'unread_count' => $rider->unreadNotifications()->count(),
            ],
        ]);
    }

    /** POST /api/rider/notifications/{notification}/read */
    public function markRead(Request $request, string $notification): JsonResponse
    {
        $rider = $request->user();
        $record = $rider->notifications()->whereKey($notification)->first();
        abort_if($record === null, 404, 'Notification not found.');
        $record->markAsRead();

        return $this->success('Notification marked as read.', ['unread_count' => $rider->unreadNotifications()->count()]);
    }

    /** POST /api/rider/notifications/read-all */
    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return $this->success('All notifications marked as read.', ['unread_count' => 0]);
    }

    /** @return array<string, mixed> */
    private function present(DatabaseNotification $notification): array
    {
        $data = (array) ($notification->data ?? []);
        $parcelId = $data['seller_order_id'] ?? $data['parcel_id'] ?? null;

        return [
            'id' => $notification->id,
            'type' => $data['type'] ?? 'notification',
            'title' => $data['title'] ?? 'Notification',
            'message' => $data['message'] ?? '',
            'seller_order_id' => is_numeric($parcelId) ? (int) $parcelId : null,
            'read' => $notification->read_at !== null,
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }
}
