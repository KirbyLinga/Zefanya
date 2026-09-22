@php
    /** @var array{prefix:string} $route */
@endphp

<header class="lg-topbar" role="banner">
    <div class="lg-search-wrap">
        <svg class="lg-search-icon" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
        <input type="search" class="lg-search-input" placeholder="Search parcels, orders, couriers..." aria-label="Search parcels, orders, couriers" />
    </div>

    @php
        $sync = 'Hub 04 · Synchronized';
    @endphp
    <span class="lg-sync-pill" aria-label="{{ $sync }}">
        <span class="lg-sync-dot" aria-hidden="true"></span>
        <span class="lg-sync-text">{{ $sync }}</span>
    </span>

    {{-- Light/dark toggle. Same class names / localStorage key as the seller
         panel so the existing theme.css icon-swap rules apply identically. --}}
    <button type="button" data-theme-toggle class="zf-theme-toggle lg-theme-toggle" aria-pressed="false" aria-label="Switch to dark mode">
        <svg class="lg-toggle-icon lg-icon zf-icon-moon" viewBox="0 0 24 24" aria-hidden="true">
            <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
        </svg>
        <svg class="lg-toggle-icon lg-icon zf-icon-sun" viewBox="0 0 24 24" aria-hidden="true">
            <circle cx="12" cy="12" r="5"/><path d="M12 1v4M12 19v4M4 12h4M16 12h4M6.5 6.5 9 9m6 6 2.5 2.5M6.5 17.5 9 15m6 0 2.5-2.5"/>
        </svg>
    </button>

    <button type="button" class="lg-icon-btn lg-bell-btn" aria-label="Notifications" aria-haspopup="true" aria-expanded="false">
        <svg class="lg-bell-icon lg-icon" viewBox="0 0 24 24" aria-hidden="true">
            <path d="M18 8a6 6 0 0 0-12 0c0 4.5-3 6.5-3 9h18s-3-2.5-3-9"/><path d="M10.26 21a2 2 0 1 0 3.46 0"/>
        </svg>
        <span class="lg-bell-dot" aria-hidden="true"></span>
    </button>

    <div class="lg-user" aria-label="Claire M., Operations Lead">
        <span class="lg-avatar" aria-hidden="true">CM</span>
        <div class="lg-user-info">
            <span class="lg-user-name">Claire M.</span>
            <span class="lg-user-role">Operations Lead</span>
        </div>
    </div>
</header>
