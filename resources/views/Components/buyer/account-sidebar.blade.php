{{-- resources/views/Components/buyer/account-sidebar.blade.php
     Shared sidebar for all buyer account pages. Extracted from Orders & Tracking.
     Pass the active tab via 'active' parameter: 'orders' | 'address' | 'profile' | 'password'
--}}
@php
    $sidebarActive = $active ?? null;
    $sidebarBuyer = $buyer ?? auth('buyer')->user();
@endphp

<aside class="space-y-6">
    {{-- User mini profile card --}}
    <div class="rounded-2xl border border-[var(--border)] bg-[var(--bg-surface)] p-5 shadow-sm">
        <div class="flex items-center gap-3.5">
            <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-secondary-300 font-serif text-lg font-semibold uppercase text-primary-950">
                {{ mb_substr($sidebarBuyer->first_name, 0, 1) }}{{ mb_substr($sidebarBuyer->last_name, 0, 1) }}
            </div>
            <div class="min-w-0 flex-1">
                <h2 class="truncate font-serif text-[17px] font-medium leading-snug text-[var(--text-ink)]">
                    {{ $sidebarBuyer->fullName() }}
                </h2>
                <p class="truncate font-sans text-[12px] text-[var(--text-muted)]">
                    {{ $sidebarBuyer->email }}
                </p>
            </div>
        </div>

        {{-- Real account stats --}}
        <div class="mt-5 grid grid-cols-2 gap-3 border-t border-[var(--border-faint)] pt-4">
            <div class="rounded-xl bg-secondary-100 p-3 text-left">
                <span class="block font-sans text-[10.5px] font-medium uppercase tracking-wider text-[var(--text-muted)]">Total Orders</span>
                <span class="mt-0.5 block font-serif text-[18px] font-semibold text-[var(--text-ink)]">{{ $counts['all'] ?? 0 }}</span>
            </div>
            <div class="rounded-xl bg-secondary-100 p-3 text-left">
                <span class="block font-sans text-[10.5px] font-medium uppercase tracking-wider text-[var(--text-muted)]">To Receive</span>
                <span class="mt-0.5 block font-serif text-[18px] font-semibold text-[var(--text-ink)]">{{ $counts['to_receive'] ?? 0 }}</span>
            </div>
        </div>
    </div>

    {{-- Account Navigation Links --}}
    <nav class="rounded-2xl border border-[var(--border)] bg-[var(--bg-surface)] p-2 shadow-sm" aria-label="Buyer account navigation">
        <ul class="space-y-1">
            <li>
                <a href="{{ route('buyer.orders.index') }}"
                   class="flex items-center justify-between rounded-xl px-4 py-2.5 font-sans text-[13px] transition-colors {{ $sidebarActive === 'orders' ? 'bg-secondary-200 font-semibold text-primary-950' : 'text-[var(--text-muted)] hover:bg-secondary-100 hover:text-[var(--text-ink)]' }}">
                    <span class="flex items-center gap-3">
                        <i data-lucide="package" width="16" height="16" class="{{ $sidebarActive === 'orders' ? 'text-primary-800' : 'text-neutral-400' }}"></i>
                        Orders &amp; History
                    </span>
                    @if (($counts['all'] ?? 0) > 0)
                        <span class="inline-flex h-5 min-w-[20px] items-center justify-center rounded-full {{ $sidebarActive === 'orders' ? 'bg-primary-800 text-white' : 'bg-secondary-100 text-[var(--text-muted)]' }} px-1.5 font-sans text-[10.5px] font-semibold">{{ $counts['all'] }}</span>
                    @endif
                </a>
            </li>
            <li>
                <a href="{{ route('buyer.account.address') }}"
                   class="flex items-center justify-between rounded-xl px-4 py-2.5 font-sans text-[13px] transition-colors {{ $sidebarActive === 'address' ? 'bg-secondary-200 font-semibold text-primary-950' : 'text-[var(--text-muted)] hover:bg-secondary-100 hover:text-[var(--text-ink)]' }}">
                    <span class="flex items-center gap-3">
                        <i data-lucide="map-pin" width="16" height="16" class="{{ $sidebarActive === 'address' ? 'text-primary-800' : 'text-neutral-400' }}"></i>
                        Shipping Addresses
                    </span>
                    <span class="font-sans text-[11.5px] {{ $sidebarActive === 'address' ? 'text-primary-800' : 'text-[var(--text-muted)]' }}">{{ $sidebarBuyer->street ? '1' : '0' }}</span>
                </a>
            </li>
            <li>
                <a href="{{ route('buyer.account.index') }}"
                   class="flex items-center justify-between rounded-xl px-4 py-2.5 font-sans text-[13px] transition-colors {{ $sidebarActive === 'profile' ? 'bg-secondary-200 font-semibold text-primary-950' : 'text-[var(--text-muted)] hover:bg-secondary-100 hover:text-[var(--text-ink)]' }}">
                    <span class="flex items-center gap-3">
                        <i data-lucide="user" width="16" height="16" class="{{ $sidebarActive === 'profile' ? 'text-primary-800' : 'text-neutral-400' }}"></i>
                        Profile
                    </span>
                </a>
            </li>
            <li>
                <a href="{{ route('buyer.account.password') }}"
                   class="flex items-center justify-between rounded-xl px-4 py-2.5 font-sans text-[13px] transition-colors {{ $sidebarActive === 'password' ? 'bg-secondary-200 font-semibold text-primary-950' : 'text-[var(--text-muted)] hover:bg-secondary-100 hover:text-[var(--text-ink)]' }}">
                    <span class="flex items-center gap-3">
                        <i data-lucide="shield-check" width="16" height="16" class="{{ $sidebarActive === 'password' ? 'text-primary-800' : 'text-neutral-400' }}"></i>
                        Password &amp; Security
                    </span>
                </a>
            </li>
            <li>
                <a href="{{ route('buyer.settings.index') }}"
                   class="flex items-center justify-between rounded-xl px-4 py-2.5 font-sans text-[13px] transition-colors {{ $sidebarActive === 'settings' ? 'bg-secondary-200 font-semibold text-primary-950' : 'text-[var(--text-muted)] hover:bg-secondary-100 hover:text-[var(--text-ink)]' }}">
                    <span class="flex items-center gap-3">
                        <i data-lucide="sliders" width="16" height="16" class="{{ $sidebarActive === 'settings' ? 'text-primary-800' : 'text-neutral-400' }}"></i>
                        Settings
                    </span>
                </a>
            </li>
        </ul>
    </nav>
</aside>
