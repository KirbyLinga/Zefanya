{{--
    Rider notification bell.

    Props:
      $notifications — latest notification rows (may be empty)
      $unreadCount   — unread badge count

    Polling and open/close behaviour live in
    resources/js/rider/notification-bell.js. The rows are rendered on the
    server; JavaScript only toggles the panel and refreshes its contents.
--}}
@props(['notifications' => collect(), 'unreadCount' => 0])

<div
    id="rider-notification-bell"
    class="relative"
    data-recent-url="{{ json_encode(route('rider.notifications.recent')) }}"
>
    <button
        type="button"
        class="sp-icon-btn"
        id="rider-bell-btn"
        aria-haspopup="true"
        aria-controls="rider-notif-dropdown"
        aria-expanded="false"
        aria-label="{{ $unreadCount > 0 ? 'Notifications, '.$unreadCount.' unread' : 'Notifications' }}"
    >
        <i data-lucide="bell" class="h-[18px] w-[18px]" aria-hidden="true"></i>

        <span
            class="sp-bell-badge"
            id="rider-bell-badge"
            aria-hidden="true"
            @if ($unreadCount <= 0) hidden @endif
        >{{ $unreadCount > 9 ? '9+' : $unreadCount }}</span>
    </button>

    <div
        class="sp-notif-dropdown"
        id="rider-notif-dropdown"
        role="region"
        aria-label="Notifications panel"
        hidden
    >
        <div class="flex items-center justify-between gap-2 border-b border-[var(--border-strong)] px-4 py-3">
            <span class="font-sans text-[13px] font-semibold text-[var(--text-ink)]">Notifications</span>
            <a
                href="{{ route('rider.notifications.index') }}"
                class="font-sans text-[11.5px] font-semibold text-primary-700 no-underline hover:underline"
            >View all</a>
        </div>

        <div id="rider-notif-list">
            @if ($notifications->isEmpty())
                <div class="sp-notif-empty">
                    <i data-lucide="bell-off" class="h-[28px] w-[28px] text-[var(--icon-soft)]" aria-hidden="true"></i>
                    <p class="sp-notif-empty__title">You're all caught up</p>
                    <p class="sp-notif-empty__sub">New assignments and account updates will show up here.</p>
                </div>
            @else
                <ul class="m-0 list-none p-0" role="list">
                    @foreach ($notifications as $notification)
                        @php($data = $notification->data ?? [])
                        <li class="sp-notif-item" role="listitem">
                            <form method="POST" action="{{ route('rider.notifications.open', $notification->id) }}">
                                @csrf
                                <button type="submit" class="block w-full cursor-pointer border-0 bg-transparent p-0 text-left">
                                    <span class="sp-notif-item__title flex items-center gap-2">
                                        @if ($notification->read_at === null)
                                            <span class="inline-block h-[6px] w-[6px] shrink-0 rounded-full bg-primary-600" aria-hidden="true"></span>
                                        @endif
                                        {{ $data['title'] ?? 'Notification' }}
                                    </span>
                                    @if (! empty($data['message']))
                                        <span class="sp-notif-item__message block">{{ $data['message'] }}</span>
                                    @endif
                                    <span class="sp-notif-item__time block">{{ $notification->created_at?->diffForHumans() }}</span>
                                </button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        <div class="flex items-center justify-end border-t border-[var(--border-strong)] px-3 py-2">
            <form
                method="POST"
                action="{{ route('rider.notifications.readAll') }}"
                data-rider-mark-all
                @if ($unreadCount <= 0) hidden @endif
            >
                @csrf
                <button
                    type="submit"
                    class="cursor-pointer border-0 bg-transparent p-0 font-sans text-[11.5px] font-semibold text-primary-700 hover:underline"
                >Mark all as read</button>
            </form>
        </div>
    </div>
</div>
