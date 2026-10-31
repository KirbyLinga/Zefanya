{{--
    Seller notification bell.

    Props:
      $notifications — Collection of notification objects (may be empty)
      $unreadCount   — integer, unread count

    The dropdown opens/closes via resources/js/seller/notification-bell.js.
    Reusable: can be moved into the layout later with the same props.
--}}
@props(['notifications' => collect(), 'unreadCount' => 0])

<div class="sp-bell-wrapper" style="position: relative;">
    <button
        type="button"
        class="sp-icon-btn"
        id="sp-bell-btn"
        aria-controls="sp-notif-dropdown"
        aria-expanded="false"
        aria-label="Notifications, {{ $unreadCount }} unread"
        data-bell-toggle
    >
        <i data-lucide="bell" class="w-[18px] h-[18px]" aria-hidden="true"></i>

        @if($unreadCount > 0)
            <span class="sp-bell-badge" aria-hidden="true">
                {{ $unreadCount > 9 ? '9+' : $unreadCount }}
            </span>
        @endif
    </button>

    {{-- ── Dropdown ── --}}
    <div
        class="sp-notif-dropdown"
        id="sp-notif-dropdown"
        role="region"
        aria-label="Notifications panel"
        hidden
    >
        <div class="sp-notif-dropdown__header flex items-center justify-between gap-3">
            <span>Notifications</span>
            @if ($unreadCount > 0)
                <form method="POST" action="{{ route('seller.notifications.readAll') }}">
                    @csrf
                    <button type="submit" class="text-xs font-semibold text-primary-700 underline">Mark all as read</button>
                </form>
            @endif
        </div>

        @if($notifications->isEmpty())
            <div class="sp-notif-empty" data-notif-empty>
                <i data-lucide="bell-off" class="w-[28px] h-[28px]" style="color: var(--icon-soft);" aria-hidden="true"></i>
                <p class="sp-notif-empty__title">You're all caught up</p>
                <p class="sp-notif-empty__sub">Low-stock alerts and store updates will show up here.</p>
            </div>
        @else
            <ul style="list-style: none; margin: 0; padding: 0;" role="list">
                @foreach($notifications as $notification)
                    @php
                        $data    = $notification->data ?? [];
                        $view    = \App\Support\SellerNotificationPresenter::present($notification);
                        $message = $data['message'] ?? '';
                    @endphp
                    <li class="sp-notif-item relative" role="listitem">
                        <a href="{{ $view['actionUrl'] ?? route('seller.notifications.index') }}" class="block rounded-lg p-2 no-underline transition-colors hover:bg-[var(--bg-hover)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-700">
                        <span class="sp-notif-item__title">{{ $view['title'] }}</span>
                        @if($message)
                            <span class="sp-notif-item__message">{{ $message }}</span>
                        @endif
                        <span class="sp-notif-item__time">
                            {{ $notification->created_at?->diffForHumans() ?? '' }}
                        </span>
                        </a>
                        @if ($notification->read_at === null)
                            <form method="POST" action="{{ route('seller.notifications.read', $notification->id) }}" class="mt-2">
                                @csrf
                                <button type="submit" class="text-xs font-semibold text-primary-700 underline">Mark as read</button>
                            </form>
                        @else
                            <span class="text-xs text-[var(--text-muted)]">Read</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
        <a href="{{ route('seller.notifications.index') }}" class="block border-t border-[var(--border-strong)] px-4 py-3 text-center text-sm font-semibold text-primary-700">View all notifications</a>
    </div>
</div>
