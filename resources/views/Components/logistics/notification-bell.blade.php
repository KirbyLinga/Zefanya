{{-- Logistics notification bell. Rendered once per page (layout toolbar or
     the page header). resources/js/logistics/notification-bell.js keeps it
     fresh. Without JS the server-rendered list and forms still work. --}}
@php
    $provider = auth('logistics')->user();
    $hasTable = \Illuminate\Support\Facades\Schema::hasTable('notifications');
    $notifications = $provider && $hasTable ? $provider->notifications()->latest()->limit(8)->get() : collect();
    $unreadCount = $provider && $hasTable ? $provider->unreadNotifications()->count() : 0;
@endphp

<div id="lg-notification-bell" class="relative z-30 shrink-0" data-recent-url="{{ route('logistics.notifications.recent') }}">
    <button type="button" class="sp-icon-btn" id="lg-bell-btn" aria-haspopup="true"
            aria-expanded="false" aria-controls="lg-notif-dropdown"
            aria-label="{{ $unreadCount > 0 ? 'Notifications, '.$unreadCount.' unread' : 'Notifications' }}">
        <i data-lucide="bell" class="h-[18px] w-[18px]" aria-hidden="true"></i>
        <span class="sp-bell-badge" id="lg-bell-badge" aria-hidden="true" @if ($unreadCount <= 0) hidden @endif>{{ $unreadCount > 9 ? '9+' : $unreadCount }}</span>
    </button>
    <div class="sp-notif-dropdown" id="lg-notif-dropdown" role="region" aria-label="Notifications panel" hidden>
        <div class="sp-notif-dropdown__header">Notifications</div>
        <div id="lg-notif-list" class="max-h-96 overflow-y-auto">
            @forelse ($notifications as $notification)
                <div class="sp-notif-item">
                    <form method="POST" action="{{ route('logistics.notifications.open', $notification->id) }}">
                        @csrf
                        <button type="submit" class="block w-full cursor-pointer text-left">
                            <span class="sp-notif-item__title block">{{ $notification->data['title'] ?? 'Notification' }}</span>
                            <span class="sp-notif-item__message block">{{ $notification->data['message'] ?? '' }}</span>
                            <span class="sp-notif-item__time block">{{ $notification->created_at?->diffForHumans() }}</span>
                        </button>
                    </form>
                    @if ($notification->read_at === null)
                        <form method="POST" action="{{ route('logistics.notifications.read', $notification->id) }}" data-notification-read>
                            @csrf
                            <button type="submit" class="font-sans text-xs font-semibold text-primary-700 hover:underline">Mark as read</button>
                        </form>
                    @else
                        <span class="font-sans text-xs text-[var(--text-muted)]">Read</span>
                    @endif
                </div>
            @empty
                <div class="sp-notif-empty">
                    <p class="sp-notif-empty__title">You're all caught up</p>
                    <p class="sp-notif-empty__sub">Hub alerts will show up here.</p>
                </div>
            @endforelse
        </div>
        <div class="flex flex-wrap items-center justify-between gap-2 border-t border-[var(--border-strong)] px-4 py-3">
            <a href="{{ route('logistics.notifications.index') }}" class="font-sans text-xs font-semibold text-primary-700 hover:underline">View all notifications</a>
            <form method="POST" action="{{ route('logistics.notifications.readAll') }}" data-lg-mark-all @if ($unreadCount <= 0) hidden @endif>
                @csrf
                <button type="submit" class="font-sans text-xs font-semibold text-primary-700 hover:underline">Mark all as read</button>
            </form>
            <p data-notification-error role="status" class="w-full font-sans text-xs text-red-700" hidden></p>
        </div>
    </div>
</div>
