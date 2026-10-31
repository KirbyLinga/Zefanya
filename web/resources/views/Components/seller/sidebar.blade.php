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

    // Calculate unread message count if seller exists
    $unreadMessagesCount = 0;
    if ($seller) {
        $unreadMessagesCount = \App\Models\Shared\Message::whereIn('conversation_id', $seller->conversations()->pluck('id'))
            ->where('sender_role', 'buyer')
            ->where('read_at', null)
            ->count();
    }

    $sections ??= [
        [
            'label' => null,
            'items' => [
                ['route' => 'seller.dashboard', 'icon' => 'layout-dashboard', 'label' => 'Dashboard'],
                ['route' => 'seller.notifications.index', 'icon' => 'bell', 'label' => 'Notifications'],
            ],
        ],
        [
            'label' => 'Catalog',
            'icon' => 'package',
            'collapsible' => true,
            'items' => [
                ['route' => 'seller.products.index', 'icon' => 'package', 'label' => 'Products'],
                ['route' => 'seller.store-categories.index', 'icon' => 'folders', 'label' => 'Sub-categories'],
            ],
        ],
        [
            'label' => 'Promotions',
            'icon' => 'tag',
            'collapsible' => true,
            'items' => [
                ['route' => 'seller.store-sales.index', 'icon' => 'percent', 'label' => 'Store Sales'],
                ['route' => 'seller.promotions.index', 'icon' => 'gift', 'label' => 'Bundle and Buy X Get Y'],
                ['route' => 'seller.vouchers.index', 'icon' => 'tag', 'label' => 'Discounts & Vouchers'],
            ],
        ],
        [
            'label' => 'Sales',
            'icon' => 'shopping-bag',
            'collapsible' => true,
            'items' => [
                ['route' => 'seller.orders.index', 'icon' => 'shopping-bag', 'label' => 'Orders'],
                ['route' => 'seller.shipments.index', 'icon' => 'truck', 'label' => 'Shipments'],
                ['route' => 'seller.returns.index', 'icon' => 'undo-2', 'label' => 'Incoming Returns'],
            ],
        ],
        [
            'label' => 'Customers',
            'icon' => 'message-circle',
            'collapsible' => true,
            'items' => [
                ['route' => 'seller.messages.index', 'icon' => 'message-circle', 'label' => 'Messages', 'badge' => $unreadMessagesCount > 0 ? $unreadMessagesCount : null],
                ['route' => 'seller.feedback.index', 'icon' => 'star', 'label' => 'Feedback'],
            ],
        ],
        [
            'label' => 'Finance',
            'icon' => 'banknote',
            'collapsible' => true,
            'items' => [
                ['route' => 'seller.earnings.index', 'icon' => 'banknote', 'label' => 'Earnings'],
                ['route' => 'seller.payout-requests.index', 'icon' => 'wallet', 'label' => 'Payout Requests'],
                ['route' => 'seller.reports.index', 'icon' => 'bar-chart-3', 'label' => 'Reports'],
            ],
        ],
        [
            'label' => 'Settings',
            'icon' => 'settings',
            'collapsible' => true,
            'items' => [
                ['route' => 'seller.shipping-providers.index', 'icon' => 'network', 'label' => 'Shipping Providers'],
                ['route' => 'seller.account.index', 'icon' => 'user', 'label' => 'Account'],
            ],
        ],
    ];
@endphp

<aside class="w-[240px] [&.is-collapsed]:w-[72px] bg-[var(--sidebar-bg)] text-[var(--sidebar-text)] flex flex-col flex-shrink-0 sticky top-0 h-[100dvh] overflow-hidden shadow-[2px_0_12px_rgba(192,122,133,0.06)] border-r border-[var(--sidebar-border)] transition-[width] duration-200 ease-out" id="sellerSidebar">
    <x-seller.sidebar-brand />

    <nav class="flex min-h-0 flex-1 flex-col gap-1 overflow-y-auto overscroll-contain px-3 py-3" id="sellerNav">
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

        // On the icon rail a group has no room to open, so a click widens the
        // sidebar first and then opens that group.
        sidebar.querySelectorAll('[data-nav-group]').forEach(function (group) {
            var summary = group.querySelector('summary');
            summary.addEventListener('click', function (event) {
                if (!sidebar.classList.contains('is-collapsed')) { return; }
                event.preventDefault();
                apply(false);
                try { localStorage.setItem(KEY, '0'); } catch (e) { /* ignore */ }
                group.open = true;
            });
            group.addEventListener('toggle', function () {
                summary.setAttribute('aria-expanded', group.open ? 'true' : 'false');
            });
        });
    })();
</script>
