@extends('Layouts.logistics')

@section('title', 'Add Rider')

@section('content')
<div class="mb-6">
    <h1 class="font-serif text-2xl text-[var(--text-ink)]">Add Rider</h1>
    <p class="mt-1 font-sans text-[12.5px] text-[var(--text-muted)]">The rider starts as <strong>Pending Activation</strong> and gets an emailed link (valid 24 hours) to set a password.</p>
</div>

<x-logistics-flash :messages="['success', 'warning', 'error']" />

<section class="bg-[var(--bg-surface)] border border-[var(--border-strong)] rounded-lg shadow-sm p-6 max-w-[720px]">
    <form method="POST" action="{{ route('logistics.riders.store') }}" enctype="multipart/form-data" id="addRiderForm" novalidate>
        @csrf
        <div class="grid grid-cols-2 gap-4 max-sm:grid-cols-1">
            <div>
                <label class="mb-1.5 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="rider_first_name">First Name *</label>
                <input type="text" name="first_name" id="rider_first_name" value="{{ old('first_name') }}" class="h-11 w-full rounded-sm border border-neutral-300 bg-white px-3 font-sans text-[13px] text-neutral-900 outline-none focus:border-[#9c5c68]">
                @error('first_name')<span class="mt-1 block font-sans text-[11.5px] text-[#a5333d]">{{ $message }}</span>@enderror
            </div>
            <div>
                <label class="mb-1.5 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="rider_last_name">Last Name *</label>
                <input type="text" name="last_name" id="rider_last_name" value="{{ old('last_name') }}" class="h-11 w-full rounded-sm border border-neutral-300 bg-white px-3 font-sans text-[13px] text-neutral-900 outline-none focus:border-[#9c5c68]">
                @error('last_name')<span class="mt-1 block font-sans text-[11.5px] text-[#a5333d]">{{ $message }}</span>@enderror
            </div>
            <div>
                <label class="mb-1.5 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="rider_birthday">Date of Birth *</label>
                <input type="date" name="birthday" id="rider_birthday" value="{{ old('birthday') }}" class="h-11 w-full rounded-sm border border-neutral-300 bg-white px-3 font-sans text-[13px] text-neutral-900 outline-none focus:border-[#9c5c68]">
                @error('birthday')<span class="mt-1 block font-sans text-[11.5px] text-[#a5333d]">{{ $message }}</span>@enderror
            </div>
            <div>
                <label class="mb-1.5 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="rider_email">Email *</label>
                <input type="email" name="email" id="rider_email" value="{{ old('email') }}" class="h-11 w-full rounded-sm border border-neutral-300 bg-white px-3 font-sans text-[13px] text-neutral-900 outline-none focus:border-[#9c5c68]">
                @error('email')<span class="mt-1 block font-sans text-[11.5px] text-[#a5333d]">{{ $message }}</span>@enderror
            </div>
            <div>
                <label class="mb-1.5 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="rider_contact_no">Mobile Number *</label>
                <input type="tel" name="contact_no" id="rider_contact_no" value="{{ old('contact_no') }}" placeholder="09XXXXXXXXX" class="h-11 w-full rounded-sm border border-neutral-300 bg-white px-3 font-sans text-[13px] text-neutral-900 outline-none focus:border-[#9c5c68]">
                @error('contact_no')<span class="mt-1 block font-sans text-[11.5px] text-[#a5333d]">{{ $message }}</span>@enderror
            </div>
            <div>
                <label class="mb-1.5 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="rider_license_no">Driver's License No. *</label>
                <input type="text" name="license_no" id="rider_license_no" value="{{ old('license_no') }}" placeholder="N01-23-456789" class="h-11 w-full rounded-sm border border-neutral-300 bg-white px-3 font-sans text-[13px] text-neutral-900 outline-none focus:border-[#9c5c68]">
                @error('license_no')<span class="mt-1 block font-sans text-[11.5px] text-[#a5333d]">{{ $message }}</span>@enderror
            </div>
        </div>
        <div class="mt-4 grid grid-cols-2 gap-4 max-sm:grid-cols-1">
            <div>
                <label class="mb-1.5 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="rider_license_expiry">License Expiry Date *</label>
                <input type="date" name="license_expiry" id="rider_license_expiry" value="{{ old('license_expiry') }}" min="{{ \App\Support\RiderValidation::today()->toDateString() }}" data-today="{{ \App\Support\RiderValidation::today()->toDateString() }}" class="h-11 w-full rounded-sm border border-neutral-300 bg-white px-3 font-sans text-[13px] text-neutral-900 outline-none focus:border-[#9c5c68]">
                @error('license_expiry')<span class="mt-1 block font-sans text-[11.5px] text-[#a5333d]">{{ $message }}</span>@enderror
            </div>
            <div>
                <label class="mb-1.5 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="rider_vehicle_type">Vehicle Type *</label>
                <select name="vehicle_type" id="rider_vehicle_type" class="h-11 w-full rounded-sm border border-neutral-300 bg-white px-3 font-sans text-[13px] text-neutral-900 outline-none focus:border-[#9c5c68]">
                    <option value="">Select vehicle type</option>
                    <option value="Motorcycle" @selected(old('vehicle_type') === 'Motorcycle')>Motorcycle</option>
                    <option value="Car" @selected(old('vehicle_type') === 'Car')>Car</option>
                    <option value="Van" @selected(old('vehicle_type') === 'Van')>Van</option>
                </select>
                @error('vehicle_type')<span class="mt-1 block font-sans text-[11.5px] text-[#a5333d]">{{ $message }}</span>@enderror
            </div>
            <div>
                <label class="mb-1.5 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="rider_plate_number">Plate Number *</label>
                <input type="text" name="plate_number" id="rider_plate_number" value="{{ old('plate_number') }}" class="h-11 w-full rounded-sm border border-neutral-300 bg-white px-3 font-sans text-[13px] text-neutral-900 outline-none focus:border-[#9c5c68]">
                @error('plate_number')<span class="mt-1 block font-sans text-[11.5px] text-[#a5333d]">{{ $message }}</span>@enderror
            </div>
        </div>
        <div class="mt-4">
            <label class="mb-1.5 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="rider_station_hub_id">Station Hub *</label>
            <select name="station_hub_id" id="rider_station_hub_id" required class="h-11 w-full rounded-sm border border-neutral-300 bg-white px-3 font-sans text-[13px] text-neutral-900 outline-none focus:border-[#9c5c68]">
                <option value="">Select an approved, active Station Hub</option>
                @foreach ($stations as $station)
                    <option value="{{ $station->id }}" @selected((string) old('station_hub_id') === (string) $station->id)>
                        {{ $station->name }} · {{ $station->municipality ?: $station->province_name }}
                    </option>
                @endforeach
            </select>
            @error('station_hub_id')<span class="mt-1 block font-sans text-[11.5px] text-[#a5333d]">{{ $message }}</span>@enderror
            @if ($stations->isEmpty())
                <p class="mt-1 font-sans text-[11px] text-[#a5333d]">Create and activate an approved Station Hub before adding riders.</p>
            @endif
        </div>
        <div class="mt-6 border-t border-neutral-200 pt-5">
            <p class="mb-3 font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600">Residential Address <span class="font-normal normal-case tracking-normal text-neutral-400">(optional)</span></p>
            <div class="grid grid-cols-2 gap-4 max-sm:grid-cols-1">
                <div>
                    <label class="mb-1.5 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="rider_province_name">Province</label>
                    <input type="text" name="province_name" id="rider_province_name" value="{{ old('province_name') }}" class="h-11 w-full rounded-sm border border-neutral-300 bg-white px-3 font-sans text-[13px] text-neutral-900 outline-none focus:border-[#9c5c68]">
                    @error('province_name')<span class="mt-1 block font-sans text-[11.5px] text-[#a5333d]">{{ $message }}</span>@enderror
                </div>
                <div>
                    <label class="mb-1.5 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="rider_municipality_name">City / Municipality</label>
                    <input type="text" name="municipality_name" id="rider_municipality_name" value="{{ old('municipality_name') }}" class="h-11 w-full rounded-sm border border-neutral-300 bg-white px-3 font-sans text-[13px] text-neutral-900 outline-none focus:border-[#9c5c68]">
                    @error('municipality_name')<span class="mt-1 block font-sans text-[11.5px] text-[#a5333d]">{{ $message }}</span>@enderror
                </div>
                <div>
                    <label class="mb-1.5 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="rider_barangay_name">Barangay</label>
                    <input type="text" name="barangay_name" id="rider_barangay_name" value="{{ old('barangay_name') }}" class="h-11 w-full rounded-sm border border-neutral-300 bg-white px-3 font-sans text-[13px] text-neutral-900 outline-none focus:border-[#9c5c68]">
                    @error('barangay_name')<span class="mt-1 block font-sans text-[11.5px] text-[#a5333d]">{{ $message }}</span>@enderror
                </div>
                <div>
                    <label class="mb-1.5 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="rider_street">Street</label>
                    <input type="text" name="street" id="rider_street" value="{{ old('street') }}" class="h-11 w-full rounded-sm border border-neutral-300 bg-white px-3 font-sans text-[13px] text-neutral-900 outline-none focus:border-[#9c5c68]">
                    @error('street')<span class="mt-1 block font-sans text-[11.5px] text-[#a5333d]">{{ $message }}</span>@enderror
                </div>
                <div>
                    <label class="mb-1.5 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="rider_house_number">House / Unit No.</label>
                    <input type="text" name="house_number" id="rider_house_number" value="{{ old('house_number') }}" class="h-11 w-full rounded-sm border border-neutral-300 bg-white px-3 font-sans text-[13px] text-neutral-900 outline-none focus:border-[#9c5c68]">
                    @error('house_number')<span class="mt-1 block font-sans text-[11.5px] text-[#a5333d]">{{ $message }}</span>@enderror
                </div>
                <div>
                    <label class="mb-1.5 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="rider_postal_code">Postal Code</label>
                    <input type="text" name="postal_code" id="rider_postal_code" value="{{ old('postal_code') }}" class="h-11 w-full rounded-sm border border-neutral-300 bg-white px-3 font-sans text-[13px] text-neutral-900 outline-none focus:border-[#9c5c68]">
                    @error('postal_code')<span class="mt-1 block font-sans text-[11.5px] text-[#a5333d]">{{ $message }}</span>@enderror
                </div>
            </div>
        </div>
        <div class="mt-4 grid grid-cols-2 gap-4 max-sm:grid-cols-1">
            <div>
                <label class="mb-1.5 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="rider_drivers_license">Driver's License (JPG, PNG, PDF · max 5 MB) *</label>
                <input type="file" name="drivers_license" id="rider_drivers_license" accept=".jpg,.jpeg,.png,.pdf" class="w-full rounded-sm border border-neutral-300 bg-white px-3 py-2.5 font-sans text-[12.5px] text-neutral-700 outline-none">
                @error('drivers_license')<span class="mt-1 block font-sans text-[11.5px] text-[#a5333d]">{{ $message }}</span>@enderror
            </div>
            <div>
                <label class="mb-1.5 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="rider_or_cr">Vehicle OR/CR (JPG, PNG, PDF · max 5 MB) *</label>
                <input type="file" name="or_cr" id="rider_or_cr" accept=".jpg,.jpeg,.png,.pdf" class="w-full rounded-sm border border-neutral-300 bg-white px-3 py-2.5 font-sans text-[12.5px] text-neutral-700 outline-none">
                @error('or_cr')<span class="mt-1 block font-sans text-[11.5px] text-[#a5333d]">{{ $message }}</span>@enderror
            </div>
        </div>
        <div class="mt-6 flex gap-3">
            <a href="{{ route('logistics.riders.index') }}" class="inline-flex h-11 flex-1 cursor-pointer items-center justify-center rounded-sm border border-neutral-300 font-sans text-[12.5px] font-semibold uppercase tracking-[1.4px] text-neutral-700 transition-colors hover:bg-neutral-50">Cancel</a>
            <button type="submit" id="riderSaveBtn" @disabled($stations->isEmpty()) class="inline-flex h-11 flex-[2] cursor-pointer items-center justify-center rounded-sm bg-neutral-900 font-sans text-[12.5px] font-semibold uppercase tracking-[1.4px] text-white transition-colors hover:bg-[#9c5c68] disabled:opacity-60">Save Rider</button>
        </div>
    </form>
</section>
@endsection

@push('scripts')
<script>
    document.getElementById('addRiderForm').addEventListener('submit', function () {
        document.getElementById('riderSaveBtn').disabled = true;
    });
</script>
@endpush
