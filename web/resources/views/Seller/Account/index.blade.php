@extends('Layouts.seller')

@section('title', 'Store Profile')

@section('content')
@php
    $value = function (string $field) use ($seller) {
        $value = old($field, $seller->{$field});
        return is_scalar($value) || $value === null ? $value : '';
    };
    $input = 'w-full rounded-md border border-[var(--border-strong)] bg-[var(--bg-page)] px-3 py-2.5 font-sans text-sm text-[var(--text-ink)] focus-visible:outline-2 focus-visible:outline-primary-700';
    $label = 'mb-2 block font-sans text-xs font-semibold text-[var(--text-ink)]';
    $section = 'rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-5 shadow-sm sm:p-6';
@endphp
<div class="mx-auto max-w-5xl">
    <header class="mb-7">
        <p class="mb-2 font-sans text-xs font-semibold uppercase tracking-wider text-primary-700">Your store</p>
        <h1 class="font-serif text-3xl text-[var(--text-ink)]">Store Profile</h1>
        <p class="mt-2 font-sans text-sm text-[var(--text-muted)]">Update your store identity, contact details, and pickup address.</p>
    </header>
    @if (session('success'))
        <div role="status" class="mb-5 rounded-md border border-[var(--border-strong)] bg-[var(--bg-surface)] p-4 font-sans text-sm text-[var(--text-ink)]">{{ session('success') }}</div>
    @endif
    @if ($errors->any())
        <div role="alert" class="mb-5 rounded-md border border-red-200 bg-red-50 p-4 font-sans text-sm text-red-800">
            <p class="font-semibold">Please review your store details.</p>
            <ul class="mt-2 list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif
    <section class="{{ $section }} mb-5" aria-labelledby="vacationTitle">
        <h2 id="vacationTitle" class="mb-3 font-serif text-xl text-[var(--text-ink)]">Vacation mode</h2>
        <p class="mb-4 text-sm text-[var(--text-muted)]">Published products stay visible. Buyers cannot add them to their cart or check out while your store is on vacation.</p>
        <form method="POST" action="{{ route('seller.account.vacation') }}" class="flex flex-wrap items-center gap-4">
            @csrf @method('PATCH')
            <input type="hidden" name="is_on_vacation" value="0">
            <label class="flex items-center gap-3 text-sm text-[var(--text-ink)]"><input type="checkbox" name="is_on_vacation" value="1" @checked(old('is_on_vacation', $seller->is_on_vacation))>On vacation</label>
            <button class="rounded-md bg-primary-800 px-4 py-2 text-sm font-semibold text-white">Save vacation mode</button>
        </form>
    </section>
    <form method="POST" action="{{ route('seller.account.update') }}" enctype="multipart/form-data" class="flex flex-col gap-5">
        @csrf
        @method('PATCH')
        <section class="{{ $section }}" aria-labelledby="storeIdentityTitle">
            <h2 id="storeIdentityTitle" class="mb-5 font-serif text-xl text-[var(--text-ink)]">Store identity</h2>
            <div class="grid gap-6 sm:grid-cols-[160px_1fr]">
                <div>
                    @if ($seller->store_logo_path)
                        <img src="{{ '/storage/'.ltrim($seller->store_logo_path, '/') }}" alt="Current store logo" class="mb-3 size-28 shrink-0 rounded-full border-4 border-[var(--bg-surface)] bg-[var(--bg-page)] object-cover shadow-sm">
                        <label class="flex items-center gap-2 font-sans text-xs text-[var(--text-muted)]"><input type="checkbox" name="remove_logo" value="1" @checked(old('remove_logo'))>Remove current logo</label>
                    @else
                        <div class="mb-3 flex size-28 shrink-0 items-center justify-center rounded-full border-4 border-[var(--bg-surface)] bg-primary-50 font-serif text-3xl text-primary-800 shadow-sm" aria-label="Store initials">{{ $seller->storeInitials() }}</div>
                    @endif
                </div>
                <div class="flex flex-col gap-4">
                    <div>
                        <label for="business_name" class="{{ $label }}">Store name *</label>
                        <input id="business_name" name="business_name" required minlength="3" maxlength="30" value="{{ $value('business_name') }}" class="{{ $input }}">
                    </div>
                    <div>
                        <label for="store_description" class="{{ $label }}">Description</label>
                        <textarea id="store_description" name="store_description" rows="4" maxlength="500" class="{{ $input }}">{{ $value('store_description') }}</textarea>
                    </div>
                    <div>
                        <label for="logo" class="{{ $label }}">Store logo</label>
                        <input id="logo" name="logo" type="file" accept="image/jpeg,image/png" class="{{ $input }}">
                        <p class="mt-2 font-sans text-xs text-[var(--text-muted)]">JPG/PNG, up to 5 MB. Leave blank to keep your logo.</p>
                    </div>
                    <div>
                        <label for="banner" class="{{ $label }}">Store banner</label>
                        @if($seller->store_banner_path)
                            <img src="{{ '/storage/'.ltrim($seller->store_banner_path, '/') }}" alt="Current store banner" class="mb-3 h-32 w-full rounded-lg object-cover">
                            <label class="mb-3 flex items-center gap-2 text-xs text-[var(--text-muted)]"><input type="checkbox" name="remove_banner" value="1" @checked(old('remove_banner'))>Remove current banner</label>
                        @endif
                        <input id="banner" name="banner" type="file" accept="image/jpeg,image/png" class="{{ $input }}">
                        <p class="mt-2 font-sans text-xs text-[var(--text-muted)]">JPG/PNG, up to 5 MB. Leave blank to keep your banner.</p>
                    </div>
                </div>
            </div>
        </section>
        <section class="{{ $section }}" aria-labelledby="storeContactTitle">
            <h2 id="storeContactTitle" class="mb-5 font-serif text-xl text-[var(--text-ink)]">Contact details</h2>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="contact_no" class="{{ $label }}">Mobile number *</label>
                    <input id="contact_no" name="contact_no" type="tel" required maxlength="25" autocomplete="tel" value="{{ $value('contact_no') }}" class="{{ $input }}" placeholder="09XXXXXXXXX">
                </div>
                <div>
                    <label for="contact_email" class="{{ $label }}">Public contact email</label>
                    <input id="contact_email" name="contact_email" type="email" maxlength="255" autocomplete="email" value="{{ $value('contact_email') }}" class="{{ $input }}">
                </div>
            </div>
            <p class="mt-4 font-sans text-xs text-[var(--text-muted)]">Login email: <span class="font-semibold">{{ $seller->email }}</span>. Your public contact email is separate from your verified login identity.</p>
        </section>
        <section id="storeAddress" data-auto-load="false" data-address-lookup
            data-selected-region="{{ $value('region_code') }}" data-selected-province="{{ $value('province_code') }}"
            data-selected-municipality="{{ $value('municipality_code') }}" data-selected-barangay="{{ $value('barangay_code') }}"
            class="{{ $section }}" aria-labelledby="storeAddressTitle">
            <h2 id="storeAddressTitle" class="mb-2 font-serif text-xl text-[var(--text-ink)]">Store and pickup address</h2>
            <p class="mb-5 font-sans text-xs text-[var(--text-muted)]">Select your location from the address lists, then enter your street and building details.</p>
            <div class="grid gap-4 sm:grid-cols-2">
                @foreach (['region_code' => ['Region', 'region'], 'province_code' => ['Province', 'province'], 'municipality_code' => ['City / Municipality', 'municipality'], 'barangay_code' => ['Barangay', 'barangay']] as $field => [$title, $attribute])
                    <div>
                        <label for="{{ $field }}" class="{{ $label }}">{{ $title }} *</label>
                        <select id="{{ $field }}" name="{{ $field }}" data-address-{{ $attribute }} required class="{{ $input }}">
                            @if ($value($field))
                                <option value="{{ $value($field) }}" selected>{{ $attribute === 'region' ? 'Saved region' : (old($attribute.'_name', $seller->{$attribute.'_name'}) ?: 'Saved location') }}</option>
                            @else
                                <option value="" disabled selected>Select {{ strtolower($title) }}</option>
                            @endif
                        </select>
                    </div>
                @endforeach
                @foreach (['street' => ['Street *', 255], 'house_number' => ['House / Building number', 50], 'postal_code' => ['Postal code', 10], 'address_detail' => ['Landmark / Additional details', 255]] as $field => [$title, $limit])
                    <div>
                        <label for="{{ $field }}" class="{{ $label }}">{{ $title }}</label>
                        <input id="{{ $field }}" name="{{ $field }}" maxlength="{{ $limit }}" @required($field === 'street') value="{{ $value($field) }}" class="{{ $input }}">
                    </div>
                @endforeach
            </div>
        </section>
        <div class="flex justify-end">
            <button type="submit" class="rounded-md bg-primary-800 px-6 py-3 font-sans text-sm font-semibold text-white hover:bg-primary-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-700">Save store profile</button>
        </div>
    </form>
</div>
@endsection

@push('scripts')
    @vite('resources/js/buyer/address-lookup.js')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const root = document.getElementById('storeAddress');
            const lookup = window.zfAddressLookup.init(root);
            if (lookup) {
                lookup.preselect({
                    region: root.dataset.selectedRegion,
                    province: root.dataset.selectedProvince,
                    municipality: root.dataset.selectedMunicipality,
                    barangay: root.dataset.selectedBarangay,
                });
            }
        });
    </script>
@endpush
