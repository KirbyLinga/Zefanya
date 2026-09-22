{{-- Admin sidebar — converted to Tailwind utilities in ADMIN-b.
     Source of truth was resources/css/admin/admin.css (sidebar block, lines
     24–165). All values are 1:1; the sidebar palette was ALREADY hardcoded in
     admin.css (#1e1b1b / #79545c / #f7d6d0 / rgba(255,248,247,…)), so nothing
     depended on design-system tokens. The `!important` override block
     (admin.css lines 352–375) is deliberately NOT ported — it existed only to
     beat design-system's unlayered element rules, which no longer load. --}}
@php
    // Shared link styling — admin.css `.admin-sidebar__nav a` (1:1):
    // gap 12px, padding 12px 14px, radius 6px, Montserrat 500 13.5px,
    // color rgba(255,248,247,0.78), transition 150ms; hover bg 10% cream + white.
    $navLink = 'flex items-center gap-3 px-3.5 py-3 rounded-md font-sans font-medium text-[13.5px] text-[#fff8f7]/78 no-underline transition-colors duration-150 hover:bg-[rgba(255,248,247,0.1)] hover:text-white';
    // admin.css `.admin-sidebar__nav a.is-active`: bg #79545c (= primary-800),
    // white, weight 600, inset box-shadow #f7d6d0 (= secondary-300).
    $navLinkActive = 'bg-primary-800 text-white font-semibold shadow-[inset_3px_0_0_0_#f7d6d0]';
    // admin.css `.admin-sidebar__nav-badge`: min-w 20px, h 20px, px 6px,
    // pill, Montserrat 700 10px; active variant swaps bg to white / #79545c.
    $badgeBase = 'ml-auto inline-flex items-center justify-center min-w-[20px] h-5 px-1.5 rounded-full font-sans text-[10px] font-bold';
@endphp
<aside class="w-60 flex-none flex flex-col bg-neutral-950 py-6">
    <div class="flex flex-col items-start gap-[2px] px-6 pb-5 mb-5 border-b border-[rgba(255,248,247,0.12)]">
        <div class="flex items-center gap-3">
            <img src="{{ asset('Images/Zefanya-Logo.png') }}" alt="Zefanya logo" class="w-9 h-9 rounded-full object-cover" />
            <span class="font-serif text-[18px] text-white">Zefanya Admin</span>
        </div>
        <span class="ml-12 font-sans text-[11px] text-[rgba(255,248,247,0.55)]">Super Admin Panel</span>
    </div>

    <nav class="flex flex-col gap-1 px-3 flex-1">
        <a href="{{ route('admin.dashboard') }}" class="{{ $navLink }} {{ request()->routeIs('admin.dashboard') ? $navLinkActive : '' }}">
            <i data-lucide="layout-dashboard" width="18" height="18" class="shrink-0"></i>
            <span>Dashboard</span>
        </a>
        @if (\Illuminate\Support\Facades\Schema::hasTable('buyers'))
            @php $regActive = request()->routeIs('admin.registrations.*'); @endphp
            <a href="{{ route('admin.registrations.index') }}" class="{{ $navLink }} {{ $regActive ? $navLinkActive : '' }}">
                <i data-lucide="user-check" width="18" height="18" class="shrink-0"></i>
                <span>Registrations</span>
                @php $pendingCount = \App\Models\Buyer\Buyer::where('status', 'pending')->count(); @endphp
                @if ($pendingCount > 0)
                    {{-- `a.is-active .admin-sidebar__nav-badge` expressed
                         conditionally — the badge classes are child-state rules
                         utilities cannot reach from here. --}}
                    <span class="{{ $badgeBase }} {{ $regActive ? 'bg-white text-primary-800' : 'bg-secondary-300 text-neutral-950' }}">{{ $pendingCount }}</span>
                @endif
            </a>
        @endif
        <a href="#" class="{{ $navLink }} {{ request()->routeIs('admin.users.*') ? $navLinkActive : '' }}">
            <i data-lucide="users" width="18" height="18" class="shrink-0"></i>
            <span>User Accounts</span>
        </a>
        <a href="#" class="{{ $navLink }} {{ request()->routeIs('admin.compliance.*') ? $navLinkActive : '' }}">
            <i data-lucide="shield-alert" width="18" height="18" class="shrink-0"></i>
            <span>Seller Compliance</span>
        </a>
        <a href="#" class="{{ $navLink }} {{ request()->routeIs('admin.disputes.*') ? $navLinkActive : '' }}">
            <i data-lucide="message-square-warning" width="18" height="18" class="shrink-0"></i>
            <span>Complaints &amp; Disputes</span>
        </a>
        <a href="#" class="{{ $navLink }} {{ request()->routeIs('admin.commissions.*') ? $navLinkActive : '' }}">
            <i data-lucide="percent" width="18" height="18" class="shrink-0"></i>
            <span>Commissions</span>
        </a>
        <a href="#" class="{{ $navLink }} {{ request()->routeIs('admin.reports.*') ? $navLinkActive : '' }}">
            <i data-lucide="file-bar-chart" width="18" height="18" class="shrink-0"></i>
            <span>Reports</span>
        </a>
        <a href="#" class="{{ $navLink }} {{ request()->routeIs('admin.settings.*') ? $navLinkActive : '' }}">
            <i data-lucide="settings" width="18" height="18" class="shrink-0"></i>
            <span>Platform Settings</span>
        </a>
        <a href="#" class="{{ $navLink }} {{ request()->routeIs('admin.chat.*') ? $navLinkActive : '' }}">
            <i data-lucide="message-circle" width="18" height="18" class="shrink-0"></i>
            <span>Chat / Messaging</span>
        </a>
        <a href="#" class="{{ $navLink }} {{ request()->routeIs('admin.account.*') ? $navLinkActive : '' }}">
            <i data-lucide="user-cog" width="18" height="18" class="shrink-0"></i>
            <span>Account Management</span>
        </a>
    </nav>

    {{-- admin.css `.admin-sidebar__logout`: px 12, mt 12, border-top
         rgba(255,248,247,0.12), pt 16. The button's `all: unset` reset is
         covered by Tailwind preflight. --}}
    <form method="POST" action="{{ route('admin.logout') }}" class="px-3 pt-4 mt-3 border-t border-[rgba(255,248,247,0.12)]">
        @csrf
        <button type="submit" class="flex items-center gap-3 w-full px-3.5 py-3 rounded-md font-sans font-medium text-[13.5px] text-[#fff8f7]/78 cursor-pointer box-border transition-colors duration-150 hover:bg-[rgba(255,248,247,0.1)] hover:text-white">
            <i data-lucide="log-out" width="18" height="18" class="shrink-0"></i>
            <span>Log out</span>
        </button>
    </form>
</aside>
