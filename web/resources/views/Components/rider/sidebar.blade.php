@props([
    'rider' => null,
    'sections' => null,
])

@php
    // Default to the currently authenticated rider so the layout can simply
    // render <x-rider.sidebar /> with no props. Mirrors x-logistics.sidebar.
    $rider = $rider ?? auth('rider')->user();

    $sections ??= [
        [
            'label' => null,
            'items' => [
                ['route' => 'rider.dashboard', 'icon' => 'layout-dashboard', 'label' => 'Dashboard', 'match' => ['rider.dashboard']],
            ],
        ],
        [
            'label' => 'Assignments',
            'items' => [
                ['route' => 'rider.scan.index', 'icon' => 'scan-line', 'label' => 'Scan Parcel', 'match' => ['rider.scan.*']],
                ['route' => 'rider.pickups.index', 'icon' => 'package-search', 'label' => 'Pickup Assignments', 'match' => ['rider.pickups.*']],
                ['route' => 'rider.return-pickups.index', 'icon' => 'package-open', 'label' => 'Return Pickups', 'match' => ['rider.return-pickups.*']],
                ['route' => 'rider.deliveries.index', 'icon' => 'truck', 'label' => 'Delivery Assignments', 'match' => ['rider.deliveries.*']],
                ['route' => 'rider.history.index', 'icon' => 'history', 'label' => 'Delivery History', 'match' => ['rider.history.*']],
            ],
        ],
        [
            'label' => 'Settings',
            'items' => [
                ['route' => 'rider.account.show', 'icon' => 'user', 'label' => 'My Account', 'match' => ['rider.account.*']],
            ],
        ],
    ];
@endphp

<aside class="w-[240px] [&.is-collapsed]:w-[72px] bg-[var(--sidebar-bg)] text-[var(--sidebar-text)] flex flex-col flex-shrink-0 sticky top-0 h-[100dvh] overflow-hidden shadow-[2px_0_12px_rgba(192,122,133,0.06)] border-r border-[var(--sidebar-border)] transition-[width] duration-200 ease-out" id="riderSidebar">
    <x-rider.sidebar-brand />

    <nav class="flex flex-col gap-5 px-3 py-3 flex-1 overflow-y-auto" id="riderNav">
        <x-rider.sidebar-nav :sections="$sections" />
    </nav>

    <div class="px-3 py-3 [.is-collapsed_&]:px-2 border-t border-[var(--sidebar-border)]">
        <x-rider.sidebar-user-panel :rider="$rider" />
        <x-rider.sidebar-logout />
    </div>
</aside>

{{-- Sidebar collapse toggle: vanilla JS, no framework. Reads the persisted
     state immediately so there is no expand-flash on page load. --}}
<script>
    (function () {
        var KEY = 'rider.sidebar.collapsed';
        var sidebar = document.getElementById('riderSidebar');
        var toggle = document.getElementById('riderSidebarToggle');
        if (!sidebar || !toggle) { return; }

        function apply(collapsed) {
            sidebar.classList.toggle('is-collapsed', collapsed);
            toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            toggle.setAttribute('aria-label', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
            toggle.setAttribute('data-tooltip', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
        }

        var saved = null;
        try { saved = localStorage.getItem(KEY); } catch (e) { /* storage unavailable */ }
        apply(saved === '1');

        toggle.addEventListener('click', function () {
            var collapsed = !sidebar.classList.contains('is-collapsed');
            apply(collapsed);
            try { localStorage.setItem(KEY, collapsed ? '1' : '0'); } catch (e) { /* ignore */ }
        });
    })();
</script>
