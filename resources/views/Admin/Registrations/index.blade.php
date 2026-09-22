@extends('Admin.Layouts.app')

@section('title', 'Manage Registrations')

@section('content')

{{-- Registrations review — converted to Tailwind utilities in ADMIN-c.
     Source of truth was resources/css/admin/admin-registration.css (DELETED in
     this chunk). All values 1:1. Legacy quirk preserved: that file's
     `.reg-detail__section:last-of-type` border removal targeted the last DIV
     child (the reject/empty panel), never a section, so every section keeps
     its bottom border — reproduced by plain `border-b` on all sections.
     `.reg-detail__documents` never had a CSS rule; its div stays bare. --}}
@php
    // Shared reg-* styling, 1:1 from admin-registration.css.
    $regItem = 'flex items-center gap-3 p-3 rounded-md no-underline transition-colors duration-200 hover:bg-blush';
    $regItemActive = 'bg-secondary-300';
    $regAvatar = 'shrink-0 w-[38px] h-[38px] rounded-full bg-blush text-primary-800 flex items-center justify-center font-sans font-bold text-[12.5px]';
    $regBadge = 'shrink-0 px-2.5 py-1 rounded-full font-sans font-semibold text-[10.5px] tracking-[0.4px] uppercase bg-[#fdf1de] text-[#9a6a1f]';
    $regSection = 'py-5 border-b border-[#e8e2e0]';
    $regSectionH4 = 'font-sans font-semibold text-[11px] tracking-[0.8px] uppercase text-primary-800 mb-3.5';
    $regGrid = 'grid grid-cols-2 gap-4';
    $regGridCell = 'flex flex-col gap-1';
    $regGridLabel = 'font-sans text-[11px] text-muted-ink';
    $regGridValue = 'font-sans font-semibold text-[13.5px] text-neutral-950';
    $regIdLink = 'inline-flex items-center gap-2 font-sans font-semibold text-[13px] text-primary-800 underline';
    $regBtn = 'inline-flex items-center justify-center gap-2 h-11 px-6 rounded-sm font-sans font-semibold text-[12.5px] tracking-[1px] cursor-pointer transition-colors duration-200';
@endphp

@if (session('success'))
  {{-- reg-toast → utilities: mb 20px, p 12px/16px, bg #eaf3ec, border
       #a8cdb0, radius 4px, Montserrat 13px, #2f5c3a. --}}
  <div class="mb-5 py-3 px-4 bg-[#eaf3ec] border border-[#a8cdb0] rounded-sm font-sans text-[13px] text-[#2f5c3a]">{{ session('success') }}</div>
@endif

{{-- reg-layout → utilities: 340px/1fr grid, gap 24px, align-items:start;
     the file's own 1024px breakpoint kept as an arbitrary variant. --}}
<div class="grid grid-cols-[340px_1fr] gap-6 items-start max-[1024px]:grid-cols-1">

    {{-- ===== Left: pending list ===== --}}
    <div class="flex flex-col gap-1.5 bg-white border border-[#e8e2e0] rounded-lg p-5">
        <div class="flex items-center justify-between mb-3">
            <h2 class="font-serif font-normal text-[18px] text-neutral-950">Pending Registrations</h2>
            <span class="inline-flex items-center justify-center min-w-[22px] h-[22px] px-1.5 bg-primary-800 text-white font-sans font-bold text-[11.5px] rounded-full">{{ $pending->count() }}</span>
        </div>

        @forelse ($pending as $applicant)
            <a href="{{ route('admin.registrations.index', ['view' => $applicant->id, 'type' => $applicant->type]) }}"
               class="{{ $regItem }} {{ $selected && $selected->id == $applicant->id && $selectedType === $applicant->type ? $regItemActive : '' }}">
                <div class="{{ $regAvatar }}">
                    {{ strtoupper(substr($applicant->model->first_name, 0, 1)) }}{{ strtoupper(substr($applicant->model->last_name, 0, 1)) }}
                </div>
                <div class="flex-1 min-w-0 flex flex-col">
                    <span class="font-sans font-semibold text-[13.5px] text-neutral-950 truncate">{{ $applicant->name }}</span>
                    <span class="font-sans text-[11.5px] text-muted-ink">{{ ucfirst($applicant->type) }} &middot; {{ $applicant->created_at->diffForHumans() }}</span>
                </div>
                <span class="{{ $regBadge }}">Pending</span>
            </a>
        @empty
            {{-- my-[13px] re-states the UA `margin: 1em 0` the preflight
                 removes (13px font) — fidelity, not decoration. --}}
            <p class="px-2 py-6 text-center font-sans text-[13px] text-muted-ink my-[13px]">No pending registrations right now.</p>
        @endforelse>
    </div>

    {{-- ===== Right: detail panel ===== --}}
    {{-- reg-detail → utilities: bg white, border #e8e2e0, radius 8px, p 28px --}}
    <div class="bg-white border border-[#e8e2e0] rounded-lg p-7">
        @if ($selected)
            @php
                $isSeller = $selectedType === 'seller';
                $isLogistics = $selectedType === 'logistics';
                $typeLabel = match ($selectedType) {
                    'seller' => 'Seller',
                    'logistics' => 'Logistics',
                    default => 'Buyer',
                };
                $approveRoute = match ($selectedType) {
                    'seller' => 'admin.registrations.seller.approve',
                    'logistics' => 'admin.registrations.logistics.approve',
                    default => 'admin.registrations.buyer.approve',
                };
                $rejectRoute = match ($selectedType) {
                    'seller' => 'admin.registrations.seller.reject',
                    'logistics' => 'admin.registrations.logistics.reject',
                    default => 'admin.registrations.buyer.reject',
                };
            @endphp

            {{-- reg-detail__header → utilities: gap 16px, pb 20px, border-bottom.
                 The header's status badge carries `.reg-detail__header
                 .reg-status-badge { margin-left: auto }` → ml-auto. --}}
            <div class="flex items-center gap-4 pb-5 border-b border-[#e8e2e0]">
                <div class="shrink-0 w-[52px] h-[52px] rounded-full bg-blush text-primary-800 flex items-center justify-center font-sans font-bold text-base">
                    {{ strtoupper(substr($selected->first_name, 0, 1)) }}{{ strtoupper(substr($selected->last_name, 0, 1)) }}
                </div>
                <div>
                    <h3 class="font-serif font-normal text-[20px] text-neutral-950">{{ $selected->fullName() }}</h3>
                    <span class="font-sans text-[12.5px] text-muted-ink">{{ $selected->email }}</span>
                </div>
                <span class="{{ $regBadge }} ml-auto">{{ $typeLabel }} &middot; Pending Review</span>
            </div>

            @if ($isSeller || $isLogistics)
                <div class="{{ $regSection }}">
                    <h4 class="{{ $regSectionH4 }}">Business Information</h4>
                    <div class="{{ $regGrid }}">
                        <div class="{{ $regGridCell }}"><span class="{{ $regGridLabel }}">Business Name</span><strong class="{{ $regGridValue }}">{{ $selected->business_name }}</strong></div>
                        @if ($isSeller)
                            <div class="{{ $regGridCell }}"><span class="{{ $regGridLabel }}">Line of Business</span><strong class="{{ $regGridValue }}">{{ $selected->lineOfBusiness?->label ?? 'Not specified' }}</strong></div>
                        @endif
                    </div>
                </div>
            @endif

            <div class="{{ $regSection }}">
                <h4 class="{{ $regSectionH4 }}">Personal Information</h4>
                <div class="{{ $regGrid }}">
                    <div class="{{ $regGridCell }}"><span class="{{ $regGridLabel }}">Sex</span><strong class="{{ $regGridValue }}">{{ ucfirst($selected->sex) }}</strong></div>
                    <div class="{{ $regGridCell }}"><span class="{{ $regGridLabel }}">Contact No.</span><strong class="{{ $regGridValue }}">{{ $selected->contact_no }}</strong></div>
                    <div class="{{ $regGridCell }}"><span class="{{ $regGridLabel }}">Birthday</span><strong class="{{ $regGridValue }}">{{ $selected->birthday->format('M d, Y') }}</strong></div>
                    <div class="{{ $regGridCell }}"><span class="{{ $regGridLabel }}">Age</span><strong class="{{ $regGridValue }}">{{ $selected->age }}</strong></div>
                </div>
            </div>

            <div class="{{ $regSection }}">
                <h4 class="{{ $regSectionH4 }}">Address</h4>
                {{-- my-[13.5px] re-states the UA `margin: 1em 0` (13.5px font)
                     removed by preflight. --}}
                <p class="font-sans text-[13.5px] text-neutral-950 leading-[1.6] my-[13.5px]">
                    @php
                        // Logistics collects house number separately from street,
                        // so fold it into the street part for their applicants only.
                        $streetPart = trim($selected->street ?? '');
                        if ($isLogistics) {
                            $streetPart = trim(implode(' ', array_filter([
                                trim($selected->house_number ?? ''),
                                $streetPart,
                            ])));
                        }
                        $locationPart = collect([
                            $selected->barangay_name,
                            $selected->municipality_name,
                            $selected->province_name,
                        ])->filter()->implode(', ');
                        $detailPart = trim($selected->address_detail ?? '');
                        $addressLine = implode('', array_filter([
                            $streetPart,
                            $locationPart ? ($streetPart ? ', ' : '') . $locationPart : '',
                            $detailPart   ? ' — ' . $detailPart : '',
                        ]));
                    @endphp
                    {{ $addressLine ?: 'No address on record' }}
                </p>
            </div>

            <div class="{{ $regSection }}">
                <h4 class="{{ $regSectionH4 }}">{{ $isSeller || $isLogistics ? 'Submitted Documents' : 'Submitted ID' }}</h4>
                {{-- Bare div: `.reg-detail__documents` never had a CSS rule. --}}
                @if ($isSeller || $isLogistics)
                    <div>
                        <a href="{{ Storage::url($selected->upload_id_path) }}" target="_blank" class="{{ $regIdLink }}">
                            <i data-lucide="file-text" width="16" height="16"></i>
                            View uploaded ID
                        </a>
                        @if ($isSeller)
                            <a href="{{ Storage::url($selected->business_permit_path) }}" target="_blank" class="{{ $regIdLink }}">
                                <i data-lucide="file-text" width="16" height="16"></i>
                                View business permit
                            </a>
                        @else
                            <a href="{{ Storage::url($selected->dti_permit_path) }}" target="_blank" class="{{ $regIdLink }}">
                                <i data-lucide="file-text" width="16" height="16"></i>
                                View DTI permit
                            </a>
                        @endif
                    </div>
                @else
                    <a href="{{ Storage::url($selected->upload_id_path) }}" target="_blank" class="{{ $regIdLink }}">
                        <i data-lucide="file-text" width="16" height="16"></i>
                        View uploaded ID
                    </a>
                @endif
            </div>

            <div class="flex gap-3 mt-6">
                <form method="POST" action="{{ route($approveRoute, $selected) }}">
                    @csrf
                    <button type="submit" class="{{ $regBtn }} bg-neutral-950 text-white hover:bg-[#2f5c3a]">
                        <i data-lucide="check" width="16" height="16"></i>
                        Approve
                    </button>
                </form>

                <button type="button" class="{{ $regBtn }} bg-transparent text-[#a5333d] border border-[#a5333d] hover:bg-[#a5333d] hover:text-white" onclick="document.getElementById('rejectPanel').hidden = false">
                    <i data-lucide="x" width="16" height="16"></i>
                    Reject
                </button>
            </div>

            {{-- reg-reject-panel → utilities: mt 16px, p 16px, bg #fbe9e7,
                 radius 6px; label 11px 600 uppercase #a5333d mb 8px;
                 textarea w-full, p 10px/12px, border #e8a49c, radius 4px,
                 13px #1e1b1b, resize:vertical. --}}
            <div id="rejectPanel" class="mt-4 p-4 bg-[#fbe9e7] rounded-md" hidden>
                <form method="POST" action="{{ route($rejectRoute, $selected) }}">
                    @csrf
                    <label for="rejection_reason" class="block font-sans font-semibold text-[11px] tracking-[0.6px] uppercase text-[#a5333d] mb-2">Reason for rejection</label>
                    <textarea name="rejection_reason" id="rejection_reason" rows="3" required class="w-full px-3 py-2.5 border border-[#e8a49c] rounded-sm font-sans text-[13px] text-neutral-950 resize-y"></textarea>
                    <button type="submit" class="mt-3 h-10 px-5 bg-[#a5333d] text-white rounded-sm font-sans font-semibold text-xs tracking-[1px] cursor-pointer">Confirm Rejection</button>
                </form>
            </div>
        @else
            <div class="flex flex-col items-center justify-center gap-3 py-20 px-5 text-muted-ink text-center">
                <i data-lucide="inbox" width="32" height="32"></i>
                <p class="font-sans text-[13.5px] my-[13.5px]">Select a registration from the list to review it.</p>
            </div>
        @endif
    </div>

</div>

@endsection