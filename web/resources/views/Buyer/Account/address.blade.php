@extends('Buyer.Layouts.app')

@section('title', 'My Account — Address')

@section('content')
@php
    // old() wins after a validation error so the user doesn't retype everything.
    $record = $editing ?? null;
    $val = fn (string $field, $default = '') => old($field, $record?->$field ?? $default);
    $atLimit = $addresses->count() >= \App\Services\Buyer\BuyerAddressBook::MAX && $record === null;

    // Shared with the buyer registration modal (Components/buyer-register-modal).
    $labelCls = 'mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-[var(--text-label)]';
    $inputCls = 'h-11 w-full rounded-md border border-neutral-300 bg-white px-4 font-sans text-sm text-neutral-900 outline-none transition-colors focus:border-[var(--brand)] focus:ring-2 focus:ring-[var(--hover-rose)]';
    $errorCls = 'mt-1 block font-sans text-[11.5px] text-[var(--status-danger)]';
@endphp

<div class="bg-[var(--bg-page)] py-8 sm:py-10">
    <div class="mx-auto w-full max-w-[1240px] px-4 sm:px-6 lg:px-8">
        <div class="grid grid-cols-1 gap-8 lg:grid-cols-[280px_1fr]">

            {{-- ── Left Sidebar ── --}}
            @include('Components.buyer.account-sidebar', ['active' => 'address'])

            {{-- ── Right Column ── --}}
            <main>
                @include('Components.buyer.account-tabs', ['active' => 'address'])

                <div class="mb-6">
                    <h1 class="font-serif text-[28px] font-normal leading-tight text-[var(--text-ink)] sm:text-[32px]">
                        Shipping Addresses
                    </h1>
                    <p class="mt-1 font-sans text-[13px] text-[var(--text-muted)]">
                        Where your orders are delivered.
                    </p>
                </div>

                @if (session('success'))
                    <div class="mb-6 flex items-center gap-2.5 rounded-lg border border-[var(--pill-success-border)] bg-[var(--pill-success-bg)] px-3.5 py-2.5 font-sans text-[12.5px] font-medium text-[var(--status-success)]" role="status">
                        <i data-lucide="check-circle-2" width="15" height="15" class="shrink-0"></i>
                        <span class="text-[var(--status-success)]">{{ session('success') }}</span>
                    </div>
                @endif

                <section class="mb-6 rounded-2xl border border-[var(--border)] bg-[var(--bg-surface)] p-5 shadow-sm sm:p-7">
                    <h2 class="font-serif text-[18px] font-normal text-[var(--text-ink)]">Saved addresses</h2>
                    <p class="mt-1 font-sans text-[12px] text-[var(--text-muted)]">{{ $addresses->count() }} of {{ \App\Services\Buyer\BuyerAddressBook::MAX }}. One address is the default.</p>
                    @if ($addresses->isEmpty())
                        <p class="mt-4 font-sans text-[13px] text-[var(--text-muted)]">No saved addresses yet.</p>
                    @else
                        <ul class="mt-4 space-y-3">
                            @foreach ($addresses as $address)
                                <li class="rounded-xl border border-[var(--border-faint)] px-4 py-3">
                                    <div class="flex flex-wrap items-start justify-between gap-3">
                                        <p class="font-sans text-[13px] text-[var(--text-ink)]">{{ $address->summary() }}</p>
                                        @if ($address->is_default)
                                            <span class="rounded-full bg-[var(--pill-success-bg)] px-2.5 py-1 font-sans text-[11px] font-semibold text-[var(--status-success)]">Default</span>
                                        @endif
                                    </div>
                                    <div class="mt-3 flex flex-wrap items-center gap-3">
                                        <a href="{{ route('buyer.account.address', ['edit' => $address->id]) }}" class="font-sans text-[12px] font-semibold text-[var(--text-ink)] underline">Edit</a>
                                        @unless ($address->is_default)
                                            <form method="POST" action="{{ route('buyer.account.addresses.default', $address) }}">
                                                @csrf
                                                <button type="submit" class="font-sans text-[12px] font-semibold text-[var(--text-ink)] underline">Set as default</button>
                                            </form>
                                            <form method="POST" action="{{ route('buyer.account.addresses.destroy', $address) }}">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="font-sans text-[12px] font-semibold text-[var(--status-danger)] underline">Delete</button>
                                            </form>
                                        @else
                                            <p class="font-sans text-[12px] text-[var(--text-muted)]">Choose another default address before deleting this one.</p>
                                        @endunless
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>

                @unless ($atLimit)
                <section class="rounded-2xl border border-[var(--border)] bg-[var(--bg-surface)] p-5 shadow-sm sm:p-7">
                    <form method="POST" action="{{ $record ? route('buyer.account.addresses.update', $record) : route('buyer.account.addresses.store') }}" data-account-address>
                        @csrf
                        @if ($record)
                            @method('PATCH')
                        @endif

                        <input type="hidden" name="address_mode" id="addressMode" value="api">
                        <input type="hidden" name="province_name" id="provinceNameField" value="{{ $val('province_name') }}">
                        <input type="hidden" name="municipality_name" id="municipalityNameField" value="{{ $val('municipality_name') }}">
                        <input type="hidden" name="barangay_name" id="barangayNameField" value="{{ $val('barangay_name') }}">

                        @if ($errors->any())
                            <div class="mb-4 flex items-center gap-2.5 rounded-lg border border-[var(--border-error-soft)] bg-[var(--bg-error-soft)] px-3.5 py-2.5 font-sans text-[12.5px] font-medium text-[var(--status-danger)]" role="alert">
                                <i data-lucide="alert-circle" width="15" height="15" class="shrink-0"></i>
                                <span class="text-[var(--status-danger)]">{{ $errors->first() }}</span>
                            </div>
                        @endif

                        <div class="mb-6">
                            <div class="mb-4">
                                <h2 class="font-serif text-[18px] font-normal leading-tight text-[var(--text-ink)]">{{ $record ? 'Edit address' : 'Add an address' }}</h2>
                                <p class="mt-1 font-sans text-[12px] text-[var(--text-muted)]">{{ $addresses->isEmpty() ? 'The first address you save becomes the default.' : 'Update where we\'ll send your orders.' }}</p>
                            </div>

                            {{-- PSGC cascading dropdowns (same DOM contract as the registration modal) --}}
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2" id="apiAddressFields">
                                <div>
                                    <label class="{{ $labelCls }}" for="region">Region *</label>
                                    <select name="region" id="region" required data-saved-code="{{ old('region') }}" class="{{ $inputCls }}">
                                        <option value="">Loading regions...</option>
                                    </select>
                                    @error('region')<span class="{{ $errorCls }}">{{ $message }}</span>@enderror
                                </div>
                                <div>
                                    <label class="{{ $labelCls }}" for="province">Province *</label>
                                    <select name="province" id="province" required
                                            data-saved-code="{{ old('province', $record?->province_code) }}"
                                            class="{{ $inputCls }}">
                                        <option value="" disabled selected>Select region first</option>
                                    </select>
                                    @error('province')<span class="{{ $errorCls }}">{{ $message }}</span>@enderror
                                </div>
                                <div>
                                    <label class="{{ $labelCls }}" for="municipality">Municipality / city *</label>
                                    <select name="municipality" id="municipality" disabled required
                                            data-saved-code="{{ old('municipality', $record?->municipality_code) }}"
                                            class="{{ $inputCls }} disabled:bg-neutral-100 disabled:text-neutral-400">
                                        <option value="" disabled selected>Select province first</option>
                                    </select>
                                    @error('municipality')<span class="{{ $errorCls }}">{{ $message }}</span>@enderror
                                </div>
                                <div class="sm:col-span-2">
                                    <label class="{{ $labelCls }}" for="barangay">Barangay *</label>
                                    <select name="barangay" id="barangay" disabled required
                                            data-saved-code="{{ old('barangay', $record?->barangay_code) }}"
                                            class="{{ $inputCls }} disabled:bg-neutral-100 disabled:text-neutral-400">
                                        <option value="" disabled selected>Select municipality first</option>
                                    </select>
                                    @error('barangay')<span class="{{ $errorCls }}">{{ $message }}</span>@enderror
                                </div>
                            </div>

                            {{-- Street / details — visible in BOTH modes, same as registration --}}
                            <div class="mt-4 grid grid-cols-1 gap-4">
                                <div>
                                    <label class="{{ $labelCls }}" for="street">Street / house number *</label>
                                    <input type="text" name="street" id="street" required
                                           placeholder="e.g. 123 Rizal St., Purok 2"
                                           value="{{ $val('street') }}"
                                           class="{{ $inputCls }}">
                                    @error('street')<span class="{{ $errorCls }}">{{ $message }}</span>@enderror
                                </div>
                                <div>
                                    <label class="{{ $labelCls }}" for="addressDetail">Additional details</label>
                                    <input type="text" name="address_detail" id="addressDetail"
                                           placeholder="Building, unit, landmark, etc."
                                           value="{{ $val('address_detail') }}"
                                           class="{{ $inputCls }}">
                                </div>
                                <div>
                                    <label class="{{ $labelCls }}" for="postalCode">Postal code</label>
                                    <input type="text" name="postal_code" id="postalCode"
                                           inputmode="numeric" maxlength="10"
                                           placeholder="e.g. 4030"
                                           value="{{ $val('postal_code') }}"
                                           class="{{ $inputCls }}">
                                    @error('postal_code')<span class="{{ $errorCls }}">{{ $message }}</span>@enderror
                                </div>
                            </div>
                        </div>

                        @if ($addresses->isNotEmpty() && ! $record?->is_default)
                            <label class="mt-4 flex items-center gap-2 font-sans text-[13px] text-[var(--text-ink)]">
                                <input type="checkbox" name="is_default" value="1" @checked(old('is_default'))>
                                Set as default
                            </label>
                        @endif

                        <div class="flex flex-col gap-3 border-t border-[var(--border-faint)] pt-5 sm:flex-row sm:items-center sm:gap-x-5">
                            <button type="submit"
                                    class="inline-flex w-full items-center justify-center rounded-full bg-[var(--btn-primary-bg)] px-8 py-2.5 font-sans text-[13px] font-semibold text-[var(--btn-primary-text)] transition-colors hover:bg-[var(--btn-primary-bg-hover)] sm:w-auto">
                                Save address
                            </button>
                        </div>
                    </form>
                </section>
                @else
                    <p class="font-sans text-[13px] text-[var(--text-muted)]">{{ \App\Services\Buyer\BuyerAddressBook::LIMIT_MESSAGE }}</p>
                @endunless
            </main>
        </div>
    </div>
</div>

{{-- Shared PSGC lookup: same module the registration modal uses (no duplicated API code). --}}
@vite('resources/js/buyer/address-lookup.js')
@endsection
