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
@props(['name', 'date'])

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

        {{-- Bell is the rightmost item; its dropdown anchors to the bell's right edge.
             No notifications exist for logistics yet — always the empty state. --}}
        <div class="sp-bell-wrapper" style="position: relative;">
            <button
                type="button"
                class="sp-icon-btn"
                id="lg-bell-btn"
                aria-haspopup="true"
                aria-expanded="false"
                aria-label="Notifications"
                data-bell-toggle
            >
                <i data-lucide="bell" class="w-[18px] h-[18px]" aria-hidden="true"></i>
            </button>

            <div
                class="sp-notif-dropdown"
                id="lg-notif-dropdown"
                role="region"
                aria-label="Notifications panel"
                hidden
            >
                <div class="sp-notif-dropdown__header">Notifications</div>
                <div class="sp-notif-empty" data-notif-empty>
                    <i data-lucide="bell-off" class="w-[28px] h-[28px]" style="color: var(--icon-soft);" aria-hidden="true"></i>
                    <p class="sp-notif-empty__title">You're all caught up</p>
                    <p class="sp-notif-empty__sub">Hub alerts will show up here.</p>
                </div>
            </div>
        </div>
    </div>

</div>
