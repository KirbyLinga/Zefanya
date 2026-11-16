{{--
    Logistics dashboard hero card.

    Props:
      $name  — logistics provider display name (business_name or fullName())
      $date  — formatted date string, e.g. "Monday, September 21, 2026"

    DOM order: body first (heading/subtitle/date), toolbar second.
    CSS (sp-hero: flex + justify-between + align-items: flex-start) places
    the body on the left and the toolbar on the right visually.
    The dashboard hosts the bell here so it sits in the banner, not in a
    separate row above it. Other logistics pages still use Layouts.logistics.
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

    {{-- ── Right: theme toggle, then the bell ── --}}
    <div class="sp-hero__toolbar ml-auto shrink-0">
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
        <x-logistics.notification-bell />
    </div>

</div>
