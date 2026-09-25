@props([
    'seller' => null,
    'sections' => null,
])

@php
    // Default to the currently authenticated seller so the layout can simply
    // render <x-seller.sidebar /> with no props. Both props are overridable so
    // other seller-module surfaces can reuse the sidebar with a custom seller
    // or a trimmed nav.
    $seller = $seller ?? auth('seller')->user();

    $sections ??= [
        [
            'label' => null,
            'items' => [
                ['route' => 'seller.dashboard', 'icon' => 'layout-dashboard', 'label' => 'Dashboard'],
            ],
        ],
        [
            'label' => 'Catalog',
            'items' => [
                ['route' => 'seller.products.index', 'icon' => 'package', 'label' => 'Products'],
                ['route' => 'seller.vouchers.index', 'icon' => 'tag', 'label' => 'Discounts & Vouchers'],
            ],
        ],
        [
            'label' => 'Sales',
            'items' => [
                ['route' => 'seller.orders.index', 'icon' => 'shopping-bag', 'label' => 'Orders'],
                ['route' => 'seller.shipments.index', 'icon' => 'truck', 'label' => 'Shipments'],
                ['route' => 'seller.reports.index', 'icon' => 'bar-chart-3', 'label' => 'Reports'],
            ],
        ],
        [
            'label' => 'Customers',
            'items' => [
                ['route' => 'seller.feedback.index', 'icon' => 'star', 'label' => 'Feedback'],
                ['route' => 'seller.chat.index', 'icon' => 'message-circle', 'label' => 'Chat'],
            ],
        ],
        [
            'label' => 'Settings',
            'items' => [
                ['route' => 'seller.account.index', 'icon' => 'user', 'label' => 'Account'],
            ],
        ],
    ];
@endphp

<aside class="w-[240px] [&.is-collapsed]:w-[72px] bg-[var(--sidebar-bg)] text-[var(--sidebar-text)] flex flex-col flex-shrink-0 sticky top-0 h-[100dvh] overflow-hidden shadow-[2px_0_12px_rgba(192,122,133,0.06)] border-r border-[var(--sidebar-border)] transition-[width] duration-200 ease-out" id="sellerSidebar">
    <x-seller.sidebar-brand />

    <nav class="flex flex-col gap-5 px-3 py-3 flex-1" id="sellerNav">
        <x-seller.sidebar-nav :sections="$sections" />
    </nav>

    <div class="px-3 py-3 [.is-collapsed_&]:px-2 border-t border-[var(--sidebar-border)]">
        <x-seller.sidebar-user-panel :seller="$seller" />
        <x-seller.sidebar-logout />
    </div>
</aside>

{{-- Sidebar collapse toggle: vanilla JS, no framework. Reads the persisted
     state from localStorage immediately so there is no expand-flash on
     page load, then wires the hamburger button. --}}
<script>
    (function () {
        var KEY = 'seller.sidebar.collapsed';
        var sidebar = document.getElementById('sellerSidebar');
        var toggle = document.getElementById('sellerSidebarToggle');
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
