<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Support\SellerNotificationPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate(['filter' => ['nullable', 'in:all,unread']]);
        $filter = $data['filter'] ?? 'all';
        $seller = $request->user('seller');
        $notifications = $seller->notifications()
            ->when($filter === 'unread', fn ($query) => $query->whereNull('read_at'))
            ->latest()->orderByDesc('id')->paginate(20)->withQueryString();

        return response()->view('Seller.Notifications.index', [
            'notifications' => $notifications, 'filter' => $filter,
            'unreadCount' => $seller->unreadNotifications()->count(),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function recent(Request $request): JsonResponse
    {
        $seller = $request->user('seller');
        $notifications = $seller->notifications()->latest()->orderByDesc('id')->limit(8)->get()
            ->map(function ($notification): array {
                $view = SellerNotificationPresenter::present($notification);

                return [
                    'id' => $notification->id,
                    'title' => $view['title'],
                    'message' => $notification->data['message'] ?? '',
                    'action_url' => $view['actionUrl'] ?? route('seller.notifications.index'),
                    'read_url' => route('seller.notifications.read', $notification->id),
                    'read' => $notification->read_at !== null,
                    'created_at_human' => $notification->created_at?->diffForHumans(),
                ];
            });

        return response()->json([
            'unread_count' => $seller->unreadNotifications()->count(),
            'notifications' => $notifications,
        ])->header('Cache-Control', 'no-store, private');
    }

    public function markRead(Request $request, string $notification): JsonResponse|RedirectResponse
    {
        $request->user('seller')->notifications()->whereKey($notification)->firstOrFail()->markAsRead();

        return $this->readResponse($request);
    }

    public function markAllRead(Request $request): JsonResponse|RedirectResponse
    {
        $request->user('seller')->unreadNotifications()->update(['read_at' => now()]);

        return $this->readResponse($request);
    }

    private function readResponse(Request $request): JsonResponse|RedirectResponse
    {
        return $request->expectsJson()
            ? response()->json(['success' => true, 'unread_count' => $request->user('seller')->unreadNotifications()->count()])
            : back()->with('success', 'Notifications marked as read.');
    }
}
