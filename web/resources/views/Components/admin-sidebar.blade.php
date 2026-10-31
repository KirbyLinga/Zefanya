@php
    $navLink = 'flex min-h-11 items-center gap-3 rounded-md px-3 py-2 font-sans text-[13px] font-medium text-[var(--sidebar-text)] no-underline transition-colors hover:bg-[var(--sidebar-hover)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--sidebar-active-border)] [&>span]:text-inherit [&>i]:size-[18px] [.is-collapsed_&]:justify-center [.is-collapsed_&]:gap-0 [.is-collapsed_&]:px-0 [.is-collapsed_&>span]:hidden';
    $admin = auth('admin')->user();
    // Mirrors routes/admin.php: support only has the dashboard and its own 2FA page.
    $canManage = in_array($admin?->role, ['admin', 'super_admin'], true);
    $canModerate = $canManage || $admin?->role === 'moderator';
    $heading = 'mt-4 px-3 pb-1 font-sans text-[10px] font-semibold uppercase tracking-[0.12em] text-[var(--sidebar-muted)] [.is-collapsed_&]:hidden';
    $hasRegistrations = \Illuminate\Support\Facades\Schema::hasTable('buyers');
    $pendingRegistrations = 0;
    if ($canModerate && $hasRegistrations) {
        $pendingRegistrations = \App\Models\Buyer\Buyer::where('status', 'pending')->count()
            + \App\Models\Seller\Seller::where('status', 'pending')->count()
            + (\Illuminate\Support\Facades\Schema::hasTable('logistics_providers')
                ? \App\Models\Logistics\LogisticsProvider::where('status', 'pending')->count()
                : 0);
    }
    $hasNetwork = \Illuminate\Support\Facades\Schema::hasTable('logistics_hubs');
    $pendingNetwork = 0;
    if ($canModerate && $hasNetwork) {
        $pendingNetwork = \App\Models\Logistics\LogisticsHub::where('approval_status', 'pending')->count()
            + (\Illuminate\Support\Facades\Schema::hasTable('logistics_provider_links')
                ? \App\Models\Logistics\LogisticsProviderLink::where('status', \App\Models\Logistics\LogisticsProviderLink::PENDING)->count()
                : 0);
    }
@endphp
<div class="w-16 shrink-0 md:w-auto">
<button type="button" id="adminSidebarBackdrop" class="fixed inset-0 z-40 bg-black/30 md:hidden" aria-label="Close navigation" hidden></button>
<aside id="adminSidebar" class="fixed left-0 top-0 z-50 flex h-dvh w-[248px] max-w-[calc(100vw-24px)] flex-col overflow-hidden border-r border-[var(--sidebar-border)] bg-[var(--sidebar-bg)] text-[var(--sidebar-text)] transition-[width] duration-200 motion-reduce:transition-none [&.is-collapsed]:w-16 md:sticky md:z-20">
    <div class="flex shrink-0 items-center gap-3 border-b border-[var(--sidebar-border)] px-4 py-4 [.is-collapsed_&]:justify-center [.is-collapsed_&]:px-2">
        <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-[var(--sidebar-active-bg)] [.is-collapsed_&]:hidden">
            <img src="{{ asset('Images/Zefanya-Logo.png') }}" alt="Zefanya logo" class="size-5 rounded-full object-cover" />
        </span>
        <div class="min-w-0 flex-1 [.is-collapsed_&]:hidden">
            <p class="font-sans text-[14px] font-semibold text-[var(--sidebar-text)]">Zefanya</p>
            <p class="font-sans text-[11px] text-[var(--sidebar-muted)]">Admin workspace</p>
        </div>
        <button type="button" id="adminSidebarToggle" class="flex shrink-0 cursor-pointer items-center justify-center rounded-md p-2 text-[var(--sidebar-muted)] transition-colors hover:bg-[var(--sidebar-hover)] hover:text-[var(--sidebar-text)]" aria-label="Collapse navigation" aria-expanded="true" aria-controls="adminNav">
            <i data-lucide="menu" class="size-[18px]" aria-hidden="true"></i>
        </button>
    </div>

    <nav id="adminNav" aria-label="Admin navigation" class="flex min-h-0 flex-1 flex-col gap-1 overflow-y-auto overscroll-contain px-3 py-4 [.is-collapsed_&]:px-2">
        <x-admin.nav-item label="Dashboard" icon="layout-dashboard" route="admin.dashboard" />

        @if ($canModerate)
            <p class="{{ $heading }}">Accounts</p>
            @if ($hasRegistrations)
                <x-admin.nav-item label="Registrations" icon="user-check" route="admin.registrations.index" :active="['admin.registrations.*']" :badge="$pendingRegistrations" />
            @endif
            <x-admin.nav-item label="User accounts" icon="users" route="admin.users.index" :active="['admin.users.*']" />

            <p class="{{ $heading }}">Marketplace &amp; operations</p>
            @if ($hasNetwork)
                <x-admin.nav-item label="Logistics network" icon="network" route="admin.hubs.index" :active="['admin.hubs.*', 'admin.partnerships.*']" :badge="$pendingNetwork" />
            @endif
            <x-admin.nav-item label="Products" icon="package" route="admin.products.index" :active="['admin.products.*']" />
            @if ($canManage)
                <x-admin.nav-item label="Vouchers" icon="ticket" route="admin.vouchers.index" :active="['admin.vouchers.*']" />
            @endif
            <x-admin.nav-item :label="'Returns & disputes'" icon="message-square-warning" />
            <x-admin.nav-item label="Parcel issues" icon="package-x" route="admin.incidents.index" :active="['admin.incidents.*', 'admin.returns.*']" />
        @endif

        @if ($canManage)
            <p class="{{ $heading }}">Money</p>
            <x-admin.nav-item label="Settlements" icon="wallet" route="admin.logistics-remittances.index" :active="['admin.logistics-remittances.*', 'admin.seller-payouts.*']" />
            <x-admin.nav-item label="Finance" icon="file-bar-chart" />
        @endif
    </nav>

    @if ($canManage)
        <div class="shrink-0 border-t border-[var(--sidebar-border)] px-3 py-2 [.is-collapsed_&]:px-2">
            <x-admin.nav-item label="Settings" icon="settings" route="admin.settings.index" :active="['admin.settings.*', 'admin.audit-logs.*']" />
        </div>
    @endif

    <div class="shrink-0 border-t border-[var(--sidebar-border)] px-3 py-3 [.is-collapsed_&]:px-2">
        <div class="flex items-center gap-3 px-3 pb-2 [.is-collapsed_&]:justify-center [.is-collapsed_&]:px-0">
            <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-[var(--sidebar-active-bg)] font-sans text-[14px] font-semibold text-[var(--sidebar-active-text)]" title="{{ $admin?->name ?? 'Admin' }}">{{ mb_strtoupper(mb_substr($admin?->name ?? 'A', 0, 1)) }}</span>
            <div class="min-w-0 [.is-collapsed_&]:hidden">
                <p class="truncate font-sans text-[13px] font-semibold text-[var(--sidebar-text)]">{{ $admin?->name ?? 'Zefanya Admin' }}</p>
                <p class="font-sans text-[11px] text-[var(--sidebar-muted)]">{{ \Illuminate\Support\Str::headline($admin?->role ?? 'admin') }}</p>
            </div>
        </div>
    {{-- Each admin's own security page, open to every role, so it lives with the profile. --}}
    <a href="{{ route('admin.two-factor.show') }}" aria-label="Two-factor security" title="Two-factor security" @if(request()->routeIs('admin.two-factor.show')) aria-current="page" @endif class="{{ $navLink }}">
        <i data-lucide="shield-check" width="20" height="20" class="shrink-0" aria-hidden="true"></i>
        <span>Two-factor security</span>
    </a>
    <form method="POST" action="{{ route('admin.logout') }}" class="mt-2">
        @csrf
        <button type="submit" aria-label="Log out" title="Log out" class="{{ $navLink }} w-full cursor-pointer">
            <i data-lucide="log-out" width="20" height="20" class="shrink-0"></i>
            <span>Log out</span>
        </button>
    </form>
    </div>
</aside>
</div>

<script>
(function () {
    var sidebar = document.getElementById('adminSidebar');
    var toggle = document.getElementById('adminSidebarToggle');
    var backdrop = document.getElementById('adminSidebarBackdrop');
    var mobile = window.matchMedia('(max-width: 767px)');
    var key = 'admin.sidebar.collapsed';
    function apply(collapsed, persist) {
        sidebar.classList.toggle('is-collapsed', collapsed);
        toggle.setAttribute('aria-expanded', String(!collapsed));
        toggle.setAttribute('aria-label', collapsed ? 'Expand navigation' : 'Collapse navigation');
        backdrop.hidden = collapsed || !mobile.matches;
        if (persist && !mobile.matches) {
            try { localStorage.setItem(key, collapsed ? '1' : '0'); } catch (e) {}
        }
    }
    function restore() {
        var saved = null;
        try { saved = localStorage.getItem(key); } catch (e) {}
        apply(mobile.matches || saved === '1', false);
    }
    restore();
    toggle.addEventListener('click', function () {
        apply(!sidebar.classList.contains('is-collapsed'), true);
    });
    backdrop.addEventListener('click', function () { apply(true, false); toggle.focus(); });
    sidebar.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && mobile.matches) { apply(true, false); toggle.focus(); }
    });
    mobile.addEventListener('change', restore);
})();
</script>
