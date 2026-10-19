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
        aria-haspopup="true"
        aria-expanded="false"
        aria-label="Notifications"
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
        <div class="sp-notif-dropdown__header">Notifications</div>

        @if($notifications->isEmpty())
            <div class="sp-notif-empty" data-notif-empty>
                <i data-lucide="bell-off" class="w-[28px] h-[28px]" style="color: var(--icon-soft);" aria-hidden="true"></i>
                <p class="sp-notif-empty__title">You're all caught up</p>
                <p class="sp-notif-empty__sub">New order alerts will show up here.</p>
            </div>
        @else
            <ul style="list-style: none; margin: 0; padding: 0;" role="list">
                @foreach($notifications as $notification)
                    @php
                        $data    = $notification->data ?? [];
                        $title   = $data['title']   ?? 'Notification';
                        $message = $data['message'] ?? '';
                    @endphp
                    <li class="sp-notif-item" role="listitem">
                        <span class="sp-notif-item__title">{{ $title }}</span>
                        @if($message)
                            <span class="sp-notif-item__message">{{ $message }}</span>
                        @endif
                        <span class="sp-notif-item__time">
                            {{ $notification->created_at?->diffForHumans() ?? '' }}
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
