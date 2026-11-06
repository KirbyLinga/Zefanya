@extends('Layouts.logistics')

@section('title', 'Delivery Areas')

@section('content')
@php
    $legacyAreas = $areas->filter(fn ($area) => blank($area->barangay_code));
    $coverageAreas = $areas->reject(fn ($area) => blank($area->barangay_code));
    $attentionAreas = $serviceableAreas->where('couriers_count', 0);
    $withoutRiders = $attentionAreas->count();
    $assignedRiders = $serviceableAreas->flatMap(fn ($area) => $area->couriers)->unique('id')->count();
    $cardClass = 'rounded-xl border border-[var(--border-strong)] bg-[var(--bg-surface)] shadow-sm';
    $labelClass = 'mb-1 block font-sans text-xs font-semibold text-[var(--text-ink)]';
    $inputClass = 'h-10 w-full rounded-md border border-[var(--border-strong)] bg-[var(--bg-surface)] px-3 font-sans text-sm text-[var(--text-ink)] outline-none transition focus:border-primary-700 focus:ring-2 focus:ring-primary-100 disabled:bg-[var(--bg-page)] disabled:text-[var(--text-muted)]';
    $hintClass = 'mt-1 font-sans text-xs leading-relaxed text-[var(--text-muted)]';
    $stats = [
        ['tab' => 'coverage', 'label' => 'Serviceable barangays', 'value' => $serviceableAreas->count(), 'unit' => $serviceableAreas->count() === 1 ? 'barangay' : 'barangays', 'icon' => 'layers', 'class' => 'bg-primary-50 text-primary-800'],
        ['tab' => 'attention', 'label' => 'Areas without riders', 'value' => $withoutRiders, 'unit' => $withoutRiders === 1 ? 'area' : 'areas', 'icon' => 'alert-triangle', 'class' => 'bg-amber-50 text-amber-700'],
        ['tab' => 'riders', 'label' => 'Assigned riders', 'value' => $assignedRiders, 'unit' => $assignedRiders === 1 ? 'rider' : 'riders', 'icon' => 'users', 'class' => 'bg-tertiary-100 text-tertiary-800'],
        ['tab' => 'legacy', 'label' => 'Legacy coverage', 'value' => $legacyAreas->count(), 'unit' => $legacyAreas->count() === 1 ? 'legacy area' : 'legacy areas', 'icon' => 'map-pinned', 'class' => 'bg-primary-50 text-primary-800'],
    ];
@endphp

<header class="mb-7 flex flex-wrap items-center justify-between gap-5">
    <div>
        <p class="mb-2 flex items-center gap-2 font-sans text-[10px] font-semibold uppercase tracking-[0.18em] text-[var(--link)]"><span class="h-px w-6 bg-primary-300"></span>Network management</p>
        <h1 class="font-serif text-3xl leading-tight text-[var(--text-ink)] sm:text-4xl">Delivery Areas</h1>
        <p class="mt-2 font-sans text-sm text-[var(--text-muted)]">Your coverage, connected. Manage barangays and the riders who serve them.</p>
        <p class="sr-only">{{ $serviceableAreas->count() }} serviceable barangays, {{ $withoutRiders }} without riders</p>
    </div>
    <div class="flex flex-wrap items-center gap-3">
        @if ($verifiedProvinceName ?? null)
            <div class="flex items-center gap-2.5 border-r border-[var(--border-strong)] pr-4">
                <span class="flex size-9 items-center justify-center rounded-full bg-tertiary-100 text-tertiary-800"><i data-lucide="shield-check" class="size-4"></i></span>
                <div><p class="font-sans text-[10px] font-medium text-[var(--text-muted)]">Verified province</p><p class="font-sans text-sm font-semibold text-[var(--text-ink)]">{{ $verifiedProvinceName }}</p></div>
            </div>
        @endif
        <a href="#coverage-form" data-focus-coverage class="inline-flex min-h-11 items-center justify-center gap-2 rounded-md bg-primary-800 px-4 font-sans text-sm font-semibold text-white shadow-sm transition hover:bg-primary-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-700"><i data-lucide="plus" class="size-4"></i>Add Coverage</a>
    </div>
</header>

<x-logistics-flash :messages="['success', 'warning', 'error']" />

@unless ($riderLinksAvailable)
    <p role="alert" class="mb-4 rounded-sm border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">Existing rider links are shown. Ask an administrator to apply the pending rider-barangay migration to enable linking riders to multiple barangays.</p>
@endunless

@unless ($coverageSchemaAvailable)
    <p role="alert" class="mb-4 rounded-sm border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">Ask an administrator to apply the pending Barangay Coverage migrations. New assignments are blocked; existing parcels can continue.</p>
@endunless

<div class="min-w-0">
        {{-- Summary --}}
        <div class="mb-6 grid grid-cols-2 overflow-hidden rounded-xl border border-[var(--border-strong)] bg-[var(--bg-surface)] lg:grid-cols-4">
            @foreach ($stats as $stat)
                <a href="#areas-panel-{{ $stat['tab'] }}" data-summary-tab="{{ $stat['tab'] }}" class="group flex min-w-0 flex-col gap-4 text-left transition hover:bg-[var(--bg-soft)] focus-visible:outline-2 focus-visible:outline-offset-[-4px] focus-visible:outline-primary-700 border-b border-[var(--border-strong)] p-4 odd:border-r lg:border-b-0 lg:not-last:border-r sm:p-5">
                    <div class="flex items-center justify-between gap-2">
                        <p class="font-sans text-xs font-medium leading-relaxed text-[var(--text-muted)]">{{ $stat['label'] }}</p>
                        <span class="flex size-8 shrink-0 items-center justify-center rounded-md {{ $stat['class'] }}"><i data-lucide="{{ $stat['icon'] }}" class="size-4"></i></span>
                    </div>
                    <p class="flex items-baseline gap-2 font-sans"><span class="text-3xl font-semibold leading-none tracking-tight text-[var(--text-ink)]">{{ $stat['value'] }}</span><span class="text-xs text-[var(--text-muted)]">{{ $stat['unit'] }}</span></p>
                    <span class="inline-flex items-center gap-1 font-sans text-xs font-semibold text-[var(--link)]">View details <i data-lucide="arrow-up-right" class="size-3.5"></i></span>
                </a>
            @endforeach
        </div>

        <section class="{{ $cardClass }}" data-area-tabs>
            <div role="tablist" aria-label="Delivery area views" class="flex gap-1 overflow-x-auto border-b border-[var(--border-strong)] px-3 pt-2 sm:px-5">
                @foreach ([['coverage', 'layers', 'Barangay Coverage'], ['riders', 'users', 'Rider Assignments'], ['attention', 'alert-triangle', 'Needs Attention'], ['legacy', 'map-pinned', 'Legacy Coverage']] as [$tab, $icon, $label])
                    <button type="button" role="tab" id="areas-tab-{{ $tab }}" aria-controls="areas-panel-{{ $tab }}" tabindex="{{ $loop->first ? '0' : '-1' }}" aria-selected="{{ $loop->first ? 'true' : 'false' }}" data-tab="{{ $tab }}"
                            class="inline-flex min-h-14 shrink-0 items-center gap-2 border-b-2 border-transparent px-3 font-sans text-xs font-semibold text-[var(--text-muted)] transition hover:text-[var(--link)] aria-selected:border-[var(--link)] aria-selected:text-[var(--link)] focus-visible:outline-2 focus-visible:outline-offset-[-4px] focus-visible:outline-primary-700 sm:text-sm">
                        <i data-lucide="{{ $icon }}" class="size-4"></i>{{ $label }}
                        @if ($tab === 'attention' && $attentionAreas->isNotEmpty())
                            <span class="rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-semibold text-amber-900">{{ $attentionAreas->count() }}</span>
                        @endif
                    </button>
                @endforeach
            </div>

            {{-- Barangay coverage --}}
            <div role="tabpanel" id="areas-panel-coverage" aria-labelledby="areas-tab-coverage" data-tab-panel="coverage" class="p-4 sm:p-5">
                <p class="mb-4 font-sans text-xs leading-relaxed text-[var(--text-muted)]">{{ $serviceableAreas->count() }} serviceable barangays; {{ $coverageAreas->count() - $serviceableAreas->count() }} inactive or not serviceable. Legacy records are listed separately and excluded from serviceable counts.</p>
                <div data-coverage-table>
                    <div class="mb-4 flex flex-wrap items-end gap-3">
                        <label class="relative w-full min-w-0 flex-1 sm:min-w-48">
                            <span class="sr-only">Search coverage</span>
                            <i data-lucide="search" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-[var(--text-muted)]"></i>
                            <input type="search" data-area-filter="search" placeholder="Search barangay, municipality, PSGC code..." class="{{ $inputClass }} pl-9">
                        </label>
                        <div class="w-full sm:w-40">
                            <label for="areaStationFilter" class="{{ $labelClass }}">Station Hub</label>
                            <select id="areaStationFilter" data-area-filter="station" class="{{ $inputClass }}">
                                <option value="">All Station Hubs</option>
                                @foreach ($stations as $station)
                                    <option value="{{ $station->id }}" @selected($selectedStation?->id === $station->id)>{{ $station->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="w-full sm:w-48">
                            <label for="areaCoverageFilter" class="{{ $labelClass }}">Coverage status</label>
                            <select id="areaCoverageFilter" data-area-filter="coverage" class="{{ $inputClass }}">
                                <option value="serviceable">Serviceable barangays</option>
                                <option value="unready">Inactive / not serviceable</option>
                                <option value="">All barangay records</option>
                            </select>
                        </div>
                        <div class="w-full sm:w-44">
                            <label for="areaRiderFilter" class="{{ $labelClass }}">Rider Status</label>
                            <select id="areaRiderFilter" data-area-filter="rider" class="{{ $inputClass }}">
                                <option value="">All Rider Statuses</option>
                                <option value="assigned">Assigned</option>
                                <option value="none">No rider</option>
                            </select>
                        </div>
                    </div>

                    @if ($coverageAreas->isEmpty())
                        <div class="rounded-md border border-dashed border-[var(--border-strong)] p-8 text-center">
                            <p class="mb-1 font-sans text-[13px] font-semibold text-[var(--text-ink)]">No areas yet</p>
                            <p class="font-sans text-sm text-[var(--text-muted)]">Choose Add Coverage to connect barangays to a Station Hub.</p>
                        </div>
                    @else
                        <div class="overflow-x-auto rounded-lg border border-[var(--border-strong)]">
                            <table aria-label="Barangay coverage areas" class="w-full min-w-[680px] text-left font-sans">
                                <thead class="border-b border-[var(--border-strong)] bg-[var(--bg-page)]">
                                    <tr>
                                        @foreach (['Barangay / Municipality', 'Station Hub', 'Assigned Rider', 'Status'] as $heading)
                                            <th scope="col" class="px-4 py-3.5 text-[10px] font-semibold uppercase tracking-wider text-[var(--text-muted)]">{{ $heading }}</th>
                                        @endforeach
                                        <th scope="col" class="relative px-4 py-3.5 text-right text-[10px] font-semibold uppercase tracking-wider text-[var(--text-muted)]"><span class="sr-only">Actions</span></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-[var(--border-strong)]">
                                    @foreach ($coverageAreas as $area)
                                        @php
                                            $hasRider = (int) $area->couriers_count > 0;
                                            $isServiceable = $serviceableAreas->contains('id', $area->id);
                                        @endphp
                                        <tr class="transition-colors hover:bg-[var(--bg-soft)]" data-area-row data-coverage="{{ $isServiceable ? 'serviceable' : 'unready' }}" data-search="{{ strtolower($area->barangay_name.' '.$area->municipality.' '.$area->barangay_code) }}" data-station="{{ $area->station_hub_id }}" data-rider="{{ $hasRider ? 'assigned' : 'none' }}">
                                            <td class="px-4 py-5"><p class="text-sm font-semibold text-[var(--text-ink)]">{{ $area->barangay_name }}</p><p class="mt-1 text-xs text-[var(--text-muted)]">{{ $area->municipality }} <span class="px-1 text-neutral-300">/</span> <span class="text-[11px] tabular-nums text-[var(--text-muted)]">{{ $area->barangay_code }}</span></p></td>
                                            <td class="px-4 py-5 text-sm text-[var(--text-ink)]">{{ $area->station?->name ?? 'Unassigned' }}</td>
                                            <td class="px-4 py-5 text-sm text-[var(--text-ink)]">
                                                <div class="flex flex-wrap gap-2">
                                                    @foreach ($area->couriers as $linkedCourier)
                                                        <form method="POST" action="{{ route('logistics.areas.couriers.unassign', [$area, $linkedCourier]) }}" data-confirm="Unlink {{ $linkedCourier->name }} from {{ $area->barangay_name ?? $area->name }}? This affects 1 rider. This barangay has {{ $pendingPickups[$area->id] ?? 0 }} pending {{ ($pendingPickups[$area->id] ?? 0) === 1 ? 'pickup' : 'pickups' }}. Existing parcel assignments will remain; other barangay links will be kept." class="inline-flex items-center rounded-full border border-[var(--border-strong)] bg-[var(--bg-page)] pl-3">
                                                            @csrf
                                                            @method('DELETE')
                                                            <span class="text-sm text-[var(--text-ink)]">{{ $linkedCourier->name }}</span>
                                                            <button type="submit" aria-label="Unlink {{ $linkedCourier->name }} from {{ $area->barangay_name ?? $area->name }}" class="inline-flex min-h-9 items-center justify-center rounded-full px-2 text-xs font-semibold text-[var(--link)] hover:bg-[var(--bg-soft)]">Unlink</button>
                                                        </form>
                                                    @endforeach
                                                    @if ($isServiceable && $riderLinksAvailable)
                                                        <a href="#rider-links" data-assign-area="{{ $area->id }}" data-assign-station="{{ $area->station_hub_id }}" data-assign-name="{{ $area->barangay_name }}" class="inline-flex min-h-9 items-center gap-1 rounded-md border border-primary-200 bg-primary-50 px-3 text-xs font-semibold text-[var(--link)] hover:bg-primary-100"><i data-lucide="user-plus" class="size-3.5"></i>{{ $hasRider ? 'Add / change rider' : 'Assign rider' }}</a>
                                                    @elseif (! $isServiceable)
                                                        <span class="text-xs text-[var(--text-muted)]">Activate validated coverage and its approved hubs before assigning.</span>
                                                    @endif
                                                </div>
                                            </td>
                                            <td class="px-4 py-5">
                                                @if (! $area->is_active)
                                                    <span class="inline-flex rounded-full bg-neutral-100 px-2.5 py-1 text-xs font-semibold text-neutral-700">Inactive</span>
                                                @elseif (! $isServiceable)
                                                    <span class="inline-flex rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-900">Not serviceable</span>
                                                @elseif ($hasRider)
                                                    <span class="inline-flex items-center gap-1 rounded-full bg-tertiary-100 px-2.5 py-1 text-xs font-semibold text-tertiary-800"><i data-lucide="check" class="size-3"></i>Serviceable &middot; assigned</span>
                                                @else
                                                    <span class="inline-flex rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-900">No rider</span>
                                                @endif
                                            </td>
                                            <td class="px-4 py-5 text-right">
                                                @include('Logistics.Areas.partials.row-actions')
                                            </td>
                                        </tr>
                                    @endforeach
                                    <tr data-area-empty hidden><td colspan="5" class="px-3 py-6 text-center text-sm text-[var(--text-muted)]">No coverage matches these filters.</td></tr>
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>

            </div>

            <div role="tabpanel" id="areas-panel-attention" aria-labelledby="areas-tab-attention" data-tab-panel="attention" class="p-4 sm:p-5" hidden>
                @if ($attentionAreas->isEmpty())
                    <p data-attention-empty class="py-6 text-center text-sm text-[var(--text-muted)]">Every serviceable barangay has a rider. Review legacy and inactive coverage separately.</p>
                @else
                    <div class="overflow-hidden rounded-lg border border-[var(--border-strong)] bg-[var(--bg-soft)]">
                        <div class="flex items-start gap-3 p-4">
                            <i data-lucide="alert-triangle" class="mt-0.5 size-5 shrink-0 text-[var(--status-warning)]"></i>
                            <div>
                                <p class="flex items-center gap-2 font-sans text-sm font-semibold text-[var(--text-ink)]">Needs attention <span class="rounded-full bg-amber-100 px-2 py-0.5 text-[11px] text-amber-900">{{ $attentionAreas->count() }}</span></p>
                                <p class="mt-0.5 font-sans text-xs text-[var(--text-muted)]">Serviceable barangays without riders. Assign a rider from the same Station Hub.</p>
                            </div>
                        </div>
                        @include('Logistics.Areas.partials.attention-list')
                    </div>
                @endif
            </div>

            <div role="tabpanel" id="areas-panel-legacy" aria-labelledby="areas-tab-legacy" data-tab-panel="legacy" class="p-4 sm:p-5" hidden>
                <h2 class="font-sans text-sm font-semibold text-[var(--text-ink)]">Legacy coverage &middot; {{ $legacyAreas->count() }}</h2>
                <p class="mb-4 mt-1 font-sans text-xs leading-relaxed text-[var(--text-muted)]">These records have no barangay code and are excluded from serviceable coverage and areas without riders. Convert each record to validated barangay coverage before assigning riders.</p>
                @if ($legacyAreas->isEmpty())
                    <p class="py-6 text-center text-sm text-[var(--text-muted)]">No legacy coverage records.</p>
                @else
                    @include('Logistics.Areas.partials.attention-list', ['attentionAreas' => $legacyAreas])
                @endif
            </div>

            {{-- Rider assignments --}}
            <div role="tabpanel" id="areas-panel-riders" aria-labelledby="areas-tab-riders" data-tab-panel="riders" class="p-4 sm:p-5" hidden>
                <div class="mb-5 flex flex-wrap items-center justify-between gap-3"><div><h2 class="font-sans text-sm font-semibold text-[var(--text-ink)]">Rider coverage</h2><p class="mt-1 text-xs text-[var(--text-muted)]">The summary counts riders linked to serviceable barangays in this view. To change a rider, assign the new rider on the barangay row, then unlink the previous rider.</p></div><a href="#rider-links" class="inline-flex min-h-10 items-center gap-2 rounded-md border border-primary-200 bg-primary-50 px-3 text-xs font-semibold text-primary-900 hover:bg-primary-100"><i data-lucide="user-plus" class="size-4"></i>Assign Rider</a></div>
                @forelse ($couriers as $courier)
                    <div class="flex flex-wrap items-start justify-between gap-3 border-b border-[var(--border-strong)] py-4 first:pt-0 last:border-0">
                        <div class="flex items-start gap-3">
                            <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-primary-50 font-sans text-sm font-semibold text-primary-800">{{ strtoupper(substr($courier->name, 0, 1)) }}</span>
                            <div>
                                <p class="font-sans text-sm font-semibold text-[var(--text-ink)]">{{ $courier->name }}</p>
                                <p class="mt-0.5 font-sans text-xs text-[var(--text-muted)]">{{ ucfirst($courier->rider?->status ?? 'No rider account') }} &middot; {{ $courier->rider?->station?->name ?? 'No Station Hub' }}</p>
                            </div>
                        </div>
                        <span class="font-sans text-xs text-[var(--text-muted)]">{{ $courier->activeWorkload() }}/{{ $courier::ACTIVE_CAPACITY }} busy</span>
                        <div class="flex w-full flex-wrap gap-2 pl-12">
                            @forelse ($courier->areas->whereIn('id', $areas->modelKeys()) as $linkedArea)
                                <span class="rounded-full bg-primary-50 px-3 py-1 text-xs text-primary-900">{{ $linkedArea->barangay_name ?? $linkedArea->name }}{{ blank($linkedArea->barangay_code) ? ' - Legacy' : ($serviceableAreas->contains('id', $linkedArea->id) ? '' : ' - Not serviceable') }}</span>
                            @empty
                                <span class="text-xs text-[var(--text-muted)]">No barangays linked</span>
                            @endforelse
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-[var(--text-muted)]">No riders in your fleet yet.</p>
                @endforelse
            </div>

        </section>
</div>
<div class="mt-5 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-[var(--border-strong)] bg-[var(--bg-soft)] px-5 py-4 font-sans">
    <div class="flex items-start gap-3">
        <i data-lucide="info" class="mt-0.5 size-4 shrink-0 text-[var(--link)]"></i>
        <p class="text-xs leading-relaxed text-[var(--text-body)]">Coverage is serviceable when the area and its hubs are active and approved.</p>
    </div>
    <a href="{{ route('logistics.earnings.index', ['tab' => 'shipping-rates']) }}" class="inline-flex min-h-9 items-center gap-2 text-xs font-semibold text-[var(--link)] hover:underline">View shipping rates <i data-lucide="arrow-up-right" class="size-3.5"></i></a>
</div>

<dialog id="coverage-form" aria-labelledby="coverage-dialog-title" data-auto-open="{{ $convertingArea || $errors->hasAny(['station_hub_id', 'city_municipality_code', 'municipality_name', 'barangay_codes', 'barangay_codes.*', 'barangay_code', 'coverage']) ? 'true' : 'false' }}" class="fixed inset-0 m-auto max-h-[90dvh] w-[calc(100%_-_2rem)] max-w-xl overflow-y-auto rounded-xl border border-[var(--border-strong)] bg-[var(--bg-surface)] text-[var(--text-ink)] shadow-2xl backdrop:bg-primary-950/40 backdrop:backdrop-blur-sm">
    <button type="button" data-close-dialog class="absolute right-4 top-4 inline-flex size-9 items-center justify-center rounded-md text-[var(--text-muted)] hover:bg-[var(--bg-soft)] focus-visible:outline-2 focus-visible:outline-primary-800" aria-label="Close coverage form"><i data-lucide="x" class="size-5"></i></button>
        <section class="p-5 sm:p-7">
            <div class="mb-6 flex items-start gap-3 pr-7">
                <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary-50 text-primary-800"><i data-lucide="{{ $convertingArea ? 'refresh-cw' : 'plus' }}" class="size-4"></i></span>
                <div>
                    <h2 id="coverage-dialog-title" class="font-serif text-2xl text-[var(--text-ink)]">{{ $convertingArea ? 'Convert legacy area' : 'Add Coverage' }}</h2>
                    @if ($convertingArea)
                        <p class="mt-0.5 font-sans text-xs text-[var(--text-muted)]">Select one barangay to replace {{ $convertingArea->name }}. Add any further barangays as new coverage afterward.</p>
                        <a href="{{ route('logistics.areas.index') }}" class="mt-1 inline-flex min-h-9 items-center text-xs font-semibold underline">Cancel conversion</a>
                    @else
                        <p class="mt-0.5 font-sans text-xs text-[var(--text-muted)]">Add barangays to a station hub. An area is serviceable only while its coverage and hubs are active and approved.</p>
                        <a href="{{ route('logistics.earnings.index', ['tab' => 'shipping-rates']) }}" class="mt-1 inline-flex min-h-8 items-center text-xs font-semibold text-[var(--link)] hover:underline">View shipping rates</a>
                    @endif
                </div>
            </div>
            <form method="POST" action="{{ $convertingArea ? route('logistics.areas.update', $convertingArea) : route('logistics.areas.store') }}" class="flex flex-col gap-4" data-coverage-form data-claims="{{ $coverageClaims->toJson() }}" data-api-base="{{ url('/') }}">
                @csrf
                @if ($convertingArea)
                    @method('PATCH')
                @endif
                <div>
                    <label class="{{ $labelClass }}" for="coverageStation">Station Hub</label>
                    <select id="coverageStation" name="station_hub_id" required data-coverage-station class="{{ $inputClass }}">
                        <option value="">Select Station Hub</option>
                        @foreach ($stations as $station)
                            <option value="{{ $station->id }}"
                                data-province-code="{{ $station->province_code }}"
                                data-province-name="{{ $station->province_name }}"
                                data-region-code="{{ $station->region_code }}"
                                @selected((string) old('station_hub_id', $convertingArea?->station_hub_id ?? $selectedStation?->id) === (string) $station->id)>
                                {{ $station->name }} · {{ $station->province_name }}
                            </option>
                        @endforeach
                    </select>
                    @error('station_hub_id')<p class="mt-1 font-sans text-xs text-[var(--status-danger)]">{{ $message }}</p>@enderror
                    @if ($stations->isEmpty())
                        <p class="{{ $hintClass }}">Create a Station Hub first; configure coverage before activation.</p>
                    @else
                        <p class="{{ $hintClass }}">Pending and inactive stations may be configured before activation.</p>
                    @endif
                </div>
                <div>
                    <label class="{{ $labelClass }}" for="areaMunicipality">Municipality</label>
                    <select id="areaMunicipality" name="city_municipality_code" required data-coverage-municipality data-selected-code="{{ old('city_municipality_code', $convertingArea?->city_municipality_code) }}" disabled class="{{ $inputClass }}">
                        <option value="" disabled selected>Select a Station Hub first</option>
                    </select>
                    <input type="hidden" name="municipality_name" data-coverage-municipality-name value="{{ old('municipality_name') }}">
                    @error('city_municipality_code')<p class="mt-1 font-sans text-xs text-[var(--status-danger)]">{{ $message }}</p>@enderror
                    @error('municipality_name')<p class="mt-1 font-sans text-xs text-[var(--status-danger)]">{{ $message }}</p>@enderror
                    <p class="{{ $hintClass }}">Existing active claims cannot be overwritten.</p>
                </div>
                <fieldset aria-describedby="barangay-status">
                    <legend class="{{ $labelClass }}">Barangays</legend>
                    <label for="barangaySearch" class="sr-only">Search barangays</label>
                    <div class="flex gap-2">
                        <input id="barangaySearch" type="search" data-barangay-search placeholder="Search barangays" class="{{ $inputClass }}">
                        @unless ($convertingArea)
                            <button type="button" data-barangay-select-all class="h-10 shrink-0 rounded-md border border-[var(--border-strong)] px-3 text-xs font-semibold disabled:opacity-50" disabled>Select all available</button>
                        @endunless
                    </div>
                    <div data-coverage-barangays data-single="{{ $convertingArea ? '1' : '0' }}" data-selected="{{ json_encode($convertingArea ? array_values(array_filter([old('barangay_code')])) : old('barangay_codes', [])) }}" class="mt-2 max-h-56 overflow-y-auto rounded-md border border-[var(--border-strong)] bg-[var(--bg-surface)] p-2">
                        <p class="p-2 text-sm text-[var(--text-muted)]">Select a municipality to load barangays.</p>
                    </div>
                    <p id="barangay-status" data-barangay-status aria-live="polite" class="{{ $hintClass }}">Select a municipality first.</p>
                    @error('barangay_codes')<p class="font-sans text-xs text-[var(--status-danger)]">{{ $message }}</p>@enderror
                    @error('barangay_code')<p class="font-sans text-xs text-[var(--status-danger)]">{{ $message }}</p>@enderror
                    @error('coverage')<p class="font-sans text-xs text-[var(--status-danger)]">{{ $message }}</p>@enderror
                </fieldset>
                <button type="submit" @disabled($stations->isEmpty()) class="inline-flex h-11 items-center justify-center rounded-md bg-primary-800 font-sans text-sm font-semibold text-white hover:bg-primary-900 disabled:cursor-not-allowed disabled:opacity-50">Save Coverage</button>
            </form>
        </section>

</dialog>
<dialog id="rider-links" aria-labelledby="rider-dialog-title" data-auto-open="{{ $errors->hasAny(['courier_id', 'area_ids', 'area_ids.*']) ? 'true' : 'false' }}" class="fixed inset-0 m-auto max-h-[90dvh] w-[calc(100%_-_2rem)] max-w-xl overflow-y-auto rounded-xl border border-[var(--border-strong)] bg-[var(--bg-surface)] text-[var(--text-ink)] shadow-2xl backdrop:bg-primary-950/40 backdrop:backdrop-blur-sm">
    <button type="button" data-close-dialog class="absolute right-4 top-4 inline-flex size-9 items-center justify-center rounded-md text-[var(--text-muted)] hover:bg-[var(--bg-soft)] focus-visible:outline-2 focus-visible:outline-primary-800" aria-label="Close rider assignment form"><i data-lucide="x" class="size-5"></i></button>
        <section class="p-5 sm:p-7">
            <div class="mb-6 flex items-start gap-3 pr-7">
                <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary-50 text-primary-800"><i data-lucide="user-plus" class="size-4"></i></span>
                <div>
                    <h2 id="rider-dialog-title" class="font-serif text-2xl text-[var(--text-ink)]">Assign Rider</h2>
                    <p class="mt-0.5 font-sans text-xs text-[var(--text-muted)]">Choose a rider, select serviceable barangays in their Station Hub, then save. To change a rider, assign the new rider here, then use Unlink beside the previous rider. Existing parcel assignments are kept.</p>
                </div>
            </div>
            <p data-assignment-context aria-live="polite" class="mb-4 font-sans text-sm font-semibold text-[var(--text-ink)]"></p>
            <form method="POST" action="{{ route('logistics.areas.couriers.linkBarangays') }}" data-rider-link-form class="flex flex-col gap-4">
                @csrf
                <div>
                    <label for="link-rider" class="{{ $labelClass }}">Rider</label>
                    <select id="link-rider" name="courier_id" required data-link-rider class="{{ $inputClass }}">
                        <option value="">Select a rider</option>
                        @foreach ($couriers as $courier)
                            <option value="{{ $courier->id }}" data-station="{{ $courier->rider?->station_hub_id }}" data-eligible="{{ $courier->isEligibleForAssignment() ? 'true' : 'false' }}" @disabled(! $courier->isEligibleForAssignment()) @selected((string) old('courier_id') === (string) $courier->id)>{{ $courier->name }} &middot; {{ $courier->activeWorkload() }}/{{ $courier::ACTIVE_CAPACITY }} busy</option>
                        @endforeach
                    </select>
                    @error('courier_id')<p class="mt-1 text-xs text-[var(--status-danger)]">{{ $message }}</p>@enderror
                </div>
                <fieldset>
                    <legend class="{{ $labelClass }}">Barangays in the rider's Station Hub</legend>
                    <p class="mb-2 text-xs leading-relaxed text-[var(--text-muted)]">Choose a rider to enable barangays in their Station Hub.</p>
                    <div class="max-h-56 overflow-y-auto rounded-md border border-[var(--border-strong)] p-1">
                        @if ($serviceableAreas->isEmpty())
                            <p class="p-3 text-sm text-[var(--text-muted)]">No serviceable barangays yet. Validate coverage and activate its approved hubs before assigning a rider.</p>
                        @endif
                        @foreach ($serviceableAreas as $area)
                                <label class="flex min-h-10 items-center gap-3 rounded px-2 text-sm">
                                    <input type="checkbox" name="area_ids[]" value="{{ $area->id }}" data-link-area data-station="{{ $area->station_hub_id }}" @checked(in_array($area->id, old('area_ids', []))) class="size-4 accent-primary-800">
                                    <span class="text-[var(--text-ink)]">{{ $area->barangay_name }} <span class="text-[var(--text-muted)]">&middot; {{ $area->station?->name }}</span></span>
                                </label>
                        @endforeach
                    </div>
                </fieldset>
                @error('area_ids')<p class="text-xs text-[var(--status-danger)]">{{ $message }}</p>@enderror
                <button type="submit" @disabled(! $riderLinksAvailable) class="inline-flex h-11 items-center justify-center rounded-md bg-primary-800 px-4 font-sans text-sm font-semibold text-white hover:bg-primary-900 disabled:cursor-not-allowed disabled:opacity-50">Link rider to selected barangays</button>
            </form>
        </section>

</dialog>
@endsection

@push('scripts')
    @vite(['resources/js/logistics/coverage-lookup.js'])
    <script>
    (function () {
        var root = document.querySelector('[data-area-tabs]');
        if (!root) return;
        var tabs = Array.from(root.querySelectorAll('[data-tab]'));
        var panels = root.querySelectorAll('[data-tab-panel]');
        function selectTab(tab, focus) {
            tabs.forEach(function (other) {
                var active = other === tab;
                other.setAttribute('aria-selected', String(active));
                other.tabIndex = active ? 0 : -1;
            });
            panels.forEach(function (panel) { panel.hidden = panel.dataset.tabPanel !== tab.dataset.tab; });
            if (focus) tab.focus();
        }
        tabs.forEach(function (tab, index) {
            tab.addEventListener('click', function () { selectTab(tab, false); });
            tab.addEventListener('keydown', function (event) {
                var target;
                if (event.key === 'ArrowRight') target = (index + 1) % tabs.length;
                if (event.key === 'ArrowLeft') target = (index + tabs.length - 1) % tabs.length;
                if (event.key === 'Home') target = 0;
                if (event.key === 'End') target = tabs.length - 1;
                if (target !== undefined) { event.preventDefault(); selectTab(tabs[target], true); }
            });
        });
        var filters = root.querySelectorAll('[data-area-filter]');
        var rows = root.querySelectorAll('[data-area-row]');
        var empty = root.querySelector('[data-area-empty]');
        function applyFilters() {
            var values = {};
            filters.forEach(function (input) { values[input.dataset.areaFilter] = input.value.trim().toLowerCase(); });
            var shown = 0;
            rows.forEach(function (row) {
                var match = (!values.search || row.dataset.search.indexOf(values.search) !== -1)
                    && (!values.station || row.dataset.station === values.station)
                    && (!values.rider || row.dataset.rider === values.rider)
                    && (!values.coverage || row.dataset.coverage === values.coverage);
                row.hidden = !match;
                if (match) shown++;
            });
            if (empty) empty.hidden = shown > 0 || rows.length === 0;
        }
        filters.forEach(function (input) { input.addEventListener('input', applyFilters); });
        applyFilters();
        document.querySelectorAll('[data-summary-tab]').forEach(function (link) {
            link.addEventListener('click', function (event) {
                event.preventDefault();
                var tab = tabs.find(function (item) { return item.dataset.tab === link.dataset.summaryTab; });
                if (!tab) return;
                if (tab.dataset.tab === 'coverage') {
                    filters.forEach(function (input) {
                        if (input.dataset.areaFilter !== 'station') input.value = input.dataset.areaFilter === 'coverage' ? 'serviceable' : '';
                    });
                    applyFilters();
                }
                selectTab(tab, true);
                tab.scrollIntoView({ block: 'nearest' });
                window.history.replaceState(null, '', link.getAttribute('href'));
            });
        });
        var hashTab = tabs.find(function (tab) { return '#areas-panel-' + tab.dataset.tab === window.location.hash; });
        if (hashTab) selectTab(hashTab, false);

        var menus = root.querySelectorAll('[data-area-menu]');
        function positionMenu(menu) {
            var actionList = menu.querySelector('[data-area-menu-list]');
            var bounds = menu.querySelector('summary').getBoundingClientRect();
            var width = actionList.offsetWidth;
            var height = actionList.offsetHeight;
            actionList.style.left = Math.max(8, Math.min(bounds.right - width, window.innerWidth - width - 8)) + 'px';
            actionList.style.top = (bounds.bottom + height + 8 > window.innerHeight ? Math.max(8, bounds.top - height - 4) : bounds.bottom + 4) + 'px';
        }
        menus.forEach(function (menu) {
            menu.addEventListener('toggle', function () {
                if (!menu.open) return;
                menus.forEach(function (other) { if (other !== menu) other.open = false; });
                positionMenu(menu);
            });
            menu.addEventListener('keydown', function (event) { if (event.key === 'Escape') { menu.open = false; menu.querySelector('summary').focus(); } });
        });
        document.addEventListener('click', function (event) { menus.forEach(function (menu) { if (!menu.contains(event.target)) menu.open = false; }); });
        window.addEventListener('resize', function () { menus.forEach(function (menu) { if (menu.open) positionMenu(menu); }); });
        document.addEventListener('scroll', function () { menus.forEach(function (menu) { menu.open = false; }); }, true);

        var dialogs = document.querySelectorAll('#coverage-form, #rider-links');
        var lastTrigger;
        function openDialog(dialog, trigger) {
            if (!dialog || dialog.open) return;
            lastTrigger = trigger || document.activeElement;
            dialog.showModal();
            var field = dialog.querySelector('select');
            if (field) field.focus();
        }
        document.querySelectorAll('a[href="#coverage-form"], a[href="#rider-links"]').forEach(function (trigger) {
            trigger.setAttribute('aria-haspopup', 'dialog');
            trigger.addEventListener('click', function (event) {
                var dialog = document.querySelector(trigger.getAttribute('href'));
                if (!dialog) return;
                event.preventDefault();
                var menu = trigger.closest('details');
                if (menu) menu.open = false;
                if (dialog.id === 'rider-links') {
                    var form = dialog.querySelector('[data-rider-link-form]');
                    var rider = form.querySelector('[data-link-rider]');
                    form.reset();
                    form.dataset.targetArea = trigger.dataset.assignArea || '';
                    form.dataset.targetStation = trigger.dataset.assignStation || '';
                    rider.value = '';
                    Array.from(rider.options).forEach(function (option) {
                        if (!option.value) return;
                        var wrongStation = form.dataset.targetStation && option.dataset.station !== form.dataset.targetStation;
                        option.hidden = Boolean(wrongStation);
                        option.disabled = option.dataset.eligible !== 'true' || Boolean(wrongStation);
                    });
                    var eligible = Array.from(rider.options).some(function (option) { return option.value && !option.disabled; });
                    dialog.querySelector('[data-assignment-context]').textContent = !eligible
                        ? 'No eligible riders available' + (trigger.dataset.assignName ? ' for ' + trigger.dataset.assignName : '') + '. Approve an active rider in this Station Hub on the Riders page.'
                        : (trigger.dataset.assignName ? 'Assigning coverage for ' + trigger.dataset.assignName + '. Only riders in its Station Hub are shown.' : 'Choose a rider to see their available barangays.');
                    rider.dispatchEvent(new Event('change'));
                }
                openDialog(dialog, menu ? menu.querySelector('summary') : trigger);
            });
        });
        dialogs.forEach(function (dialog) {
            dialog.querySelector('[data-close-dialog]').addEventListener('click', function () { dialog.close(); });
            dialog.addEventListener('click', function (event) {
                var bounds = dialog.getBoundingClientRect();
                if (event.target === dialog && (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom)) dialog.close();
            });
            dialog.addEventListener('close', function () { if (lastTrigger && lastTrigger.isConnected) lastTrigger.focus(); });
        });
        var initialDialog = Array.from(dialogs).find(function (dialog) { return dialog.dataset.autoOpen === 'true'; });
        if (!initialDialog && ['#coverage-form', '#rider-links'].includes(window.location.hash)) initialDialog = document.querySelector(window.location.hash);
        if (initialDialog) openDialog(initialDialog);
    })();
    </script>
@endpush
