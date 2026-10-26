<form method="POST" action="{{ route('logistics.hubs.update', $provinceHub) }}" class="mt-3 flex flex-col gap-3" data-address-lookup
    data-locked-province-code="{{ $provider->province_code ?: $provinceHub->province_code }}"
    data-locked-region-code="{{ $provinceHub->region_code }}"
    data-selected-municipality="{{ $provinceEditSubmitted ? old('city_municipality_code', $provinceEditLocation->city_municipality_code) : $provinceEditLocation->city_municipality_code }}"
    data-selected-barangay="{{ $provinceEditSubmitted ? old('barangay_code', $provinceEditLocation->barangay_code) : $provinceEditLocation->barangay_code }}">
    @csrf
    @method('PATCH')
    <input type="hidden" name="edit_hub_id" value="{{ $provinceHub->id }}">
    <input type="hidden" name="region_code" value="{{ $provinceHub->region_code }}">
    <input type="hidden" name="region_name" value="{{ $provinceHub->region_name }}">
    <input type="hidden" name="province_code" value="{{ $provider->province_code ?: $provinceHub->province_code }}">
    <input type="hidden" name="province_name" value="{{ $provider->province_name ?: $provinceHub->province_name }}">

    @if ($provinceEditSubmitted && $errors->any())
        <div class="rounded-sm border border-rose-200 bg-rose-50 px-3 py-2 font-sans text-xs text-rose-700">
            <ul class="list-disc pl-4">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
        <input name="name" required maxlength="120" value="{{ $provinceEditSubmitted ? old('name', $provinceHub->name) : $provinceHub->name }}" aria-label="Hub name" placeholder="Hub name *" class="h-9 min-w-0 rounded-sm border border-[var(--border-strong)] bg-[var(--bg-page)] px-3 font-sans text-xs text-[var(--text-ink)]">
        <input name="location_details" maxlength="255" value="{{ $provinceEditSubmitted ? old('location_details', $provinceHub->location_details) : $provinceHub->location_details }}" aria-label="Location details" placeholder="Location details / notes" class="h-9 min-w-0 rounded-sm border border-[var(--border-strong)] bg-[var(--bg-page)] px-3 font-sans text-xs text-[var(--text-ink)]">
    </div>

    <div class="rounded-sm border border-[var(--border-strong)] bg-[var(--bg-page)] px-3 py-2 font-sans text-[11px] text-[var(--text-muted)]">
        <p class="font-semibold text-[var(--text-ink)]">Verified service province (read-only)</p>
        <p class="mt-1">{{ $provider->province_name ?: $provinceHub->province_name }}@if($provinceHub->region_name) · {{ $provinceHub->region_name }}@endif</p>
        <p class="mt-1">Contact Admin to change the service province. You may update the facility address inside this province.</p>
    </div>

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div>
            <label class="mb-1 block font-sans text-[11px] font-semibold uppercase tracking-wide text-[var(--text-muted)]">City / Municipality *</label>
            <select name="city_municipality_code" data-address-municipality required class="h-9 w-full rounded-sm border border-[var(--border-strong)] bg-[var(--bg-page)] px-3 font-sans text-xs text-[var(--text-ink)]" aria-label="City / Municipality">
                <option value="" disabled selected>Loading municipalities...</option>
            </select>
            <input type="hidden" name="municipality_name" data-address-municipality-name value="{{ $provinceEditSubmitted ? old('municipality_name', $provinceEditLocation->municipality) : $provinceEditLocation->municipality }}">
        </div>
        <div>
            <label class="mb-1 block font-sans text-[11px] font-semibold uppercase tracking-wide text-[var(--text-muted)]">Barangay *</label>
            <select name="barangay_code" data-address-barangay required disabled class="h-9 w-full rounded-sm border border-[var(--border-strong)] bg-[var(--bg-page)] px-3 font-sans text-xs text-[var(--text-ink)] disabled:bg-neutral-100 disabled:text-neutral-400" aria-label="Barangay">
                <option value="" disabled selected>Select city/municipality first</option>
            </select>
            <input type="hidden" name="barangay_name" data-address-barangay-name value="{{ $provinceEditSubmitted ? old('barangay_name', $provinceEditLocation->barangay_name) : $provinceEditLocation->barangay_name }}">
        </div>
    </div>

    <select data-address-province class="hidden" aria-hidden="true" tabindex="-1">
        <option value="{{ $provider->province_code ?: $provinceHub->province_code }}" selected>{{ $provider->province_name ?: $provinceHub->province_name }}</option>
    </select>
    <select data-address-region class="hidden" aria-hidden="true" tabindex="-1">
        <option value="{{ $provinceHub->region_code }}" selected>{{ $provinceHub->region_name }}</option>
    </select>

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <input name="street" required maxlength="255" value="{{ $provinceEditSubmitted ? old('street', $provinceEditLocation->street) : $provinceEditLocation->street }}" aria-label="Street address" placeholder="Street address *" class="h-9 min-w-0 rounded-sm border border-[var(--border-strong)] bg-[var(--bg-page)] px-3 font-sans text-xs text-[var(--text-ink)]">
        <input name="building" maxlength="255" value="{{ $provinceEditSubmitted ? old('building', $provinceEditLocation->building) : $provinceEditLocation->building }}" aria-label="Building" placeholder="Building / warehouse name" class="h-9 min-w-0 rounded-sm border border-[var(--border-strong)] bg-[var(--bg-page)] px-3 font-sans text-xs text-[var(--text-ink)]">
        <input name="landmark" maxlength="255" value="{{ $provinceEditSubmitted ? old('landmark', $provinceEditLocation->landmark) : $provinceEditLocation->landmark }}" aria-label="Landmark" placeholder="Landmark" class="h-9 min-w-0 rounded-sm border border-[var(--border-strong)] bg-[var(--bg-page)] px-3 font-sans text-xs text-[var(--text-ink)]">
    </div>

    <div class="flex items-center gap-2">
        <input name="postal_code" maxlength="20" value="{{ $provinceEditSubmitted ? old('postal_code', $provinceEditLocation->postal_code) : $provinceEditLocation->postal_code }}" aria-label="Postal code" placeholder="Postal code" class="h-9 min-w-0 flex-1 rounded-sm border border-[var(--border-strong)] bg-[var(--bg-page)] px-3 font-sans text-xs text-[var(--text-ink)] sm:max-w-[180px]">
        <button type="submit" class="h-9 min-w-0 rounded-sm bg-primary-800 px-4 font-sans text-[11px] font-semibold text-white">Save</button>
    </div>
    <button type="button" data-hub-cancel class="self-start rounded-sm px-1 py-2 font-sans text-xs font-semibold text-[var(--text-muted)] hover:text-primary-800 focus-visible:outline-2 focus-visible:outline-primary-700">Cancel</button>
</form>
