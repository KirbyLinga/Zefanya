@extends('Admin.Layouts.app')

@section('title', 'Partnership Approvals')
@section('content-width', 'max-w-none')

@section('content')
<x-admin.tabs :tabs="[
    ['label' => 'Hub approvals', 'route' => 'admin.hubs.index'],
    ['label' => 'Partnerships', 'route' => 'admin.partnerships.index'],
]" />
<div data-partnership-approvals class="network-workspace">
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

    <header class="network-header">
        <p class="network-eyebrow">Logistics network</p>
        <h1>Partnership Approvals</h1>
        <p>Review accepted cross-provider links, set routing priority, and revoke approvals when a route must stop.</p>
    </header>

    @php
        $statusStyles = [
            'PENDING' => ['bg' => 'network-status-pending', 'text' => '', 'border' => '', 'label' => 'Pending'],
            'ACTIVE' => ['bg' => 'network-status-approved', 'text' => '', 'border' => '', 'label' => 'Active'],
            'REJECTED' => ['bg' => 'network-status-rejected', 'text' => '', 'border' => '', 'label' => 'Rejected'],
            'REVOKED' => ['bg' => 'bg-neutral-100', 'text' => 'text-neutral-700', 'border' => 'border-neutral-300', 'label' => 'Revoked'],
            'INACTIVE' => ['bg' => 'bg-neutral-100', 'text' => 'text-neutral-600', 'border' => 'border-neutral-200', 'label' => 'Inactive'],
        ];
    @endphp

    <section class="network-metrics" aria-label="Partnership overview">
        @foreach ([
            ['label' => 'Pending Review', 'key' => 'PENDING', 'icon' => 'clock', 'tone' => 'pending', 'caption' => 'Destination response or admin decision'],
            ['label' => 'Active Links', 'key' => 'ACTIVE', 'icon' => 'handshake', 'tone' => 'approved', 'caption' => 'Approved directed provider routes'],
            ['label' => 'Rejected', 'key' => 'REJECTED', 'icon' => 'circle-x', 'tone' => 'rejected', 'caption' => 'Requests declined by the destination'],
            ['label' => 'Revoked', 'key' => 'REVOKED', 'icon' => 'shield-off', 'tone' => 'total', 'caption' => 'Approval withdrawn for new handoffs'],
        ] as $card)
            <a href="{{ route('admin.partnerships.index', ['status' => strtolower($card['key']), 'per_page' => $perPage]) }}" class="network-metric network-tone-{{ $card['tone'] }}" @if ($status === strtolower($card['key'])) aria-current="true" @endif>
                <div><h2>{{ $card['label'] }}</h2><span class="network-metric-icon"><i data-lucide="{{ $card['icon'] }}" class="w-5 h-5" aria-hidden="true"></i></span></div><div class="network-metric-value"><strong>{{ number_format((int) ($counts[$card['key']] ?? 0)) }}</strong>@if ($status === strtolower($card['key']))<span class="network-current"><i data-lucide="check" aria-hidden="true"></i>Selected</span>@endif</div><p>{{ $card['caption'] }}</p>
            </a>
        @endforeach
    </section>
    <p class="network-scope">Network totals across all providers and routes. Select a card to open its review queue.</p>

    @if (($handoffExceptions ?? collect())->isNotEmpty())
        <section class="network-exceptions" aria-labelledby="handoffExceptionsAdminTitle">
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
            <div class="network-pagination network-exception-pagination">{{ $handoffExceptions->links() }}</div>
        </section>
    @endif

    <section class="network-filter-panel" aria-label="Partnership filters">
        <form data-admin-auto-filter method="GET" action="{{ route('admin.partnerships.index') }}" class="network-filters">
            <div class="network-search"><label for="partnershipSearch">Search partnerships</label><div><i data-lucide="search" class="w-4 h-4" aria-hidden="true"></i><input id="partnershipSearch" type="search" name="search" value="{{ $search }}" placeholder="Provider or province..."></div></div>
            <div><label for="partnershipStatus">Review status</label><select id="partnershipStatus" name="status">@foreach (['pending' => 'Pending Review', 'active' => 'Active', 'rejected' => 'Rejected', 'revoked' => 'Revoked', 'inactive' => 'Inactive', 'all' => 'All Statuses'] as $value => $label)<option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>@endforeach</select></div>
            <div><label for="partnershipPerPage">Rows per page</label><select id="partnershipPerPage" name="per_page">@foreach ([10, 25, 50] as $size)<option value="{{ $size }}" @selected($perPage === $size)>{{ $size }} per page</option>@endforeach</select></div>
            <a href="{{ route('admin.partnerships.index') }}" class="network-reset"><i data-lucide="rotate-ccw" class="w-4 h-4" aria-hidden="true"></i>Reset</a>
            <noscript><button type="submit" class="network-filter-submit">Apply filters</button></noscript>
        </form>
        <div class="network-results" role="status"><p>@if ($links->total() === 0)No partnerships found
        @else
            Showing <strong>{{ $links->firstItem() ?? 0 }} to {{ $links->lastItem() ?? 0 }}</strong> of <strong>{{ number_format($links->total()) }}</strong> partnerships
        @endif</p><p><span>Filters update automatically</span><i data-lucide="arrow-down-wide-narrow" class="w-4 h-4" aria-hidden="true"></i>Newest first</p></div>
    </section>

    <section class="network-table-panel" aria-label="Provider routes">
        <div class="network-table-heading"><h2>Provider routes</h2><span class="network-queue-label"><i data-lucide="list-filter" class="w-3.5 h-3.5" aria-hidden="true"></i>{{ (['pending' => 'Pending review', 'active' => 'Active', 'rejected' => 'Rejected', 'revoked' => 'Revoked', 'inactive' => 'Inactive', 'all' => 'All statuses'])[$status] }}</span></div>
        @if ($links->isNotEmpty())<p class="network-scroll-cue"><i data-lucide="move-horizontal" class="w-4 h-4" aria-hidden="true"></i>Scroll the table to view all details and actions</p>@endif
        <div class="network-table-scroll" role="region" aria-label="Partnership records" tabindex="0">
            <table class="w-full border-collapse text-left {{ $links->isEmpty() ? 'network-table-empty' : '' }}">
                <thead>
                    <tr class="border-b border-neutral-200">
                        <th>Directed route</th>
                        <th>Destination coverage</th>
                        <th>Status</th>
                        <th>Priority</th>
                        <th>Consent</th>
                        <th>Requested</th>
                        <th class="text-right">Actions</th>
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
                            <td>
                                <p class="font-semibold text-neutral-900">{{ $link->originProvider?->business_name ?? 'Provider' }}</p>
                                <p class="mt-1 flex items-center gap-1 text-xs text-neutral-500"><i data-lucide="arrow-down" class="h-3 w-3"></i>{{ $link->destinationProvider?->business_name ?? 'Provider' }}</p>
                                <p class="mt-1 text-[11px] text-neutral-600">{{ $link->originProvider?->province_name ?? '—' }} <span aria-hidden="true">→</span> {{ $link->destinationProvider?->province_name ?? '—' }}</p>
                            </td>

                            <td>
                                <p class="text-xs font-medium text-neutral-800">{{ $link->destinationProvider?->province_name ?? '—' }}</p>
                                <p class="mt-1 text-[11px] text-neutral-500">{{ $link->destinationProvider?->business_name ?? '—' }}</p>
                                <p class="mt-1 text-[11px] text-neutral-600">Verified province · operational coverage required</p>
                            </td>

                            <td>
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

                            <td>
                                @if ($canSetPriority)
                                    <form method="POST" action="{{ route('admin.partnerships.priority', $link) }}" class="flex items-center gap-1.5">
                                        @csrf
                                        @method('PATCH')
                                        <label for="priority_{{ $link->id }}" class="sr-only">Priority</label>
                                        <input id="priority_{{ $link->id }}" type="number" name="priority" value="{{ $link->priority }}" min="0" max="1000000" required class="h-8 w-20 rounded border border-neutral-300 px-2 text-xs focus:outline-none focus:ring-1 focus:ring-primary-800">
                                        <button type="submit" class="h-8 rounded bg-neutral-800 px-2 text-[11px] font-semibold text-white hover:bg-neutral-900">Save</button>
                                    </form>
                                @else
                                    <span class="text-sm font-semibold text-neutral-700">{{ $link->priority }}</span>
                                @endif
                            </td>

                            <td>
                                @if ($link->destination_consented_at)
                                    <p class="text-xs font-medium text-green-700">Destination accepted</p>
                                    <p class="mt-1 text-[11px] text-neutral-500">{{ $link->destination_consented_at->format('M d, Y h:i A') }}</p>
                                @else
                                    <p class="text-xs font-medium text-amber-700">Awaiting destination</p>
                                    <p class="mt-1 text-[11px] text-neutral-500">Origin requested {{ $link->created_at?->format('M d, Y') ?? '—' }}</p>
                                @endif
                            </td>

                            <td class="whitespace-nowrap text-xs text-neutral-500">
                                {{ $link->created_at?->format('M d, Y') ?? '—' }}
                                <span class="block text-[11px] text-neutral-600">{{ $link->created_at?->format('h:i A') ?? '' }}</span>
                            </td>

                            <td class="text-right">
                                @if ($link->isPending())
                                    @if ($awaitingApproval)
                                        <form method="POST" action="{{ route('admin.partnerships.approve', $link) }}" class="inline-flex flex-wrap items-center justify-end gap-1.5">
                                            @csrf
                                            <label for="approve_priority_{{ $link->id }}" class="sr-only">Priority</label>
                                            <input id="approve_priority_{{ $link->id }}" type="number" name="priority" value="{{ $link->priority }}" min="0" max="1000000" required class="h-8 w-20 rounded border border-neutral-300 px-2 text-xs focus:outline-none focus:ring-1 focus:ring-primary-800">
                                            <button type="submit" class="h-8 rounded bg-tertiary-700 px-3 text-xs font-semibold text-white hover:bg-tertiary-800">Approve</button>
                                        </form>
                                    @elseif ($awaitingDestination)
                                        <button type="button" disabled title="The destination provider must accept before Admin approval." class="inline-flex h-8 cursor-not-allowed items-center rounded bg-neutral-200 px-3 text-xs font-semibold text-neutral-600">Approve</button>
                                        <p class="mt-1 text-[11px] text-neutral-600">Destination response required</p>
                                    @else
                                        <span class="text-[11px] text-neutral-600">Awaiting destination acceptance</span>
                                    @endif
                                @elseif ($link->isActive())
                                    <div class="inline-flex flex-col items-end gap-1.5">
                                        <button type="button" data-revoke-link-id="{{ $link->id }}" data-revoke-link-label="{{ $link->originProvider?->business_name }} → {{ $link->destinationProvider?->business_name }}" class="inline-flex h-8 items-center rounded bg-error px-3 text-xs font-semibold text-white hover:bg-error/90">Revoke approval</button>
                                        <span class="text-[11px] text-neutral-600">Blocks new handoffs</span>
                                    </div>
                                @else
                                    <span class="text-xs text-neutral-600">No action</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-12 text-center text-neutral-500">
                                <span class="network-empty-icon"><i data-lucide="handshake" aria-hidden="true"></i></span>
                                @if ($search !== '')
                                    <h2 class="network-empty-title">No partnerships match your search</h2><p class="network-empty-text">Try another provider name or province.</p><a class="network-empty-link" href="{{ route('admin.partnerships.index', ['status' => $status, 'per_page' => $perPage]) }}">Clear search</a>
                                @elseif ($status === 'pending' && (int) ($counts['PENDING'] ?? 0) === 0 && collect($counts)->sum() > 0)
                                    <h2 class="network-empty-title">No partnerships waiting for review</h2><p class="network-empty-text">The review queue is clear. You can inspect existing provider routes.</p><a class="network-empty-link" href="{{ route('admin.partnerships.index', ['status' => (int) ($counts['ACTIVE'] ?? 0) > 0 ? 'active' : 'all', 'per_page' => $perPage]) }}">{{ (int) ($counts['ACTIVE'] ?? 0) > 0 ? 'View active partnerships' : 'View all partnerships' }}</a>
                                @else
                                    <h2 class="network-empty-title">{{ collect($counts)->sum() > 0 ? 'No partnerships in this queue' : 'No partnership requests yet' }}</h2><p class="network-empty-text">{{ collect($counts)->sum() > 0 ? 'Choose another status to inspect provider routes.' : 'Provider requests appear here for destination consent and admin review.' }}</p>@if (collect($counts)->sum() > 0)<a class="network-empty-link" href="{{ route('admin.partnerships.index', ['status' => 'all', 'per_page' => $perPage]) }}">View all partnerships</a>@endif
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($links->hasPages())
            <div class="network-pagination">
                {{ $links->links() }}
            </div>
        @endif
    </section>

    <div id="revokePartnershipModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-neutral-900/60 p-4 backdrop-blur-xs">
        <div class="w-full max-w-md overflow-hidden rounded-lg border border-neutral-200 bg-white shadow-xl">
            <div class="flex items-center justify-between border-b border-neutral-200 px-6 py-4">
                <h3 class="font-serif text-lg font-bold text-neutral-900">Revoke Partnership Approval</h3>
                <button type="button" data-close-revoke class="text-xl font-bold text-neutral-600 hover:text-neutral-600">&times;</button>
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
                    <button type="submit" class="rounded-md bg-error px-4 py-2 text-sm font-semibold text-white hover:bg-error/90">Confirm Revocation</button>
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
