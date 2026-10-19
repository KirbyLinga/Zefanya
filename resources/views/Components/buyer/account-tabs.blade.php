{{-- resources/views/Components/buyer/account-tabs.blade.php
     Shared buyer account tab strip used by the profile, address and password
     pages. It deliberately has no element IDs: Buyer/Layouts/app also includes
     the buyer registration modal, so duplicate account/modal IDs would break
     getElementById bindings. Hosts pass the active tab explicitly:
     'profile' | 'address' | 'password' | (none — Order History is a link only).
     Every entry links to a real route; a "Preferences" tab was removed because
     no preferences page exists (a non-clickable tab is dead UI). --}}
@php
    $accountActive = $active ?? null;
    $accountTabs = [
        ['key' => 'profile', 'label' => 'Profile', 'route' => 'buyer.account.index'],
        ['key' => 'address', 'label' => 'Address Book', 'route' => 'buyer.account.address'],
        ['key' => 'password', 'label' => 'Password & Security', 'route' => 'buyer.account.password'],
        ['key' => 'orders', 'label' => 'Order History', 'route' => 'buyer.orders.index'],
    ];
@endphp

<nav class="mb-6" aria-label="Account sections">
    <ul class="flex items-center gap-x-5 overflow-x-auto border-b border-[var(--border-faint)] sm:gap-x-7">
        @foreach ($accountTabs as $accountTab)
            <li class="shrink-0">
                @if ($accountActive === $accountTab['key'])
                    <span class="relative -mb-px block whitespace-nowrap border-b-2 border-[var(--link)] px-0.5 pb-2.5 font-sans text-[12.5px] font-semibold text-[var(--link)] sm:text-[13px]" aria-current="page">{{ $accountTab['label'] }}</span>
                @else
                    <a href="{{ route($accountTab['route']) }}" class="block whitespace-nowrap px-0.5 pb-2.5 font-sans text-[12.5px] text-[var(--text-muted)] transition-colors hover:text-[var(--text-ink)] sm:text-[13px]">{{ $accountTab['label'] }}</a>
                @endif
            </li>
        @endforeach
    </ul>
</nav>
