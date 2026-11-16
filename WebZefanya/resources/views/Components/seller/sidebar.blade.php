@props([
    'seller' => null,
    'sections' => null,
    'tabsOnly' => false,
])

@php
    // Default to the currently authenticated seller so the layout can simply
    // render <x-seller.sidebar /> with no props. Both props are overridable so
    // other seller-module surfaces can reuse the sidebar with a custom seller
    // or a trimmed nav.
    $seller = $seller ?? auth('seller')->user();

    // Calculate unread message count if seller exists
    $unreadMessagesCount = 0;
    $newOrdersCount = 0;
    if ($seller && ! $tabsOnly) {
        // Orders still waiting on the seller: the same statuses as the dashboard's "Pending orders".
        $newOrdersCount = \App\Models\Shared\SellerOrder::query()
            ->where('seller_id', $seller->id)
            ->whereIn('status', [
                \App\Enums\OrderStatus::Placed->value,
                \App\Enums\OrderStatus::Confirmed->value,
                \App\Enums\OrderStatus::Preparing->value,
            ])->count();
        $unreadMessagesCount = \App\Models\Shared\Message::whereIn('conversation_id', $seller->conversations()->pluck('id'))
            ->where('sender_role', 'buyer')
            ->where('read_at', null)
            ->count();
    }

    // Same shape as the logistics sidebar: labelled groups, one icon per page.
    $sections ??= [
        ['label' => null, 'items' => [
            ['route' => 'seller.dashboard', 'icon' => 'layout-dashboard', 'label' => 'Dashboard'],
            ['route' => 'seller.notifications.index', 'icon' => 'bell', 'label' => 'Notifications'],
        ]],
        ['label' => 'Catalog', 'items' => [
            ['route' => 'seller.products.index', 'icon' => 'package', 'label' => 'All Products'],
            ['route' => 'seller.store-categories.index', 'icon' => 'tags', 'label' => 'Categories'],
        ]],
        ['label' => 'Orders', 'items' => [
            ['route' => 'seller.orders.index', 'icon' => 'shopping-cart', 'label' => 'All Orders', 'badge' => $newOrdersCount, 'badgeNoun' => 'new orders'],
            ['route' => 'seller.shipments.index', 'icon' => 'truck', 'label' => 'Shipments'],
            ['route' => 'seller.returns.index', 'icon' => 'undo-2', 'label' => 'Returns & Refunds'],
            ...(app(\App\Services\Buyer\ConsumerClaimAvailability::class)->enabled() ? [['route' => 'seller.consumer-claims.index', 'icon' => 'file-text', 'label' => 'Consumer Claims']] : []),
        ]],
        ['label' => 'Customers', 'items' => [
            ['route' => 'seller.messages.index', 'icon' => 'message-circle', 'label' => 'Messages', 'badge' => $unreadMessagesCount, 'badgeNoun' => 'unread messages'],
            ['route' => 'seller.feedback.index', 'icon' => 'star', 'label' => 'Reviews'],
        ]],
        ['label' => 'Promotions', 'items' => [
            ['route' => 'seller.discounts.index', 'icon' => 'megaphone', 'label' => 'Discounts & Vouchers', 'active' => ['seller.vouchers.*', 'seller.store-sales.*']],
        ]],
        ['label' => 'Finance', 'items' => [
            ['route' => 'seller.earnings.index', 'icon' => 'wallet', 'label' => 'Earnings'],
            ['route' => 'seller.payout-requests.index', 'icon' => 'banknote', 'label' => 'Payout Requests'],
            ['route' => 'seller.reports.index', 'icon' => 'bar-chart-3', 'label' => 'Reports'],
        ]],
    ];
    $setup = ['route' => 'seller.account.index', 'icon' => 'settings-2', 'label' => 'Store Settings',
        'active' => ['seller.shipping-providers.*']];

    // Match the whole route family, including create, edit and detail pages.
    $matchesItem = function ($item) {
        $route = $item['route'] ?? '';
        $pattern = str_ends_with($route, '.index') ? substr($route, 0, -5).'*' : $route;

        return $route !== '' && request()->routeIs($pattern);
    };
    // Every other page is listed in the sidebar, so tabs only join the two Store Settings pages.
    $tabSections = [['label' => 'Store Settings', 'items' => [
        ['route' => 'seller.account.index', 'label' => 'Store Profile'],
        ['route' => 'seller.shipping-providers.index', 'label' => 'Shipping Providers'],
    ]]];
    $activeSection = collect($tabSections)->first(fn ($section) => collect($section['items'])->contains($matchesItem));

@endphp

@if ($tabsOnly)
    @if ($activeSection && count($activeSection['items']) > 1)
        <nav aria-label="{{ $activeSection['label'] }} pages" class="mb-6 flex flex-wrap gap-2 border-b border-[var(--border-strong)] pb-3">
            @foreach ($activeSection['items'] as $item)
                <a href="{{ route($item['route']) }}"
                   @if ($matchesItem($item)) aria-current="page" @endif
                   class="inline-flex min-h-11 items-center rounded-md px-4 py-2 font-sans text-sm font-medium no-underline transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--sidebar-active-border)] {{ $matchesItem($item) ? 'bg-[var(--sidebar-active-bg)] text-[var(--sidebar-active-text)]' : 'text-[var(--text-muted)] hover:bg-[var(--sidebar-hover)] hover:text-[var(--text-ink)]' }}">
                    {{ $item['label'] }}
                </a>
            @endforeach
        </nav>
    @endif
@else
{{-- Below lg the sidebar is a drawer opened from the layout's top bar. --}}
<div id="sellerSidebarBackdrop" class="fixed inset-0 z-40 hidden bg-black/40 lg:hidden"></div>
<aside class="w-[248px] [&.is-collapsed]:w-16 bg-[var(--sidebar-bg)] text-[var(--sidebar-text)] flex flex-col flex-shrink-0 sticky top-0 h-[100dvh] overflow-hidden border-r border-[var(--sidebar-border)] transition-[width,translate] duration-200 ease-out max-lg:fixed max-lg:inset-y-0 max-lg:left-0 max-lg:z-50 max-lg:-translate-x-full max-lg:[&.is-open]:translate-x-0" id="sellerSidebar">
    <x-seller.sidebar-brand />

    <nav class="flex min-h-0 flex-1 flex-col gap-1 overflow-y-auto overscroll-contain px-3 py-4 [.is-collapsed_&]:px-2" id="sellerNav" aria-label="Seller navigation">
        <x-seller.sidebar-nav :sections="$sections" />
    </nav>

    <div class="shrink-0 border-t border-[var(--sidebar-border)] px-3 py-2 [.is-collapsed_&]:px-2">
        <x-seller.sidebar-nav-item :route="$setup['route']" :icon="$setup['icon']" :label="$setup['label']" :active="$setup['active']" />
    </div>

    <div class="shrink-0 border-t border-[var(--sidebar-border)] px-3 py-3 [.is-collapsed_&]:px-2">
        <x-seller.sidebar-user-panel :seller="$seller" />
        <x-seller.sidebar-logout />
    </div>
</aside>

{{-- Sidebar collapse toggle: vanilla JS, no framework. Reads the persisted
     state from localStorage immediately so there is no expand-flash on
     page load, then wires the hamburger button. --}}
@include('Components.mobile-drawer-accessibility')
<script>
    (function () {
        var KEY = 'seller.sidebar.collapsed';
        var sidebar = document.getElementById('sellerSidebar');
        var toggle = document.getElementById('sellerSidebarToggle');
        if (!sidebar || !toggle || sidebar.dataset.drawerInitialized === '1') { return; }
        sidebar.dataset.drawerInitialized = '1';

        var drawer = window.matchMedia('(max-width: 1023.98px)');
        var backdrop = document.getElementById('sellerSidebarBackdrop');
        var accessibility = window.zfCreateMobileDrawer(sidebar, drawer, {
            label: 'Seller navigation', hideWhenClosed: true,
            opener: function () { return document.getElementById('sellerSidebarOpen'); },
            close: function () { setDrawer(false); }
        });

        function setDrawer(open, restoreFocus) {
            // The opener is in the layout's top bar, which renders after this script.
            var opener = document.getElementById('sellerSidebarOpen');
            sidebar.classList.toggle('is-open', open);
            backdrop.classList.toggle('hidden', !open);
            if (opener) { opener.setAttribute('aria-expanded', open ? 'true' : 'false'); }
            accessibility.sync(open, restoreFocus !== false);
        }

        function apply(collapsed) {
            // The icon rail is a desktop state; the drawer always shows full labels.
            sidebar.classList.toggle('is-collapsed', collapsed && !drawer.matches);
            toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            toggle.setAttribute('aria-label', drawer.matches ? 'Close menu' : (collapsed ? 'Expand sidebar' : 'Collapse sidebar'));
            toggle.setAttribute('data-tooltip', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
        }

        var saved = null;
        try { saved = localStorage.getItem(KEY); } catch (e) { /* storage unavailable */ }
        apply(saved === '1');
        setDrawer(false, false);

        toggle.addEventListener('click', function () {
            if (drawer.matches) { setDrawer(false); return; }
            var collapsed = !sidebar.classList.contains('is-collapsed');
            apply(collapsed);
            try { localStorage.setItem(KEY, collapsed ? '1' : '0'); } catch (e) { /* ignore */ }
        });

        document.addEventListener('click', function (event) {
            if (event.target.closest('#sellerSidebarOpen')) { setDrawer(true); }
        });
        backdrop.addEventListener('click', function () { setDrawer(false); });
        drawer.addEventListener('change', function () {
            setDrawer(false, drawer.matches);
            var current = null;
            try { current = localStorage.getItem(KEY); } catch (e) { /* storage unavailable */ }
            apply(current === '1');
        });

    })();
</script>

@endif
