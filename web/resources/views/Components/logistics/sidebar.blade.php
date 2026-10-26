@props(['provider' => null, 'sections' => null])

@php
    $provider = $provider ?? auth('logistics')->user();
    $sections ??= [
        ['label' => null, 'items' => [
            ['route' => 'logistics.dashboard', 'icon' => 'layout-dashboard', 'label' => 'Dashboard'],
            ['route' => 'logistics.scan.index', 'icon' => 'scan-line', 'label' => 'Scan parcel'],
        ]],
        ['label' => 'Daily operations', 'items' => [
            ['route' => 'logistics.incoming-parcels.index', 'icon' => 'inbox', 'label' => 'Incoming parcels'],
            ['route' => 'logistics.parcel-sorting.index', 'icon' => 'archive', 'label' => 'Parcel sorting'],
            ['route' => 'logistics.delivery-assignments.index', 'icon' => 'map-pin', 'label' => 'Assign deliveries'],
            ['route' => 'logistics.delivery-monitoring.index', 'icon' => 'activity', 'label' => 'Delivery tracking'],
        ]],
        ['label' => 'More tools', 'items' => [
            ['route' => 'logistics.cod-remittances.index', 'icon' => 'banknote', 'label' => 'COD remittances'],
        ]],
        ['label' => 'Transfers & returns', 'icon' => 'repeat-2', 'collapsible' => true, 'items' => [
            ['route' => 'logistics.handoffs.index', 'icon' => 'repeat-2', 'label' => 'Provider handoffs'],
            ['route' => 'logistics.manifests.index', 'icon' => 'truck', 'label' => 'Manifests'],
            ['route' => 'logistics.returns.index', 'icon' => 'undo-2', 'label' => 'Physical returns'],
        ]],
        ['label' => 'Team & network', 'icon' => 'network', 'collapsible' => true, 'items' => [
            ['route' => 'logistics.riders.index', 'icon' => 'users', 'label' => 'Riders'],
            ['route' => 'logistics.hubs.index', 'icon' => 'network', 'label' => 'Hubs & stations'],
            ['route' => 'logistics.partnerships.index', 'icon' => 'handshake', 'label' => 'Provider partnerships'],
            ['route' => 'logistics.areas.index', 'icon' => 'map', 'label' => 'Delivery areas'],
        ]],
    ];
@endphp

<div class="w-16 shrink-0 md:w-auto">
    <button type="button" id="logisticsSidebarBackdrop" class="fixed inset-0 z-40 bg-black/30 md:hidden" aria-label="Close navigation" hidden></button>
    <aside class="fixed left-0 top-0 z-50 flex h-dvh w-[248px] max-w-[calc(100vw-24px)] flex-col overflow-hidden border-r border-[var(--sidebar-border)] bg-[var(--sidebar-bg)] text-[var(--sidebar-text)] transition-[width] duration-200 motion-reduce:transition-none [&.is-collapsed]:w-16 md:sticky md:z-20" id="logisticsSidebar">
        <x-logistics.sidebar-brand />

        <nav class="flex min-h-0 flex-1 flex-col gap-1 overflow-y-auto overscroll-contain px-3 py-4 [.is-collapsed_&]:px-2" id="logisticsNav" aria-label="Logistics navigation">
            <x-logistics.sidebar-nav :sections="$sections" />
        </nav>

        <div class="shrink-0 border-t border-[var(--sidebar-border)] px-3 py-3 [.is-collapsed_&]:px-2">
            <x-logistics.sidebar-user-panel :provider="$provider" />
            <x-logistics.sidebar-logout />
        </div>
    </aside>
</div>

<script>
(function () {
    var KEY = 'logistics.sidebar.collapsed';
    var sidebar = document.getElementById('logisticsSidebar');
    var toggle = document.getElementById('logisticsSidebarToggle');
    var backdrop = document.getElementById('logisticsSidebarBackdrop');
    var mobile = window.matchMedia('(max-width: 767px)');
    if (!sidebar || !toggle) return;

    function apply(collapsed, persist) {
        sidebar.classList.toggle('is-collapsed', collapsed);
        toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        toggle.setAttribute('aria-label', collapsed ? 'Expand navigation' : 'Collapse navigation');
        toggle.setAttribute('data-tooltip', collapsed ? 'Expand navigation' : 'Collapse navigation');
        backdrop.hidden = collapsed || !mobile.matches;
        if (persist && !mobile.matches) {
            try { localStorage.setItem(KEY, collapsed ? '1' : '0'); } catch (e) {}
        }
    }

    function restore() {
        var saved = null;
        try { saved = localStorage.getItem(KEY); } catch (e) {}
        apply(mobile.matches || saved === '1', false);
    }

    restore();
    toggle.addEventListener('click', function () {
        apply(!sidebar.classList.contains('is-collapsed'), true);
    });
    backdrop.addEventListener('click', function () {
        apply(true, false);
        toggle.focus();
    });
    sidebar.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && mobile.matches) {
            apply(true, false);
            toggle.focus();
        }
    });
    sidebar.querySelectorAll('[data-nav-group]').forEach(function (group) {
        var summary = group.querySelector('summary');
        summary.addEventListener('click', function (event) {
            if (sidebar.classList.contains('is-collapsed')) {
                event.preventDefault();
                apply(false, true);
                group.open = true;
            }
        });
        group.addEventListener('toggle', function () {
            summary.setAttribute('aria-expanded', group.open ? 'true' : 'false');
        });
    });
    mobile.addEventListener('change', restore);
})();
</script>
