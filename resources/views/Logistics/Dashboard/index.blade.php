@extends('Layouts.logistics')

@section('title', 'Dashboard')

@push('scripts')
    @vite('resources/js/seller/notification-bell.js')
@endpush

@section('content')
    <x-logistics.dashboard-hero
        :name="$provider->business_name ?? $provider->fullName()"
        :date="now()->format('l, F j, Y')"
    />

    {{-- ========================== STAT CARDS =========================== --}}
    {{-- All four stats are honest placeholders: no courier/parcel/delivery
         tables exist yet, so nothing here can be derived from real data.
         Same treatment the seller dashboard used before orders existed. --}}
    <section class="grid grid-cols-4 gap-5 mb-6 max-lg:grid-cols-2 max-sm:grid-cols-1">
        <div class="bg-[var(--bg-surface)] border border-[var(--border-strong)] rounded-lg p-5 shadow-sm">
            <div class="w-[36px] h-[36px] rounded-md flex items-center justify-center mb-4 bg-primary-500 text-[#fff8f7]">
                <i data-lucide="inbox" class="w-[18px] h-[18px]"></i>
            </div>
            <p class="font-sans text-[12.5px] text-[var(--text-muted)] mb-1">Parcels in hub</p>
            <p class="font-sans text-[24px] font-extrabold text-[var(--text-ink)]">
                <span class="text-[var(--text-muted)] font-medium">—</span>
            </p>
            <p class="font-sans text-xs text-[var(--text-muted)] mt-1">Awaiting courier intake</p>
        </div>

        <div class="bg-[var(--bg-surface)] border border-[var(--border-strong)] rounded-lg p-5 shadow-sm">
            <div class="w-[36px] h-[36px] rounded-md flex items-center justify-center mb-4 bg-secondary-700 text-[#fff8f7]">
                <i data-lucide="truck" class="w-[18px] h-[18px]"></i>
            </div>
            <p class="font-sans text-[12.5px] text-[var(--text-muted)] mb-1">Parcels in transit</p>
            <p class="font-sans text-[24px] font-extrabold text-[var(--text-ink)]">
                <span class="text-[var(--text-muted)] font-medium">—</span>
            </p>
            <p class="font-sans text-xs text-[var(--text-muted)] mt-1">Out for delivery</p>
        </div>

        <div class="bg-[var(--bg-surface)] border border-[var(--border-strong)] rounded-lg p-5 shadow-sm">
            <div class="w-[36px] h-[36px] rounded-md flex items-center justify-center mb-4 bg-warning text-[#fff8f7]">
                <i data-lucide="check-circle-2" class="w-[18px] h-[18px]"></i>
            </div>
            <p class="font-sans text-[12.5px] text-[var(--text-muted)] mb-1">Delivered today</p>
            <p class="font-sans text-[24px] font-extrabold text-[var(--text-ink)]">
                <span class="text-[var(--text-muted)] font-medium">—</span>
            </p>
            <p class="font-sans text-xs text-[var(--text-muted)] mt-1">Completed deliveries</p>
        </div>

        <div class="bg-[var(--bg-surface)] border border-[var(--border-strong)] rounded-lg p-5 shadow-sm">
            <div class="w-[36px] h-[36px] rounded-md flex items-center justify-center mb-4 bg-tertiary-500 text-[#fff8f7]">
                <i data-lucide="users" class="w-[18px] h-[18px]"></i>
            </div>
            <p class="font-sans text-[12.5px] text-[var(--text-muted)] mb-1">Active riders</p>
            <p class="font-sans text-[24px] font-extrabold text-[var(--text-ink)]">
                <span class="text-[var(--text-muted)] font-medium">—</span>
            </p>
            <p class="font-sans text-xs text-[var(--text-muted)] mt-1">Assigned to deliveries</p>
        </div>
    </section>

    <div class="grid grid-cols-[2fr_1fr] gap-5 mb-5 max-lg:grid-cols-1">

        {{-- ======================= DELIVERY ACTIVITY ====================== --}}
        {{-- Mirrors the seller dashboard's sales chart: a placeholder chart is
             NOT rendered — it would read as fake data, so an empty state
             stands in until the delivery pipeline exists. --}}
        <section class="bg-[var(--bg-surface)] border border-[var(--border-strong)] rounded-lg p-5 shadow-sm flex flex-col">
            <div class="flex items-center justify-between mb-5">
                <h2 class="font-sans text-base font-semibold text-[var(--text-ink)]">Delivery activity</h2>
                <span class="font-sans text-[11.5px] font-semibold text-[var(--text-muted)] bg-[var(--bg-chip)] rounded-full px-1 px-3">Last 7 days</span>
            </div>

            <div class="flex flex-col items-center justify-center text-center p-10 px-4">
                <i data-lucide="line-chart" class="w-[28px] h-[28px] text-[var(--icon-soft)] mb-3"></i>
                <p class="font-sans text-sm font-semibold text-[var(--text-ink)] mb-1">No delivery data yet</p>
                <p class="font-sans text-[12.5px] text-[var(--text-muted)] max-w-[320px]">
                    Delivery trends will appear here once parcels start moving through your hub.
                </p>
            </div>
        </section>

        {{-- ====================== COURIER APPLICATIONS ==================== --}}
        <section class="bg-[var(--bg-surface)] border border-[var(--border-strong)] rounded-lg p-5 shadow-sm">
            <div class="flex items-center justify-between mb-5">
                <h2 class="font-sans text-base font-semibold text-[var(--text-ink)]">Pending applications</h2>
            </div>

            <div class="flex flex-col items-center justify-center text-center p-6 px-4">
                <i data-lucide="clipboard-check" class="w-[28px] h-[28px] text-[var(--icon-soft)] mb-3"></i>
                <p class="font-sans text-sm font-semibold text-[var(--text-ink)] mb-1">No applications yet</p>
                <p class="font-sans text-[12.5px] text-[var(--text-muted)] max-w-[320px]">
                    Courier applications will appear here once intake opens.
                </p>
            </div>
        </section>
    </div>

    {{-- =========================== RECENT PARCELS ======================= --}}
    <section class="bg-[var(--bg-surface)] border border-[var(--border-strong)] rounded-lg p-5 shadow-sm">
        <div class="flex items-center justify-between mb-5">
            <h2 class="font-sans text-base font-semibold text-[var(--text-ink)]">Recent parcels</h2>
        </div>

        <div class="flex flex-col items-center justify-center text-center p-10 px-4">
            <i data-lucide="package" class="w-[28px] h-[28px] text-[var(--icon-soft)] mb-3"></i>
            <p class="font-sans text-sm font-semibold text-[var(--text-ink)] mb-1">No parcels yet</p>
            <p class="font-sans text-[12.5px] text-[var(--text-muted)] max-w-[320px]">
                Parcels will show up here as soon as sellers hand over shipments to your hub.
            </p>
        </div>
    </section>
@endsection

