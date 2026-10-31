@extends('Admin.Layouts.app')

@section('title', 'User Accounts')

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

<div class="mb-8">
    <h1 class="text-[28px] font-bold text-neutral-950 mb-1">User Accounts</h1>
    <p class="text-base text-neutral-600">Search buyers, sellers, and logistics providers by account status. Suspend approved accounts or reactivate suspended accounts.</p>
</div>

<div class="mb-6 bg-white border border-neutral-200 rounded-lg p-5">
    <form method="GET" action="{{ route('admin.users.index') }}" class="flex flex-col lg:flex-row lg:items-end gap-4">
        <div class="flex-1">
            <label for="search" class="block text-[11px] font-semibold text-neutral-600 uppercase tracking-[0.5px] mb-2">Search</label>
            <input type="text" name="search" id="search" value="{{ $search }}" placeholder="Name, email, contact…"
                   class="w-full h-10 px-3 border border-neutral-300 rounded-lg text-base focus:ring-2 focus:ring-primary-400 focus:border-transparent" />
        </div>
        <div>
            <label for="type" class="block text-[11px] font-semibold text-neutral-600 uppercase tracking-[0.5px] mb-2">Account Type</label>
            <select name="type" id="type" class="w-full lg:w-40 h-10 px-3 border border-neutral-300 rounded-lg text-base">
                <option value="seller" {{ $type === 'seller' ? 'selected' : '' }}>Seller</option>
                <option value="logistics" {{ $type === 'logistics' ? 'selected' : '' }}>Logistics</option>
                <option value="buyer" {{ $type === 'buyer' ? 'selected' : '' }}>Buyer</option>
            </select>
        </div>
        <div>
            <label for="status" class="block text-[11px] font-semibold text-neutral-600 uppercase tracking-[0.5px] mb-2">Account Status</label>
            <select name="status" id="status" class="w-full lg:w-40 h-10 px-3 border border-neutral-300 rounded-lg text-base">
                <option value="" @selected($status === '')>All statuses</option>
                @foreach ($statuses as $value => $label)
                    <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="h-10 px-5 bg-primary-800 text-white font-semibold text-[13px] rounded-lg hover:bg-primary-900 transition-colors">
            Filter
        </button>
    </form>
</div>

<div class="bg-white border border-neutral-200 rounded-lg overflow-hidden">
    @if ($accounts->count() > 0)
        <table class="w-full text-base">
            <thead class="bg-neutral-50 border-b border-neutral-200">
                <tr>
                    <th class="px-6 py-4 text-left font-semibold text-neutral-700">Name</th>
                    <th class="px-6 py-4 text-left font-semibold text-neutral-700">Email</th>
                    @if ($type === 'logistics')
                        <th class="px-6 py-4 text-left font-semibold text-neutral-700">Province</th>
                    @endif
                    <th class="px-6 py-4 text-left font-semibold text-neutral-700">Status</th>
                    <th class="px-6 py-4 text-center font-semibold text-neutral-700">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200">
                @foreach ($accounts as $account)
                    @php
                        $statusValue = $account->status instanceof \BackedEnum ? $account->status->value : $account->status;
                        $blockers = ($provinceBlockers ?? [])[$account->id] ?? [];
                    @endphp
                    <tr class="hover:bg-neutral-50">
                        <td class="px-6 py-4 font-semibold text-neutral-950">
                            {{ $account->fullName() }}
                            @if (! empty($account->business_name))
                                <p class="text-xs text-neutral-500 font-normal">{{ $account->business_name }}</p>
                            @endif
                        </td>
                        <td class="px-6 py-4 text-neutral-700">{{ $account->email }}</td>
                        @if ($type === 'logistics')
                            <td class="px-6 py-4 text-sm text-neutral-700">
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
                        <td class="px-6 py-4">
                            @if ($statusValue === 'suspended')
                                <span class="inline-flex px-3 py-1 rounded-full text-xs font-semibold bg-red-50 text-red-700">Suspended</span>
                            @elseif ($statusValue === 'approved')
                                <span class="inline-flex px-3 py-1 rounded-full text-xs font-semibold bg-green-50 text-green-700">Approved</span>
                            @else
                                <span class="inline-flex px-3 py-1 rounded-full text-xs font-semibold bg-neutral-100 text-neutral-700">{{ $statuses[$statusValue] ?? ucfirst($statusValue) }}</span>
                            @endif
                        </td>
                        <td class="px-6 py-4 text-center">
                            @if ($statusValue === 'approved')
                                <form method="POST" action="{{ route(match ($type) {
                                    'buyer' => 'admin.users.buyer.suspend',
                                    'logistics' => 'admin.users.logistics.suspend',
                                    default => 'admin.users.seller.suspend',
                                }, $account) }}" class="inline" onsubmit="return confirm('Suspend this account?');">
                                    @csrf
                                    <label for="suspend-reason-{{ $account->id }}" class="sr-only">Reason for suspension</label>
                                    <input id="suspend-reason-{{ $account->id }}" name="reason" required maxlength="1000" placeholder="Reason for suspension"
                                           class="mb-2 w-full h-10 px-3 border border-neutral-300 rounded-lg text-sm" />
                                    <button type="submit" class="px-3 py-1.5 text-sm font-semibold text-red-700 bg-red-50 rounded-lg hover:bg-red-100">Suspend</button>
                                </form>
                            @elseif ($statusValue === 'suspended')
                                <form method="POST" action="{{ route(match ($type) {
                                    'buyer' => 'admin.users.buyer.reactivate',
                                    'logistics' => 'admin.users.logistics.reactivate',
                                    default => 'admin.users.seller.reactivate',
                                }, $account) }}" class="inline" onsubmit="return confirm('Reactivate this account?');">
                                    @csrf
                                    <label for="reactivate-reason-{{ $account->id }}" class="sr-only">Reason for reactivation</label>
                                    <input id="reactivate-reason-{{ $account->id }}" name="reason" required maxlength="1000" placeholder="Reason for reactivation"
                                           class="mb-2 w-full h-10 px-3 border border-neutral-300 rounded-lg text-sm" />
                                    <button type="submit" class="px-3 py-1.5 text-sm font-semibold text-green-700 bg-green-50 rounded-lg hover:bg-green-100">Reactivate</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <div class="px-6 py-4 border-t border-neutral-200">
            {{ $accounts->links() }}
        </div>
    @else
        <div class="px-6 py-16 text-center text-neutral-500">No accounts match your filters.</div>
    @endif
</div>

@endsection
