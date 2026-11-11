@extends('Admin.Layouts.app')

@section('title', 'Logistics Hub Approvals')
@section('content-width', 'max-w-none')

@section('content')
<x-admin.tabs :tabs="[
    ['label' => 'Hub approvals', 'route' => 'admin.hubs.index'],
    ['label' => 'Partnerships', 'route' => 'admin.partnerships.index'],
]" />

<div class="network-workspace">
@php
    $statusColors = [
        'pending' => ['bg' => 'network-status-pending', 'text' => '', 'border' => '', 'label' => 'Pending Review'],
        'approved' => ['bg' => 'network-status-approved', 'text' => '', 'border' => '', 'label' => 'Approved'],
        'rejected' => ['bg' => 'network-status-rejected', 'text' => '', 'border' => '', 'label' => 'Rejected'],
    ];
@endphp

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
    <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg text-red-700 text-sm">
        <ul class="list-disc pl-5">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

{{-- Page Header --}}
<header class="network-header">
    <div>
        <p class="network-eyebrow">Logistics network</p>
        <h1>Hub Approvals</h1>
        <p>Provision Province Hubs for approved providers, then review Station Hub submissions.</p>
    </div>
</header>

@php
    $networkCards = [
        ['label' => 'Pending Review', 'value' => $pendingCount, 'status' => 'pending', 'icon' => 'clock', 'tone' => 'pending', 'caption' => 'Waiting for an admin decision'],
        ['label' => 'Approved Hubs', 'value' => $approvedCount, 'status' => 'approved', 'icon' => 'circle-check', 'tone' => 'approved', 'caption' => 'Reviewed and approved'],
        ['label' => 'Rejected Hubs', 'value' => $rejectedCount, 'status' => 'rejected', 'icon' => 'circle-x', 'tone' => 'rejected', 'caption' => 'Reviewed applications not approved'],
        ['label' => 'Total Network Hubs', 'value' => $totalCount, 'status' => 'all', 'icon' => 'network', 'tone' => 'total', 'caption' => 'Province and Station Hubs'],
    ];
@endphp
<section class="network-metrics" aria-label="Hub overview">
    @foreach ($networkCards as $card)
        <a href="{{ route('admin.hubs.index', ['status' => $card['status'], 'per_page' => $perPage]) }}" class="network-metric network-tone-{{ $card['tone'] }}" @if ($status === $card['status']) aria-current="true" @endif>
            <div><h2>{{ $card['label'] }}</h2><span class="network-metric-icon"><i data-lucide="{{ $card['icon'] }}" class="w-5 h-5" aria-hidden="true"></i></span></div>
            <div class="network-metric-value"><strong>{{ number_format($card['value']) }}</strong>@if ($status === $card['status'])<span class="network-current"><i data-lucide="check" aria-hidden="true"></i>Selected</span>@endif</div><p>{{ $card['caption'] }}</p>
        </a>
    @endforeach
</section>
<p class="network-scope">Network totals across all providers and hub types. Select a card to open its review queue.</p>

@if (($providersNeedingProvinceHub ?? collect())->isNotEmpty())
    <details class="network-provision" @if ($selectedProvider || request()->filled('provision_provider') || $errors->any()) open @endif>
        <summary><span class="network-provision-icon"><i data-lucide="building-2" class="w-5 h-5" aria-hidden="true"></i></span><span><strong>Provision Province Hub</strong><small>{{ $providersNeedingProvinceHub->count() }} approved {{ Str::plural('provider', $providersNeedingProvinceHub->count()) }} ready for a Province Hub</small></span><i data-lucide="chevron-down" class="network-provision-chevron w-4 h-4" aria-hidden="true"></i></summary>
        <p class="network-provision-description">Create a facility inside the provider's verified province. The provider's account address and this facility address are separate.</p>
        <form method="POST" action="{{ route('admin.hubs.province.provision') }}" class="network-provision-form grid grid-cols-1 md:grid-cols-2 gap-4">
            @csrf
            <div class="md:col-span-2">
                <label for="provisionProvider" class="block text-[11px] font-semibold text-neutral-600 uppercase tracking-[0.5px] mb-2">Logistics provider</label>
                <select id="provisionProvider" name="logistics_provider_id" required class="w-full h-10 px-3 border border-neutral-300 rounded-lg text-sm">
                    <option value="">Select provider…</option>
                    @foreach ($providersNeedingProvinceHub as $p)
                        <option value="{{ $p->id }}" @selected((string) old('logistics_provider_id', $selectedProvider?->id) === (string) $p->id)>
                            {{ $p->business_name }} — {{ $p->province_name }} ({{ $p->province_code }})
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="provision-name" class="block text-[11px] font-semibold text-neutral-600 uppercase tracking-[0.5px] mb-2">Hub name (optional)</label>
                <input id="provision-name" name="name" value="{{ old('name') }}" maxlength="120" class="w-full h-10 px-3 border border-neutral-300 rounded-lg text-sm" placeholder="Defaults to “{Province} Province Hub”">
            </div>
            <div>
                <label for="provision-region_code" class="block text-[11px] font-semibold text-neutral-600 uppercase tracking-[0.5px] mb-2">Region code *</label>
                <input id="provision-region_code" name="region_code" required value="{{ old('region_code') }}" maxlength="20" class="w-full h-10 px-3 border border-neutral-300 rounded-lg text-sm" placeholder="e.g. 030000000">
                <input type="hidden" name="region_name" value="{{ old('region_name') }}">
            </div>
            <div>
                <label for="provision-city_municipality_code" class="block text-[11px] font-semibold text-neutral-600 uppercase tracking-[0.5px] mb-2">City / municipality code *</label>
                <input id="provision-city_municipality_code" name="city_municipality_code" required value="{{ old('city_municipality_code') }}" maxlength="20" class="w-full h-10 px-3 border border-neutral-300 rounded-lg text-sm" placeholder="PSGC city code">
                <input type="hidden" name="municipality_name" value="{{ old('municipality_name') }}">
            </div>
            <div>
                <label for="provision-barangay_code" class="block text-[11px] font-semibold text-neutral-600 uppercase tracking-[0.5px] mb-2">Barangay code *</label>
                <input id="provision-barangay_code" name="barangay_code" required value="{{ old('barangay_code') }}" maxlength="20" class="w-full h-10 px-3 border border-neutral-300 rounded-lg text-sm" placeholder="PSGC barangay code">
                <input type="hidden" name="barangay_name" value="{{ old('barangay_name') }}">
            </div>
            <div class="md:col-span-2">
                <label for="provision-street" class="block text-[11px] font-semibold text-neutral-600 uppercase tracking-[0.5px] mb-2">Street address *</label>
                <input id="provision-street" name="street" required value="{{ old('street') }}" maxlength="255" class="w-full h-10 px-3 border border-neutral-300 rounded-lg text-sm">
            </div>
            <div>
                <label for="provision-building" class="network-field-label">Building / warehouse (optional)</label>
                <input id="provision-building" name="building" value="{{ old('building') }}" maxlength="255" class="w-full h-10 px-3 border border-neutral-300 rounded-lg text-sm" placeholder="Building / warehouse">
            </div>
            <div>
                <label for="provision-landmark" class="network-field-label">Landmark (optional)</label>
                <input id="provision-landmark" name="landmark" value="{{ old('landmark') }}" maxlength="255" class="w-full h-10 px-3 border border-neutral-300 rounded-lg text-sm" placeholder="Landmark">
            </div>
            <div>
                <label for="provision-postal_code" class="network-field-label">Postal code (optional)</label>
                <input id="provision-postal_code" name="postal_code" value="{{ old('postal_code') }}" maxlength="20" class="w-full h-10 px-3 border border-neutral-300 rounded-lg text-sm" placeholder="Postal code">
            </div>
            <div>
                <label for="provision-location_details" class="network-field-label">Notes (optional)</label>
                <input id="provision-location_details" name="location_details" value="{{ old('location_details') }}" maxlength="255" class="w-full h-10 px-3 border border-neutral-300 rounded-lg text-sm" placeholder="Notes">
            </div>
            <div class="md:col-span-2">
                <button type="submit" class="inline-flex items-center px-4 py-2 bg-primary-800 hover:bg-primary-900 text-white rounded-md text-sm font-semibold">Provision Province Hub</button>
            </div>
        </form>
    </details>
@endif

@if ($locationChanges->isNotEmpty())
    {{-- Pending physical-location changes for already-approved hubs. --}}
    <section class="network-location-panel">
        <div class="px-5 py-4 border-b border-amber-200 bg-amber-50">
            <h2 class="font-serif text-lg font-bold text-amber-950">Pending Location Changes</h2>
            <p class="text-xs text-amber-800 mt-1">These hubs stay approved at their current address until you approve the proposed location.</p>
        </div>
        <div class="divide-y divide-neutral-200">
            @foreach ($locationChanges as $change)
                <div class="p-4 flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="font-semibold text-neutral-900 text-sm">{{ $change->hub?->name ?? 'Hub' }}</p>
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-semibold uppercase bg-amber-100 text-amber-800 border border-amber-200">Pending Location</span>
                            <span class="text-xs text-neutral-500">{{ $change->hub?->hub_type === \App\Models\Logistics\LogisticsHub::PROVINCE ? 'Province Hub' : 'Station Hub' }}</span>
                        </div>
                        <p class="text-xs text-neutral-500 mt-1">{{ $change->hub?->provider?->business_name ?? '—' }} · requested {{ $change->requested_at?->diffForHumans() ?? '—' }}</p>
                        <div class="mt-2 grid grid-cols-1 md:grid-cols-2 gap-2 text-xs">
                            <p class="text-neutral-600"><span class="font-semibold text-neutral-800">Current:</span> {{ $change->hub?->fullAddress() ?: '—' }}</p>
                            <p class="text-neutral-800"><span class="font-semibold">Proposed:</span> {{ $change->proposedAddress() ?: '—' }}</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <form method="POST" action="{{ route('admin.hubs.location-changes.approve', $change) }}">
                            @csrf
                            <button type="submit" class="inline-flex items-center px-3 py-1.5 bg-tertiary-700 hover:bg-tertiary-800 text-white rounded text-xs font-semibold shadow-xs">Approve Location</button>
                        </form>
                        <button type="button" onclick="openLocationRejectModal({{ $change->id }}, '{{ addslashes($change->hub?->name ?? 'Hub') }}')" class="inline-flex items-center px-3 py-1.5 bg-error hover:bg-error/90 text-white rounded text-xs font-semibold shadow-xs">Reject</button>
                    </div>
                </div>
            @endforeach
        </div>
    </section>
@endif

<section class="network-filter-panel" aria-label="Hub filters">
    <form data-admin-auto-filter method="GET" action="{{ route('admin.hubs.index') }}" class="network-filters network-hub-filters">
        <div class="network-search"><label for="hubSearch">Search hubs</label><div><i data-lucide="search" class="w-4 h-4" aria-hidden="true"></i><input id="hubSearch" type="search" name="search" value="{{ $search }}" placeholder="Company, hub or location..."></div></div>
        <div><label for="statusFilter">Review status</label><select id="statusFilter" name="status">@foreach (['pending' => 'Pending Review', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All Statuses'] as $value => $label)<option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>@endforeach</select></div>
        <div><label for="typeFilter">Hub type</label><select id="typeFilter" name="type">@foreach (['all' => 'All Hub Types', 'province_hub' => 'Province Hubs', 'station_hub' => 'Station Hubs'] as $value => $label)<option value="{{ $value }}" @selected($type === $value)>{{ $label }}</option>@endforeach</select></div>
        <div><label for="hubPerPage">Rows per page</label><select id="hubPerPage" name="per_page">@foreach ([10, 25, 50] as $size)<option value="{{ $size }}" @selected($perPage === $size)>{{ $size }} per page</option>@endforeach</select></div>
        <a href="{{ route('admin.hubs.index') }}" class="network-reset"><i data-lucide="rotate-ccw" class="w-4 h-4" aria-hidden="true"></i>Reset</a>
            <noscript><button type="submit" class="network-filter-submit">Apply filters</button></noscript>
    </form>
    <div class="network-results" role="status"><p>@if ($hubs->total() === 0)No hubs found
        @else
            Showing <strong>{{ $hubs->firstItem() ?? 0 }} to {{ $hubs->lastItem() ?? 0 }}</strong> of <strong>{{ number_format($hubs->total()) }}</strong> hubs
        @endif</p><p><span>Filters update automatically</span><i data-lucide="arrow-down-wide-narrow" class="w-4 h-4" aria-hidden="true"></i>Newest first</p></div>
</section>

{{-- Hubs Table --}}
<section class="network-table-panel" aria-label="Hub directory">
        <div class="network-table-heading"><h2>Hub directory</h2><span class="network-queue-label"><i data-lucide="list-filter" class="w-3.5 h-3.5" aria-hidden="true"></i>{{ (['pending' => 'Pending review', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All statuses'])[$status] }}</span></div>
    @if ($hubs->isNotEmpty())<p class="network-scroll-cue"><i data-lucide="move-horizontal" class="w-4 h-4" aria-hidden="true"></i>Scroll the table to view all details and actions</p>@endif
    <div class="network-table-scroll" role="region" aria-label="Hub records" tabindex="0">
        <table class="w-full text-left border-collapse {{ $hubs->isEmpty() ? 'network-table-empty' : '' }}">
            <thead>
                <tr class="border-b border-neutral-200">
                    <th>Logistics Company</th>
                    <th>Hub Details</th>
                    <th>Type</th>
                    <th>Parent Hub</th>
                    <th>Facility Address</th>
                    <th>Status</th>
                    <th>Submitted</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 text-sm">
                @forelse ($hubs as $hub)
                    <tr class="hover:bg-neutral-50/75 transition-colors">
                        <td class="align-top">
                            <p class="font-semibold text-neutral-900">{{ $hub->provider?->business_name ?? '—' }}</p>
                            <p class="text-xs text-neutral-500 mt-0.5">{{ $hub->provider?->email ?? '—' }}</p>
                            <p class="text-xs text-neutral-500">{{ $hub->provider?->contact_no ?? '—' }}</p>
                        </td>

                        <td class="align-top">
                            <p class="font-semibold text-neutral-900">{{ $hub->name }}</p>
                            @if ($hub->location_details)
                                <p class="text-xs text-neutral-500 mt-0.5 italic">{{ $hub->location_details }}</p>
                            @endif
                        </td>

                        <td class="align-top">
                            @if ($hub->hub_type === \App\Models\Logistics\LogisticsHub::PROVINCE)
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-primary-50 text-primary-800 border border-primary-200">
                                    Province Hub
                                </span>
                            @else
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-tertiary-50 text-tertiary-800 border border-tertiary-200">
                                    Station Hub
                                </span>
                            @endif
                        </td>

                        <td class="align-top">
                            @if ($hub->hub_type === \App\Models\Logistics\LogisticsHub::STATION)
                                @if ($hub->parent)
                                    <div>
                                        <p class="font-medium text-neutral-900 text-xs">{{ $hub->parent->name }}</p>
                                        <span class="inline-flex items-center px-1.5 py-0.5 mt-1 rounded text-[11px] font-semibold {{ $hub->parent->isApproved() && $hub->parent->is_active ? 'bg-green-50 text-green-700' : 'bg-amber-50 text-amber-700' }}">
                                            {{ $hub->parent->isApproved() && $hub->parent->is_active ? 'Parent Active' : 'Parent Inactive/Pending' }}
                                        </span>
                                    </div>
                                @else
                                    <span class="text-xs text-red-600 font-semibold">Missing Parent</span>
                                @endif
                            @else
                                <span class="text-xs text-neutral-600">— (Root)</span>
                            @endif
                        </td>

                        <td class="align-top max-w-xs">
                            <p class="text-xs text-neutral-800">{{ $hub->fullAddress() ?: '—' }}</p>
                            @if ($hub->postal_code)
                                <p class="text-[11px] text-neutral-500 mt-0.5">Postal: {{ $hub->postal_code }}</p>
                            @endif
                        </td>

                        <td class="align-top">
                            @php
                                $cfg = $statusColors[$hub->approval_status] ?? ['bg' => 'bg-neutral-100', 'text' => 'text-neutral-700', 'border' => 'border-neutral-200', 'label' => ucfirst($hub->approval_status)];
                            @endphp
                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold border {{ $cfg['bg'] }} {{ $cfg['text'] }} {{ $cfg['border'] }}">
                                {{ $cfg['label'] }}
                            </span>
                            @if ($hub->isApproved())
                                <p class="text-[11px] mt-1 {{ $hub->is_active ? 'text-green-700 font-medium' : 'text-neutral-500' }}">
                                    Ops: {{ $hub->is_active ? 'Active' : 'Inactive' }}
                                </p>
                            @endif
                            @if ($hub->isApproved() && $hub->approved_at)
                                <p class="text-[11px] text-neutral-500 mt-1">
                                    By {{ $hub->approver?->name ?? 'Admin' }} · {{ $hub->approved_at->format('M d, Y h:i A') }}
                                </p>
                            @endif
                            @if ($hub->isRejected() && $hub->rejection_reason)
                                <p class="text-[11px] text-red-600 mt-1 max-w-[180px] break-words">
                                    <span class="font-semibold">Reason:</span> {{ $hub->rejection_reason }}
                                </p>
                            @endif
                            @if ($hub->isRejected() && $hub->rejected_at)
                                <p class="text-[11px] text-neutral-500 mt-1">
                                    By {{ $hub->rejector?->name ?? 'Admin' }} · {{ $hub->rejected_at->format('M d, Y h:i A') }}
                                </p>
                            @endif
                        </td>

                        <td class="align-top whitespace-nowrap text-xs text-neutral-500">
                            {{ $hub->created_at?->format('M d, Y') ?? '—' }}
                            <span class="block text-[11px] text-neutral-600">{{ $hub->created_at?->format('h:i A') ?? '' }}</span>
                        </td>

                        <td class="align-top text-right whitespace-nowrap">
                            @if ($hub->isPending())
                                @php
                                    $canApprove = true;
                                    $blockReason = null;
                                    if ($hub->hub_type === \App\Models\Logistics\LogisticsHub::STATION) {
                                        if (! $hub->parent || ! $hub->parent->isApproved() || ! $hub->parent->is_active) {
                                            $canApprove = false;
                                            $blockReason = 'Parent Province Hub must be approved & active first.';
                                        }
                                    }
                                @endphp

                                <div class="inline-flex items-center gap-2">
                                    @if ($canApprove)
                                        <form method="POST" action="{{ route('admin.hubs.approve', $hub) }}">
                                            @csrf
                                            <button type="submit" onclick="return confirm('Approve hub \'{{ addslashes($hub->name) }}\'?');" class="inline-flex items-center px-3 py-1.5 bg-tertiary-700 hover:bg-tertiary-800 text-white rounded text-xs font-semibold shadow-xs">
                                                Approve
                                            </button>
                                        </form>
                                    @else
                                        <button type="button" disabled title="{{ $blockReason }}" class="inline-flex items-center px-3 py-1.5 bg-neutral-200 text-neutral-600 cursor-not-allowed rounded text-xs font-semibold">
                                            Approve
                                        </button>
                                    @endif

                                    <button type="button" onclick="openRejectModal({{ $hub->id }}, '{{ addslashes($hub->name) }}')" class="inline-flex items-center px-3 py-1.5 bg-error hover:bg-error/90 text-white rounded text-xs font-semibold shadow-xs">
                                        Reject
                                    </button>
                                </div>
                                @if (! $canApprove)<p class="mt-2 max-w-xs whitespace-normal text-xs text-neutral-600">{{ $blockReason }}</p>@endif
                            @elseif ($hub->isApproved())
                                <span class="text-xs text-neutral-600">Approved</span>
                            @else
                                <span class="text-xs text-neutral-600">Rejected</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-4 py-12 text-center text-neutral-500">
                            <span class="network-empty-icon"><i data-lucide="network" aria-hidden="true"></i></span>
                            @if ($search !== '')
                                <h2 class="network-empty-title">No hubs match your search</h2><p class="network-empty-text">Try another company, hub name or location in this queue.</p><a class="network-empty-link" href="{{ route('admin.hubs.index', ['status' => $status, 'type' => $type, 'per_page' => $perPage]) }}">Clear search</a>
                            @elseif ($type !== 'all')
                                <h2 class="network-empty-title">No hubs of this type in this queue</h2><p class="network-empty-text">Choose another hub type or view all types with the same review status.</p><a class="network-empty-link" href="{{ route('admin.hubs.index', ['status' => $status, 'per_page' => $perPage]) }}">View all hub types</a>
                            @elseif ($status === 'pending' && $pendingCount === 0 && $totalCount > 0)
                                <h2 class="network-empty-title">No hubs waiting for review</h2><p class="network-empty-text">The review queue is clear. Existing hubs remain available in the network.</p><a class="network-empty-link" href="{{ route('admin.hubs.index', ['status' => $approvedCount > 0 ? 'approved' : 'all', 'per_page' => $perPage]) }}">{{ $approvedCount > 0 ? 'View approved hubs' : 'View all hubs' }}</a>
                            @else
                                <h2 class="network-empty-title">{{ $totalCount > 0 ? 'No hubs in this queue' : 'No network hubs yet' }}</h2><p class="network-empty-text">{{ $totalCount > 0 ? 'Choose another review status to explore the network.' : 'Provision a Province Hub for an eligible provider to start the network.' }}</p>@if ($totalCount > 0)<a class="network-empty-link" href="{{ route('admin.hubs.index', ['status' => 'all', 'per_page' => $perPage]) }}">View all hubs</a>@endif
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($hubs->hasPages())
        <div class="network-pagination">
            {{ $hubs->links() }}
        </div>
    @endif
</section>

{{-- Rejection Reason Modal --}}
<div id="rejectModal" class="fixed inset-0 z-50 hidden bg-neutral-900/60 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl border border-neutral-200 w-full max-w-md overflow-hidden">
        <div class="px-6 py-4 border-b border-neutral-200 flex items-center justify-between">
            <h3 class="font-serif text-lg font-bold text-neutral-900">Reject Hub Application</h3>
            <button type="button" onclick="closeRejectModal()" class="text-neutral-600 hover:text-neutral-600 text-xl font-bold">&times;</button>
        </div>

        <form id="rejectForm" method="POST" action="" class="p-6">
            @csrf
            <p class="text-sm text-neutral-600 mb-4">
                You are rejecting <span id="rejectHubName" class="font-semibold text-neutral-900"></span>. Please provide a clear rejection reason for the logistics provider:
            </p>

            <div class="mb-4">
                <label for="rejectionReason" class="block text-xs font-semibold uppercase tracking-wider text-neutral-600 mb-1">
                    Rejection Reason <span class="text-red-600">*</span>
                </label>
                <textarea id="rejectionReason" name="rejection_reason" required maxlength="500" rows="4" class="w-full p-3 text-sm border border-neutral-300 rounded-md focus:outline-none focus:ring-1 focus:ring-primary-800" placeholder="e.g. Address is incomplete or municipality is already covered by another facility."></textarea>
            </div>

            <div class="flex justify-end gap-3 pt-2 border-t border-neutral-100">
                <button type="button" onclick="closeRejectModal()" class="px-4 py-2 border border-neutral-300 rounded-md text-sm font-semibold text-neutral-700 hover:bg-neutral-50">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-error hover:bg-error/90 text-white rounded-md text-sm font-semibold">Confirm Rejection</button>
            </div>
        </form>
    </div>
</div>

{{-- Location Change Rejection Modal --}}
<div id="locationRejectModal" class="fixed inset-0 z-50 hidden bg-neutral-900/60 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl border border-neutral-200 w-full max-w-md overflow-hidden">
        <div class="px-6 py-4 border-b border-neutral-200 flex items-center justify-between">
            <h3 class="font-serif text-lg font-bold text-neutral-900">Reject Location Change</h3>
            <button type="button" onclick="closeLocationRejectModal()" class="text-neutral-600 hover:text-neutral-600 text-xl font-bold">&times;</button>
        </div>

        <form id="locationRejectForm" method="POST" action="" class="p-6">
            @csrf
            <p class="text-sm text-neutral-600 mb-4">
                Reject the proposed location for <span id="locationRejectHubName" class="font-semibold text-neutral-900"></span>. The current approved location will remain active.
            </p>

            <div class="mb-4">
                <label for="locationRejectionReason" class="block text-xs font-semibold uppercase tracking-wider text-neutral-600 mb-1">
                    Rejection Reason <span class="text-red-600">*</span>
                </label>
                <textarea id="locationRejectionReason" name="rejection_reason" required maxlength="500" rows="4" class="w-full p-3 text-sm border border-neutral-300 rounded-md focus:outline-none focus:ring-1 focus:ring-primary-800" placeholder="Explain why the proposed location cannot be approved."></textarea>
            </div>

            <div class="flex justify-end gap-3 pt-2 border-t border-neutral-100">
                <button type="button" onclick="closeLocationRejectModal()" class="px-4 py-2 border border-neutral-300 rounded-md text-sm font-semibold text-neutral-700 hover:bg-neutral-50">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-error hover:bg-error/90 text-white rounded-md text-sm font-semibold">Confirm Rejection</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openRejectModal(hubId, hubName) {
        var form = document.getElementById('rejectForm');
        form.action = '/admin/hubs/' + hubId + '/reject';
        document.getElementById('rejectHubName').textContent = hubName;
        document.getElementById('rejectionReason').value = '';
        document.getElementById('rejectModal').classList.remove('hidden');
    }

    function closeRejectModal() {
        document.getElementById('rejectModal').classList.add('hidden');
    }

    function openLocationRejectModal(changeId, hubName) {
        var form = document.getElementById('locationRejectForm');
        form.action = '/admin/hubs/location-changes/' + changeId + '/reject';
        document.getElementById('locationRejectHubName').textContent = hubName;
        document.getElementById('locationRejectionReason').value = '';
        document.getElementById('locationRejectModal').classList.remove('hidden');
    }

    function closeLocationRejectModal() {
        document.getElementById('locationRejectModal').classList.add('hidden');
    }
</script>

</div>
@endsection

