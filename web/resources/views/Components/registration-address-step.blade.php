<section class="{{ $role }}-panel mt-6 hidden" data-panel="2">
                <p class="mb-1 font-sans text-[11px] font-semibold uppercase tracking-[1px] text-neutral-500">{{ $role === 'rider' ? 'Rider address' : 'Logistics address' }}</p>
                <p class="mb-4 font-sans text-xs text-neutral-500">Choose your location, then add your exact street address.</p>

                <div class="rounded-lg border border-[#ADC7AD]/50 bg-[#F4F8F4] p-6">
                    {{-- Province / municipality / barangay: dropdowns only (no free-text). --}}
                    <div class="grid grid-cols-2 gap-4 max-sm:grid-cols-1" id="{{ $role }}ApiAddressFields">
                        <div>
                            <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="{{ $role }}Province">Province *</label>
                            <select name="province" id="{{ $role }}Province" required
                                    class="h-11 w-full rounded-sm border border-neutral-300 bg-white px-4 font-sans text-sm text-neutral-900 outline-none focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30" @if($role === 'rider') data-validate-rule="required" @endif>
                                <option value="" disabled selected>Loading provinces...</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="{{ $role }}Municipality">Municipality / city *</label>
                            <select name="municipality" id="{{ $role }}Municipality" required disabled
                                    class="h-11 w-full rounded-sm border border-neutral-300 bg-white px-4 font-sans text-sm text-neutral-900 outline-none focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30 disabled:bg-neutral-100 disabled:text-neutral-400" @if($role === 'rider') data-validate-rule="required" @endif>
                                <option value="" disabled selected>Select province first</option>
                            </select>
                        </div>
                        <div class="col-span-2 max-sm:col-span-1">
                            <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="{{ $role }}Barangay">Barangay *</label>
                            <select name="barangay" id="{{ $role }}Barangay" required disabled
                                    class="h-11 w-full rounded-sm border border-neutral-300 bg-white px-4 font-sans text-sm text-neutral-900 outline-none focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30 disabled:bg-neutral-100 disabled:text-neutral-400" @if($role === 'rider') data-validate-rule="required" @endif>
                                <option value="" disabled selected>Select municipality first</option>
                            </select>
                        </div>
                    </div>

                    {{-- Street / house number â€” free text only for these fields. --}}
                    <div class="mt-4 grid grid-cols-1 gap-4">
                        <div>
                            <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="{{ $role }}Street">Street *</label>
                            <input type="text" name="street" id="{{ $role }}Street" required @if($role === 'rider') data-validate-rule="required" @endif
                                   placeholder="e.g. Rizal St., Purok 2"
                                   value="{{ old('street') }}"
                                   class="h-11 w-full rounded-sm border border-neutral-300 bg-white px-4 font-sans text-sm text-neutral-900 outline-none focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30">
                            <span class="field-error mt-1 block font-sans text-[11.5px] text-[#a5333d]" id="{{ $role }}StreetError"></span>
                        </div>
                        <div class="grid grid-cols-2 gap-4 max-sm:grid-cols-1">
                            <div>
                                <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="{{ $role }}HouseNumber">House / unit no.</label>
                                <input type="text" name="house_number" id="{{ $role }}HouseNumber"
                                       placeholder="e.g. 123"
                                       value="{{ old('house_number') }}"
                                       class="h-11 w-full rounded-sm border border-neutral-300 bg-white px-4 font-sans text-sm text-neutral-900 outline-none focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30">
                                <span class="field-error mt-1 block font-sans text-[11.5px] text-[#a5333d]"></span>
                            </div>
                            <div>
                                <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="{{ $role }}AddressDetail">Additional details</label>
                                <input type="text" name="address_detail" id="{{ $role }}AddressDetail"
                                       placeholder="Building, unit, landmark, etc."
                                       value="{{ old('address_detail') }}"
                                       class="h-11 w-full rounded-sm border border-neutral-300 bg-white px-4 font-sans text-sm text-neutral-900 outline-none focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30">
                                <span class="field-error mt-1 block font-sans text-[11.5px] text-[#a5333d]"></span>
                            </div>
                            <div>
                                <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="{{ $role }}PostalCode">Postal code</label>
                                <input type="text" name="postal_code" id="{{ $role }}PostalCode"
                                       inputmode="numeric" maxlength="10"
                                       placeholder="e.g. 4030"
                                       value="{{ old('postal_code') }}"
                                       class="h-11 w-full rounded-sm border border-neutral-300 bg-white px-4 font-sans text-sm text-neutral-900 outline-none focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30">
                                <span class="field-error mt-1 block font-sans text-[11.5px] text-[#a5333d]"></span>
                            </div>
                        </div>
                    </div>
                    <span class="field-error mt-3 block font-sans text-[11.5px] text-[#a5333d]" id="{{ $role }}AddressError"></span>
                </div>

                <div class="mt-6 flex gap-3">
                    <button type="button" id="{{ $role }}BackStep2" class="h-11 flex-1 cursor-pointer rounded-sm border border-neutral-300 font-sans text-[12.5px] font-semibold uppercase tracking-[1.4px] text-neutral-700 transition-colors hover:bg-neutral-50">Back</button>
                    <button type="button" id="{{ $role }}NextStep2" class="h-11 flex-[2] cursor-pointer rounded-sm bg-neutral-900 font-sans text-[12.5px] font-semibold uppercase tracking-[1.4px] text-white transition-colors hover:bg-[#9c5c68]">Next</button>
                </div>
            </section>
