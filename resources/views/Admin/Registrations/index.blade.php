@extends('Admin.Layouts.app')

@section('title', 'Manage Registrations')

@section('content')

@if (session('success'))
  <div class="reg-toast">{{ session('success') }}</div>
@endif

<div class="reg-layout">

    {{-- ===== Left: pending list ===== --}}
    <div class="reg-list">
        <div class="reg-list__header">
            <h2>Pending Registrations</h2>
            <span class="reg-count-badge">{{ $pending->count() }}</span>
        </div>

        @forelse ($pending as $applicant)
            <a href="{{ route('admin.registrations.index', ['view' => $applicant->id, 'type' => $applicant->type]) }}"
               class="reg-list__item {{ $selected && $selected->id == $applicant->id && $selectedType === $applicant->type ? 'is-active' : '' }}">
                <div class="reg-list__avatar">
                    {{ strtoupper(substr($applicant->model->first_name, 0, 1)) }}{{ strtoupper(substr($applicant->model->last_name, 0, 1)) }}
                </div>
                <div class="reg-list__info">
                    <span class="reg-list__name">{{ $applicant->name }}</span>
                    <span class="reg-list__role">{{ ucfirst($applicant->type) }} &middot; {{ $applicant->created_at->diffForHumans() }}</span>
                </div>
                <span class="reg-status-badge reg-status-badge--pending">Pending</span>
            </a>
        @empty
            <p class="reg-list__empty">No pending registrations right now.</p>
        @endforelse>
    </div>

    {{-- ===== Right: detail panel ===== --}}
    <div class="reg-detail">
        @if ($selected)
            @php
                $isSeller = $selectedType === 'seller';
            @endphp

            <div class="reg-detail__header">
                <div class="reg-detail__avatar">
                    {{ strtoupper(substr($selected->first_name, 0, 1)) }}{{ strtoupper(substr($selected->last_name, 0, 1)) }}
                </div>
                <div>
                    <h3>{{ $selected->fullName() }}</h3>
                    <span class="reg-detail__email">{{ $selected->email }}</span>
                </div>
                <span class="reg-status-badge reg-status-badge--pending">{{ $isSeller ? 'Seller' : 'Buyer' }} &middot; Pending Review</span>
            </div>

            @if ($isSeller)
                <div class="reg-detail__section">
                    <h4>Business Information</h4>
                    <div class="reg-detail__grid">
                        <div><span>Business Name</span><strong>{{ $selected->business_name }}</strong></div>
                        <div><span>Line of Business</span><strong>{{ $selected->lineOfBusiness?->label ?? 'Not specified' }}</strong></div>
                    </div>
                </div>
            @endif

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
                    @if ($selected->street || $selected->house_number)
                        {{ $selected->house_number }} {{ $selected->street }}, {{ $selected->address_detail }}
                    @else
                        {{ $selected->barangay_name }}, {{ $selected->municipality_name }}, {{ $selected->province_name }}
                    @endif
                </p>
            </div>

            <div class="reg-detail__section">
                <h4>{{ $isSeller ? 'Submitted Documents' : 'Submitted ID' }}</h4>
                @if ($isSeller)
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
                @else
                    <a href="{{ Storage::url($selected->upload_id_path) }}" target="_blank" class="reg-detail__id-link">
                        <i data-lucide="file-text" width="16" height="16"></i>
                        View uploaded ID
                    </a>
                @endif
            </div>

            <div class="reg-detail__actions">
                @if ($isSeller)
                    <form method="POST" action="{{ route('admin.registrations.seller.approve', $selected) }}">
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
                @else
                    <form method="POST" action="{{ route('admin.registrations.buyer.approve', $selected) }}">
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
                @endif
            </div>

            <div id="rejectPanel" class="reg-reject-panel" hidden>
                @if ($isSeller)
                    <form method="POST" action="{{ route('admin.registrations.seller.reject', $selected) }}">
                @else
                    <form method="POST" action="{{ route('admin.registrations.buyer.reject', $selected) }}">
                @endif
                    @csrf
                    <label for="rejection_reason">Reason for rejection</label>
                    <textarea name="rejection_reason" id="rejection_reason" rows="3" required></textarea>
                    <button type="submit" class="reg-btn reg-btn--reject-confirm">Confirm Rejection</button>
                </form>
            </div>
        @else
            <div class="reg-detail__empty">
                <i data-lucide="inbox" width="32" height="32"></i>
                <p>Select a registration from the list to review it.</p>
            </div>
        @endif
    </div>

</div>

@endsection