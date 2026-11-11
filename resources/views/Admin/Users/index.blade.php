@extends('Admin.Layouts.app')

@section('title', 'User Accounts')
@section('content-width', 'max-w-none')

@section('content')

@if (session('success'))
    <div class="mb-6 p-4 bg-green-50 border border-green-200 rounded-lg text-green-700 text-sm">
        {{ session('success') }}
    </div>
@endif
@if (session('error'))
    <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg text-red-700 text-sm">
        {{ session('error') }}
    </div>
@endif

@if ($errors->any())
    <div role="alert" class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg text-red-700 text-sm">
        @foreach ($errors->all() as $error)
            <p>{{ $error }}</p>
        @endforeach
    </div>
@endif

@php
    $typeLabel = match ($type) { 'buyer' => 'Buyer', 'logistics' => 'Logistics', default => 'Seller' };
    $typePlural = match ($type) { 'buyer' => 'buyers', 'logistics' => 'logistics providers', default => 'sellers' };
    $summary = $accountSummary ?? collect();
    $cards = [
        ['label' => 'Total Accounts', 'value' => $summary->sum(), 'caption' => 'Across all account statuses', 'icon' => 'users', 'tone' => 'total'],
        ['label' => 'Approved Accounts', 'value' => $summary->get('approved', 0), 'caption' => 'Approved to access the platform', 'icon' => 'user-check', 'tone' => 'approved'],
        ['label' => 'Pending Accounts', 'value' => $summary->get('pending', 0), 'caption' => 'Waiting for account review', 'icon' => 'clock', 'tone' => 'pending'],
        ['label' => 'Suspended Accounts', 'value' => $summary->get('suspended', 0), 'caption' => 'Platform access is restricted', 'icon' => 'user-x', 'tone' => 'suspended'],
    ];
@endphp
<div class="user-accounts-workspace">
    <header class="user-accounts-header">
        <h1>User Accounts</h1>
        <p>Manage account access and review buyers, sellers, and logistics providers.</p>
    </header>

    <section class="user-account-metrics" aria-label="Account overview">
        @foreach ($cards as $card)
            <article class="user-account-metric user-account-tone-{{ $card['tone'] }}">
                <div class="user-account-metric-heading"><h2>{{ $card['label'] }}</h2><span class="user-account-metric-icon"><i data-lucide="{{ $card['icon'] }}" class="w-5 h-5" aria-hidden="true"></i></span></div>
                <strong>{{ number_format($card['value']) }}</strong>
                <p>{{ $card['caption'] }}</p>
            </article>
        @endforeach
    </section>
    <p class="user-account-metric-scope">Overview for all {{ $typePlural }}. Search and status filters apply to the table.</p>

    <section class="user-account-filter-panel" aria-label="Account filters">
        <form data-admin-auto-filter method="GET" action="{{ route('admin.users.index') }}" class="user-account-filters">
            <input type="hidden" name="per_page" value="{{ $perPage ?? 25 }}">
            <div class="user-account-search"><label for="search">Search accounts</label><div><i data-lucide="search" class="w-4 h-4" aria-hidden="true"></i><input type="search" name="search" id="search" value="{{ $search }}" placeholder="Name, email, contact..."></div></div>
            <div><label for="type">Account Type</label><select name="type" id="type">@foreach (['seller' => 'Seller', 'logistics' => 'Logistics', 'buyer' => 'Buyer'] as $value => $label)<option value="{{ $value }}" @selected($type === $value)>{{ $label }}</option>@endforeach</select></div>
            <div><label for="status">Account Status</label><select name="status" id="status"><option value="" @selected($status === '')>All statuses</option>@foreach ($statuses as $value => $label)<option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>@endforeach</select></div>
            <a href="{{ route('admin.users.index', ['type' => $type]) }}" class="user-account-reset"><i data-lucide="rotate-ccw" class="w-4 h-4" aria-hidden="true"></i>Reset</a>
        </form>
        <div class="user-account-results"><p>Showing <strong>{{ $accounts->firstItem() ?? 0 }} to {{ $accounts->lastItem() ?? 0 }}</strong> of <strong>{{ number_format($accounts->total()) }}</strong> {{ $typePlural }}</p><p><i data-lucide="arrow-down-wide-narrow" class="w-4 h-4" aria-hidden="true"></i>Recently updated</p></div>
    </section>

<section class="user-account-table-panel" aria-label="User accounts">
    @if ($accounts->count() > 0)
        <div class="user-account-table-scroll" role="region" aria-label="Account records" tabindex="0"><table class="w-full"><caption class="sr-only">{{ $typeLabel }} accounts matching your filters, most recently updated first</caption>
            <thead class="border-b border-neutral-200">
                <tr>
                    <th class="text-left">User</th>
                    <th class="text-left">Email</th><th class="text-left">Account Type</th>
                    @if ($type === 'logistics')
                        <th class="text-left">Province</th>
                    @endif
                    <th class="text-left">Status</th>
                    <th class="text-left">Store / Business</th><th class="text-left">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200">
                @foreach ($accounts as $account)
                    @php
                        $statusValue = $account->status instanceof \BackedEnum ? $account->status->value : $account->status;
                        $blockers = ($provinceBlockers ?? [])[$account->id] ?? [];
                    @endphp
                    <tr class="hover:bg-neutral-50">
                        <td><div class="user-account-person"><span class="user-account-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($account->first_name ?? 'U', 0, 1).mb_substr($account->last_name ?? '', 0, 1)) }}</span><div><p>{{ $account->fullName() }}</p><small>ID: ZF-{{ $account->id }}</small></div></div></td>
                        <td><span class="user-account-email">{{ $account->email }}</span></td>
                        <td><span class="user-account-type"><i data-lucide="{{ $type === 'buyer' ? 'users' : ($type === 'logistics' ? 'truck' : 'store') }}" class="w-3.5 h-3.5" aria-hidden="true"></i>{{ $typeLabel }}</span></td>
                        @if ($type === 'logistics')
                            <td class="text-sm text-neutral-700">
                                <p class="font-semibold">{{ $account->province_name ?: '—' }}</p>
                                @if ($account->province_verified_at)
                                    <p class="text-xs text-green-700 mt-0.5">Verified {{ $account->province_verified_at->format('M d, Y') }}</p>
                                @else
                                    <p class="text-xs text-amber-700 mt-0.5">Not verified</p>
                                @endif
                                <details class="mt-2">
                                    <summary class="cursor-pointer text-xs font-semibold text-primary-800">Change province</summary>
                                    @if ($blockers !== [])
                                        <div class="mt-2 rounded-lg border border-amber-200 bg-amber-50 p-2 text-xs text-amber-900">
                                            <p class="font-semibold">Resolve before changing:</p>
                                            <ul class="list-disc pl-4 mt-1">
                                                @foreach ($blockers as $blocker)
                                                    <li>{{ $blocker }}</li>
                                                @endforeach
                                            </ul>
                                        </div>
                                    @else
                                        <form method="POST" action="{{ route('admin.users.logistics.province', $account) }}" class="mt-2 flex flex-col gap-2">
                                            @csrf
                                            <input name="province_code" required maxlength="20" placeholder="PSGC province code" class="h-9 px-2 border border-neutral-300 rounded-lg text-xs">
                                            <input name="province_name" maxlength="120" placeholder="Province name (optional)" class="h-9 px-2 border border-neutral-300 rounded-lg text-xs">
                                            <input name="region_code" maxlength="20" placeholder="Region code (optional)" class="h-9 px-2 border border-neutral-300 rounded-lg text-xs">
                                            <button type="submit" class="h-8 px-3 text-xs font-semibold text-white bg-primary-800 rounded-lg hover:bg-primary-900"
                                                    onclick="return confirm('Change this provider\'s verified province? Their live Province Hub will be archived.');">
                                                Save province
                                            </button>
                                        </form>
                                    @endif
                                </details>
                            </td>
                        @endif
                        <td >
                            @if ($statusValue === 'suspended')
                                <span class="inline-flex px-3 py-1 rounded-full text-xs font-semibold user-account-status-suspended">Suspended</span>
                            @elseif ($statusValue === 'approved')
                                <span class="inline-flex px-3 py-1 rounded-full text-xs font-semibold user-account-status-approved">Approved</span>
                            @else
                                <span class="inline-flex px-3 py-1 rounded-full text-xs font-semibold user-account-status-{{ $statusValue }}">{{ $statuses[$statusValue] ?? ucfirst($statusValue) }}</span>
                            @endif
                        </td>
                        <td><p class="user-account-business">{{ $account->business_name ?: ($type === 'buyer' ? 'Personal account' : 'Not provided') }}</p></td>
                        <td class="user-account-actions">
                            <details class="user-account-disclosure user-account-view">
                                <summary><i data-lucide="eye" class="w-3.5 h-3.5" aria-hidden="true"></i>View<span class="sr-only"> account details for {{ $account->fullName() }}</span></summary>
                                <dl class="user-account-detail-content">
                                    <div><dt>Name</dt><dd>{{ $account->fullName() }}</dd></div>
                                    <div><dt>Email</dt><dd>{{ $account->email }}</dd></div>
                                    <div><dt>Contact</dt><dd>{{ $account->contact_no ?: 'Not provided' }}</dd></div>
                                    <div><dt>Account</dt><dd>{{ $typeLabel }} / {{ $statuses[$statusValue] ?? ucfirst($statusValue) }}</dd></div>
                                    <div><dt>Registered</dt><dd>{{ $account->created_at?->format('d M Y, g:i A') ?? 'Not available' }}</dd></div>
                                    <div><dt>Last updated</dt><dd>{{ $account->updated_at?->format('d M Y, g:i A') ?? 'Not available' }}</dd></div>
                                </dl>
                            </details>
                            @if ($statusValue === 'approved')
                                <details class="user-account-disclosure user-account-suspend"><summary><i data-lucide="pause" class="w-3.5 h-3.5" aria-hidden="true"></i>Suspend<span class="sr-only"> {{ $account->fullName() }}</span></summary>
                                <form method="POST" action="{{ route(match ($type) {
                                    'buyer' => 'admin.users.buyer.suspend',
                                    'logistics' => 'admin.users.logistics.suspend',
                                    default => 'admin.users.seller.suspend',
                                }, $account) }}" class="user-account-reason-form" onsubmit="return confirm('Suspend this account?');">
                                    @csrf
                                    <label for="suspend-reason-{{ $account->id }}" class="sr-only">Reason for suspension</label>
                                    <input id="suspend-reason-{{ $account->id }}" name="reason" required maxlength="1000" placeholder="Reason for suspension"
                                           class="mb-2 w-full h-10 px-3 border border-neutral-300 rounded-lg text-sm" />
                                    <button type="submit" class="px-3 py-1.5 text-sm font-semibold text-red-700 bg-red-50 rounded-lg hover:bg-red-100">Suspend</button>
                                </form></details>
                            @elseif ($statusValue === 'suspended')
                                <details class="user-account-disclosure user-account-reactivate"><summary><i data-lucide="rotate-ccw" class="w-3.5 h-3.5" aria-hidden="true"></i>Reactivate<span class="sr-only"> {{ $account->fullName() }}</span></summary>
                                <form method="POST" action="{{ route(match ($type) {
                                    'buyer' => 'admin.users.buyer.reactivate',
                                    'logistics' => 'admin.users.logistics.reactivate',
                                    default => 'admin.users.seller.reactivate',
                                }, $account) }}" class="user-account-reason-form" onsubmit="return confirm('Reactivate this account?');">
                                    @csrf
                                    <label for="reactivate-reason-{{ $account->id }}" class="sr-only">Reason for reactivation</label>
                                    <input id="reactivate-reason-{{ $account->id }}" name="reason" required maxlength="1000" placeholder="Reason for reactivation"
                                           class="mb-2 w-full h-10 px-3 border border-neutral-300 rounded-lg text-sm" />
                                    <button type="submit" class="px-3 py-1.5 text-sm font-semibold text-green-700 bg-green-50 rounded-lg hover:bg-green-100">Reactivate</button>
                                </form></details>
                                @if ($type === 'logistics')
                                    @php
                                        $inHand = $outForDelivery[$account->id] ?? 0;
                                        $windDownUntil = $account->allowsRiderWindDown() ? $account->rider_wind_down_until : null;
                                    @endphp
                                    <div class="mt-3 rounded-lg border border-neutral-200 bg-neutral-50 p-2 text-left text-xs text-neutral-700" data-rider-wind-down="{{ $account->id }}">
                                        <p class="font-semibold">Riders are blocked.</p>
                                        <p class="mt-0.5">{{ $inHand }} {{ Str::plural('parcel', $inHand) }} out for delivery with this company's riders.</p>
                                        @if ($windDownUntil)
                                            <p class="mt-0.5 text-amber-800">Riders may finish those until {{ $windDownUntil->timezone(\App\Models\Seller\StoreSale::TIMEZONE)->format('M j, g:i A') }}.</p>
                                        @endif
                                        <form method="POST" action="{{ route('admin.users.logistics.rider-wind-down', $account) }}" class="mt-2 flex flex-col gap-2"
                                              onsubmit="return confirm('Change what this company\'s riders may do?');">
                                            @csrf
                                            <label for="wind-down-hours-{{ $account->id }}" class="font-semibold">Let riders finish parcels out for delivery</label>
                                            <select id="wind-down-hours-{{ $account->id }}" name="hours" class="h-9 px-2 border border-neutral-300 rounded-lg text-xs">
                                                @foreach ([12, 24, 48, $maxWindDownHours] as $hours)
                                                    <option value="{{ $hours }}">For {{ $hours }} hours</option>
                                                @endforeach
                                                @if ($windDownUntil)
                                                    <option value="0">End now</option>
                                                @endif
                                            </select>
                                            <label for="wind-down-reason-{{ $account->id }}" class="sr-only">Reason</label>
                                            <input id="wind-down-reason-{{ $account->id }}" name="reason" required maxlength="1000" placeholder="Reason"
                                                   class="h-9 px-2 border border-neutral-300 rounded-lg text-xs" />
                                            <button type="submit" class="h-8 px-3 text-xs font-semibold text-amber-800 bg-amber-50 border border-amber-200 rounded-lg hover:bg-amber-100">Save</button>
                                        </form>
                                    </div>
                                @endif
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table></div>
        <div class="user-account-pagination"><form data-admin-auto-filter method="GET" action="{{ route('admin.users.index') }}" class="user-account-page-size"><input type="hidden" name="search" value="{{ $search }}"><input type="hidden" name="type" value="{{ $type }}"><input type="hidden" name="status" value="{{ $status }}"><label for="accounts_per_page">Rows per page</label><select name="per_page" id="accounts_per_page">@foreach ([10, 25, 50] as $size)<option value="{{ $size }}" @selected(($perPage ?? 25) === $size)>{{ $size }}</option>@endforeach</select><noscript><button type="submit">Apply</button></noscript></form>
            {{ $accounts->links() }}
        </div>
    @else
        <div class="px-6 py-16 text-center text-neutral-500">No accounts match your filters.</div>
    @endif
</section>
</div>

@endsection
