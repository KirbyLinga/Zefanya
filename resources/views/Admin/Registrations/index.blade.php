@extends('Admin.Layouts.app')

@section('title', 'Registration Management')

@section('content')

@php
    // Status colors and labels (updated for new status values)
    $statusColors = [
        'pending' => ['bg' => 'bg-orange-50', 'text' => 'text-orange-700', 'label' => 'Pending'],
        'approved' => ['bg' => 'bg-green-50', 'text' => 'text-green-700', 'label' => 'Approved'],
        'rejected' => ['bg' => 'bg-red-50', 'text' => 'text-red-700', 'label' => 'Rejected'],
        'suspended' => ['bg' => 'bg-neutral-100', 'text' => 'text-neutral-700', 'label' => 'Suspended'],
    ];

    // Account type badge colors
    $typeColors = [
        'buyer' => ['bg' => 'bg-blue-50', 'text' => 'text-blue-700', 'label' => 'Buyer'],
        'seller' => ['bg' => 'bg-rose-50', 'text' => 'text-rose-700', 'label' => 'Seller'],
        'logistics' => ['bg' => 'bg-green-50', 'text' => 'text-green-700', 'label' => 'Logistics'],
    ];

    // Prefer controller-passed values (defaults: status=pending, date_range=0 all-time).
    $searchQuery = $searchQuery ?? request('search', '');
    $accountType = $accountType ?? request('type', '');
    $status = $status ?? request('status', 'pending');
    $dateRange = $dateRange ?? request('date_range', 0);
    $perPage = $perPage ?? request('per_page', 25);
@endphp

{{-- Success message --}}
@if (session('success'))
    <div class="mb-6 p-4 bg-green-50 border border-green-200 rounded-lg text-green-700 text-sm">
        {{ session('success') }}
    </div>
@endif

{{-- Page Header --}}
<div class="mb-8">
    <h1 class="text-[28px] font-bold text-neutral-950 mb-1">Registration Management</h1>
    <p class="text-base text-neutral-600">Review and manage new user registrations based on your verification process.</p>
</div>

{{-- Stat Cards Grid (2x2 on tablet, 1 row on desktop) --}}
<div class="mb-8 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
    {{-- Pending Stat Card --}}
    <div class="bg-white border border-neutral-200 rounded-lg p-6 shadow-sm hover:shadow-md transition-shadow">
        <div class="flex items-start gap-4 mb-4">
            <div class="flex-shrink-0 w-12 h-12 bg-orange-100 rounded-full flex items-center justify-center">
                <i data-lucide="clock" class="w-6 h-6 text-orange-600"></i>
            </div>
            <div class="flex-1">
                <p class="text-[13px] font-medium text-neutral-600 uppercase tracking-[0.5px]">Pending Review</p>
                <p class="text-[32px] font-bold text-neutral-950 mt-1">{{ $pendingCount ?? 0 }}</p>
            </div>
        </div>
        @if (isset($pendingTrend))
            <p class="text-[13px] {{ $pendingTrend >= 0 ? 'text-orange-600' : 'text-green-600' }}">
                {{ $pendingTrend >= 0 ? '↑' : '↓' }} {{ abs($pendingTrend) }}% vs. last 7 days
            </p>
        @endif
    </div>

    {{-- Approved Stat Card --}}
    <div class="bg-white border border-neutral-200 rounded-lg p-6 shadow-sm hover:shadow-md transition-shadow">
        <div class="flex items-start gap-4 mb-4">
            <div class="flex-shrink-0 w-12 h-12 bg-green-100 rounded-full flex items-center justify-center">
                <i data-lucide="check-circle" class="w-6 h-6 text-green-600"></i>
            </div>
            <div class="flex-1">
                <p class="text-[13px] font-medium text-neutral-600 uppercase tracking-[0.5px]">Approved</p>
                <p class="text-[32px] font-bold text-neutral-950 mt-1">{{ $approvedCount ?? 0 }}</p>
            </div>
        </div>
        @if (isset($approvedTrend))
            <p class="text-[13px] {{ $approvedTrend >= 0 ? 'text-green-600' : 'text-red-600' }}">
                {{ $approvedTrend >= 0 ? '↑' : '↓' }} {{ abs($approvedTrend) }}% vs. last 7 days
            </p>
        @endif
    </div>

    {{-- Rejected Stat Card --}}
    <div class="bg-white border border-neutral-200 rounded-lg p-6 shadow-sm hover:shadow-md transition-shadow">
        <div class="flex items-start gap-4 mb-4">
            <div class="flex-shrink-0 w-12 h-12 bg-red-100 rounded-full flex items-center justify-center">
                <i data-lucide="x-circle" class="w-6 h-6 text-red-600"></i>
            </div>
            <div class="flex-1">
                <p class="text-[13px] font-medium text-neutral-600 uppercase tracking-[0.5px]">Rejected</p>
                <p class="text-[32px] font-bold text-neutral-950 mt-1">{{ $rejectedCount ?? 0 }}</p>
            </div>
        </div>
        @if (isset($rejectedTrend))
            <p class="text-[13px] {{ $rejectedTrend >= 0 ? 'text-red-600' : 'text-green-600' }}">
                {{ $rejectedTrend >= 0 ? '↑' : '↓' }} {{ abs($rejectedTrend) }}% vs. last 7 days
            </p>
        @endif
    </div>

    {{-- Total Stat Card --}}
    <div class="bg-white border border-neutral-200 rounded-lg p-6 shadow-sm hover:shadow-md transition-shadow">
        <div class="flex items-start gap-4 mb-4">
            <div class="flex-shrink-0 w-12 h-12 bg-purple-100 rounded-full flex items-center justify-center">
                <i data-lucide="users" class="w-6 h-6 text-purple-600"></i>
            </div>
            <div class="flex-1">
                <p class="text-[13px] font-medium text-neutral-600 uppercase tracking-[0.5px]">Total Registrations</p>
                <p class="text-[32px] font-bold text-neutral-950 mt-1">{{ ($pendingCount ?? 0) + ($approvedCount ?? 0) + ($rejectedCount ?? 0) }}</p>
            </div>
        </div>
        <p class="text-[13px] text-neutral-500">All time</p>
    </div>
</div>

{{-- Compact Filter Bar --}}
<div class="mb-6 bg-white border border-neutral-200 rounded-lg p-5">
    <form method="GET" action="{{ route('admin.registrations.index') }}" class="flex flex-col lg:flex-row lg:items-end gap-4 lg:gap-3">
        {{-- Search input (takes remaining width on desktop) --}}
        <div class="flex-1 lg:flex-1">
            <label for="search" class="block text-[11px] font-semibold text-neutral-600 uppercase tracking-[0.5px] mb-2">Search</label>
            <input type="text" name="search" id="search" value="{{ $searchQuery }}" placeholder="Name, email, contact…"
                   class="w-full h-10 px-3 border border-neutral-300 rounded-lg text-base focus:ring-2 focus:ring-primary-400 focus:border-transparent" />
        </div>

        {{-- Account Type filter --}}
        <div class="lg:flex-0">
            <label for="type" class="block text-[11px] font-semibold text-neutral-600 uppercase tracking-[0.5px] mb-2">Account Type</label>
            <select name="type" id="type" class="w-full lg:w-40 h-10 px-3 border border-neutral-300 rounded-lg text-base focus:ring-2 focus:ring-primary-400 focus:border-transparent">
                <option value="">All Types</option>
                <option value="buyer" {{ $accountType === 'buyer' ? 'selected' : '' }}>Buyer</option>
                <option value="seller" {{ $accountType === 'seller' ? 'selected' : '' }}>Seller</option>
                <option value="logistics" {{ $accountType === 'logistics' ? 'selected' : '' }}>Logistics</option>
            </select>
        </div>

        {{-- Status filter --}}
        <div class="lg:flex-0">
            <label for="status" class="block text-[11px] font-semibold text-neutral-600 uppercase tracking-[0.5px] mb-2">Status</label>
            <select name="status" id="status" class="w-full lg:w-40 h-10 px-3 border border-neutral-300 rounded-lg text-base focus:ring-2 focus:ring-primary-400 focus:border-transparent">
                <option value="pending" {{ $status === 'pending' ? 'selected' : '' }}>Pending</option>
                <option value="approved" {{ $status === 'approved' ? 'selected' : '' }}>Approved</option>
                <option value="rejected" {{ $status === 'rejected' ? 'selected' : '' }}>Rejected</option>
                <option value="suspended" {{ $status === 'suspended' ? 'selected' : '' }}>Suspended</option>
            </select>
        </div>

        {{-- Date Range filter (default All Time so pending older than 7 days stays visible) --}}
        <div class="lg:flex-0">
            <label for="date_range" class="block text-[11px] font-semibold text-neutral-600 uppercase tracking-[0.5px] mb-2">Date Range</label>
            <select name="date_range" id="date_range" class="w-full lg:w-40 h-10 px-3 border border-neutral-300 rounded-lg text-base focus:ring-2 focus:ring-primary-400 focus:border-transparent">
                <option value="0" {{ (int) $dateRange === 0 ? 'selected' : '' }}>All Time</option>
                <option value="7" {{ (int) $dateRange === 7 ? 'selected' : '' }}>Last 7 days</option>
                <option value="30" {{ (int) $dateRange === 30 ? 'selected' : '' }}>Last 30 days</option>
                <option value="90" {{ (int) $dateRange === 90 ? 'selected' : '' }}>Last 90 days</option>
            </select>
        </div>

        {{-- Filter button --}}
        <button type="submit" class="h-10 px-5 bg-primary-800 text-white font-semibold text-[13px] rounded-lg hover:bg-primary-900 transition-colors flex items-center justify-center gap-2 lg:mt-0">
            <i data-lucide="filter" class="w-4 h-4"></i>
            Filter
        </button>
    </form>
</div>

{{-- Role Tabs --}}
<div class="mb-6 flex flex-wrap gap-2 items-center justify-between">
    <div class="flex flex-wrap gap-2">
        @php
            $types = ['' => 'All', 'buyer' => 'Buyer', 'seller' => 'Seller', 'logistics' => 'Logistics'];
        @endphp
        @foreach ($types as $typeVal => $typeLabel)
            @php
                $isActive = ($accountType === $typeVal);
            @endphp
            <a href="{{ route('admin.registrations.index', array_merge(request()->query(), ['type' => $typeVal, 'page' => 1])) }}"
               class="px-4 py-2 rounded-full text-sm font-semibold transition-colors {{ $isActive ? 'bg-primary-800 text-white' : 'bg-neutral-100 text-neutral-700 hover:bg-neutral-200' }}">
                {{ $typeLabel }}
            </a>
        @endforeach
    </div>
    <div class="text-sm text-neutral-600">
        Showing <span class="font-semibold">{{ $pendingCount }}</span> {{ $status === 'pending' ? 'pending' : ($status === 'approved' ? 'approved' : 'rejected') }} registrations
    </div>
</div>

{{-- Data table --}}
<div class="bg-white border border-neutral-200 rounded-lg overflow-hidden">
    @if ($registrations && $registrations->count() > 0)
        <table class="w-full text-base">
            <thead class="bg-neutral-50 border-b border-neutral-200">
                <tr>
                    <th class="px-6 py-4 text-left font-semibold text-neutral-700 text-base">Name</th>
                    <th class="px-6 py-4 text-left font-semibold text-neutral-700 text-base">Email</th>
                    <th class="px-6 py-4 text-left font-semibold text-neutral-700 text-base">Account Type</th>
                    <th class="px-6 py-4 text-left font-semibold text-neutral-700 text-base">Registration Date</th>
                    <th class="px-6 py-4 text-left font-semibold text-neutral-700 text-base">Status</th>
                    <th class="px-6 py-4 text-center font-semibold text-neutral-700 text-base">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200">
                @foreach ($registrations as $registration)
                    @php
                        $model = $registration->model;
                        $type = $registration->type;
                        $statusValue = $registration->status;
                        $statusInfo = $statusColors[$statusValue] ?? $statusColors['pending'];
                        $typeInfo = $typeColors[$type] ?? $typeColors['buyer'];
                    @endphp
                    <tr class="hover:bg-neutral-50 transition-colors">
                        {{-- Applicant name with initials --}}
                        <td class="px-6 py-4">
                            <div class="flex items-center gap-3">
                                <div class="flex-shrink-0 w-10 h-10 rounded-full bg-primary-100 text-primary-800 flex items-center justify-center font-semibold text-sm">
                                    {{ strtoupper(substr($model->first_name ?? 'U', 0, 1)) }}{{ strtoupper(substr($model->last_name ?? '', 0, 1)) }}
                                </div>
                                <div>
                                    <p class="font-semibold text-neutral-950">{{ $model->first_name }} {{ $model->last_name }}</p>
                                    <p class="text-xs text-neutral-500">ID: ZF-{{ $model->id }}</p>
                                </div>
                            </div>
                        </td>

                        {{-- Email --}}
                        <td class="px-6 py-4 text-neutral-700">
                            {{ $model->email }}
                        </td>

                        {{-- Account type badge --}}
                        <td class="px-6 py-4">
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold {{ $typeInfo['bg'] }} {{ $typeInfo['text'] }}">
                                {{ $typeInfo['label'] }}
                            </span>
                        </td>

                        {{-- Registration date --}}
                        <td class="px-6 py-4 text-neutral-700">
                            {{ $model->created_at?->format('d M Y, g:i A') ?? 'N/A' }}
                        </td>

                        {{-- Status badge --}}
                        <td class="px-6 py-4">
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold {{ $statusInfo['bg'] }} {{ $statusInfo['text'] }}">
                                {{ $statusInfo['label'] }}
                            </span>
                        </td>

                        {{-- Actions --}}
                        <td class="px-6 py-4">
                            <div class="flex items-center justify-center gap-2">
                                {{-- Approve button (only for pending) --}}
                                @if ($statusValue === 'pending')
                                    <form method="POST" 
                                          action="{{ route(match ($type) {
                                              'seller' => 'admin.registrations.seller.approve',
                                              'logistics' => 'admin.registrations.logistics.approve',
                                              default => 'admin.registrations.buyer.approve',
                                          }, $model) }}"
                                          class="inline"
                                          onsubmit="return confirm('Approve this registration?');">
                                        @csrf
                                        <button type="submit" 
                                                class="inline-flex items-center justify-center w-8 h-8 rounded-md text-green-600 hover:bg-green-50 transition-colors"
                                                title="Approve">
                                            <i data-lucide="check" class="w-4 h-4"></i>
                                        </button>
                                    </form>

                                    {{-- Reject button (only for pending) --}}
                                    <button type="button" 
                                            class="inline-flex items-center justify-center w-8 h-8 rounded-md text-red-600 hover:bg-red-50 transition-colors"
                                            title="Reject"
                                            onclick="openRejectModal({{ $model->id }}, '{{ $type }}', '{{ $model->first_name }} {{ $model->last_name }}')">
                                        <i data-lucide="x" class="w-4 h-4"></i>
                                    </button>
                                @endif

                                {{-- View details (wired modal — C22) --}}
                                <button type="button"
                                        class="inline-flex items-center justify-center w-8 h-8 rounded-md text-neutral-600 hover:bg-neutral-100 transition-colors"
                                        title="View details"
                                        data-detail-name="{{ e($model->first_name.' '.$model->last_name) }}"
                                        data-detail-email="{{ e($model->email) }}"
                                        data-detail-type="{{ e($typeInfo['label']) }}"
                                        data-detail-status="{{ e($statusInfo['label']) }}"
                                        data-detail-contact="{{ e($model->contact_no ?? '') }}"
                                        data-detail-business="{{ e($model->business_name ?? '') }}"
                                        data-detail-address="{{ e(trim(collect([$model->street, $model->house_number, $model->barangay_name, $model->municipality_name, $model->province_name, $model->postal_code])->filter()->implode(', '))) }}"
                                        data-detail-id-url="{{ $model->upload_id_path ? route('admin.documents.stream', ['type' => $type, 'id' => $model->id, 'document' => 'id']) : '' }}"
                                        data-detail-permit-url="{{ $type === 'seller' && $model->business_permit_path ? route('admin.documents.stream', ['type' => 'seller', 'id' => $model->id, 'document' => 'business_permit']) : ($type === 'logistics' && ($model->dti_permit_path ?? null) ? route('admin.documents.stream', ['type' => 'logistics', 'id' => $model->id, 'document' => 'dti_permit']) : '') }}"
                                        data-detail-permit-label="{{ $type === 'seller' ? 'Business permit' : ($type === 'logistics' ? 'DTI permit' : '') }}"
                                        onclick="openDetailModal(this)">
                                    <i data-lucide="eye" class="w-4 h-4"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        {{-- Pagination --}}
        <div class="px-6 py-4 border-t border-neutral-200 flex flex-col sm:flex-row items-center justify-between gap-4">
            <div class="text-base text-neutral-600">
                Show <select name="per_page" id="footer_per_page" onchange="window.location.href = '{{ route('admin.registrations.index') }}?' + Object.keys({...new URLSearchParams(window.location.search), per_page: this.value}).map(k => k + '=' + (k === 'per_page' ? this.value : new URLSearchParams(window.location.search).get(k) || '')).join('&')"
                        class="px-2 py-1 border border-neutral-300 rounded text-base">
                    <option value="10" {{ $perPage == 10 ? 'selected' : '' }}>10</option>
                    <option value="25" {{ $perPage == 25 ? 'selected' : '' }}>25</option>
                    <option value="50" {{ $perPage == 50 ? 'selected' : '' }}>50</option>
                </select> per page
            </div>
            <div class="flex gap-1">
                @if ($registrations->onFirstPage())
                    <button disabled class="px-3 py-2 border border-neutral-300 rounded-lg text-neutral-400 cursor-not-allowed">«</button>
                @else
                    <a href="{{ $registrations->previousPageUrl() }}" class="px-3 py-2 border border-neutral-300 rounded-lg text-neutral-700 hover:bg-neutral-50 transition-colors">«</a>
                @endif

                {{-- Page numbers --}}
                @for ($i = 1; $i <= $registrations->lastPage(); $i++)
                    @if ($i == $registrations->currentPage())
                        <button disabled class="px-3 py-2 bg-primary-800 text-white rounded-lg font-semibold">{{ $i }}</button>
                    @elseif ($i == 1 || $i == $registrations->lastPage() || abs($i - $registrations->currentPage()) <= 1)
                        <a href="{{ $registrations->url($i) }}" class="px-3 py-2 border border-neutral-300 rounded-lg text-neutral-700 hover:bg-neutral-50 transition-colors">{{ $i }}</a>
                    @elseif ($i == 2 || $i == $registrations->lastPage() - 1)
                        <span class="px-3 py-2 text-neutral-500">…</span>
                    @endif
                @endfor

                @if ($registrations->hasMorePages())
                    <a href="{{ $registrations->nextPageUrl() }}" class="px-3 py-2 border border-neutral-300 rounded-lg text-neutral-700 hover:bg-neutral-50 transition-colors">»</a>
                @else
                    <button disabled class="px-3 py-2 border border-neutral-300 rounded-lg text-neutral-400 cursor-not-allowed">»</button>
                @endif
            </div>
        </div>
    @else
        {{-- Empty state --}}
        <div class="px-6 py-16 text-center">
            <i data-lucide="inbox" class="w-12 h-12 text-neutral-400 mx-auto mb-4"></i>
            <p class="text-neutral-700 font-semibold text-base mb-2">No registrations found</p>
            <p class="text-neutral-500 text-base">Try adjusting your filters or search terms</p>
        </div>
    @endif
</div>

{{-- Detail Modal (C22) --}}
<div id="detailModal" class="fixed inset-0 bg-black/50 flex items-center justify-center hidden z-50">
    <div class="bg-white rounded-lg p-6 max-w-lg w-full mx-4 max-h-[90vh] overflow-y-auto">
        <div class="flex items-start justify-between mb-4">
            <h3 class="text-lg font-bold text-neutral-950">Registration details</h3>
            <button type="button" onclick="closeDetailModal()" class="text-neutral-500 hover:text-neutral-800" aria-label="Close">
                <i data-lucide="x" class="w-5 h-5"></i>
            </button>
        </div>
        <dl class="space-y-3 text-sm">
            <div><dt class="text-neutral-500">Name</dt><dd id="detailName" class="font-semibold text-neutral-950"></dd></div>
            <div><dt class="text-neutral-500">Email</dt><dd id="detailEmail" class="text-neutral-800"></dd></div>
            <div><dt class="text-neutral-500">Contact</dt><dd id="detailContact" class="text-neutral-800"></dd></div>
            <div><dt class="text-neutral-500">Type / Status</dt><dd class="text-neutral-800"><span id="detailType"></span> · <span id="detailStatus"></span></dd></div>
            <div id="detailBusinessWrap" class="hidden"><dt class="text-neutral-500">Business</dt><dd id="detailBusiness" class="text-neutral-800"></dd></div>
            <div><dt class="text-neutral-500">Address</dt><dd id="detailAddress" class="text-neutral-800"></dd></div>
            <div class="flex flex-wrap gap-2 pt-2">
                <a id="detailIdLink" href="#" target="_blank" rel="noopener" class="hidden px-3 py-2 bg-neutral-100 rounded-lg text-neutral-800 font-medium hover:bg-neutral-200">View ID</a>
                <a id="detailPermitLink" href="#" target="_blank" rel="noopener" class="hidden px-3 py-2 bg-neutral-100 rounded-lg text-neutral-800 font-medium hover:bg-neutral-200"></a>
            </div>
        </dl>
    </div>
</div>

{{-- Reject Modal --}}
<div id="rejectModal" class="fixed inset-0 bg-black/50 flex items-center justify-center hidden z-50">
    <div class="bg-white rounded-lg p-6 max-w-md w-full mx-4">
        <h3 class="text-lg font-bold text-neutral-950 mb-4">Reject Registration</h3>
        <p class="text-gray-600 mb-4">Are you sure you want to reject <span id="rejectApplicantName" class="font-medium"></span>'s registration?</p>

        <form id="rejectForm" method="POST" class="space-y-4">
            @csrf
            <div>
                <label for="rejection_reason" class="block text-sm font-medium text-gray-700 mb-1">Reason for rejection</label>
                <textarea name="rejection_reason" id="rejection_reason" rows="4" required 
                          placeholder="Please provide a reason for rejecting this registration…"
                          class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary-400 focus:border-transparent"></textarea>
            </div>

            <div class="flex gap-3">
                <button type="button" onclick="closeRejectModal()" 
                        class="flex-1 px-4 py-2 border border-gray-300 rounded-lg text-gray-700 font-medium hover:bg-gray-50 transition-colors">
                    Cancel
                </button>
                <button type="submit" 
                        class="flex-1 px-4 py-2 bg-red-600 text-white font-medium rounded-lg hover:bg-red-700 transition-colors">
                    Confirm Rejection
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    function openDetailModal(btn) {
        document.getElementById('detailName').textContent = btn.dataset.detailName || '';
        document.getElementById('detailEmail').textContent = btn.dataset.detailEmail || '';
        document.getElementById('detailContact').textContent = btn.dataset.detailContact || '—';
        document.getElementById('detailType').textContent = btn.dataset.detailType || '';
        document.getElementById('detailStatus').textContent = btn.dataset.detailStatus || '';
        document.getElementById('detailAddress').textContent = btn.dataset.detailAddress || '—';

        const business = btn.dataset.detailBusiness || '';
        const businessWrap = document.getElementById('detailBusinessWrap');
        if (business) {
            businessWrap.classList.remove('hidden');
            document.getElementById('detailBusiness').textContent = business;
        } else {
            businessWrap.classList.add('hidden');
        }

        const idLink = document.getElementById('detailIdLink');
        if (btn.dataset.detailIdUrl) {
            idLink.href = btn.dataset.detailIdUrl;
            idLink.classList.remove('hidden');
        } else {
            idLink.classList.add('hidden');
        }

        const permitLink = document.getElementById('detailPermitLink');
        if (btn.dataset.detailPermitUrl) {
            permitLink.href = btn.dataset.detailPermitUrl;
            permitLink.textContent = btn.dataset.detailPermitLabel || 'View permit';
            permitLink.classList.remove('hidden');
        } else {
            permitLink.classList.add('hidden');
        }

        document.getElementById('detailModal').classList.remove('hidden');
        if (window.lucide) window.lucide.createIcons();
    }

    function closeDetailModal() {
        document.getElementById('detailModal').classList.add('hidden');
    }

    document.getElementById('detailModal')?.addEventListener('click', function (e) {
        if (e.target === this) closeDetailModal();
    });

    function openRejectModal(id, type, applicantName) {
        document.getElementById('rejectApplicantName').textContent = applicantName;
        
        // Build the reject route URL based on type
        let routeUrl = '';
        if (type === 'seller') {
            routeUrl = '{{ route("admin.registrations.seller.reject", ":id") }}'.replace(':id', id);
        } else if (type === 'logistics') {
            routeUrl = '{{ route("admin.registrations.logistics.reject", ":id") }}'.replace(':id', id);
        } else {
            routeUrl = '{{ route("admin.registrations.buyer.reject", ":id") }}'.replace(':id', id);
        }
        
        document.getElementById('rejectForm').action = routeUrl;
        document.getElementById('rejection_reason').value = '';
        document.getElementById('rejectModal').classList.remove('hidden');
    }

    function closeRejectModal() {
        document.getElementById('rejectModal').classList.add('hidden');
    }

    // Close modal when clicking outside
    document.getElementById('rejectModal')?.addEventListener('click', function(e) {
        if (e.target === this) {
            closeRejectModal();
        }
    });
</script>

@endsection
