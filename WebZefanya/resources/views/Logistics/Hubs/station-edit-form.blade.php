<form method="POST" action="{{ route('logistics.hubs.update', $station) }}" class="mt-2 flex flex-col gap-2" data-address-lookup
    data-locked-province-code="{{ $provinceHub->province_code }}"
    data-locked-region-code="{{ $provinceHub->region_code }}"
    data-selected-municipality="{{ $stationEditSubmitted ? old('city_municipality_code', $stationEditLocation->city_municipality_code) : $stationEditLocation->city_municipality_code }}"
    data-selected-barangay="{{ $stationEditSubmitted ? old('barangay_code', $stationEditLocation->barangay_code) : $stationEditLocation->barangay_code }}">
    @csrf
    @method('PATCH')
    <input type="hidden" name="edit_hub_id" value="{{ $station->id }}">

    @if ($stationEditSubmitted && $errors->any())
        <div class="rounded-sm border border-rose-200 bg-rose-50 px-3 py-2 font-sans text-xs text-rose-700">
            <ul class="list-disc pl-4">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
        <input name="name" required maxlength="120" value="{{ $stationEditSubmitted ? old('name', $station->name) : $station->name }}" aria-label="Station name" placeholder="Station name *" class="h-9 min-w-0 rounded-sm border border-[var(--border-strong)] bg-[var(--bg-page)] px-3 font-sans text-xs text-[var(--text-ink)]">
        <input name="location_details" maxlength="255" value="{{ $stationEditSubmitted ? old('location_details', $station->location_details) : $station->location_details }}" aria-label="Location details" placeholder="Location details / notes" class="h-9 min-w-0 rounded-sm border border-[var(--border-strong)] bg-[var(--bg-page)] px-3 font-sans text-xs text-[var(--text-ink)]">
    </div>

    <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
        <div>
            <label class="mb-1 block font-sans text-[11px] font-semibold uppercase tracking-wide text-[var(--text-muted)]">City / Municipality *</label>
            <select name="city_municipality_code" data-address-municipality required class="h-9 w-full rounded-sm border border-[var(--border-strong)] bg-[var(--bg-page)] px-3 font-sans text-xs text-[var(--text-ink)]" aria-label="City / Municipality">
                <option value="" disabled selected>Loading municipalities...</option>
            </select>
            <input type="hidden" name="municipality_name" data-address-municipality-name value="{{ $stationEditSubmitted ? old('municipality_name', $stationEditLocation->municipality) : $stationEditLocation->municipality }}">
        </div>
        <div>
            <label class="mb-1 block font-sans text-[11px] font-semibold uppercase tracking-wide text-[var(--text-muted)]">Barangay *</label>
            <select name="barangay_code" data-address-barangay required disabled class="h-9 w-full rounded-sm border border-[var(--border-strong)] bg-[var(--bg-page)] px-3 font-sans text-xs text-[var(--text-ink)] disabled:bg-neutral-100 disabled:text-neutral-400" aria-label="Barangay">
                <option value="" disabled selected>Select municipality first</option>
            </select>
            <input type="hidden" name="barangay_name" data-address-barangay-name value="{{ $stationEditSubmitted ? old('barangay_name', $stationEditLocation->barangay_name) : $stationEditLocation->barangay_name }}">
        </div>
    </div>

    <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
        <input name="street" required maxlength="255" value="{{ $stationEditSubmitted ? old('street', $stationEditLocation->street) : $stationEditLocation->street }}" aria-label="Street address" placeholder="Street address *" class="h-9 min-w-0 rounded-sm border border-[var(--border-strong)] bg-[var(--bg-page)] px-3 font-sans text-xs text-[var(--text-ink)]">
        <input name="building" maxlength="255" value="{{ $stationEditSubmitted ? old('building', $stationEditLocation->building) : $stationEditLocation->building }}" aria-label="Building" placeholder="Building / warehouse name" class="h-9 min-w-0 rounded-sm border border-[var(--border-strong)] bg-[var(--bg-page)] px-3 font-sans text-xs text-[var(--text-ink)]">
        <input name="landmark" maxlength="255" value="{{ $stationEditSubmitted ? old('landmark', $stationEditLocation->landmark) : $stationEditLocation->landmark }}" aria-label="Landmark" placeholder="Landmark" class="h-9 min-w-0 rounded-sm border border-[var(--border-strong)] bg-[var(--bg-page)] px-3 font-sans text-xs text-[var(--text-ink)]">
    </div>

    <div class="flex items-center gap-2">
        <input name="postal_code" maxlength="20" value="{{ $stationEditSubmitted ? old('postal_code', $stationEditLocation->postal_code) : $stationEditLocation->postal_code }}" aria-label="Postal code" placeholder="Postal code" class="h-9 min-w-0 flex-1 rounded-sm border border-[var(--border-strong)] bg-[var(--bg-page)] px-3 font-sans text-xs text-[var(--text-ink)] sm:max-w-[180px]">
        <button type="submit" class="h-9 min-w-0 rounded-sm bg-primary-800 px-4 font-sans text-[11px] font-semibold text-white">Save</button>
    </div>

    <select data-address-province class="hidden" aria-hidden="true" tabindex="-1">
        <option value="{{ $provinceHub->province_code }}" selected>{{ $provinceHub->province_name }}</option>
    </select>
    <button type="button" data-hub-cancel class="self-start rounded-sm px-1 py-2 font-sans text-xs font-semibold text-[var(--text-muted)] hover:text-primary-800 focus-visible:outline-2 focus-visible:outline-primary-700">Cancel</button>
</form>
