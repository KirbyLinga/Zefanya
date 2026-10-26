<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

class NotificationController extends Controller
{
    public function recent(Request $request): JsonResponse
    {
        $provider = $request->user('logistics');

        return response()->json([
            'unread_count' => $provider->unreadNotifications()->count(),
            'notifications' => $provider->notifications()->latest()->limit(8)->get()
                ->map(fn (DatabaseNotification $notification): array => [
                    'id' => $notification->id,
                    'title' => $notification->data['title'] ?? 'Notification',
                    'message' => $notification->data['message'] ?? '',
                    'url' => $this->safeLogisticsUrl($notification->data['url'] ?? null),
                    'open_url' => route('logistics.notifications.open', $notification->id),
                    'read' => $notification->read_at !== null,
                    'created_at_human' => $notification->created_at?->diffForHumans(),
                    'read_url' => route('logistics.notifications.read', $notification->id),
                ])->values(),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function markRead(Request $request, string $notification): JsonResponse|RedirectResponse
    {
        $request->user('logistics')->notifications()->whereKey($notification)->firstOrFail()->markAsRead();

        return $this->readResponse($request);
    }

    public function markAllRead(Request $request): JsonResponse|RedirectResponse
    {
        $request->user('logistics')->unreadNotifications()->update(['read_at' => now()]);

        return $this->readResponse($request);
    }

    public function open(Request $request, string $notification): RedirectResponse
    {
        $row = $request->user('logistics')->notifications()->whereKey($notification)->first();
        abort_if($row === null, 404);

        if ($row->read_at === null) {
            $row->markAsRead();
        }

        return redirect()->to($this->safeLogisticsUrl(data_get($row->data, 'url')));
    }

    private function safeLogisticsUrl(mixed $url): string
    {
        $fallback = route('logistics.incoming-parcels.index');

        if (! is_string($url) || $url === '' || str_contains($url, '..') || str_contains($url, '\\')) {
            return $fallback;
        }

        $base = rtrim(url('/logistics'), '/');

        if ($url === $base || str_starts_with($url, $base.'/')) {
            return $url;
        }

        if (str_starts_with($url, '/logistics/')) {
            return $url;
        }

        return $fallback;
    }

    private function readResponse(Request $request): JsonResponse|RedirectResponse
    {
        return $request->expectsJson()
            ? response()->json(['success' => true, 'unread_count' => $request->user('logistics')->unreadNotifications()->count()])
            : back()->with('success', 'Notifications marked as read.');
    }
}
