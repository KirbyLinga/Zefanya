<aside class="admin-sidebar">
    <div class="admin-sidebar__brand">
        <div class="admin-sidebar__brand-row">
            <img src="{{ asset('Images/Zefanya-Logo-128.png') }}" alt="Zefanya logo" />
            <span>Zefanya Admin</span>
        </div>
        <span class="admin-sidebar__brand-role">Super Admin Panel</span>
    </div>

    <nav class="admin-sidebar__nav">
        <a href="{{ route('admin.dashboard') }}" class="{{ request()->routeIs('admin.dashboard') ? 'is-active' : '' }}">
            <i data-lucide="layout-dashboard" width="18" height="18"></i>
            <span>Dashboard</span>
        </a>
        @if (\Illuminate\Support\Facades\Schema::hasTable('buyers'))
            <a href="{{ route('admin.registrations.index') }}" class="{{ request()->routeIs('admin.registrations.*') ? 'is-active' : '' }}">
                <i data-lucide="user-check" width="18" height="18"></i>
                <span>Registrations</span>
                @php $pendingCount = \App\Models\Buyer::where('status', 'pending')->count(); @endphp
                @if ($pendingCount > 0)
                    <span class="admin-sidebar__nav-badge">{{ $pendingCount }}</span>
                @endif
            </a>
        @endif
        <a href="#" class="{{ request()->routeIs('admin.users.*') ? 'is-active' : '' }}">
            <i data-lucide="users" width="18" height="18"></i>
            <span>User Accounts</span>
        </a>
        <a href="#" class="{{ request()->routeIs('admin.compliance.*') ? 'is-active' : '' }}">
            <i data-lucide="shield-alert" width="18" height="18"></i>
            <span>Seller Compliance</span>
        </a>
        <a href="#" class="{{ request()->routeIs('admin.disputes.*') ? 'is-active' : '' }}">
            <i data-lucide="message-square-warning" width="18" height="18"></i>
            <span>Complaints &amp; Disputes</span>
        </a>
        <a href="#" class="{{ request()->routeIs('admin.commissions.*') ? 'is-active' : '' }}">
            <i data-lucide="percent" width="18" height="18"></i>
            <span>Commissions</span>
        </a>
        <a href="#" class="{{ request()->routeIs('admin.reports.*') ? 'is-active' : '' }}">
            <i data-lucide="file-bar-chart" width="18" height="18"></i>
            <span>Reports</span>
        </a>
        <a href="#" class="{{ request()->routeIs('admin.settings.*') ? 'is-active' : '' }}">
            <i data-lucide="settings" width="18" height="18"></i>
            <span>Platform Settings</span>
        </a>
        <a href="#" class="{{ request()->routeIs('admin.chat.*') ? 'is-active' : '' }}">
            <i data-lucide="message-circle" width="18" height="18"></i>
            <span>Chat / Messaging</span>
        </a>
        <a href="#" class="{{ request()->routeIs('admin.account.*') ? 'is-active' : '' }}">
            <i data-lucide="user-cog" width="18" height="18"></i>
            <span>Account Management</span>
        </a>
    </nav>

    <form method="POST" action="{{ route('admin.logout') }}" class="admin-sidebar__logout">
        @csrf
        <button type="submit">
            <i data-lucide="log-out" width="18" height="18"></i>
            <span>Log out</span>
        </button>
    </form>
</aside>
