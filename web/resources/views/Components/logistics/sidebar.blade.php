@props([
    'provider' => null,
    'sections' => null,
])

@php
    // Default to the currently authenticated logistics provider so the layout
    // can simply render <x-logistics.sidebar /> with no props. Both props are
    // overridable so other logistics surfaces can reuse the sidebar with a
    // custom provider or a trimmed nav. Mirrors x-seller.sidebar 1:1.
    $provider = $provider ?? auth('logistics')->user();

    // Only logistics.dashboard is wired today; the other areas fall back to
    // "#" through Route::has() so a link never 404s before its route exists.
    // No fake badges/counts — every area without backing data renders plain.
    $sections ??= [
        [
            'label' => null,
            'items' => [
                ['route' => 'logistics.dashboard', 'icon' => 'layout-dashboard', 'label' => 'Dashboard'],
            ],
        ],
        [
            'label' => 'Operations',
            'items' => [
                ['route' => null, 'icon' => 'users', 'label' => 'Rider Management'],
                ['route' => null, 'icon' => 'clipboard-check', 'label' => 'Courier Applications'],
                ['route' => null, 'icon' => 'inbox', 'label' => 'Incoming Parcels'],
                ['route' => null, 'icon' => 'archive', 'label' => 'Parcel Sorting'],
                ['route' => null, 'icon' => 'map-pin', 'label' => 'Delivery Assignment'],
                ['route' => null, 'icon' => 'activity', 'label' => 'Delivery Monitoring'],
            ],
        ],
        [
            'label' => 'Settings',
            'items' => [
                ['route' => null, 'icon' => 'user', 'label' => 'Account'],
            ],
        ],
    ];
@endphp

<aside class="w-[240px] [&.is-collapsed]:w-[72px] bg-[var(--sidebar-bg)] text-[var(--sidebar-text)] flex flex-col flex-shrink-0 sticky top-0 h-[100dvh] overflow-hidden shadow-[2px_0_12px_rgba(192,122,133,0.06)] border-r border-[var(--sidebar-border)] transition-[width] duration-200 ease-out" id="logisticsSidebar">
    <x-logistics.sidebar-brand />

    <nav class="flex flex-col gap-5 px-3 py-3 flex-1" id="logisticsNav">
        <x-logistics.sidebar-nav :sections="$sections" />
    </nav>

    <div class="px-3 py-3 [.is-collapsed_&]:px-2 border-t border-[var(--sidebar-border)]">
        <x-logistics.sidebar-user-panel :provider="$provider" />
        <x-logistics.sidebar-logout />
    </div>
</aside>

{{-- Sidebar collapse toggle: vanilla JS, no framework. Reads the persisted
     state from localStorage immediately so there is no expand-flash on
     page load, then wires the hamburger button. Mirrors x-seller.sidebar. --}}
<script>
    (function () {
        var KEY = 'logistics.sidebar.collapsed';
        var sidebar = document.getElementById('logisticsSidebar');
        var toggle = document.getElementById('logisticsSidebarToggle');
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
