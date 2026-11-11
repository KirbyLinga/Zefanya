@props(['provider' => null, 'sections' => null])

@php
    $provider = $provider ?? auth('logistics')->user();
    // Nine items. Pages that belong to one job share an item and switch with
    // tabs (x-logistics.tabs); `active` keeps the item lit on every tab.
    // Notifications live in the bell shown at the top of every page.
    $sections ??= [
        ['label' => null, 'items' => [
            ['route' => 'logistics.scan.index', 'icon' => 'scan-line', 'label' => 'Scan parcel', 'primary' => true],
            ['route' => 'logistics.dashboard', 'icon' => 'layout-dashboard', 'label' => 'Dashboard'],
        ]],
        ['label' => 'Parcel flow', 'items' => [
            ['route' => 'logistics.incoming-parcels.index', 'icon' => 'inbox', 'label' => 'Incoming & sorting', 'active' => ['logistics.parcel-sorting.*']],
            ['route' => 'logistics.delivery-assignments.index', 'icon' => 'map-pin', 'label' => 'Deliveries', 'active' => ['logistics.delivery-monitoring.*']],
            ['route' => 'logistics.exceptions.index', 'icon' => 'alert-triangle', 'label' => 'Exceptions'],
        ]],
        ['label' => 'Network', 'items' => [
            ['route' => 'logistics.handoffs.index', 'icon' => 'repeat-2', 'label' => 'Transfers', 'active' => ['logistics.manifests.*', 'logistics.transfer-couriers.*']],
            ['route' => 'logistics.returns.index', 'icon' => 'undo-2', 'label' => 'Returns'],
            ['route' => 'logistics.riders.index', 'icon' => 'users', 'label' => 'Riders'],
        ]],
        ['label' => 'Money', 'items' => [
            ['route' => 'logistics.earnings.index', 'icon' => 'wallet', 'label' => 'Money', 'active' => ['logistics.cod-remittances.*']],
        ]],
    ];
    $setup = ['route' => 'logistics.hubs.index', 'icon' => 'settings-2', 'label' => 'Setup',
        'active' => ['logistics.transport-connections.*', 'logistics.partnerships.*', 'logistics.areas.*', 'logistics.audit-logs.*', 'logistics.shipping-rates.*']];
@endphp

<div class="w-16 shrink-0 md:w-auto">
    <button type="button" id="logisticsSidebarBackdrop" class="fixed inset-0 z-40 bg-black/30 md:hidden" aria-label="Close navigation" hidden></button>
    <aside class="fixed left-0 top-0 z-50 flex h-dvh w-[248px] max-w-[calc(100vw-24px)] flex-col overflow-hidden border-r border-[var(--sidebar-border)] bg-[var(--sidebar-bg)] text-[var(--sidebar-text)] transition-[width] duration-200 motion-reduce:transition-none [&.is-collapsed]:w-16 md:sticky md:z-20" id="logisticsSidebar">
        <x-logistics.sidebar-brand />

        <nav class="flex min-h-0 flex-1 flex-col gap-1 overflow-y-auto overscroll-contain px-3 py-4 [.is-collapsed_&]:px-2" id="logisticsNav" aria-label="Logistics navigation">
            <x-logistics.sidebar-nav :sections="$sections" />
        </nav>

        <div class="shrink-0 border-t border-[var(--sidebar-border)] px-3 py-2 [.is-collapsed_&]:px-2">
            <x-logistics.sidebar-nav-item :route="$setup['route']" :icon="$setup['icon']" :label="$setup['label']" :active="$setup['active']" />
        </div>

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
