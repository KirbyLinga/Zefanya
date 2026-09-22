@php
/**
 * Logistics › Dispatch & Operations sidebar.
 * Mirrors resources/views/Components/seller/sidebar.blade.php. Icons render via
 * the bundled lucide (loaded by Layouts/logistics), not inline SVG.
 * Future area routes fall back to "#" through Route::has() so a link never 404s
 * before its route exists — only `logistics.dashboard` is wired today.
 */
@endphp

<aside class="lg-sidebar" id="lgSidebar" aria-label="Logistics navigation">
    <div class="lg-brand">
        <span class="lg-brand-mark" aria-hidden="true">
            <i data-lucide="package" width="20" height="20"></i>
        </span>
        <span class="lg-brand-text">
            <span class="lg-brand-name">Zefanya</span>
            <span class="lg-brand-sub">Dispatch &amp; Operations</span>
        </span>
    </div>

    @php
        $sections = [
            ['label' => 'Operations Core', 'items' => [
                ['route' => 'logistics.dashboard', 'icon' => 'layout-dashboard', 'label' => 'Dashboard',          'badge' => null,  'dot' => false],
                ['route' => null, 'icon' => 'users',           'label' => 'Rider Management',   'badge' => '24',    'dot' => false],
                ['route' => null, 'icon' => 'clipboard-check', 'label' => 'Confirm &amp; Approve', 'badge' => '8 new', 'dot' => false, 'accent' => true],
                ['route' => null, 'icon' => 'inbox',           'label' => 'Incoming Parcels',    'badge' => null,    'dot' => false],
                ['route' => null, 'icon' => 'archive',         'label' => 'Parcel Sorting',     'badge' => null,    'dot' => false],
                ['route' => null, 'icon' => 'map-pin',         'label' => 'Delivery Assignment','badge' => null,    'dot' => false],
                ['route' => null, 'icon' => 'activity',        'label' => 'Delivery Monitoring', 'badge' => null,    'dot' => false],
            ]],
            ['label' => 'Intelligence &amp; Desk', 'items' => [
                ['route' => null, 'icon' => 'bar-chart-3',    'label' => 'Reports &amp; Analytics', 'badge' => null, 'dot' => false],
                ['route' => null, 'icon' => 'message-square', 'label' => 'Chat / Messaging',        'badge' => null, 'dot' => true],
                ['route' => null, 'icon' => 'settings',       'label' => 'Account Management',    'badge' => null, 'dot' => false],
            ]],
        ];
    @endphp

    <nav class="lg-nav" aria-label="Main operations">
        @foreach ($sections as $section)
            @if (! empty($section['label']))
                <p class="lg-nav-label">{{ $section['label'] }}</p>
            @endif

            @foreach ($section['items'] as $item)
                @php
                    $hasRoute = ! empty($item['route']) && \Illuminate\Support\Facades\Route::has($item['route']);
                    $href     = $hasRoute ? route($item['route']) : '#';
                    $isActive = $hasRoute ? request()->routeIs($item['route'].'*') : false;
                @endphp
                <a href="{{ $href }}"
                   class="lg-nav-link{{ $isActive ? ' is-active' : '' }}"
                   @if ($item['route'] === 'logistics.dashboard') aria-current="page" @endif
                   aria-label="{{ $item['label'] }}{{ $item['badge'] ? ', '.$item['badge'] : '' }}"
                   data-tooltip="{{ $item['label'] }}"
                   data-lg-sidebar-item>
                    <span class="lg-nav-icon"><i data-lucide="{{ $item['icon'] }}" width="18" height="18"></i></span>
                    <span class="lg-nav-text">{{ $item['label'] }}</span>
                    @if (! empty($item['badge']))
                        <span class="lg-nav-badge{{ ($item['accent'] ?? false) ? ' lg-nav-badge--accent' : '' }}" aria-hidden="true">{{ $item['badge'] }}</span>
                    @endif
                    @if (! empty($item['dot']))
                        <span class="lg-nav-dot" aria-hidden="true"></span>
                    @endif
                </a>
            @endforeach
        @endforeach
    </nav>

    <div class="lg-sidebar-foot">
        <form method="POST" action="{{ route('unified.logout') }}">
            @csrf
            <button type="submit"
                    class="lg-nav-link lg-nav-signout"
                    data-tooltip="Sign Out"
                    aria-label="Sign Out">
                <span class="lg-nav-icon"><i data-lucide="log-out" width="18" height="18"></i></span>
                <span class="lg-nav-text">Sign Out</span>
            </button>
        </form>
    </div>
</aside>
