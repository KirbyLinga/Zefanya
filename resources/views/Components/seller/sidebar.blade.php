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
                ['route' => 'seller.inventory.index', 'icon' => 'boxes', 'label' => 'Inventory'],
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

<aside class="seller-sidebar" id="sellerSidebar">
    <x-seller.sidebar-brand />

    <nav class="seller-nav" id="sellerNav">
        <x-seller.sidebar-nav :sections="$sections" />
    </nav>

    <div class="seller-sidebar__footer">
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
