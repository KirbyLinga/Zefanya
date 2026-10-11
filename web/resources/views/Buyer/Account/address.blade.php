@extends('Buyer.Layouts.app')

@section('title', 'My Account — Address')

@section('content')
@php
    // old() wins after a validation error so the user doesn't retype everything.
    $val = fn (string $field, $default = '') => old($field, $buyer->$field ?? $default);
    $isManualOld = old('address_mode', $buyer->address_mode ?? 'api') === 'manual';

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

                <section class="rounded-2xl border border-[var(--border)] bg-[var(--bg-surface)] p-5 shadow-sm sm:p-7">
                    <form method="POST" action="{{ route('buyer.account.address.update') }}">
                        @csrf
                        @method('PATCH')

                        <input type="hidden" name="address_mode" id="addressMode" value="{{ old('address_mode', $buyer->address_mode ?? 'api') }}">
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
                            <div class="mb-4 flex justify-between items-start">
                                <div>
                                    <h2 class="font-serif text-[18px] font-normal leading-tight text-[var(--text-ink)]">Delivery Address</h2>
                                    <p class="mt-1 font-sans text-[12px] text-[var(--text-muted)]">Update where we'll send your orders.</p>
                                </div>
                                <button type="button"
                                        class="font-sans text-xs font-semibold text-[var(--brand)] hover:text-[var(--link)]"
                                        id="toggleManualAddress">{{ $isManualOld ? 'Use address lookup' : 'Enter address manually' }}</button>
                            </div>

                            {{-- API mode: PSGC cascading dropdowns (same DOM contract as the registration modal) --}}
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 {{ $isManualOld ? 'hidden' : '' }}" id="apiAddressFields">
                                <div>
                                    <label class="{{ $labelCls }}" for="province">Province *</label>
                                    <select name="province" id="province" {{ $isManualOld ? '' : 'required' }}
                                            data-saved-code="{{ old('province', $buyer->province_code) }}"
                                            class="{{ $inputCls }}">
                                        <option value="" disabled selected>{{ $isManualOld ? 'Select province' : 'Loading provinces...' }}</option>
                                    </select>
                                    @error('province')<span class="{{ $errorCls }}">{{ $message }}</span>@enderror
                                </div>
                                <div>
                                    <label class="{{ $labelCls }}" for="municipality">Municipality / city *</label>
                                    <select name="municipality" id="municipality" disabled {{ $isManualOld ? '' : 'required' }}
                                            data-saved-code="{{ old('municipality', $buyer->municipality_code) }}"
                                            class="{{ $inputCls }} disabled:bg-neutral-100 disabled:text-neutral-400">
                                        <option value="" disabled selected>Select province first</option>
                                    </select>
                                    @error('municipality')<span class="{{ $errorCls }}">{{ $message }}</span>@enderror
                                </div>
                                <div class="sm:col-span-2">
                                    <label class="{{ $labelCls }}" for="barangay">Barangay *</label>
                                    <select name="barangay" id="barangay" disabled {{ $isManualOld ? '' : 'required' }}
                                            data-saved-code="{{ old('barangay', $buyer->barangay_code) }}"
                                            class="{{ $inputCls }} disabled:bg-neutral-100 disabled:text-neutral-400">
                                        <option value="" disabled selected>Select municipality first</option>
                                    </select>
                                    @error('barangay')<span class="{{ $errorCls }}">{{ $message }}</span>@enderror
                                </div>
                            </div>

                            {{-- Manual fallback: free-text province/municipality/barangay --}}
                            <div class="mt-4 grid grid-cols-1 gap-4 {{ $isManualOld ? '' : 'hidden' }}" id="manualAddressFields">
                                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div>
                                        <label class="{{ $labelCls }}" for="manualProvince">Province</label>
                                        <input type="text" id="manualProvince" name="province_name_manual" placeholder="Province name" value="{{ $val('province_name') }}"
                                               class="{{ $inputCls }}">
                                    </div>
                                    <div>
                                        <label class="{{ $labelCls }}" for="manualMunicipality">Municipality / City</label>
                                        <input type="text" id="manualMunicipality" name="municipality_name_manual" placeholder="Municipality or city" value="{{ $val('municipality_name') }}"
                                               class="{{ $inputCls }}">
                                    </div>
                                </div>
                                <div>
                                    <label class="{{ $labelCls }}" for="manualBarangay">Barangay</label>
                                    <input type="text" id="manualBarangay" name="barangay_name_manual" placeholder="Barangay name" value="{{ $val('barangay_name') }}"
                                           class="{{ $inputCls }}">
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
                            </div>
                        </div>

                        <div class="flex flex-col gap-3 border-t border-[var(--border-faint)] pt-5 sm:flex-row sm:items-center sm:gap-x-5">
                            <button type="submit"
                                    class="inline-flex w-full items-center justify-center rounded-full bg-[var(--btn-primary-bg)] px-8 py-2.5 font-sans text-[13px] font-semibold text-[var(--btn-primary-text)] transition-colors hover:bg-[var(--btn-primary-bg-hover)] sm:w-auto">
                                Save address
                            </button>
                        </div>
                    </form>
                </section>
            </main>
        </div>
    </div>
</div>

{{-- Shared PSGC lookup: same module the registration modal uses (no duplicated API code). --}}
@vite('resources/js/buyer/address-lookup.js')
<script>
    // Account-page bootstrapping: scope the shared lookup to THIS form (the
    // layout also embeds the registration modal whose duplicate IDs would
    // otherwise win a document-wide getElementById), then restore the saved
    // record's mode and preselect its PSGC codes.
    (function boot() {
        var root = document.querySelector('form[action*="account/address"]');
        if (!root || !window.zfAddressLookup) {
            // Module is a deferred Vite entry — retry until it lands.
            return setTimeout(boot, 25);
        }

        var api = window.zfAddressLookup.init(root);
        if (!api) return;

        var saved = {
            province: root.querySelector('#province').dataset.savedCode || '',
            municipality: root.querySelector('#municipality').dataset.savedCode || '',
            barangay: root.querySelector('#barangay').dataset.savedCode || '',
        };

        if (root.querySelector('#addressMode').value === 'manual') {
            // Saved record used manual entry — start there. setManualMode also
            // attaches the mirror listeners that keep the hidden *_name fields
            // in sync with what the user types.
            api.setManualMode(true);
        } else if (saved.province) {
            api.preselect(saved);
        } else {
            api.ensureLoaded();
        }
    })();
</script>
@endsection
