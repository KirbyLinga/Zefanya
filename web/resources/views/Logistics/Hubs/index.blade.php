@extends('Layouts.logistics')

@section('title', 'Hubs and Stations')

@section('content')
<div data-hubs-page>
    <header class="mb-7 flex flex-wrap items-end justify-between gap-4 border-b border-[var(--border-strong)] pb-6">
        <div>
            <p class="mb-2 font-sans text-[10px] font-semibold uppercase tracking-[0.18em] text-primary-700">Logistics network</p>
            <h1 class="font-serif text-[28px] leading-tight text-[var(--text-ink)] sm:text-[32px]">Hubs and Stations</h1>
            <p class="mt-2 font-sans text-xs text-[var(--text-muted)]">{{ $provider->business_name }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <a href="{{ route('logistics.areas.index') }}" class="inline-flex min-h-10 items-center justify-center gap-2 rounded-sm border border-[var(--border-strong)] px-3 font-sans text-[11px] font-semibold text-[var(--text-ink)] transition hover:bg-[var(--bg-surface)] focus-visible:outline-2 focus-visible:outline-primary-700"><i data-lucide="map" class="size-3.5"></i>Barangay coverage</a>
        </div>
    </header>

    <x-logistics-flash :messages="['success', 'warning', 'error']" />
    @if($errors->any())
        <div role="alert" class="mb-5 rounded-md border border-red-200 bg-red-50 p-4 font-sans text-xs leading-relaxed text-red-900">
            <p class="font-semibold">Please review the following before saving:</p>
            <ul class="mt-1 list-disc pl-4">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="grid min-w-0 grid-cols-1 items-start gap-6 xl:grid-cols-[minmax(260px,0.8fr)_minmax(0,2fr)]">
        <aside class="min-w-0">
            <div class="rounded-lg border border-[var(--border-strong)] border-t-2 border-t-primary-300 bg-[var(--bg-surface)] p-5">
                <p class="flex items-center gap-2 font-sans text-xs font-semibold text-primary-800"><i data-lucide="shield-check" class="size-4"></i>Service province</p>
                @if ($provinceLocked ?? false)
                    <p class="mt-3 font-serif text-lg text-[var(--text-ink)]">{{ $provider->province_name ?: 'Verified province' }}</p>
                    <p class="mt-1 font-sans text-[11px] text-[var(--text-muted)]">Verified and locked. Admin provisions your Province Hub; you manage Station Hubs under it.</p>
                @else
                    <p class="mt-3 font-sans text-xs leading-relaxed text-[var(--text-muted)]">Your service province will be verified when Admin approves your registration.</p>
                @endif
                @if ($liveProvinceHub ?? null)
                    <p class="mt-3 font-sans text-[11px] text-[var(--text-muted)]">Live Province Hub: <span class="font-semibold text-[var(--text-ink)]">{{ $liveProvinceHub->name }}</span></p>
                @else
                    <p class="mt-3 font-sans text-[11px] text-amber-800">No live Province Hub yet. Wait for Admin to provision one before adding stations.</p>
                @endif
            </div>
            <div class="mt-5 flex items-start gap-3 px-1 font-sans text-[11px] leading-relaxed text-[var(--text-muted)]">
                <i data-lucide="info" class="mt-0.5 size-4 shrink-0 text-primary-600"></i>
                <p>New hubs and location changes are reviewed by Admin. Your approved operational address stays in use while a change is pending.</p>
            </div>
        </aside>

        <section aria-labelledby="provinceHubsTitle" class="min-w-0">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                <h2 id="provinceHubsTitle" class="font-serif text-xl text-[var(--text-ink)]">Province Hubs</h2>
                <p class="font-sans text-[10px] font-medium text-[var(--text-muted)]">{{ $provinceHubs->count() }} province hubs <span aria-hidden="true" class="mx-1">/</span> {{ $provinceHubs->sum('stations_count') }} stations</p>
            </div>
            <div class="flex min-w-0 flex-col gap-5">
                @forelse ($provinceHubs as $provinceHub)
                    @php
                        $provinceEditLocation = $provinceHub->pendingLocationChange ?: $provinceHub;
                        $provinceEditSubmitted =(string) old('edit_hub_id') === (string) $provinceHub->id;
                        $stationFormSubmitted = !old('edit_hub_id') && (string) old('parent_hub_id') === (string) $provinceHub->id;
                    @endphp
                    <article class="min-w-0 overflow-hidden rounded-lg border border-[var(--border-strong)] border-l-[3px] border-l-primary-300 bg-[var(--bg-surface)] shadow-sm" aria-labelledby="province-hub-{{ $provinceHub->id }}">
                        <header class="p-5">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="flex min-w-0 items-start gap-3">
                                    <span class="flex size-9 shrink-0 items-center justify-center rounded-md bg-primary-50 text-primary-800"><i data-lucide="warehouse" class="size-[18px]"></i></span>
                                    <div class="min-w-0">
                                        <p class="mb-1 font-sans text-[9px] font-semibold uppercase tracking-[0.14em] text-primary-700">{{ $provinceHub->province_name ?: 'Province hub' }}</p>
                                        <h3 id="province-hub-{{ $provinceHub->id }}" class="break-words font-serif text-[19px] leading-snug text-[var(--text-ink)]">{{ $provinceHub->name }}</h3>
                                    </div>
                                </div>
                                @include('Logistics.Hubs.status-badges', ['hub' => $provinceHub])
                            </div>
                            <p class="mt-3 flex items-start gap-2 font-sans text-[11px] leading-relaxed text-[var(--text-muted)]"><i data-lucide="map-pin" class="mt-0.5 size-3.5 shrink-0"></i><span class="min-w-0 break-words">{{ $provinceHub->fullAddress() ?: $provinceHub->province_name }}</span></p>
                            @if($provinceHub->location_details)<p class="mt-1 pl-5 font-sans text-[11px] leading-relaxed text-[var(--text-muted)]">{{ $provinceHub->location_details }}</p>@endif
                            @include('Logistics.Hubs.review-notices', ['hub' => $provinceHub])
                            <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
                                <span class="font-sans text-[10px] text-[var(--text-muted)]">{{ $provinceHub->stations_count }} stations</span>
                                @if($provinceHub->isApproved())
                                    @include('Logistics.Hubs.status-form', ['hub' => $provinceHub])
                                @else
                                    <span class="font-sans text-[10px] text-[var(--text-muted)]">Awaiting Admin approval</span>
                                @endif
                            </div>
                            <details id="hub-edit-{{ $provinceHub->id }}" data-hub-edit data-hub-id="{{ $provinceHub->id }}" class="mt-3" @if($provinceEditSubmitted && $errors->any()) open @endif>
                                <summary class="inline-flex cursor-pointer list-none items-center gap-1.5 rounded-sm py-1 font-sans text-[11px] font-semibold text-primary-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-700 [&::-webkit-details-marker]:hidden"><i data-lucide="pencil" class="size-3"></i>Edit Province Hub</summary>
                                @include('Logistics.Hubs.province-edit-form')
                            </details>
                        </header>

                        <section class="border-t border-[var(--border-strong)]" aria-label="Stations in {{ $provinceHub->name }}">
                            <div class="flex items-center justify-between gap-3 bg-[var(--bg-page)]/50 px-5 py-3">
                                <h4 class="font-sans text-[9px] font-semibold uppercase tracking-[0.14em] text-[var(--text-muted)]">Station Hubs</h4>
                                <a href="{{ route('logistics.areas.index') }}" class="font-sans text-[10px] font-semibold text-primary-800 hover:underline focus-visible:outline-2 focus-visible:outline-primary-700">Manage coverage</a>
                            </div>
                            <div class="divide-y divide-[var(--border-strong)] px-5">
                                @forelse ($provinceHub->stations as $station)
                                    @php
                                        $stationEditLocation = $station->pendingLocationChange ?: $station;
                                        $stationEditSubmitted =(string) old('edit_hub_id') === (string) $station->id;
                                    @endphp
                                    <div class="py-4">
                                        <details id="station-details-{{ $station->id }}" data-station-details class="group/station" @if($stationEditSubmitted && $errors->any()) open @endif>
                                            <summary class="flex cursor-pointer list-none flex-col items-start justify-between gap-3 rounded-sm sm:flex-row sm:flex-wrap focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary-700 [&::-webkit-details-marker]:hidden">
                                                <span class="flex w-full min-w-0 items-start gap-2.5 sm:w-auto sm:flex-1">
                                                    <i data-lucide="git-branch" class="mt-0.5 size-4 shrink-0 text-primary-500"></i>
                                                    <span class="min-w-0">
                                                        <span class="block break-words font-sans text-xs font-semibold text-[var(--text-ink)]">{{ $station->name }}</span>
                                                        <span class="mt-1 block font-sans text-[10px] leading-relaxed text-[var(--text-muted)]">{{ $station->municipality }} <span aria-hidden="true">/</span> {{ $station->areas->where('is_active', true)->whereNotNull('coverage_validated_at')->whereNotNull('barangay_code')->count() }} barangays <span aria-hidden="true">/</span> {{ $station->couriers_count }} fleet riders</span>
                                                        <span class="mt-1 block break-words font-sans text-[10px] leading-relaxed text-[var(--text-muted)]">Coverage: {{ $station->areas->where('is_active', true)->whereNotNull('coverage_validated_at')->pluck('barangay_name')->filter()->join(', ') ?: 'Barangay coverage required' }}</span>
                                                    </span>
                                                </span>
                                                <span class="flex w-full items-center justify-between gap-2 pl-[26px] sm:w-auto sm:justify-start sm:pl-0">
                                                    @include('Logistics.Hubs.status-badges', ['hub' => $station])
                                                    <i data-lucide="chevron-down" class="size-3.5 shrink-0 text-[var(--text-muted)] transition group-open/station:rotate-180"></i>
                                                </span>
                                            </summary>
                                            <div class="mt-4 border-l border-primary-200 pl-4">
                                                <p class="break-words font-sans text-[11px] leading-relaxed text-[var(--text-muted)]">{{ $station->fullAddress() ?: $station->municipality }}</p>
                                                @if($station->location_details)<p class="mt-1 font-sans text-[11px] text-[var(--text-muted)]">{{ $station->location_details }}</p>@endif
                                                <div class="mt-3 flex flex-wrap items-center gap-2">
                                                    @if($station->isApproved())
                                                        <a href="{{ route('logistics.areas.index', ['station' => $station->id]) }}" class="inline-flex h-8 items-center rounded-sm border border-[var(--border-strong)] px-3 font-sans text-[11px] font-semibold text-primary-800 hover:bg-[var(--bg-page)]">Coverage</a>
                                                        @include('Logistics.Hubs.status-form', ['hub' => $station])
                                                    @else
                                                        <span class="font-sans text-[11px] text-[var(--text-muted)]">Awaiting Admin review</span>
                                                    @endif
                                                </div>
                                                <details id="hub-edit-{{ $station->id }}" data-hub-edit data-hub-id="{{ $station->id }}" class="mt-3" @if($stationEditSubmitted && $errors->any()) open @endif>
                                                    <summary class="inline-flex cursor-pointer list-none items-center gap-1.5 rounded-sm py-1 font-sans text-[11px] font-semibold text-primary-800 focus-visible:outline-2 focus-visible:outline-primary-700 [&::-webkit-details-marker]:hidden"><i data-lucide="pencil" class="size-3"></i>Edit Station Hub</summary>
                                                    @include('Logistics.Hubs.station-edit-form')
                                                </details>
                                            </div>
                                        </details>
                                        @include('Logistics.Hubs.review-notices', ['hub' => $station])
                                    </div>
                                @empty
                                    <p class="py-5 font-sans text-xs text-[var(--text-muted)]">No Station Hubs in this Province Hub.</p>
                                @endforelse
                            </div>
                        </section>

                        <footer class="border-t border-[var(--border-strong)] px-5 py-3">
                            @if($provinceHub->isApproved() && $provinceHub->is_active && filled($provinceHub->province_code) && filled($provinceHub->region_code))
                                <details id="station-create-{{ $provinceHub->id }}" data-station-create data-parent-hub-id="{{ $provinceHub->id }}" @if($stationFormSubmitted && $errors->any()) open @endif>
                                    <summary class="inline-flex min-h-8 cursor-pointer list-none items-center gap-1.5 rounded-sm border border-primary-200 px-3 font-sans text-[11px] font-semibold text-primary-800 hover:bg-primary-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-700 [&::-webkit-details-marker]:hidden"><i data-lucide="plus" class="size-3.5"></i>Add Station</summary>
                                    @include('Logistics.Hubs.station-create-form')
                                </details>
                            @else
                                <p class="font-sans text-[10px] leading-relaxed text-[var(--text-muted)]">Station Hubs can be added once this Province Hub is approved, active, and has valid location details.</p>
                            @endif
                        </footer>
                    </article>
                @empty
                    <div class="rounded-lg border border-dashed border-[var(--border-strong)] bg-[var(--bg-surface)] px-6 py-14 text-center">
                        <i data-lucide="network" class="mx-auto mb-4 size-8 text-primary-400"></i>
                        <h3 class="font-serif text-lg text-[var(--text-ink)]">No Province Hub yet</h3>
                        <p class="mx-auto mt-2 max-w-sm font-sans text-xs leading-relaxed text-[var(--text-muted)]">Province Hubs are provisioned by Admin after your company's province is verified. Once yours is approved and active, add its local Station Hubs here.</p>
                    </div>
                @endforelse
            </div>
        </section>
    </div>
</div>
@endsection

@push('scripts')
    @vite(['resources/js/buyer/address-lookup.js', 'resources/js/logistics/hubs.js'])
@endpush
