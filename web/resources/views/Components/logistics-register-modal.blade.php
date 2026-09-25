{{--
    resources/views/Components/logistics-register-modal.blade.php
    Single modal, 4 steps: Personal -> Address -> Business & Documents -> OTP.

    Trigger: <a href="#" data-logistics-register-trigger>REGISTER AS LOGISTICS</a>
    Include once: @include('Components.logistics-register-modal')
    Behavior: resources/js/logistics/logistics-register-modal.js

    IMPORTANT — every element the JS touches carries a `logistics*` id. The buyer
    and seller modals already share generic ids (birthday, birthMonth, province,
    email, contactNo, ...) on this page, so getElementById in a third modal would
    silently bind to the FIRST modal's copy. Unique ids are not cosmetic here.

    Backend contract:
      POST {{ route('register.logistics.store') }}            -> 201 { logistics_provider_id, email, verify_url }
      POST /register/logistics/verify-otp/{provider}           -> { redirect, message } | 422 { message }
      POST /register/logistics/verify-otp/{provider}/resend    -> { message }
--}}
<div class="buyer-modal-overlay fixed inset-0 z-[999] hidden items-center justify-center bg-black/55 p-4 backdrop-blur-[2px] [&.is-open]:flex" id="logisticsModalOverlay" aria-hidden="true">
    <div class="relative max-h-[92vh] w-full max-w-[640px] overflow-y-auto rounded-lg border border-neutral-200 bg-white pb-8 pt-10 px-8 shadow-[0_24px_60px_rgba(15,23,42,0.25)] max-sm:px-6 max-sm:pt-8" role="dialog" aria-modal="true" aria-labelledby="logisticsModalTitle">

        <button type="button" class="absolute right-4 top-4 inline-flex h-8 w-8 cursor-pointer items-center justify-center rounded-full text-neutral-500 transition-colors hover:bg-[#F6DEE2] hover:text-[#7a4550]" id="logisticsModalClose" aria-label="Close registration form">
            <i data-lucide="x" width="18" height="18"></i>
        </button>

        {{-- ===== Brand + header ===== --}}
        <div class="mb-5 flex justify-center">
            <div class="flex h-12 w-12 items-center justify-center rounded-full bg-[#F7D6D0] text-[#7a4550] shadow-sm" id="logisticsModalIcon">
                <i data-lucide="truck" width="20" height="20"></i>
            </div>
        </div>
        <h2 class="text-center font-serif text-[26px] font-normal text-neutral-900" id="logisticsModalTitle">Register as Logistics</h2>
        <p class="mt-2 text-center font-sans text-[13.5px] text-neutral-500">Manage courier operations for the Zefanya marketplace.</p>

        @if ($errors->any())
            <div class="mt-4 rounded-sm border border-[#e8a49c] bg-[#fbe9e7] px-4 py-3 font-sans text-[12.5px] text-[#a5333d]">{{ $errors->first() }}</div>
        @endif

        {{-- ===== Stepper ===== --}}
        <div class="mt-6 rounded-lg border border-neutral-100 bg-neutral-50 p-4">
            <div class="mb-3 flex items-center justify-between">
                <span class="font-sans text-[11px] font-semibold uppercase tracking-[1.2px] text-neutral-400">Registration progress</span>
                <span class="font-sans text-[11px] font-semibold text-[#9c5c68]">4 steps</span>
            </div>
            <div class="flex items-center" id="logisticsStepper">
                @foreach(['Personal','Address','Business','OTP'] as $i => $label)
                    <div class="flex flex-1 items-center {{ $loop->last ? 'flex-none' : '' }}">
                        <div class="flex flex-col items-center gap-1.5" data-step-node="{{ $i + 1 }}">
                            <div class="flex h-8 w-8 items-center justify-center rounded-full border-2 border-neutral-200 bg-white font-sans text-xs font-semibold text-neutral-400 transition-colors" data-step-circle="{{ $i + 1 }}">{{ $i + 1 }}</div>
                            <span class="font-sans text-[11px] font-medium text-neutral-400 max-sm:hidden" data-step-label="{{ $i + 1 }}">{{ $label }}</span>
                        </div>
                        @unless($loop->last)
                            <div class="mx-2 h-0.5 flex-1 bg-neutral-200" data-step-line="{{ $i + 1 }}"></div>
                        @endunless
                    </div>
                @endforeach
            </div>
        </div>

        {{-- ===== Steps 1-3: one multipart form so all field values persist across panels ===== --}}
        <form id="logisticsRegisterForm" method="POST"
              action="{{ Route::has('register.logistics.store') ? route('register.logistics.store') : '#' }}"
              enctype="multipart/form-data" novalidate>
            @csrf

            <input type="hidden" name="address_mode" id="logisticsAddressMode" value="{{ old('address_mode', 'api') }}">
            <input type="hidden" name="province_name" id="logisticsProvinceNameField" value="{{ old('province_name') }}">
            <input type="hidden" name="municipality_name" id="logisticsMunicipalityNameField" value="{{ old('municipality_name') }}">
            <input type="hidden" name="barangay_name" id="logisticsBarangayNameField" value="{{ old('barangay_name') }}">

            {{-- ---- Panel 1: Personal ---- --}}
            <section class="logistics-panel mt-6" data-panel="1">
                <p class="mb-4 font-sans text-[11px] font-semibold uppercase tracking-[1px] text-neutral-500">Personal information</p>

                <div class="mb-4 grid grid-cols-2 gap-4 max-sm:grid-cols-1">
                    <div>
                        <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="logisticsLastName">Last name *</label>
                        <input type="text" name="last_name" id="logisticsLastName" required value="{{ old('last_name') }}"
                               class="h-11 w-full rounded-sm border border-neutral-300 px-4 font-sans text-sm text-neutral-900 outline-none transition-colors focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30">
                        <span class="field-error mt-1 block font-sans text-[11.5px] text-[#a5333d]"></span>
                    </div>
                    <div>
                        <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="logisticsFirstName">First name *</label>
                        <input type="text" name="first_name" id="logisticsFirstName" required value="{{ old('first_name') }}"
                               class="h-11 w-full rounded-sm border border-neutral-300 px-4 font-sans text-sm text-neutral-900 outline-none transition-colors focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30">
                        <span class="field-error mt-1 block font-sans text-[11.5px] text-[#a5333d]"></span>
                    </div>
                </div>

                <div class="mb-4 grid grid-cols-2 gap-4 max-sm:grid-cols-1">
                    <div>
                        <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="logisticsMiddleInitial">Middle initial</label>
                        <input type="text" name="middle_initial" id="logisticsMiddleInitial" maxlength="2" value="{{ old('middle_initial') }}"
                               class="h-11 w-full rounded-sm border border-neutral-300 px-4 font-sans text-sm text-neutral-900 outline-none transition-colors focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30">
                    </div>
                    <div>
                        <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="logisticsSex">Sex *</label>
                        <select name="sex" id="logisticsSex" required
                                class="h-11 w-full rounded-sm border border-neutral-300 px-4 font-sans text-sm text-neutral-900 outline-none transition-colors focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30">
                            <option value="" disabled {{ old('sex') ? '' : 'selected' }}>Select</option>
                            <option value="male" {{ old('sex') === 'male' ? 'selected' : '' }}>Male</option>
                            <option value="female" {{ old('sex') === 'female' ? 'selected' : '' }}>Female</option>
                        </select>
                        <span class="field-error mt-1 block font-sans text-[11.5px] text-[#a5333d]"></span>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="logisticsEmail">Email *</label>
                    <input type="email" name="email" id="logisticsEmail" required value="{{ old('email') }}"
                           class="h-11 w-full rounded-sm border border-neutral-300 px-4 font-sans text-sm text-neutral-900 outline-none transition-colors focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30">
                    <span class="field-error mt-1 block font-sans text-[11.5px] text-[#a5333d]" id="server-email"></span>
                </div>

                <div class="mb-4 grid grid-cols-2 gap-4 max-sm:grid-cols-1">
                    <div>
                        <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="logisticsPassword">Password *</label>
                        <div class="relative">
                            <input type="password" name="password" id="logisticsPassword" placeholder="At least 8 characters" required minlength="8" autocomplete="new-password"
                                   class="h-11 w-full rounded-sm border border-neutral-300 px-4 pr-11 font-sans text-sm text-neutral-900 outline-none transition-colors focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30">
                            <button type="button" class="absolute right-3 top-1/2 -translate-y-1/2 text-neutral-500 hover:text-[#7a4550]" data-toggle-visibility="logisticsPassword" aria-label="Show password">
                                <i data-lucide="eye" width="16" height="16"></i>
                            </button>
                        </div>
                        <span class="field-error mt-1 block font-sans text-[11.5px] text-[#a5333d]"></span>
                    </div>
                    <div>
                        <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="logisticsPasswordConfirmation">Confirm password *</label>
                        <div class="relative">
                            <input type="password" name="password_confirmation" id="logisticsPasswordConfirmation" placeholder="Re-enter your password" required autocomplete="new-password"
                                   class="h-11 w-full rounded-sm border border-neutral-300 px-4 pr-11 font-sans text-sm text-neutral-900 outline-none transition-colors focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30">
                            <button type="button" class="absolute right-3 top-1/2 -translate-y-1/2 text-neutral-500 hover:text-[#7a4550]" data-toggle-visibility="logisticsPasswordConfirmation" aria-label="Show password">
                                <i data-lucide="eye" width="16" height="16"></i>
                            </button>
                        </div>
                        <span class="field-error mt-1 block font-sans text-[11.5px] text-[#a5333d]"></span>
                    </div>
                </div>

                <div class="mb-6 grid grid-cols-2 gap-4 max-sm:grid-cols-1">
                    <div>
                        <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="logisticsContactNo">Contact no. *</label>
                        <input type="tel" name="contact_no" id="logisticsContactNo" placeholder="09XXXXXXXXX" required value="{{ old('contact_no') }}"
                               class="h-11 w-full rounded-sm border border-neutral-300 px-4 font-sans text-sm text-neutral-900 outline-none transition-colors focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30">
                        <span class="field-error mt-1 block font-sans text-[11.5px] text-[#a5333d]"></span>
                    </div>
                    <div>
                        <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="logisticsAge">Age</label>
                        {{-- Display only: no `name` attribute, so it is never submitted. --}}
                        <input type="text" id="logisticsAge" readonly placeholder="—"
                               class="h-11 w-full rounded-sm border border-neutral-200 bg-neutral-50 px-3 text-center font-sans text-sm text-neutral-500">
                    </div>
                </div>

                <div class="mb-6">
                    @php
                        $oldLogisticsBirthday = old('birthday') ? explode('-', old('birthday')) : [null, null, null];
                    @endphp

                    <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600">Birthday *</label>
                    <div class="grid grid-cols-[1.6fr_0.5fr_0.8fr] gap-2">
                        <select id="logisticsBirthMonth" aria-label="Birth month"
                                class="h-11 w-full min-w-0 rounded-sm border border-neutral-300 px-2 font-sans text-[13px] text-neutral-900 outline-none transition-colors focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30">
                            <option value="" disabled selected>Month</option>
                            @foreach(['January','February','March','April','May','June','July','August','September','October','November','December'] as $logisticsMonthIndex => $logisticsMonthName)
                                <option value="{{ sprintf('%02d', $logisticsMonthIndex + 1) }}" {{ ($oldLogisticsBirthday[1] ?? null) === sprintf('%02d', $logisticsMonthIndex + 1) ? 'selected' : '' }}>{{ $logisticsMonthName }}</option>
                            @endforeach
                        </select>
                        <select id="logisticsBirthDay" aria-label="Birth day"
                                class="h-11 w-full min-w-0 rounded-sm border border-neutral-300 px-2 font-sans text-[13px] text-neutral-900 outline-none transition-colors focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30">
                            <option value="" disabled selected>Day</option>
                            @for ($logisticsDay = 1; $logisticsDay <= 31; $logisticsDay++)
                                <option value="{{ $logisticsDay }}" {{ (int) ($oldLogisticsBirthday[2] ?? 0) === $logisticsDay ? 'selected' : '' }}>{{ $logisticsDay }}</option>
                            @endfor
                        </select>
                        <select id="logisticsBirthYear" aria-label="Birth year"
                                class="h-11 w-full min-w-0 rounded-sm border border-neutral-300 px-2 font-sans text-[13px] text-neutral-900 outline-none transition-colors focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30">
                            <option value="" disabled selected>Year</option>
                            @for ($logisticsYear = (int) date('Y'); $logisticsYear >= 1900; $logisticsYear--)
                                <option value="{{ $logisticsYear }}" {{ (int) ($oldLogisticsBirthday[0] ?? 0) === $logisticsYear ? 'selected' : '' }}>{{ $logisticsYear }}</option>
                            @endfor
                        </select>
                    </div>
                    <input type="hidden" name="birthday" id="logisticsBirthday" value="{{ old('birthday') }}">
                    <span class="field-error mt-1 block font-sans text-[11.5px] text-[#a5333d]" id="logisticsBirthdayError"></span>
                </div>

                <button type="button" id="logisticsNextStep1" class="h-11 w-full cursor-pointer rounded-sm bg-neutral-900 font-sans text-[12.5px] font-semibold uppercase tracking-[1.4px] text-white transition-colors hover:bg-[#9c5c68]">
                    Next
                </button>
            </section>

            {{-- ---- Panel 2: Address ---- --}}
            <section class="logistics-panel mt-6 hidden" data-panel="2">
                <p class="mb-1 font-sans text-[11px] font-semibold uppercase tracking-[1px] text-neutral-500">Logistics address</p>
                <p class="mb-4 font-sans text-xs text-neutral-500">Choose your location, then add your exact street address.</p>

                <div class="rounded-lg border border-[#ADC7AD]/50 bg-[#F4F8F4] p-6">
                    <div class="mb-3 flex justify-end">
                        <button type="button" class="font-sans text-xs font-semibold text-[#9c5c68] hover:text-[#7a4550]" id="logisticsToggleManualAddress">Enter address manually</button>
                    </div>

                    <div class="grid grid-cols-2 gap-4 max-sm:grid-cols-1" id="logisticsApiAddressFields">
                        <div>
                            <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="logisticsProvince">Province *</label>
                            <select name="province" id="logisticsProvince" required
                                    class="h-11 w-full rounded-sm border border-neutral-300 bg-white px-4 font-sans text-sm text-neutral-900 outline-none focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30">
                                <option value="" disabled selected>Loading provinces...</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="logisticsMunicipality">Municipality / city *</label>
                            <select name="municipality" id="logisticsMunicipality" required disabled
                                    class="h-11 w-full rounded-sm border border-neutral-300 bg-white px-4 font-sans text-sm text-neutral-900 outline-none focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30 disabled:bg-neutral-100 disabled:text-neutral-400">
                                <option value="" disabled selected>Select province first</option>
                            </select>
                        </div>
                        <div class="col-span-2 max-sm:col-span-1">
                            <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="logisticsBarangay">Barangay *</label>
                            <select name="barangay" id="logisticsBarangay" required disabled
                                    class="h-11 w-full rounded-sm border border-neutral-300 bg-white px-4 font-sans text-sm text-neutral-900 outline-none focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30 disabled:bg-neutral-100 disabled:text-neutral-400">
                                <option value="" disabled selected>Select municipality first</option>
                            </select>
                        </div>
                    </div>

                    {{-- Manual-only fallback: text inputs (no `name`; they are copied into the
                         hidden *_name fields by the JS) when the PSGC API is unreachable. --}}
                    <div class="mt-4 hidden grid grid-cols-1 gap-4" id="logisticsManualAddressFields">
                        <div class="grid grid-cols-2 gap-4 max-sm:grid-cols-1">
                            <div>
                                <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="logisticsManualProvince">Province</label>
                                <input type="text" id="logisticsManualProvince" placeholder="Province name" value="{{ old('province_name') }}"
                                       class="h-11 w-full rounded-sm border border-neutral-300 bg-white px-4 font-sans text-sm text-neutral-900 outline-none focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30">
                            </div>
                            <div>
                                <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="logisticsManualMunicipality">Municipality / City</label>
                                <input type="text" id="logisticsManualMunicipality" placeholder="Municipality or city" value="{{ old('municipality_name') }}"
                                       class="h-11 w-full rounded-sm border border-neutral-300 bg-white px-4 font-sans text-sm text-neutral-900 outline-none focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30">
                            </div>
                        </div>
                        <div>
                            <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="logisticsManualBarangay">Barangay</label>
                            <input type="text" id="logisticsManualBarangay" placeholder="Barangay name" value="{{ old('barangay_name') }}"
                                   class="h-11 w-full rounded-sm border border-neutral-300 bg-white px-4 font-sans text-sm text-neutral-900 outline-none focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30">
                        </div>
                    </div>

                    {{-- Street / house number — always visible in BOTH modes. --}}
                    <div class="mt-4 grid grid-cols-1 gap-4">
                        <div>
                            <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="logisticsStreet">Street *</label>
                            <input type="text" name="street" id="logisticsStreet" required
                                   placeholder="e.g. Rizal St., Purok 2"
                                   value="{{ old('street') }}"
                                   class="h-11 w-full rounded-sm border border-neutral-300 bg-white px-4 font-sans text-sm text-neutral-900 outline-none focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30">
                            <span class="field-error mt-1 block font-sans text-[11.5px] text-[#a5333d]" id="logisticsStreetError"></span>
                        </div>
                        <div class="grid grid-cols-2 gap-4 max-sm:grid-cols-1">
                            <div>
                                <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="logisticsHouseNumber">House / unit no.</label>
                                <input type="text" name="house_number" id="logisticsHouseNumber"
                                       placeholder="e.g. 123"
                                       value="{{ old('house_number') }}"
                                       class="h-11 w-full rounded-sm border border-neutral-300 bg-white px-4 font-sans text-sm text-neutral-900 outline-none focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30">
                                <span class="field-error mt-1 block font-sans text-[11.5px] text-[#a5333d]"></span>
                            </div>
                            <div>
                                <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="logisticsAddressDetail">Additional details</label>
                                <input type="text" name="address_detail" id="logisticsAddressDetail"
                                       placeholder="Building, unit, landmark, etc."
                                       value="{{ old('address_detail') }}"
                                       class="h-11 w-full rounded-sm border border-neutral-300 bg-white px-4 font-sans text-sm text-neutral-900 outline-none focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30">
                                <span class="field-error mt-1 block font-sans text-[11.5px] text-[#a5333d]"></span>
                            </div>
                        </div>
                    </div>
                    <span class="field-error mt-3 block font-sans text-[11.5px] text-[#a5333d]" id="logisticsAddressError"></span>
                </div>

                <div class="mt-6 flex gap-3">
                    <button type="button" id="logisticsBackStep2" class="h-11 flex-1 cursor-pointer rounded-sm border border-neutral-300 font-sans text-[12.5px] font-semibold uppercase tracking-[1.4px] text-neutral-700 transition-colors hover:bg-neutral-50">Back</button>
                    <button type="button" id="logisticsNextStep2" class="h-11 flex-[2] cursor-pointer rounded-sm bg-neutral-900 font-sans text-[12.5px] font-semibold uppercase tracking-[1.4px] text-white transition-colors hover:bg-[#9c5c68]">Next</button>
                </div>
            </section>

            {{-- ---- Panel 3: Business & documents ---- --}}
            <section class="logistics-panel mt-6 hidden" data-panel="3">
                <p class="mb-1 font-sans text-[11px] font-semibold uppercase tracking-[1px] text-neutral-500">Business &amp; documents</p>
                <p class="mb-4 font-sans text-xs text-neutral-500">Your logistics company details and supporting papers.</p>

                <div class="mb-5">
                    <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="logisticsBusinessName">Business / company name *</label>
                    <input type="text" name="business_name" id="logisticsBusinessName" required value="{{ old('business_name') }}"
                           class="h-11 w-full rounded-sm border border-neutral-300 px-4 font-sans text-sm text-neutral-900 outline-none transition-colors focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30">
                    <span class="field-error mt-1 block font-sans text-[11.5px] text-[#a5333d]" id="server-business_name"></span>
                </div>

                <div class="mb-5 rounded-sm bg-[#F7D6D0]/50 px-4 py-3 font-sans text-xs text-[#7a4550]">
                    After you submit this form, our team will verify your details and notify you via email. Please allow some time for review.
                </div>

                <div class="mb-5">
                    <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="logisticsUploadId">Upload valid ID *</label>
                    <label for="logisticsUploadId" class="mb-1 flex cursor-pointer items-center gap-3 rounded-sm border border-dashed border-neutral-300 bg-neutral-50 p-4 transition-colors hover:border-[#9c5c68]">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-sm bg-white text-[#9c5c68] shadow-sm"><i data-lucide="upload" width="18" height="18"></i></span>
                        <span>
                            <span class="block font-sans text-sm font-semibold text-neutral-800" id="logisticsUploadIdName">Choose a valid ID</span>
                            <span class="block font-sans text-xs text-neutral-500">JPG, JPEG, PNG or PDF · Max 5MB</span>
                        </span>
                    </label>
                    <input type="file" name="upload_id" id="logisticsUploadId" accept="image/*,.pdf" required class="hidden">
                    <span class="field-error mt-1 block font-sans text-[11.5px] text-[#a5333d]" id="server-upload_id"></span>
                </div>

                <div class="mb-6">
                    <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="logisticsDtiPermit">Upload DTI permit *</label>
                    <label for="logisticsDtiPermit" class="mb-1 flex cursor-pointer items-center gap-3 rounded-sm border border-dashed border-neutral-300 bg-neutral-50 p-4 transition-colors hover:border-[#9c5c68]">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-sm bg-white text-[#9c5c68] shadow-sm"><i data-lucide="file-text" width="18" height="18"></i></span>
                        <span>
                            <span class="block font-sans text-sm font-semibold text-neutral-800" id="logisticsDtiPermitName">Choose a DTI permit</span>
                            <span class="block font-sans text-xs text-neutral-500">JPG, JPEG, PNG or PDF · Max 5MB</span>
                        </span>
                    </label>
                    <input type="file" name="dti_permit_upload" id="logisticsDtiPermit" accept="image/*,.pdf" required class="hidden">
                    <span class="field-error mt-1 block font-sans text-[11.5px] text-[#a5333d]" id="server-dti_permit_upload"></span>
                </div>

                <div class="flex gap-3">
                    <button type="button" id="logisticsBackStep3" class="h-11 flex-1 cursor-pointer rounded-sm border border-neutral-300 font-sans text-[12.5px] font-semibold uppercase tracking-[1.4px] text-neutral-700 transition-colors hover:bg-neutral-50">Back</button>
                    <button type="button" id="submitLogisticsRegistration" class="h-11 flex-[2] cursor-pointer rounded-sm bg-neutral-900 font-sans text-[12.5px] font-semibold uppercase tracking-[1.4px] text-white transition-colors hover:bg-[#9c5c68]">Submit</button>
                </div>
            </section>




        </form>
        {{-- ---- Panel 4: OTP (separate small form — registration already submitted by here) ---- --}}
        <section class="logistics-panel mt-6 hidden" data-panel="4">
            <p class="mb-1 font-sans text-[11px] font-semibold uppercase tracking-[1px] text-neutral-500">Verify your email</p>
            <p class="mb-5 font-sans text-xs text-neutral-500">We sent a 6-digit code to <strong class="text-neutral-700" id="logisticsOtpEmail">your email</strong>.</p>

            <div class="mb-4 hidden rounded-sm border border-[#e8a49c] bg-[#fbe9e7] px-4 py-3 font-sans text-sm text-[#a5333d]" id="logisticsOtpErrorSummary"></div>

            <form id="logisticsOtpForm" novalidate>
                <input type="hidden" id="logisticsOtpId" value="">
                <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600">Verification code *</label>
                <div class="mb-1 flex justify-between gap-2" id="logisticsOtpInputs">
                    @for($i = 0; $i < 6; $i++)
                        <input type="text" inputmode="numeric" pattern="\d" maxlength="1" data-otp-index="{{ $i }}" {{ $i === 0 ? 'autocomplete=one-time-code' : '' }}
                               class="logistics-otp-input h-12 w-full rounded-sm border border-neutral-300 text-center font-sans text-lg font-semibold text-neutral-900 outline-none transition-colors focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30">
                    @endfor
                </div>
                <input type="hidden" id="logisticsOtpHidden" value="">
                <span class="mb-5 block font-sans text-[11.5px] text-[#a5333d]" id="logisticsOtpFieldError"></span>

                <p class="mb-6 font-sans text-xs text-neutral-500">
                    Didn't get a code?
                    <button type="button" class="font-semibold text-[#9c5c68] hover:text-[#7a4550]" id="logisticsOtpResendBtn">Resend code</button>
                    <span class="ml-1 text-neutral-400" id="logisticsOtpResendStatus" aria-live="polite"></span>
                </p>

                <div class="flex gap-3">
                    <button type="button" id="logisticsBackStep4" class="h-11 flex-1 cursor-pointer rounded-sm border border-neutral-300 font-sans text-[12.5px] font-semibold uppercase tracking-[1.4px] text-neutral-700 transition-colors hover:bg-neutral-50">Back</button>
                    <button type="submit" id="logisticsOtpSubmit" class="h-11 flex-[2] cursor-pointer rounded-sm bg-neutral-900 font-sans text-[12.5px] font-semibold uppercase tracking-[1.4px] text-white transition-colors hover:bg-[#9c5c68]">Verify</button>
                </div>
            </form>
        </section>



        <p class="mt-6 text-center font-sans text-[13px] text-neutral-500">Already have an account? <a href="{{ route('login') }}" class="font-semibold text-[#9c5c68] hover:text-[#7a4550]">Sign in</a></p>
    </div>
</div>

@if ($errors->any())
    <script>window.__logisticsModalHasServerErrors = true;</script>
@endif
