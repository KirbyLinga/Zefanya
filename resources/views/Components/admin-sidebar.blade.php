@php
    // Sidebar nav link styling with better sizing
    $navLink = 'flex items-center gap-3 px-4 py-3 rounded-lg font-sans font-medium text-[14px] text-[#fff8f7]/78 no-underline transition-colors duration-150 hover:bg-[rgba(255,248,247,0.1)] hover:text-white';
    $navLinkActive = 'bg-primary-800 text-white font-semibold shadow-[inset_3px_0_0_0_#f7d6d0]';
    $navLinkSoon = 'flex items-center gap-3 px-4 py-3 rounded-lg font-sans font-medium text-[14px] text-[rgba(255,248,247,0.35)] cursor-default';
    $badgeBase = 'ml-auto inline-flex items-center justify-center min-w-[20px] h-5 px-1.5 rounded-full font-sans text-[10px] font-bold';
@endphp
<aside class="w-60 flex-none flex flex-col bg-neutral-950 py-6">
    <div class="flex flex-col items-start gap-[2px] px-6 pb-5 mb-5 border-b border-[rgba(255,248,247,0.12)]">
        <div class="flex items-center gap-3">
            <img src="{{ asset('Images/Zefanya-Logo.png') }}" alt="Zefanya logo" class="w-9 h-9 rounded-full object-cover" />
            <span class="font-serif text-[18px] text-white">Zefanya Admin</span>
        </div>
        <span class="ml-12 font-sans text-[11px] text-[rgba(255,248,247,0.55)]">Admin Panel</span>
    </div>

    <nav class="flex flex-col gap-1 px-3 flex-1">
        <a href="{{ route('admin.dashboard') }}" class="{{ $navLink }} {{ request()->routeIs('admin.dashboard') ? $navLinkActive : '' }}">
            <i data-lucide="layout-dashboard" width="20" height="20" class="shrink-0"></i>
            <span>Dashboard</span>
        </a>
        @if (\Illuminate\Support\Facades\Schema::hasTable('buyers'))
            @php
                $regActive = request()->routeIs('admin.registrations.*');
                $pendingCount = \App\Models\Buyer\Buyer::where('status', 'pending')->count()
                    + \App\Models\Seller\Seller::where('status', 'pending')->count()
                    + (\Illuminate\Support\Facades\Schema::hasTable('logistics_providers')
                        ? \App\Models\Logistics\LogisticsProvider::where('status', 'pending')->count()
                        : 0);
            @endphp
            <a href="{{ route('admin.registrations.index') }}" class="{{ $navLink }} {{ $regActive ? $navLinkActive : '' }}">
                <i data-lucide="user-check" width="20" height="20" class="shrink-0"></i>
                <span>Registrations</span>
                @if ($pendingCount > 0)
                    <span class="{{ $badgeBase }} {{ $regActive ? 'bg-white text-primary-800' : 'bg-secondary-300 text-neutral-950' }}">{{ $pendingCount }}</span>
                @endif
            </a>
        @endif
        <a href="{{ route('admin.users.index') }}" class="{{ $navLink }} {{ request()->routeIs('admin.users.*') ? $navLinkActive : '' }}">
            <i data-lucide="users" width="20" height="20" class="shrink-0"></i>
            <span>User Accounts</span>
        </a>

        {{-- Modules not built yet — disabled labels, not fake # links --}}
        <span class="{{ $navLinkSoon }}" title="Coming soon">
            <i data-lucide="shield-alert" width="20" height="20" class="shrink-0"></i>
            <span>Seller Compliance</span>
            <span class="ml-auto text-[10px] uppercase tracking-wide">Soon</span>
        </span>
        <span class="{{ $navLinkSoon }}" title="Coming soon">
            <i data-lucide="message-square-warning" width="20" height="20" class="shrink-0"></i>
            <span>Complaints &amp; Disputes</span>
            <span class="ml-auto text-[10px] uppercase tracking-wide">Soon</span>
        </span>
        <span class="{{ $navLinkSoon }}" title="Coming soon">
            <i data-lucide="percent" width="20" height="20" class="shrink-0"></i>
            <span>Commissions</span>
            <span class="ml-auto text-[10px] uppercase tracking-wide">Soon</span>
        </span>
        <span class="{{ $navLinkSoon }}" title="Coming soon">
            <i data-lucide="file-bar-chart" width="20" height="20" class="shrink-0"></i>
            <span>Reports</span>
            <span class="ml-auto text-[10px] uppercase tracking-wide">Soon</span>
        </span>
        <span class="{{ $navLinkSoon }}" title="Coming soon">
            <i data-lucide="settings" width="20" height="20" class="shrink-0"></i>
            <span>Platform Settings</span>
            <span class="ml-auto text-[10px] uppercase tracking-wide">Soon</span>
        </span>
        <span class="{{ $navLinkSoon }}" title="Coming soon">
            <i data-lucide="message-circle" width="20" height="20" class="shrink-0"></i>
            <span>Chat / Messaging</span>
            <span class="ml-auto text-[10px] uppercase tracking-wide">Soon</span>
        </span>
        <span class="{{ $navLinkSoon }}" title="Coming soon">
            <i data-lucide="user-cog" width="20" height="20" class="shrink-0"></i>
            <span>Account Management</span>
            <span class="ml-auto text-[10px] uppercase tracking-wide">Soon</span>
        </span>
    </nav>

    <form method="POST" action="{{ route('admin.logout') }}" class="px-3 pt-4 mt-3 border-t border-[rgba(255,248,247,0.12)]">
        @csrf
        <button type="submit" class="flex items-center gap-3 w-full px-4 py-3 rounded-lg font-sans font-medium text-[14px] text-[#fff8f7]/78 cursor-pointer box-border transition-colors duration-150 hover:bg-[rgba(255,248,247,0.1)] hover:text-white">
            <i data-lucide="log-out" width="20" height="20" class="shrink-0"></i>
            <span>Log out</span>
        </button>
    </form>
</aside>
