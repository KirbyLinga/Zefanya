@extends('Admin.Layouts.app')

@section('title', 'Logistics Hub Approvals')

@section('content')
<x-admin.tabs :tabs="[
    ['label' => 'Hub approvals', 'route' => 'admin.hubs.index'],
    ['label' => 'Partnerships', 'route' => 'admin.partnerships.index'],
]" />

@php
    $statusColors = [
        'pending' => ['bg' => 'bg-orange-50', 'text' => 'text-orange-700', 'border' => 'border-orange-200', 'label' => 'Pending Review'],
        'approved' => ['bg' => 'bg-green-50', 'text' => 'text-green-700', 'border' => 'border-green-200', 'label' => 'Approved'],
        'rejected' => ['bg' => 'bg-red-50', 'text' => 'text-red-700', 'border' => 'border-red-200', 'label' => 'Rejected'],
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
<div class="mb-8 flex flex-wrap items-end justify-between gap-4">
    <div>
        <h1 class="text-[28px] font-bold text-neutral-950 mb-1">Hub Approvals</h1>
        <p class="text-base text-neutral-600">Provision Province Hubs for approved providers, then review Station Hub submissions.</p>
    </div>
</div>

@if (($providersNeedingProvinceHub ?? collect())->isNotEmpty())
    <div class="bg-white border border-indigo-200 rounded-lg shadow-sm overflow-hidden mb-6">
        <div class="px-5 py-4 border-b border-indigo-200 bg-indigo-50">
            <h2 class="font-serif text-lg font-bold text-indigo-950">Provision Province Hub</h2>
            <p class="text-xs text-indigo-800 mt-1">Approved providers with a verified province and no live Province Hub. Facility address must sit inside their verified province.</p>
        </div>
        <form method="POST" action="{{ route('admin.hubs.province.provision') }}" class="p-5 grid grid-cols-1 md:grid-cols-2 gap-4">
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
                <label class="block text-[11px] font-semibold text-neutral-600 uppercase tracking-[0.5px] mb-2">Hub name (optional)</label>
                <input name="name" value="{{ old('name') }}" maxlength="120" class="w-full h-10 px-3 border border-neutral-300 rounded-lg text-sm" placeholder="Defaults to “{Province} Province Hub”">
            </div>
            <div>
                <label class="block text-[11px] font-semibold text-neutral-600 uppercase tracking-[0.5px] mb-2">Region code *</label>
                <input name="region_code" required value="{{ old('region_code') }}" maxlength="20" class="w-full h-10 px-3 border border-neutral-300 rounded-lg text-sm" placeholder="e.g. 030000000">
                <input type="hidden" name="region_name" value="{{ old('region_name') }}">
            </div>
            <div>
                <label class="block text-[11px] font-semibold text-neutral-600 uppercase tracking-[0.5px] mb-2">City / municipality code *</label>
                <input name="city_municipality_code" required value="{{ old('city_municipality_code') }}" maxlength="20" class="w-full h-10 px-3 border border-neutral-300 rounded-lg text-sm" placeholder="PSGC city code">
                <input type="hidden" name="municipality_name" value="{{ old('municipality_name') }}">
            </div>
            <div>
                <label class="block text-[11px] font-semibold text-neutral-600 uppercase tracking-[0.5px] mb-2">Barangay code *</label>
                <input name="barangay_code" required value="{{ old('barangay_code') }}" maxlength="20" class="w-full h-10 px-3 border border-neutral-300 rounded-lg text-sm" placeholder="PSGC barangay code">
                <input type="hidden" name="barangay_name" value="{{ old('barangay_name') }}">
            </div>
            <div class="md:col-span-2">
                <label class="block text-[11px] font-semibold text-neutral-600 uppercase tracking-[0.5px] mb-2">Street address *</label>
                <input name="street" required value="{{ old('street') }}" maxlength="255" class="w-full h-10 px-3 border border-neutral-300 rounded-lg text-sm">
            </div>
            <div>
                <input name="building" value="{{ old('building') }}" maxlength="255" class="w-full h-10 px-3 border border-neutral-300 rounded-lg text-sm" placeholder="Building / warehouse">
            </div>
            <div>
                <input name="landmark" value="{{ old('landmark') }}" maxlength="255" class="w-full h-10 px-3 border border-neutral-300 rounded-lg text-sm" placeholder="Landmark">
            </div>
            <div>
                <input name="postal_code" value="{{ old('postal_code') }}" maxlength="20" class="w-full h-10 px-3 border border-neutral-300 rounded-lg text-sm" placeholder="Postal code">
            </div>
            <div>
                <input name="location_details" value="{{ old('location_details') }}" maxlength="255" class="w-full h-10 px-3 border border-neutral-300 rounded-lg text-sm" placeholder="Notes">
            </div>
            <div class="md:col-span-2">
                <button type="submit" class="inline-flex items-center px-4 py-2 bg-indigo-800 hover:bg-indigo-900 text-white rounded-md text-sm font-semibold">Provision Province Hub</button>
            </div>
        </form>
    </div>
@endif

{{-- Stat Cards Grid --}}
<div class="mb-8 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
    <div class="bg-white border border-neutral-200 rounded-lg p-6 shadow-sm hover:shadow-md transition-shadow">
        <div class="flex items-start gap-4">
            <div class="flex-shrink-0 w-12 h-12 bg-orange-100 rounded-full flex items-center justify-center">
                <i data-lucide="clock" class="w-6 h-6 text-orange-600"></i>
            </div>
            <div class="flex-1">
                <p class="text-[13px] font-medium text-neutral-600 uppercase tracking-[0.5px]">Pending Review</p>
                <p class="text-[32px] font-bold text-neutral-950 mt-1">{{ $pendingCount }}</p>
            </div>
        </div>
    </div>

    <div class="bg-white border border-neutral-200 rounded-lg p-6 shadow-sm hover:shadow-md transition-shadow">
        <div class="flex items-start gap-4">
            <div class="flex-shrink-0 w-12 h-12 bg-green-100 rounded-full flex items-center justify-center">
                <i data-lucide="check-circle" class="w-6 h-6 text-green-600"></i>
            </div>
            <div class="flex-1">
                <p class="text-[13px] font-medium text-neutral-600 uppercase tracking-[0.5px]">Approved Hubs</p>
                <p class="text-[32px] font-bold text-neutral-950 mt-1">{{ $approvedCount }}</p>
            </div>
        </div>
    </div>

    <div class="bg-white border border-neutral-200 rounded-lg p-6 shadow-sm hover:shadow-md transition-shadow">
        <div class="flex items-start gap-4">
            <div class="flex-shrink-0 w-12 h-12 bg-red-100 rounded-full flex items-center justify-center">
                <i data-lucide="x-circle" class="w-6 h-6 text-red-600"></i>
            </div>
            <div class="flex-1">
                <p class="text-[13px] font-medium text-neutral-600 uppercase tracking-[0.5px]">Rejected Hubs</p>
                <p class="text-[32px] font-bold text-neutral-950 mt-1">{{ $rejectedCount }}</p>
            </div>
        </div>
    </div>

    <div class="bg-white border border-neutral-200 rounded-lg p-6 shadow-sm hover:shadow-md transition-shadow">
        <div class="flex items-start gap-4">
            <div class="flex-shrink-0 w-12 h-12 bg-blue-100 rounded-full flex items-center justify-center">
                <i data-lucide="network" class="w-6 h-6 text-blue-600"></i>
            </div>
            <div class="flex-1">
                <p class="text-[13px] font-medium text-neutral-600 uppercase tracking-[0.5px]">Total Network Hubs</p>
                <p class="text-[32px] font-bold text-neutral-950 mt-1">{{ $totalCount }}</p>
            </div>
        </div>
    </div>
</div>

@if ($locationChanges->isNotEmpty())
    {{-- Pending physical-location changes for already-approved hubs. --}}
    <div class="bg-white border border-amber-200 rounded-lg shadow-sm overflow-hidden mb-6">
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
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-semibold uppercase bg-amber-100 text-amber-800 border border-amber-200">Pending Location</span>
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
                            <button type="submit" class="inline-flex items-center px-3 py-1.5 bg-green-700 hover:bg-green-800 text-white rounded text-xs font-semibold shadow-xs">Approve Location</button>
                        </form>
                        <button type="button" onclick="openLocationRejectModal({{ $change->id }}, '{{ addslashes($change->hub?->name ?? 'Hub') }}')" class="inline-flex items-center px-3 py-1.5 bg-red-700 hover:bg-red-800 text-white rounded text-xs font-semibold shadow-xs">Reject</button>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
@endif

{{-- Filters Card --}}
<div class="bg-white border border-neutral-200 rounded-lg p-5 mb-6 shadow-sm">
    <form method="GET" action="{{ route('admin.hubs.index') }}" class="flex flex-wrap items-center gap-4">
        <div class="flex-1 min-w-[240px]">
            <label for="hubSearch" class="sr-only">Search</label>
            <div class="relative">
                <i data-lucide="search" class="absolute left-3 top-2.5 h-4 w-4 text-neutral-400"></i>
                <input id="hubSearch" type="search" name="search" value="{{ $search }}" placeholder="Search company, hub name, province, municipality..." class="w-full pl-9 pr-3 py-2 text-sm border border-neutral-300 rounded-md bg-white focus:outline-none focus:ring-1 focus:ring-primary-800">
            </div>
        </div>

        <div>
            <label for="statusFilter" class="sr-only">Status</label>
            <select id="statusFilter" name="status" class="px-3 py-2 text-sm border border-neutral-300 rounded-md bg-white text-neutral-800">
                <option value="pending" {{ $status === 'pending' ? 'selected' : '' }}>Pending Review</option>
                <option value="approved" {{ $status === 'approved' ? 'selected' : '' }}>Approved</option>
                <option value="rejected" {{ $status === 'rejected' ? 'selected' : '' }}>Rejected</option>
                <option value="all" {{ $status === 'all' ? 'selected' : '' }}>All Statuses</option>
            </select>
        </div>

        <div>
            <label for="typeFilter" class="sr-only">Hub Type</label>
            <select id="typeFilter" name="type" class="px-3 py-2 text-sm border border-neutral-300 rounded-md bg-white text-neutral-800">
                <option value="all" {{ $type === 'all' ? 'selected' : '' }}>All Hub Types</option>
                <option value="province_hub" {{ $type === 'province_hub' ? 'selected' : '' }}>Province Hubs</option>
                <option value="station_hub" {{ $type === 'station_hub' ? 'selected' : '' }}>Station Hubs</option>
            </select>
        </div>

        <button type="submit" class="px-4 py-2 bg-primary-800 hover:bg-primary-900 text-white rounded-md text-sm font-semibold">Filter</button>
        <a href="{{ route('admin.hubs.index') }}" class="px-3 py-2 border border-neutral-300 rounded-md text-sm text-neutral-600 hover:bg-neutral-50">Reset</a>
    </form>
</div>

{{-- Hubs Table --}}
<div class="bg-white border border-neutral-200 rounded-lg shadow-sm overflow-hidden mb-6">
    <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse">
            <thead>
                <tr class="bg-neutral-50 border-b border-neutral-200 text-neutral-600 text-xs font-semibold uppercase tracking-wider">
                    <th class="px-4 py-3.5">Logistics Company</th>
                    <th class="px-4 py-3.5">Hub Details</th>
                    <th class="px-4 py-3.5">Type</th>
                    <th class="px-4 py-3.5">Parent Hub</th>
                    <th class="px-4 py-3.5">Facility Address</th>
                    <th class="px-4 py-3.5">Status</th>
                    <th class="px-4 py-3.5">Submitted</th>
                    <th class="px-4 py-3.5 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 text-sm">
                @forelse ($hubs as $hub)
                    <tr class="hover:bg-neutral-50/75 transition-colors">
                        <td class="px-4 py-4 align-top">
                            <p class="font-semibold text-neutral-900">{{ $hub->provider?->business_name ?? '—' }}</p>
                            <p class="text-xs text-neutral-500 mt-0.5">{{ $hub->provider?->email ?? '—' }}</p>
                            <p class="text-xs text-neutral-500">{{ $hub->provider?->contact_no ?? '—' }}</p>
                        </td>

                        <td class="px-4 py-4 align-top">
                            <p class="font-semibold text-neutral-900">{{ $hub->name }}</p>
                            @if ($hub->location_details)
                                <p class="text-xs text-neutral-500 mt-0.5 italic">{{ $hub->location_details }}</p>
                            @endif
                        </td>

                        <td class="px-4 py-4 align-top">
                            @if ($hub->hub_type === \App\Models\Logistics\LogisticsHub::PROVINCE)
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-indigo-50 text-indigo-700 border border-indigo-200">
                                    Province Hub
                                </span>
                            @else
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-purple-50 text-purple-700 border border-purple-200">
                                    Station Hub
                                </span>
                            @endif
                        </td>

                        <td class="px-4 py-4 align-top">
                            @if ($hub->hub_type === \App\Models\Logistics\LogisticsHub::STATION)
                                @if ($hub->parent)
                                    <div>
                                        <p class="font-medium text-neutral-900 text-xs">{{ $hub->parent->name }}</p>
                                        <span class="inline-flex items-center px-1.5 py-0.5 mt-1 rounded text-[10px] font-semibold {{ $hub->parent->isApproved() && $hub->parent->is_active ? 'bg-green-50 text-green-700' : 'bg-amber-50 text-amber-700' }}">
                                            {{ $hub->parent->isApproved() && $hub->parent->is_active ? 'Parent Active' : 'Parent Inactive/Pending' }}
                                        </span>
                                    </div>
                                @else
                                    <span class="text-xs text-red-600 font-semibold">Missing Parent</span>
                                @endif
                            @else
                                <span class="text-xs text-neutral-400">— (Root)</span>
                            @endif
                        </td>

                        <td class="px-4 py-4 align-top max-w-xs">
                            <p class="text-xs text-neutral-800">{{ $hub->fullAddress() ?: '—' }}</p>
                            @if ($hub->postal_code)
                                <p class="text-[11px] text-neutral-500 mt-0.5">Postal: {{ $hub->postal_code }}</p>
                            @endif
                        </td>

                        <td class="px-4 py-4 align-top">
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

                        <td class="px-4 py-4 align-top whitespace-nowrap text-xs text-neutral-500">
                            {{ $hub->created_at?->format('M d, Y') ?? '—' }}
                            <span class="block text-[11px] text-neutral-400">{{ $hub->created_at?->format('h:i A') ?? '' }}</span>
                        </td>

                        <td class="px-4 py-4 align-top text-right whitespace-nowrap">
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
                                            <button type="submit" onclick="return confirm('Approve hub \'{{ addslashes($hub->name) }}\'?');" class="inline-flex items-center px-3 py-1.5 bg-green-700 hover:bg-green-800 text-white rounded text-xs font-semibold shadow-xs">
                                                Approve
                                            </button>
                                        </form>
                                    @else
                                        <button type="button" disabled title="{{ $blockReason }}" class="inline-flex items-center px-3 py-1.5 bg-neutral-200 text-neutral-400 cursor-not-allowed rounded text-xs font-semibold">
                                            Approve
                                        </button>
                                    @endif

                                    <button type="button" onclick="openRejectModal({{ $hub->id }}, '{{ addslashes($hub->name) }}')" class="inline-flex items-center px-3 py-1.5 bg-red-700 hover:bg-red-800 text-white rounded text-xs font-semibold shadow-xs">
                                        Reject
                                    </button>
                                </div>
                            @elseif ($hub->isApproved())
                                <span class="text-xs text-neutral-400">Approved</span>
                            @else
                                <span class="text-xs text-neutral-400">Rejected</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-4 py-12 text-center text-neutral-500">
                            <i data-lucide="network" class="mx-auto h-8 w-8 text-neutral-300 mb-2"></i>
                            <p class="font-medium text-neutral-800">No hub applications found</p>
                            <p class="text-xs text-neutral-400 mt-1">When logistics providers submit new Province Hubs or Station Hubs, they will appear here for review.</p>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($hubs->hasPages())
        <div class="px-4 py-3 border-t border-neutral-200 bg-neutral-50">
            {{ $hubs->links() }}
        </div>
    @endif
</div>

{{-- Rejection Reason Modal --}}
<div id="rejectModal" class="fixed inset-0 z-50 hidden bg-neutral-900/60 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl border border-neutral-200 w-full max-w-md overflow-hidden">
        <div class="px-6 py-4 border-b border-neutral-200 flex items-center justify-between">
            <h3 class="font-serif text-lg font-bold text-neutral-900">Reject Hub Application</h3>
            <button type="button" onclick="closeRejectModal()" class="text-neutral-400 hover:text-neutral-600 text-xl font-bold">&times;</button>
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
                <button type="submit" class="px-4 py-2 bg-red-700 hover:bg-red-800 text-white rounded-md text-sm font-semibold">Confirm Rejection</button>
            </div>
        </form>
    </div>
</div>

{{-- Location Change Rejection Modal --}}
<div id="locationRejectModal" class="fixed inset-0 z-50 hidden bg-neutral-900/60 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl border border-neutral-200 w-full max-w-md overflow-hidden">
        <div class="px-6 py-4 border-b border-neutral-200 flex items-center justify-between">
            <h3 class="font-serif text-lg font-bold text-neutral-900">Reject Location Change</h3>
            <button type="button" onclick="closeLocationRejectModal()" class="text-neutral-400 hover:text-neutral-600 text-xl font-bold">&times;</button>
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
                <button type="submit" class="px-4 py-2 bg-red-700 hover:bg-red-800 text-white rounded-md text-sm font-semibold">Confirm Rejection</button>
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

@endsection

