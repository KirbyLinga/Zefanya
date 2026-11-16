<form method="POST" action="{{ route('logistics.hubs.stations.store', $provinceHub) }}" class="mt-4 flex min-w-0 flex-col gap-3 rounded-md border border-[var(--border-strong)] bg-[var(--bg-page)] p-4" data-address-lookup
    data-selected-municipality="{{ $stationFormSubmitted ? old('city_municipality_code') : '' }}"
    data-selected-barangay="{{ $stationFormSubmitted ? old('barangay_code') : '' }}"
    data-locked-province-code="{{ $provinceHub->province_code }}" data-locked-region-code="{{ $provinceHub->region_code }}">
    @csrf
    <input type="hidden" name="parent_hub_id" value="{{ $provinceHub->id }}">

    @if ($stationFormSubmitted && $errors->any())
        <div class="rounded-sm border border-rose-200 bg-rose-50 px-3 py-2 font-sans text-xs text-rose-700">
            <ul class="list-disc pl-4">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="flex items-center justify-between">
        <h5 class="font-serif text-sm font-semibold text-[var(--text-ink)]">Add Station Hub</h5>
        <span class="font-sans text-[10px] uppercase tracking-wider text-[var(--text-muted)]">{{ $provinceHub->province_name }}</span>
    </div>

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div>
            <label class="mb-1 block font-sans text-[11px] font-semibold uppercase tracking-wide text-[var(--text-muted)]">Station name *</label>
            <input name="name" required maxlength="120" value="{{ $stationFormSubmitted ? old('name') : '' }}" placeholder="e.g. Angeles Station" class="h-9 w-full rounded-sm border border-[var(--border-strong)] bg-[var(--bg-surface)] px-3 font-sans text-xs text-[var(--text-ink)]" aria-label="Station name">
        </div>
        <div>
            <label class="mb-1 block font-sans text-[11px] font-semibold uppercase tracking-wide text-[var(--text-muted)]">City / Municipality *</label>
            <select name="city_municipality_code" data-address-municipality required class="h-9 w-full rounded-sm border border-[var(--border-strong)] bg-[var(--bg-surface)] px-3 font-sans text-xs text-[var(--text-ink)]" aria-label="City / Municipality">
                <option value="" disabled selected>Loading municipalities...</option>
            </select>
            <input type="hidden" name="municipality_name" data-address-municipality-name value="{{ $stationFormSubmitted ? old('municipality_name') : '' }}">
        </div>
    </div>

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div>
            <label class="mb-1 block font-sans text-[11px] font-semibold uppercase tracking-wide text-[var(--text-muted)]">Barangay *</label>
            <select name="barangay_code" data-address-barangay required disabled class="h-9 w-full rounded-sm border border-[var(--border-strong)] bg-[var(--bg-surface)] px-3 font-sans text-xs text-[var(--text-ink)] disabled:bg-neutral-100 disabled:text-neutral-400" aria-label="Barangay">
                <option value="" disabled selected>Select municipality first</option>
            </select>
            <input type="hidden" name="barangay_name" data-address-barangay-name value="{{ $stationFormSubmitted ? old('barangay_name') : '' }}">
        </div>
        <div>
            <label class="mb-1 block font-sans text-[11px] font-semibold uppercase tracking-wide text-[var(--text-muted)]">Street Address *</label>
            <input name="street" required maxlength="255" value="{{ $stationFormSubmitted ? old('street') : '' }}" placeholder="e.g. 123 Rizal Street" class="h-9 w-full rounded-sm border border-[var(--border-strong)] bg-[var(--bg-surface)] px-3 font-sans text-xs text-[var(--text-ink)]" aria-label="Street Address">
        </div>
    </div>

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <div>
            <input name="building" maxlength="255" value="{{ $stationFormSubmitted ? old('building') : '' }}" placeholder="Building / Warehouse name" class="h-9 w-full rounded-sm border border-[var(--border-strong)] bg-[var(--bg-surface)] px-3 font-sans text-xs text-[var(--text-ink)]" aria-label="Building / Warehouse Name">
        </div>
        <div>
            <input name="landmark" maxlength="255" value="{{ $stationFormSubmitted ? old('landmark') : '' }}" placeholder="Landmark" class="h-9 w-full rounded-sm border border-[var(--border-strong)] bg-[var(--bg-surface)] px-3 font-sans text-xs text-[var(--text-ink)]" aria-label="Landmark">
        </div>
        <div>
            <input name="postal_code" maxlength="20" value="{{ $stationFormSubmitted ? old('postal_code') : '' }}" placeholder="Postal code" class="h-9 w-full rounded-sm border border-[var(--border-strong)] bg-[var(--bg-surface)] px-3 font-sans text-xs text-[var(--text-ink)]" aria-label="Postal Code">
        </div>
    </div>

    <select data-address-province class="hidden" aria-hidden="true" tabindex="-1">
        <option value="{{ $provinceHub->province_code }}" selected>{{ $provinceHub->province_name }}</option>
    </select>

    <div class="flex flex-col items-stretch gap-3 pt-1 sm:flex-row sm:items-center sm:justify-between">
        <input name="location_details" maxlength="255" value="{{ $stationFormSubmitted ? old('location_details') : '' }}" placeholder="Location details (optional)" class="h-9 min-w-0 flex-1 sm:mr-2 rounded-sm border border-[var(--border-strong)] bg-[var(--bg-surface)] px-3 font-sans text-xs text-[var(--text-ink)]" aria-label="Location Details / Notes">
        <button type="submit" class="h-9 shrink-0 rounded-sm bg-primary-800 px-4 font-sans text-[11px] font-semibold uppercase text-white hover:bg-primary-900">Add Station</button>
    </div>
    <button type="button" data-hub-cancel class="self-start rounded-sm px-1 py-2 font-sans text-xs font-semibold text-[var(--text-muted)] hover:text-primary-800 focus-visible:outline-2 focus-visible:outline-primary-700">Cancel</button>
</form>
