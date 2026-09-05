@extends('Admin.Layouts.app')

@section('title', 'Manage Seller Registrations')

@section('content')

@if (session('success'))
  <div class="reg-toast">{{ session('success') }}</div>
@endif

<div class="reg-layout">

    {{-- ===== Left: pending list ===== --}}
    <div class="reg-list">
        <div class="reg-list__header">
            <h2>Pending Seller Registrations</h2>
            <span class="reg-count-badge">{{ $pending->count() }}</span>
        </div>

        @forelse ($pending as $applicant)
            <a href="{{ route('registrations.sellers.index', ['view' => $applicant->id]) }}"
               class="reg-list__item {{ $selected && $selected->id === $applicant->id ? 'is-active' : '' }}">
                <div class="reg-list__avatar">
                    {{ strtoupper(substr($applicant->first_name, 0, 1)) }}{{ strtoupper(substr($applicant->last_name, 0, 1)) }}
                </div>
                <div class="reg-list__info">
                    <span class="reg-list__name">{{ $applicant->fullName() }}</span>
                    <span class="reg-list__role">Seller &middot; {{ $applicant->created_at->diffForHumans() }}</span>
                </div>
                <span class="reg-status-badge reg-status-badge--pending">Pending</span>
            </a>
        @empty
            <p class="reg-list__empty">No pending seller registrations right now.</p>
        @endforelse
    </div>

    {{-- ===== Right: detail panel ===== --}}
    <div class="reg-detail">
        @if ($selected)
            <div class="reg-detail__header">
                <div class="reg-detail__avatar">
                    {{ strtoupper(substr($selected->first_name, 0, 1)) }}{{ strtoupper(substr($selected->last_name, 0, 1)) }}
                </div>
                <div>
                    <h3>{{ $selected->fullName() }}</h3>
                    <span class="reg-detail__email">{{ $selected->email }}</span>
                </div>
                <span class="reg-status-badge reg-status-badge--pending">Pending Review</span>
            </div>

            <div class="reg-detail__section">
                <h4>Business Information</h4>
                <div class="reg-detail__grid">
                    <div><span>Business Name</span><strong>{{ $selected->business_name }}</strong></div>
                    <div><span>Line of Business</span><strong>{{ $selected->lineOfBusiness?->label ?? 'Not specified' }}</strong></div>
                </div>
            </div>

            <div class="reg-detail__section">
                <h4>Personal Information</h4>
                <div class="reg-detail__grid">
                    <div><span>Sex</span><strong>{{ ucfirst($selected->sex) }}</strong></div>
                    <div><span>Contact No.</span><strong>{{ $selected->contact_no }}</strong></div>
                    <div><span>Birthday</span><strong>{{ $selected->birthday->format('M d, Y') }}</strong></div>
                    <div><span>Age</span><strong>{{ $selected->age }}</strong></div>
                </div>
            </div>

            <div class="reg-detail__section">
                <h4>Address</h4>
                <p class="reg-detail__address">
                    @if ($selected->address_mode === 'manual')
                        {{ $selected->house_number }} {{ $selected->street }}, {{ $selected->address_detail }}
                    @else
                        {{ $selected->barangay_name }}, {{ $selected->municipality_name }}, {{ $selected->province_name }}
                    @endif
                </p>
            </div>

            <div class="reg-detail__section">
                <h4>Submitted Documents</h4>
                <div class="reg-detail__documents">
                    <a href="{{ Storage::url($selected->upload_id_path) }}" target="_blank" class="reg-detail__id-link">
                        <i data-lucide="file-text" width="16" height="16"></i>
                        View uploaded ID
                    </a>
                    <a href="{{ Storage::url($selected->business_permit_path) }}" target="_blank" class="reg-detail__id-link">
                        <i data-lucide="file-text" width="16" height="16"></i>
                        View business permit
                    </a>
                </div>
            </div>

            <div class="reg-detail__actions">
                <form method="POST" action="{{ route('registrations.sellers.approve', $selected) }}">
                    @csrf
                    <button type="submit" class="reg-btn reg-btn--approve">
                        <i data-lucide="check" width="16" height="16"></i>
                        Approve
                    </button>
                </form>

                <button type="button" class="reg-btn reg-btn--reject" onclick="document.getElementById('rejectPanel').hidden = false">
                    <i data-lucide="x" width="16" height="16"></i>
                    Reject
                </button>
            </div>

            <div id="rejectPanel" class="reg-reject-panel" hidden>
                <form method="POST" action="{{ route('registrations.sellers.reject', $selected) }}">
                    @csrf
                    <label for="rejection_reason">Reason for rejection</label>
                    <textarea name="rejection_reason" id="rejection_reason" rows="3" required></textarea>
                    <button type="submit" class="reg-btn reg-btn--reject-confirm">Confirm Rejection</button>
                </form>
            </div>
        @else
            <div class="reg-detail__empty">
                <i data-lucide="inbox" width="32" height="32"></i>
                <p>Select a seller registration from the list to review it.</p>
            </div>
        @endif
    </div>

</div>

@endsection