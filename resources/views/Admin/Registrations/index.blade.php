@extends('Admin.Layouts.app')

@section('title', 'Registration Management')
@section('content-width', 'max-w-none')

@section('content')
@php
    $canViewIdentityDocuments = in_array(auth('admin')->user()?->role, ['super_admin', 'admin'], true);
@endphp
@php
    // Status colors and labels (updated for new status values)
    $statusColors = [
        'pending' => ['bg' => 'registration-status-pending', 'text' => '', 'label' => 'Pending Review'],
        'approved' => ['bg' => 'registration-status-approved', 'text' => '', 'label' => 'Approved'],
        'rejected' => ['bg' => 'registration-status-rejected', 'text' => '', 'label' => 'Rejected'],
        'suspended' => ['bg' => 'bg-neutral-100', 'text' => 'text-neutral-700', 'label' => 'Suspended'],
    ];

    // Account type badge colors
    $typeColors = [
        'buyer' => ['bg' => 'bg-primary-50', 'text' => 'text-primary-800', 'label' => 'Buyer'],
        'seller' => ['bg' => 'bg-secondary-100', 'text' => 'text-secondary-900', 'label' => 'Seller'],
        'logistics' => ['bg' => 'bg-tertiary-100', 'text' => 'text-tertiary-800', 'label' => 'Logistics'],
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

@php
    $totalCount = ($pendingCount ?? 0) + ($approvedCount ?? 0) + ($rejectedCount ?? 0) + ($suspendedCount ?? 0);
    $roleOptions = [
        '' => ['label' => 'All Registrations', 'icon' => 'layers'],
        'buyer' => ['label' => 'Buyers', 'icon' => 'users'],
        'seller' => ['label' => 'Sellers', 'icon' => 'store'],
        'logistics' => ['label' => 'Logistics', 'icon' => 'truck'],
    ];
    $metrics = [
        ['label' => 'Pending Review', 'count' => $pendingCount, 'icon' => 'clock', 'tone' => 'pending', 'trend' => $pendingTrend ?? null],
        ['label' => 'Approved', 'count' => $approvedCount, 'icon' => 'circle-check', 'tone' => 'approved', 'trend' => $approvedTrend ?? null],
        ['label' => 'Rejected', 'count' => $rejectedCount, 'icon' => 'circle-x', 'tone' => 'rejected', 'trend' => $rejectedTrend ?? null],
        ['label' => 'Total Registrations', 'count' => $totalCount, 'icon' => 'users', 'tone' => 'total', 'trend' => null],
    ];
@endphp
<div class="registration-workspace">
    @if ($errors->any())
        <div role="alert" class="mb-5 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">
            <p class="font-semibold">The registration could not be updated.</p>
            <ul class="mt-2 list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif
    <header class="registration-header">
        <div class="registration-heading-icon" aria-hidden="true"><i data-lucide="users" class="w-7 h-7"></i></div>
        <div>
            <h1>Registration Management</h1>
            <p>Review and manage new user registrations based on your verification process.</p>
        </div>
        <a href="#date_range" class="registration-date-link"><i data-lucide="calendar-days" class="w-4 h-4"></i>{{ (int) $dateRange === 0 ? 'All Time' : 'Last '.$dateRange.' days' }}<i data-lucide="chevron-down" class="w-4 h-4"></i></a>
    </header>

    <section class="registration-overview" aria-label="Registration overview">
        <div class="registration-overview-main">
            <div class="registration-summary">
                <div><strong>{{ number_format($totalCount) }}</strong> Total registrations</div>
                @foreach (array_slice($metrics, 0, 3) as $metric)
                    <div><i data-lucide="{{ $metric['icon'] }}" class="registration-tone-{{ $metric['tone'] }} w-4 h-4"></i><strong>{{ number_format($metric['count']) }}</strong> {{ $metric['tone'] === 'pending' ? 'Pending' : $metric['label'] }} <span>({{ $totalCount ? number_format($metric['count'] / $totalCount * 100, 1) : '0' }}%)</span></div>
                @endforeach
                @if (($suspendedCount ?? 0) > 0)
                    <div><strong>{{ $suspendedCount }}</strong> Suspended</div>
                @endif
            </div>
            <div class="registration-metrics">
                @foreach ($metrics as $metric)
                    <article class="registration-metric registration-metric-{{ $metric['tone'] }}">
                        <div class="registration-metric-top">
                            <span class="registration-metric-icon"><i data-lucide="{{ $metric['icon'] }}" class="w-5 h-5"></i></span>
                            <div><h2>{{ $metric['label'] }}</h2><strong>{{ number_format($metric['count']) }}</strong></div>
                        </div>
                        @if ($metric['trend'] !== null)
                            <p class="registration-metric-caption"><i data-lucide="{{ $metric['trend'] > 0 ? 'trending-up' : ($metric['trend'] < 0 ? 'trending-down' : 'minus') }}" class="w-3.5 h-3.5"></i>{{ $metric['trend'] > 0 ? '+' : '' }}{{ $metric['trend'] }}% vs. previous 7 days</p>
                        @else
                            <p class="registration-metric-caption">All time / all account types</p>
                        @endif
                    </article>
                @endforeach
            </div>
        </div>
        <aside class="registration-insights" aria-labelledby="registration-insights-title">
            <h2 id="registration-insights-title"><i data-lucide="chart-no-axes-column" class="w-5 h-5"></i>Registration Insights</h2>
            <p class="registration-insights-caption">All time / all reviewed statuses</p>
            @foreach ($roleOptions as $role => $option)
                @continue($role === '')
                <div class="registration-insight-row">
                    <span class="registration-insight-icon"><i data-lucide="{{ $option['icon'] }}" class="w-5 h-5"></i></span>
                    <div><h3>{{ $option['label'] }}</h3><p>{{ number_format($roleCounts[$role] ?? 0) }} registrations</p></div>
                    <span class="registration-insight-share">{{ $totalCount ? number_format(($roleCounts[$role] ?? 0) / $totalCount * 100, 1) : '0' }}%</span>
                </div>
            @endforeach
        </aside>
    </section>

    <nav data-admin-tabs class="registration-role-tabs" aria-label="Registration account types">
        @foreach ($roleOptions as $typeVal => $option)
            <a href="{{ route('admin.registrations.index', request()->except(['type', 'page']) + ($typeVal !== '' ? ['type' => $typeVal] : [])) }}" @if ($accountType === $typeVal) aria-current="page" @endif class="registration-role-tab {{ $accountType === $typeVal ? 'is-active' : '' }}">
                <i data-lucide="{{ $option['icon'] }}" class="w-5 h-5"></i>{{ $option['label'] }}<span>{{ number_format($typeVal === '' ? $totalCount : ($roleCounts[$typeVal] ?? 0)) }}</span>
            </a>
        @endforeach
    </nav>

    <form data-admin-auto-filter method="GET" action="{{ route('admin.registrations.index') }}" class="registration-filters">
        <input type="hidden" name="per_page" value="{{ $perPage }}">
        <div class="registration-search">
            <label for="search">Search registrations</label>
            <div><i data-lucide="search" class="w-4 h-4" aria-hidden="true"></i><input type="search" name="search" id="search" value="{{ $searchQuery }}" placeholder="Name, email, contact..."></div>
        </div>
        <div><label for="type">Account Type</label><select name="type" id="type"><option value="">All Types</option>@foreach (['buyer' => 'Buyer', 'seller' => 'Seller', 'logistics' => 'Logistics'] as $value => $label)<option value="{{ $value }}" @selected($accountType === $value)>{{ $label }}</option>@endforeach</select></div>
        <div><label for="status">Status</label><select name="status" id="status">@foreach (['pending' => 'Pending Review', 'approved' => 'Approved', 'rejected' => 'Rejected', 'suspended' => 'Suspended'] as $value => $label)<option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>@endforeach</select></div>
        <div><label for="date_range">Date Range</label><select name="date_range" id="date_range">@foreach ([0 => 'All Time', 7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days'] as $value => $label)<option value="{{ $value }}" @selected((int) $dateRange === $value)>{{ $label }}</option>@endforeach</select></div>
        <a href="{{ route('admin.registrations.index') }}" class="registration-filter-reset"><i data-lucide="rotate-ccw" class="w-4 h-4"></i>Reset</a>
    </form>

{{-- Data table --}}
<section class="registration-table-panel" aria-label="Registration results">
    <div class="registration-table-toolbar"><p>Showing <strong>{{ $registrations->firstItem() ?? 0 }} to {{ $registrations->lastItem() ?? 0 }}</strong> of <strong>{{ number_format($registrations->total()) }}</strong> registrations</p><p class="registration-order"><i data-lucide="arrow-down-wide-narrow" class="w-4 h-4"></i>Registration date / newest first</p></div>
    @if ($registrations && $registrations->count() > 0)
        <div class="registration-table-scroll" role="region" aria-label="Registration records" tabindex="0"><table class="w-full"><caption class="sr-only">Registration applications matching your filters, newest first</caption>
            <thead class="border-b border-neutral-200">
                <tr>
                    <th class="text-left">Name</th>
                    <th class="text-left">Email</th>
                    <th class="text-left">Account Type</th>
                    <th class="text-left">Registration Date</th>
                    <th class="text-left">Status</th>
                    <th class="text-left">Actions</th>
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
                        <td>
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
                        <td class="text-neutral-700">
                            {{ $model->email }}
                        </td>

                        {{-- Account type badge --}}
                        <td>
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold {{ $typeInfo['bg'] }} {{ $typeInfo['text'] }}">
                                {{ $typeInfo['label'] }}
                            </span>
                        </td>

                        {{-- Registration date --}}
                        <td class="text-neutral-700">
                            {{ $model->created_at?->format('d M Y, g:i A') ?? 'N/A' }}
                        </td>

                        {{-- Status badge --}}
                        <td>
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold {{ $statusInfo['bg'] }} {{ $statusInfo['text'] }}">
                                {{ $statusInfo['label'] }}
                            </span>
                        </td>

                        {{-- Actions --}}
                        <td>
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
                                                class="registration-action registration-action-approve"
                                                title="Approve" aria-label="Approve registration">
                                            <i data-lucide="check" class="w-4 h-4"></i><span class="registration-action-label">Approve</span>
                                        </button>
                                    </form>

                                    {{-- Reject button (only for pending) --}}
                                    <button type="button" 
                                            class="registration-action registration-action-reject"
                                            title="Reject" aria-label="Reject registration"
                                            onclick="openRejectModal({{ $model->id }}, '{{ $type }}', '{{ $model->first_name }} {{ $model->last_name }}')">
                                        <i data-lucide="x" class="w-4 h-4"></i><span class="registration-action-label">Reject</span>
                                    </button>
                                @endif

                                {{-- View details (wired modal — C22) --}}
                                <button type="button"
                                        class="inline-flex items-center justify-center w-8 h-8 rounded-md text-neutral-600 hover:bg-neutral-100 transition-colors"
                                        title="View details" aria-label="View registration details"
                                        data-detail-name="{{ e($model->first_name.' '.$model->last_name) }}"
                                        data-detail-email="{{ e($model->email) }}"
                                        data-detail-type="{{ e($typeInfo['label']) }}"
                                        data-detail-status="{{ e($statusInfo['label']) }}"
                                        data-detail-contact="{{ e($model->contact_no ?? '') }}"
                                        data-detail-business="{{ e($model->business_name ?? '') }}"
                                        data-detail-address="{{ e(trim(collect([$model->street, $model->house_number, $model->barangay_name, $model->municipality_name, $model->province_name, $model->postal_code])->filter()->implode(', '))) }}"
                                        data-detail-id-url="{{ $canViewIdentityDocuments && $model->upload_id_path ? route('admin.documents.stream', ['type' => $type, 'id' => $model->id, 'document' => 'id']) : '' }}"
                                        data-detail-permit-url="{{ $canViewIdentityDocuments && $type === 'seller' && $model->business_permit_path ? route('admin.documents.stream', ['type' => 'seller', 'id' => $model->id, 'document' => 'business_permit']) : ($canViewIdentityDocuments && $type === 'logistics' && ($model->dti_permit_path ?? null) ? route('admin.documents.stream', ['type' => 'logistics', 'id' => $model->id, 'document' => 'dti_permit']) : '') }}"
                                        data-detail-sec-url="{{ $canViewIdentityDocuments && $type === 'seller' && $model->sec_certificate_path ? route('admin.documents.stream', ['type' => 'seller', 'id' => $model->id, 'document' => 'sec_certificate']) : '' }}"
                                        data-detail-documents-locked="{{ ! $canViewIdentityDocuments && ($model->upload_id_path || ($type === 'seller' && ($model->business_permit_path || $model->sec_certificate_path)) || ($type === 'logistics' && ($model->dti_permit_path ?? null))) ? '1' : '' }}"
                                        data-detail-permit-label="{{ $type === 'seller' ? 'Business permit' : ($type === 'logistics' ? 'DTI permit' : '') }}"
                                        onclick="openDetailModal(this)">
                                    <i data-lucide="eye" class="w-4 h-4"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table></div>

        {{-- Pagination --}}
        <div class="border-t border-neutral-200 flex flex-col sm:flex-row items-center justify-between gap-4">
            <div class="text-base text-neutral-600">
                Show <select name="per_page" id="footer_per_page" onchange="changeRegistrationPageSize(this.value)" aria-label="Rows per page"
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
</section>

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
            <p id="detailDocumentNote" class="hidden text-sm text-neutral-600">Identity documents can only be opened by an administrator. This view is recorded when an administrator opens them.</p>
            <div class="flex flex-wrap gap-2 pt-2">
                <a id="detailIdLink" href="#" target="_blank" rel="noopener" class="hidden px-3 py-2 bg-neutral-100 rounded-lg text-neutral-800 font-medium hover:bg-neutral-200">View ID</a>
                <a id="detailPermitLink" href="#" target="_blank" rel="noopener" class="hidden px-3 py-2 bg-neutral-100 rounded-lg text-neutral-800 font-medium hover:bg-neutral-200"></a>
                <a id="detailSecLink" href="#" target="_blank" rel="noopener" class="hidden px-3 py-2 bg-neutral-100 rounded-lg text-neutral-800 font-medium hover:bg-neutral-200">View SEC certificate</a>
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
    function changeRegistrationPageSize(value) {
        const url = new URL(window.location.href);
        url.searchParams.set('per_page', value);
        url.searchParams.delete('page');
        window.location.assign(url.toString());
    }

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

        const secLink = document.getElementById('detailSecLink');
        secLink.classList.toggle('hidden', !btn.dataset.detailSecUrl);
        secLink.href = btn.dataset.detailSecUrl || '#';

        document.getElementById('detailDocumentNote').classList.toggle('hidden', btn.dataset.detailDocumentsLocked !== '1');

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
