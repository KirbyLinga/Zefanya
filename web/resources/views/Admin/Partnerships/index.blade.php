@extends('Admin.Layouts.app')

@section('title', 'Partnership Approvals')

@section('content')
<x-admin.tabs :tabs="[
    ['label' => 'Hub approvals', 'route' => 'admin.hubs.index'],
    ['label' => 'Partnerships', 'route' => 'admin.partnerships.index'],
]" />
<div data-partnership-approvals>
    @if (session('success'))
        <div class="mb-6 rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-700">{{ session('success') }}</div>
    @endif

    @if (session('error'))
        <div class="mb-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">{{ session('error') }}</div>
    @endif

    @if ($errors->any())
        <div class="mb-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">
            <ul class="list-disc pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="mb-8">
        <h1 class="mb-1 text-[28px] font-bold text-neutral-950">Partnership Approvals</h1>
        <p class="text-base text-neutral-600">Review accepted cross-provider links, set routing priority, and revoke approvals when a route must stop.</p>
    </div>

    @php
        $statusStyles = [
            'PENDING' => ['bg' => 'bg-orange-50', 'text' => 'text-orange-700', 'border' => 'border-orange-200', 'label' => 'Pending'],
            'ACTIVE' => ['bg' => 'bg-green-50', 'text' => 'text-green-700', 'border' => 'border-green-200', 'label' => 'Active'],
            'REJECTED' => ['bg' => 'bg-red-50', 'text' => 'text-red-700', 'border' => 'border-red-200', 'label' => 'Rejected'],
            'REVOKED' => ['bg' => 'bg-neutral-100', 'text' => 'text-neutral-700', 'border' => 'border-neutral-300', 'label' => 'Revoked'],
            'INACTIVE' => ['bg' => 'bg-neutral-100', 'text' => 'text-neutral-600', 'border' => 'border-neutral-200', 'label' => 'Inactive'],
        ];
    @endphp

    <div class="mb-8 grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            ['label' => 'Pending Review', 'key' => 'PENDING', 'icon' => 'clock', 'bg' => 'bg-orange-100', 'text' => 'text-orange-600'],
            ['label' => 'Active Links', 'key' => 'ACTIVE', 'icon' => 'circle-check', 'bg' => 'bg-green-100', 'text' => 'text-green-600'],
            ['label' => 'Rejected', 'key' => 'REJECTED', 'icon' => 'x-circle', 'bg' => 'bg-red-100', 'text' => 'text-red-600'],
            ['label' => 'Revoked', 'key' => 'REVOKED', 'icon' => 'shield-off', 'bg' => 'bg-neutral-100', 'text' => 'text-neutral-600'],
        ] as $card)
            <div class="rounded-lg border border-neutral-200 bg-white p-6 shadow-sm transition-shadow hover:shadow-md">
                <div class="flex items-start gap-4">
                    <div class="flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-full {{ $card['bg'] }}">
                        <i data-lucide="{{ $card['icon'] }}" class="h-6 w-6 {{ $card['text'] }}"></i>
                    </div>
                    <div class="flex-1">
                        <p class="text-[13px] font-medium uppercase tracking-[0.5px] text-neutral-600">{{ $card['label'] }}</p>
                        <p class="mt-1 text-[32px] font-bold text-neutral-950">{{ (int) ($counts[$card['key']] ?? 0) }}</p>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    @if (($handoffExceptions ?? collect())->isNotEmpty())
        <section class="mb-6 rounded-lg border border-red-200 bg-red-50 p-5" aria-labelledby="handoffExceptionsAdminTitle">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 id="handoffExceptionsAdminTitle" class="flex items-center gap-2 text-base font-semibold text-red-900">
                        <i data-lucide="triangle-alert" class="h-4 w-4"></i>
                        Cross-provider handoff exceptions
                    </h2>
                    <p class="mt-1 text-sm text-red-800">Parcels with no eligible destination partner. They remain safely held by the origin provider until the route is restored.</p>
                </div>
                <span class="rounded-full border border-red-300 bg-white px-3 py-1 text-xs font-semibold text-red-800">{{ (int) ($openExceptionCount ?? 0) }} open</span>
            </div>
            <ul class="mt-4 space-y-2">
                @foreach ($handoffExceptions as $exception)
                    <li class="rounded-md border border-red-200 bg-white px-4 py-3 text-sm text-neutral-800">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="font-mono text-xs font-semibold">{{ $exception->sellerOrder?->tracking_number ?? 'Parcel #'.$exception->seller_order_id }}</span>
                            <span class="text-xs text-neutral-500">{{ $exception->created_at?->format('M j, Y g:i A') }}</span>
                        </div>
                        <p class="mt-1 text-xs text-neutral-600">
                            Origin: <span class="font-medium text-neutral-900">{{ $exception->originProvider?->business_name ?? '—' }}</span>
                            @if ($exception->sellerOrder?->order?->reference)
                                · Order {{ $exception->sellerOrder->order->reference }}
                            @endif
                        </p>
                        <p class="mt-1 text-xs text-red-800">{{ $exception->reason }}</p>
                        <p class="mt-2 text-xs font-semibold">{{ $exception->status }}</p>
                        @if ($exception->status === \App\Models\Logistics\ParcelHandoffException::OPEN)
                            <form method="POST" action="{{ route('admin.partnerships.exceptions.resolve', $exception) }}" class="mt-3 flex flex-wrap items-end gap-2">
                                @csrf
                                <label class="min-w-0 flex-1 text-xs">Resolution note
                                    <textarea name="resolution_note" required maxlength="1000" rows="2" class="mt-1 block w-full rounded-md border border-neutral-300 p-2">{{ old('resolution_note') }}</textarea>
                                </label>
                                <button class="rounded-md bg-primary-800 px-3 py-2 text-xs font-semibold text-white" type="submit">Resolve exception</button>
                            </form>
                        @else
                            <p class="mt-1 text-xs text-neutral-600">{{ $exception->resolved_at?->format('M j, Y g:i A') }} · {{ $exception->resolver?->name ?? 'Automatic route recovery' }}</p>
                            <p class="mt-1 text-xs text-neutral-700">{{ $exception->resolution_note }}</p>
                        @endif
                    </li>
                @endforeach
            </ul>
            <div class="mt-4">{{ $handoffExceptions->links() }}</div>
        </section>
    @endif

    <div class="mb-6 rounded-lg border border-neutral-200 bg-white p-5 shadow-sm">
        <form method="GET" action="{{ route('admin.partnerships.index') }}" class="flex flex-wrap items-center gap-4">
            <div class="min-w-[240px] flex-1">
                <label for="partnershipSearch" class="sr-only">Search</label>
                <div class="relative">
                    <i data-lucide="search" class="absolute left-3 top-2.5 h-4 w-4 text-neutral-400"></i>
                    <input id="partnershipSearch" type="search" name="search" value="{{ $search }}" placeholder="Search provider, province…" class="w-full rounded-md border border-neutral-300 bg-white py-2 pl-9 pr-3 text-sm focus:outline-none focus:ring-1 focus:ring-primary-800">
                </div>
            </div>

            <div>
                <label for="partnershipStatus" class="sr-only">Status</label>
                <select id="partnershipStatus" name="status" class="rounded-md border border-neutral-300 bg-white px-3 py-2 text-sm text-neutral-800">
                    <option value="pending" @selected($status === 'pending')>Pending Review</option>
                    <option value="active" @selected($status === 'active')>Active</option>
                    <option value="rejected" @selected($status === 'rejected')>Rejected</option>
                    <option value="revoked" @selected($status === 'revoked')>Revoked</option>
                    <option value="inactive" @selected($status === 'inactive')>Inactive</option>
                    <option value="all" @selected($status === 'all')>All Statuses</option>
                </select>
            </div>

            <div>
                <label for="partnershipPerPage" class="sr-only">Rows per page</label>
                <select id="partnershipPerPage" name="per_page" class="rounded-md border border-neutral-300 bg-white px-3 py-2 text-sm text-neutral-800">
                    @foreach ([10, 25, 50] as $size)
                        <option value="{{ $size }}" @selected($perPage === $size)>{{ $size }} per page</option>
                    @endforeach
                </select>
            </div>

            <button type="submit" class="rounded-md bg-primary-800 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-900">Filter</button>
            <a href="{{ route('admin.partnerships.index') }}" class="rounded-md border border-neutral-300 px-3 py-2 text-sm text-neutral-600 hover:bg-neutral-50">Reset</a>
        </form>
    </div>

    <div class="mb-6 overflow-hidden rounded-lg border border-neutral-200 bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table class="w-full border-collapse text-left">
                <thead>
                    <tr class="border-b border-neutral-200 bg-neutral-50 text-xs font-semibold uppercase tracking-wider text-neutral-600">
                        <th class="px-4 py-3.5">Directed route</th>
                        <th class="px-4 py-3.5">Destination coverage</th>
                        <th class="px-4 py-3.5">Status</th>
                        <th class="px-4 py-3.5">Priority</th>
                        <th class="px-4 py-3.5">Consent</th>
                        <th class="px-4 py-3.5">Requested</th>
                        <th class="px-4 py-3.5 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 text-sm">
                    @forelse ($links as $link)
                        @php
                            $style = $statusStyles[$link->status] ?? ['bg' => 'bg-neutral-100', 'text' => 'text-neutral-700', 'border' => 'border-neutral-200', 'label' => ucfirst(strtolower($link->status))];
                            $canSetPriority = in_array($link->status, [\App\Models\Logistics\LogisticsProviderLink::PENDING, \App\Models\Logistics\LogisticsProviderLink::ACTIVE], true);
                            $awaitingDestination = $link->isPending() && $link->destination_consented_at === null;
                            $awaitingApproval = $link->isPending() && $link->destination_consented_at !== null && $link->approved_at === null;
                        @endphp
                        <tr class="align-top transition-colors hover:bg-neutral-50/75">
                            <td class="px-4 py-4">
                                <p class="font-semibold text-neutral-900">{{ $link->originProvider?->business_name ?? 'Provider' }}</p>
                                <p class="mt-1 flex items-center gap-1 text-xs text-neutral-500"><i data-lucide="arrow-down" class="h-3 w-3"></i>{{ $link->destinationProvider?->business_name ?? 'Provider' }}</p>
                                <p class="mt-1 text-[11px] text-neutral-400">{{ $link->originProvider?->province_name ?? '—' }} <span aria-hidden="true">→</span> {{ $link->destinationProvider?->province_name ?? '—' }}</p>
                            </td>

                            <td class="px-4 py-4">
                                <p class="text-xs font-medium text-neutral-800">{{ $link->destinationProvider?->province_name ?? '—' }}</p>
                                <p class="mt-1 text-[11px] text-neutral-500">{{ $link->destinationProvider?->business_name ?? '—' }}</p>
                                <p class="mt-1 text-[10px] text-neutral-400">Verified province · operational coverage required</p>
                            </td>

                            <td class="px-4 py-4">
                                <span class="inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-semibold {{ $style['bg'] }} {{ $style['text'] }} {{ $style['border'] }}">
                                    {{ $style['label'] }}
                                </span>
                                @if ($link->approved_at)
                                    <p class="mt-1 text-[11px] text-neutral-500">By {{ $link->approver?->name ?? 'Admin' }} · {{ $link->approved_at->format('M d, Y h:i A') }}</p>
                                @endif
                                @if ($link->rejected_at)
                                    <p class="mt-1 max-w-[190px] break-words text-[11px] text-red-600"><span class="font-semibold">Reason:</span> {{ $link->rejection_reason }}</p>
                                    <p class="text-[11px] text-neutral-500">By {{ $link->rejector?->business_name ?? 'Destination provider' }} · {{ $link->rejected_at->format('M d, Y h:i A') }}</p>
                                @endif
                                @if ($link->revoked_at)
                                    <p class="mt-1 max-w-[190px] break-words text-[11px] text-neutral-700"><span class="font-semibold">Reason:</span> {{ $link->revocation_reason }}</p>
                                    <p class="text-[11px] text-neutral-500">By {{ $link->revoker?->name ?? 'Admin' }} · {{ $link->revoked_at->format('M d, Y h:i A') }}</p>
                                @endif
                            </td>

                            <td class="px-4 py-4">
                                @if ($canSetPriority)
                                    <form method="POST" action="{{ route('admin.partnerships.priority', $link) }}" class="flex items-center gap-1.5">
                                        @csrf
                                        @method('PATCH')
                                        <label for="priority_{{ $link->id }}" class="sr-only">Priority</label>
                                        <input id="priority_{{ $link->id }}" type="number" name="priority" value="{{ $link->priority }}" min="0" max="1000000" required class="h-8 w-20 rounded border border-neutral-300 px-2 text-xs focus:outline-none focus:ring-1 focus:ring-primary-800">
                                        <button type="submit" class="h-8 rounded bg-neutral-800 px-2 text-[10px] font-semibold text-white hover:bg-neutral-900">Save</button>
                                    </form>
                                @else
                                    <span class="text-sm font-semibold text-neutral-700">{{ $link->priority }}</span>
                                @endif
                            </td>

                            <td class="px-4 py-4">
                                @if ($link->destination_consented_at)
                                    <p class="text-xs font-medium text-green-700">Destination accepted</p>
                                    <p class="mt-1 text-[11px] text-neutral-500">{{ $link->destination_consented_at->format('M d, Y h:i A') }}</p>
                                @else
                                    <p class="text-xs font-medium text-amber-700">Awaiting destination</p>
                                    <p class="mt-1 text-[11px] text-neutral-500">Origin requested {{ $link->created_at?->format('M d, Y') ?? '—' }}</p>
                                @endif
                            </td>

                            <td class="whitespace-nowrap px-4 py-4 text-xs text-neutral-500">
                                {{ $link->created_at?->format('M d, Y') ?? '—' }}
                                <span class="block text-[11px] text-neutral-400">{{ $link->created_at?->format('h:i A') ?? '' }}</span>
                            </td>

                            <td class="px-4 py-4 text-right">
                                @if ($link->isPending())
                                    @if ($awaitingApproval)
                                        <form method="POST" action="{{ route('admin.partnerships.approve', $link) }}" class="inline-flex flex-wrap items-center justify-end gap-1.5">
                                            @csrf
                                            <label for="approve_priority_{{ $link->id }}" class="sr-only">Priority</label>
                                            <input id="approve_priority_{{ $link->id }}" type="number" name="priority" value="{{ $link->priority }}" min="0" max="1000000" required class="h-8 w-20 rounded border border-neutral-300 px-2 text-xs focus:outline-none focus:ring-1 focus:ring-primary-800">
                                            <button type="submit" class="h-8 rounded bg-green-700 px-3 text-xs font-semibold text-white hover:bg-green-800">Approve</button>
                                        </form>
                                    @elseif ($awaitingDestination)
                                        <button type="button" disabled title="The destination provider must accept before Admin approval." class="inline-flex h-8 cursor-not-allowed items-center rounded bg-neutral-200 px-3 text-xs font-semibold text-neutral-400">Approve</button>
                                        <p class="mt-1 text-[10px] text-neutral-400">Destination response required</p>
                                    @else
                                        <span class="text-[11px] text-neutral-400">Awaiting destination acceptance</span>
                                    @endif
                                @elseif ($link->isActive())
                                    <div class="inline-flex flex-col items-end gap-1.5">
                                        <button type="button" data-revoke-link-id="{{ $link->id }}" data-revoke-link-label="{{ $link->originProvider?->business_name }} → {{ $link->destinationProvider?->business_name }}" class="inline-flex h-8 items-center rounded bg-red-700 px-3 text-xs font-semibold text-white hover:bg-red-800">Revoke approval</button>
                                        <span class="text-[10px] text-neutral-400">Blocks new handoffs</span>
                                    </div>
                                @else
                                    <span class="text-xs text-neutral-400">No action</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-12 text-center text-neutral-500">
                                <i data-lucide="handshake" class="mx-auto mb-2 h-8 w-8 text-neutral-300"></i>
                                <p class="font-medium text-neutral-800">No partnership links found</p>
                                <p class="mt-1 text-xs text-neutral-400">Accepted provider requests appear here for Admin approval.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($links->hasPages())
            <div class="border-t border-neutral-200 bg-neutral-50 px-4 py-3">
                {{ $links->links() }}
            </div>
        @endif
    </div>

    <div id="revokePartnershipModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-neutral-900/60 p-4 backdrop-blur-xs">
        <div class="w-full max-w-md overflow-hidden rounded-lg border border-neutral-200 bg-white shadow-xl">
            <div class="flex items-center justify-between border-b border-neutral-200 px-6 py-4">
                <h3 class="font-serif text-lg font-bold text-neutral-900">Revoke Partnership Approval</h3>
                <button type="button" data-close-revoke class="text-xl font-bold text-neutral-400 hover:text-neutral-600">&times;</button>
            </div>

            <form id="revokePartnershipForm" method="POST" action="" class="p-6">
                @csrf
                <p class="mb-4 text-sm text-neutral-600">Revoke <span id="revokePartnershipName" class="font-semibold text-neutral-900"></span>. Existing handoff history stays intact; new handoffs will be blocked.</p>

                <div class="mb-4">
                    <label for="revocationReason" class="mb-1 block text-xs font-semibold uppercase tracking-wider text-neutral-600">Revocation reason <span class="text-red-600">*</span></label>
                    <textarea id="revocationReason" name="revocation_reason" required maxlength="500" rows="4" class="w-full rounded-md border border-neutral-300 p-3 text-sm focus:outline-none focus:ring-1 focus:ring-primary-800" placeholder="Explain why this partnership can no longer operate."></textarea>
                </div>

                <div class="flex justify-end gap-3 border-t border-neutral-100 pt-2">
                    <button type="button" data-close-revoke class="rounded-md border border-neutral-300 px-4 py-2 text-sm font-semibold text-neutral-700 hover:bg-neutral-50">Cancel</button>
                    <button type="submit" class="rounded-md bg-red-700 px-4 py-2 text-sm font-semibold text-white hover:bg-red-800">Confirm Revocation</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
    <script>
        (function () {
            var baseUrl = @json(route('admin.partnerships.index'));
            var modal = document.getElementById('revokePartnershipModal');
            var form = document.getElementById('revokePartnershipForm');
            var name = document.getElementById('revokePartnershipName');
            var reason = document.getElementById('revocationReason');

            function closeModal() {
                if (!modal) return;
                modal.classList.add('hidden');
                modal.classList.remove('flex');
            }

            document.querySelectorAll('[data-revoke-link-id]').forEach(function (button) {
                button.addEventListener('click', function () {
                    if (!modal || !form) return;
                    form.action = baseUrl + '/' + button.dataset.revokeLinkId + '/revoke';
                    if (name) name.textContent = button.dataset.revokeLinkLabel || 'this partnership';
                    if (reason) reason.value = '';
                    modal.classList.remove('hidden');
                    modal.classList.add('flex');
                });
            });

            document.querySelectorAll('[data-close-revoke]').forEach(function (button) {
                button.addEventListener('click', closeModal);
            });

            if (modal) {
                modal.addEventListener('click', function (event) {
                    if (event.target === modal) closeModal();
                });
            }
        })();
    </script>
@endpush
