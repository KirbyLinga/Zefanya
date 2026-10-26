{{--
    Logistics dashboard hero card.

    Props:
      $name  — logistics provider display name (business_name or fullName())
      $date  — formatted date string, e.g. "Monday, September 21, 2026"

    DOM order: body first (heading/subtitle/date), toolbar second.
    CSS (sp-hero: flex + justify-between + align-items: flex-start) places
    the body on the left and the toolbar on the right visually.
    Mirrors x-seller.dashboard-hero 1:1; the bell intentionally renders the
    honest empty dropdown — there is no notifications source for logistics yet.
--}}
@props(['name', 'date', 'notifications' => collect(), 'unreadCount' => 0])

<div class="sp-hero" data-dashboard-hero>

    {{-- ── Left: heading + subtitle + date chip ── --}}
    <div class="sp-hero__body">
        <h1 class="sp-hero__heading">
            Welcome back, {{ $name }}
        </h1>
        <p class="sp-hero__subtitle">
            Here's what's happening at your hub today.
        </p>
        <div class="sp-hero__date-chip">
            <i data-lucide="calendar" class="w-[13px] h-[13px] flex-shrink-0" aria-hidden="true"></i>
            <span>{{ $date }}</span>
        </div>
    </div>

    {{-- ── Right: toolbar — theme toggle then notification bell ── --}}
    <div class="sp-hero__toolbar">
        <button
            type="button"
            data-theme-toggle
            class="sp-icon-btn zf-theme-toggle"
            aria-label="Switch to dark mode"
            aria-pressed="false"
        >
            <i data-lucide="moon" class="zf-icon-moon w-[18px] h-[18px] block" aria-hidden="true"></i>
            <i data-lucide="sun"  class="zf-icon-sun  w-[18px] h-[18px] block" aria-hidden="true"></i>
        </button>

        <div id="lg-notification-bell" class="relative" data-recent-url="{{ route('logistics.notifications.recent') }}">
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
                <div class="border-t border-[var(--border-strong)] px-4 py-3">
                    <form method="POST" action="{{ route('logistics.notifications.readAll') }}" data-lg-mark-all @if ($unreadCount <= 0) hidden @endif>
                        @csrf
                        <button type="submit" class="font-sans text-xs font-semibold text-primary-700 hover:underline">Mark all as read</button>
                    </form>
                    <p data-notification-error role="status" class="mt-2 font-sans text-xs text-red-700" hidden></p>
                </div>
            </div>
        </div>
    </div>

</div>
