@extends('Layouts.logistics')

@section('title', 'Delivery Areas')

@section('content')
<div class="flex items-center justify-between mb-6">
    <div>
        <h1 class="font-serif text-2xl text-[var(--text-ink)]">Delivery Areas</h1>
        <p class="mt-2 font-sans text-sm font-semibold text-[var(--text-ink)]">
            {{ $areas->count() }} {{ $areas->count() === 1 ? 'area' : 'areas' }}, {{ $areas->where('couriers_count', 0)->count() }} without riders
        </p>
        <p class="font-sans text-sm text-[var(--text-ink)] mt-1">
            Each active Station Hub requires PSGC barangay coverage. A barangay can have only one active coverage assignment per Logistics company.
        </p>
        @if ($verifiedProvinceName ?? null)
            <p class="mt-4 rounded-lg border border-primary-300 bg-primary-50 p-4 font-sans text-base text-primary-900">
                Coverage limited to verified province: <span class="font-semibold">{{ $verifiedProvinceName }}</span>
            </p>
        @endif
    </div>
</div>

<x-logistics-flash :messages="['success', 'warning', 'error']" />

@unless ($riderLinksAvailable)
    <p role="alert" class="mb-4 rounded-sm border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">Existing rider links are shown. Ask an administrator to apply the pending rider-barangay migration to enable linking riders to multiple barangays.</p>
@endunless

@unless ($coverageSchemaAvailable)
    <p role="alert" class="mb-4 rounded-sm border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">Ask an administrator to apply the pending Barangay Coverage migrations. New assignments are blocked; existing parcels can continue.</p>
@endunless

<div class="grid grid-cols-1 lg:grid-cols-[1fr_360px] gap-6">
    {{-- Areas list --}}
    <section class="bg-[var(--bg-surface)] border border-[var(--border-strong)] rounded-lg shadow-sm overflow-x-auto">
        @if ($areas->isEmpty())
            <div class="p-8 text-center">
                <p class="font-sans text-[13px] font-semibold text-[var(--text-ink)] mb-1">No areas yet</p>
                <p class="font-sans text-sm text-[var(--text-ink)]">Add your first coverage area on the right.</p>
            </div>
        @else
            <table class="w-full min-w-[780px] text-left font-sans">
                <thead class="bg-[var(--bg-page)] border-b border-[var(--border-strong)]">
                    <tr>
                        <th class="text-sm font-semibold uppercase tracking-[0.6px] text-[var(--text-ink)] px-4 py-2.5">Barangay</th>
                        <th class="text-sm font-semibold uppercase tracking-[0.6px] text-[var(--text-ink)] px-4 py-2.5">Municipality</th>
                        <th class="text-sm font-semibold uppercase tracking-[0.6px] text-[var(--text-ink)] px-4 py-2.5">Code</th>
                        <th class="text-sm font-semibold uppercase tracking-[0.6px] text-[var(--text-ink)] px-4 py-2.5">Station Hub</th>
                        <th class="text-sm font-semibold uppercase tracking-[0.6px] text-[var(--text-ink)] px-4 py-2.5">Riders</th>
                        <th class="text-sm font-semibold uppercase tracking-[0.6px] text-[var(--text-ink)] px-4 py-2.5 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--border-strong)]">
                    @foreach ($areas as $area)
                        <tr>
                            <td class="px-4 py-3">
                                <p class="font-sans text-sm font-semibold text-[var(--text-ink)]">{{ $area->barangay_name ?? 'Barangay required' }}</p>
                                @if (blank($area->barangay_code))
                                    <span class="inline-flex rounded-sm bg-amber-100 px-2 py-1 text-sm font-semibold text-amber-900">Legacy</span>
                                @endif
                                @unless ($area->is_active)
                                    <span class="font-sans text-sm text-amber-700">Inactive</span>
                                @endunless
                            </td>
                            <td class="px-4 py-3 font-sans text-sm text-[var(--text-ink)]">{{ $area->municipality }}</td>
                            <td class="px-4 py-3 font-sans text-sm text-[var(--text-muted)]">{{ $area->barangay_code ?? '—' }}</td>
                            <td class="px-4 py-3 font-sans text-sm text-[var(--text-ink)]">{{ $area->station?->name ?? 'Unassigned' }}</td>
                            <td class="px-4 py-3 font-sans text-sm text-[var(--text-ink)]">
                                <div class="flex flex-wrap gap-2">
                                    @foreach ($area->couriers as $linkedCourier)
                                        <form method="POST" action="{{ route('logistics.areas.couriers.unassign', [$area, $linkedCourier]) }}" data-confirm="Unlink {{ $linkedCourier->name }} from {{ $area->barangay_name ?? $area->name }}? This affects 1 rider. This barangay has {{ $pendingPickups[$area->id] ?? 0 }} pending {{ ($pendingPickups[$area->id] ?? 0) === 1 ? 'pickup' : 'pickups' }}. Existing parcel assignments will remain; other barangay links will be kept." class="inline-flex items-center rounded-full border border-[var(--border-strong)] bg-[var(--bg-page)] pl-3">
                                            @csrf
                                            @method('DELETE')
                                            <span class="text-sm">{{ $linkedCourier->name }}</span>
                                            <button type="submit" aria-label="Unlink {{ $linkedCourier->name }} from {{ $area->barangay_name ?? $area->name }}" class="inline-flex h-11 w-11 items-center justify-center rounded-full text-[var(--text-ink)] hover:bg-amber-100">&times;</button>
                                        </form>
                                    @endforeach
                                </div>
                                @if ((int) $area->couriers_count === 0)
                                    <span class="mt-1 block rounded-sm bg-amber-100 px-2 py-1 text-sm font-semibold text-amber-900">No rider, pickups here will fail</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-2">
                                    @if (blank($area->barangay_code))
                                        <a href="{{ route('logistics.areas.index', array_filter(['convert' => $area->id, 'station' => $selectedStation?->id])) }}#coverage-form" class="inline-flex min-h-11 items-center px-2 font-sans text-sm font-semibold text-amber-800 hover:underline">Convert to barangays</a>
                                    @endif
                                    <form method="POST" action="{{ route('logistics.areas.destroy', $area) }}"
                                          data-confirm="Remove {{ $area->barangay_name ?? $area->name }}? This affects {{ $area->couriers_count }} {{ (int) $area->couriers_count === 1 ? 'rider' : 'riders' }} and {{ $pendingPickups[$area->id] ?? 0 }} pending {{ ($pendingPickups[$area->id] ?? 0) === 1 ? 'pickup' : 'pickups' }}. Linked riders, parcel history, and active work can block removal. Unlink riders and finish pending work first.">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="inline-flex min-h-11 items-center rounded-sm border border-[var(--border-strong)] px-4 font-sans text-sm font-semibold text-[var(--text-ink)] hover:bg-[var(--bg-page)]">Remove</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>

    {{-- Add / update area --}}
    <aside class="flex flex-col gap-6">
        <section id="coverage-form" class="bg-[var(--bg-surface)] border border-[var(--border-strong)] rounded-lg shadow-sm p-5">
            <h2 class="font-serif text-[17px] text-[var(--text-ink)] mb-4">{{ $convertingArea ? 'Convert legacy area' : 'Configure Barangay Coverage' }}</h2>
            @if ($convertingArea)
                <p class="mb-4 font-sans text-sm text-[var(--text-ink)]">Select one barangay to replace {{ $convertingArea->name }}. Add any further barangays as new coverage afterward.</p>
                <a href="{{ route('logistics.areas.index') }}" class="mb-3 inline-flex min-h-11 items-center px-2 text-sm underline">Cancel conversion</a>
            @endif
            <form method="POST" action="{{ $convertingArea ? route('logistics.areas.update', $convertingArea) : route('logistics.areas.store') }}" class="flex flex-col gap-4" data-coverage-form data-claims="{{ $coverageClaims->toJson() }}" data-api-base="{{ url('/') }}">
                @csrf
                @if ($convertingArea)
                    @method('PATCH')
                @endif
                <div>
                    <label class="block font-sans text-sm font-semibold uppercase tracking-[0.8px] text-[var(--text-ink)] mb-1" for="coverageStation">Station Hub</label>
                    <select id="coverageStation" name="station_hub_id" required data-coverage-station
                            class="h-11 w-full rounded-sm border border-[var(--border-strong)] bg-white px-4 font-sans text-sm text-[var(--text-ink)] outline-none focus:border-[var(--link)]">
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
                    @error('station_hub_id')<p class="mt-1 font-sans text-sm text-red-700">{{ $message }}</p>@enderror
                    @if ($stations->isEmpty())
                        <p class="mt-1 font-sans text-sm text-[var(--text-ink)]">Create a Station Hub first; configure coverage before activation.</p>
                    @else
                        <p class="mt-1 font-sans text-sm text-[var(--text-ink)]">Pending and inactive stations may be configured before activation. Choose the barangays this station serves.</p>
                    @endif
                </div>
                <div>
                    <label class="block font-sans text-sm font-semibold uppercase tracking-[0.8px] text-[var(--text-ink)] mb-1" for="areaMunicipality">Municipality / City</label>
                    <select id="areaMunicipality" name="city_municipality_code" required data-coverage-municipality data-selected-code="{{ old('city_municipality_code', $convertingArea?->city_municipality_code) }}" disabled
                            class="h-11 w-full rounded-sm border border-[var(--border-strong)] bg-white px-4 font-sans text-sm text-[var(--text-ink)] outline-none focus:border-[var(--link)] disabled:bg-neutral-100 disabled:text-neutral-400">
                        <option value="" disabled selected>Select a Station Hub first</option>
                    </select>
                    <input type="hidden" name="municipality_name" data-coverage-municipality-name value="{{ old('municipality_name') }}">
                    @error('city_municipality_code')<p class="mt-1 font-sans text-sm text-red-700">{{ $message }}</p>@enderror
                    @error('municipality_name')<p class="mt-1 font-sans text-sm text-red-700">{{ $message }}</p>@enderror
                    <p class="mt-1 font-sans text-sm text-[var(--text-ink)]">Select a municipality within the station province, then choose its barangays. Existing active claims cannot be overwritten.</p>
                </div>
                <div>
                    <label for="barangaySearch" class="block font-sans text-sm text-[var(--text-ink)]">Search barangays</label>
                    <input id="barangaySearch" type="search" data-barangay-search placeholder="Type a barangay name" class="h-11 w-full rounded-sm border border-[var(--border-strong)] bg-[var(--bg-surface)] px-3">
                    <fieldset class="mt-3" aria-describedby="barangay-status">
                        <legend class="font-sans text-sm font-semibold text-[var(--text-ink)]">Barangays</legend>
                        @unless ($convertingArea)
                            <button type="button" data-barangay-select-all class="my-2 min-h-11 rounded-sm border border-[var(--border-strong)] px-4 text-sm font-semibold" disabled>Select all available</button>
                        @endunless
                        <div data-coverage-barangays data-single="{{ $convertingArea ? '1' : '0' }}" data-selected="{{ json_encode($convertingArea ? array_values(array_filter([old('barangay_code')])) : old('barangay_codes', [])) }}" class="max-h-72 overflow-y-auto rounded-sm border border-[var(--border-strong)] bg-[var(--bg-surface)] p-2">
                            <p class="p-2 text-sm text-[var(--text-ink)]">Select a municipality to load barangays.</p>
                        </div>
                    </fieldset>
                    <p id="barangay-status" data-barangay-status aria-live="polite" class="mt-2 font-sans text-sm text-[var(--text-ink)]">Select a municipality first.</p>
                    @error('barangay_codes')<p class="font-sans text-sm text-red-700">{{ $message }}</p>@enderror
                    @error('barangay_code')<p class="font-sans text-sm text-red-700">{{ $message }}</p>@enderror
                    @error('coverage')<p class="font-sans text-sm text-red-700">{{ $message }}</p>@enderror
                </div>
                <button type="submit" @disabled($stations->isEmpty()) class="inline-flex items-center justify-center h-11 rounded-sm font-sans font-semibold text-sm tracking-[0.5px] uppercase text-white bg-primary-800 hover:bg-primary-900 disabled:cursor-not-allowed disabled:opacity-50">Save coverage</button>
            </form>
        </section>

        <section id="rider-links" class="rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-5 shadow-sm">
            <h2 class="mb-3 font-serif text-lg text-[var(--text-ink)]">Link a rider to barangays</h2>
            <p class="mb-4 text-sm text-[var(--text-ink)]">Select a rider, then tick their barangays. Existing links are kept.</p>
            <form method="POST" action="{{ route('logistics.areas.couriers.linkBarangays') }}" data-rider-link-form class="space-y-4">
                @csrf
                <label for="link-rider" class="block text-sm font-semibold">Rider</label>
                <select id="link-rider" name="courier_id" required data-link-rider class="min-h-11 w-full rounded-sm border border-[var(--border-strong)] bg-[var(--bg-surface)] px-3 text-base">
                    <option value="">Select a rider</option>
                    @foreach ($couriers as $courier)
                        <option value="{{ $courier->id }}" data-station="{{ $courier->rider?->station_hub_id }}" @disabled(! $courier->isEligibleForAssignment()) @selected((string) old('courier_id') === (string) $courier->id)>{{ $courier->name }} &middot; {{ $courier->activeWorkload() }}/{{ $courier::ACTIVE_CAPACITY }} busy</option>
                    @endforeach
                </select>
                @error('courier_id')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
                <fieldset>
                    <legend class="mb-2 text-sm font-semibold">Barangays in the rider's Station Hub</legend>
                    <div class="max-h-64 overflow-y-auto rounded-sm border border-[var(--border-strong)] p-2">
                        @foreach ($areas as $area)
                            @if ($area->is_active && filled($area->barangay_code) && $area->coverage_validated_at)
                                <label class="flex min-h-11 items-center gap-3 px-2 text-sm">
                                    <input type="checkbox" name="area_ids[]" value="{{ $area->id }}" data-link-area data-station="{{ $area->station_hub_id }}" @checked(in_array($area->id, old('area_ids', []))) class="h-5 w-5 accent-primary-800">
                                    <span>{{ $area->barangay_name }} &middot; {{ $area->station?->name }}</span>
                                </label>
                            @endif
                        @endforeach
                    </div>
                </fieldset>
                @error('area_ids')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
                <button type="submit" @disabled(! $riderLinksAvailable) class="min-h-11 w-full rounded-sm bg-primary-800 px-4 text-base font-semibold text-white hover:bg-primary-900 disabled:cursor-not-allowed disabled:opacity-50">Link rider to selected barangays</button>
            </form>
        </section>

        <section class="rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-5 shadow-sm">
            <h2 class="mb-3 font-serif text-lg text-[var(--text-ink)]">Riders &amp; their areas</h2>
            @forelse ($couriers as $courier)
                <div class="border-b border-[var(--border-strong)] py-4 last:border-0">
                    <p class="font-semibold text-[var(--text-ink)]">{{ $courier->name }}</p>
                    <p class="mt-1 text-sm text-[var(--text-ink)]">{{ ucfirst($courier->rider?->status ?? 'No rider account') }} &middot; {{ $courier->activeWorkload() }}/{{ $courier::ACTIVE_CAPACITY }} busy</p>
                    <p class="mt-1 text-sm text-[var(--text-ink)]">{{ $courier->rider?->station?->name ?? 'No Station Hub' }}</p>
                    <div class="mt-2 flex flex-wrap gap-2">
                        @forelse ($courier->areas as $linkedArea)
                            <span class="rounded-full border border-[var(--border-strong)] bg-[var(--bg-page)] px-3 py-2 text-sm">{{ $linkedArea->barangay_name ?? $linkedArea->name }}</span>
                        @empty
                            <span class="text-sm text-[var(--text-ink)]">No barangays linked</span>
                        @endforelse
                    </div>
                </div>
            @empty
                <p class="text-sm text-[var(--text-ink)]">No riders in your fleet yet.</p>
            @endforelse
        </section>
    </aside>
</div>
@endsection

@push('scripts')
    @vite(['resources/js/logistics/coverage-lookup.js'])
@endpush
