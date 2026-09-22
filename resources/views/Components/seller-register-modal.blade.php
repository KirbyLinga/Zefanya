{{--
    resources/views/Components/seller-register-modal.blade.php
    Single modal, 4 steps: Personal -> Address -> Business & Documents -> OTP.
    Mirrors Components/buyer-register-modal.blade.php: same layout,
    stepper, Tailwind styling, exclusive-class state toggles, and
    validation helpers.

    Trigger: <a href="#" data-seller-register-trigger>REGISTER AS SELLER</a>

    Backend contract (unchanged):
      POST {{ route('register.seller.store') }}       -> 201 { seller_id, email, verify_url }
      POST /register/seller/verify-otp/{seller}        -> { redirect, message } | 422 { message }
      POST /register/seller/verify-otp/{seller}/resend -> { message }

    Behavior: resources/js/seller/seller-register-modal.js
--}}
<div class="buyer-modal-overlay fixed inset-0 z-[999] hidden items-center justify-center bg-black/55 p-4 backdrop-blur-[2px] [&.is-open]:flex" id="sellerModalOverlay" aria-hidden="true">
    <div class="relative max-h-[92vh] w-full max-w-[640px] overflow-y-auto rounded-lg border border-neutral-200 bg-white pb-8 pt-10 px-8 shadow-[0_24px_60px_rgba(15,23,42,0.25)] max-sm:px-6 max-sm:pt-8" role="dialog" aria-modal="true" aria-labelledby="sellerModalTitle">

        <button type="button" class="absolute right-4 top-4 inline-flex h-8 w-8 cursor-pointer items-center justify-center rounded-full text-neutral-500 transition-colors hover:bg-[#F6DEE2] hover:text-[#7a4550]" id="sellerModalClose" aria-label="Close registration form">
            <i data-lucide="x" width="18" height="18"></i>
        </button>

        {{-- ===== Brand + header (centered, same treatment as the buyer modal) ===== --}}
        <div class="mb-5 flex justify-center">
            <div class="flex h-12 w-12 items-center justify-center rounded-full bg-[#F7D6D0] text-[#7a4550] shadow-sm" id="sellerModalIcon">
                <i data-lucide="store" width="20" height="20"></i>
            </div>
        </div>
        @if ($errors->any())
    <div class="mt-4 rounded-sm border border-[#e8a49c] bg-[#fbe9e7] px-4 py-3 font-sans text-[12.5px] text-[#a5333d]">{{ $errors->first() }}</div>
@endif

        {{-- ===== Stepper ===== --}}
        <div class="mt-6 rounded-lg border border-neutral-100 bg-neutral-50 p-4">
            <div class="mb-3 flex items-center justify-between">
                <span class="font-sans text-[11px] font-semibold uppercase tracking-[1.2px] text-neutral-400">Registration progress</span>
                <span class="font-sans text-[11px] font-semibold text-[#9c5c68]">4 steps</span>
            </div>
            <div class="flex items-center" id="sellerStepper">
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
        <form id="sellerRegisterForm" method="POST"
              action="{{ Route::has('register.seller.store') ? route('register.seller.store') : '#' }}"
              enctype="multipart/form-data" novalidate>
            @csrf

            <input type="hidden" name="address_mode" id="sellerAddressMode" value="{{ old('address_mode', 'api') }}">
            <input type="hidden" name="province_name" id="sellerProvinceNameField" value="{{ old('province_name') }}">
            <input type="hidden" name="municipality_name" id="sellerMunicipalityNameField" value="{{ old('municipality_name') }}">
            <input type="hidden" name="barangay_name" id="sellerBarangayNameField" value="{{ old('barangay_name') }}">

            {{-- ---- Panel 1: Personal ---- --}}
            <section class="seller-panel mt-6" data-panel="1">
                <p class="mb-4 font-sans text-[11px] font-semibold uppercase tracking-[1px] text-neutral-500">Personal information</p>

                <div class="mb-4 grid grid-cols-2 gap-4 max-sm:grid-cols-1">
                    <div>
                        <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="lastName">Last name *</label>
                        <input type="text" name="last_name" id="lastName" required value="{{ old('last_name') }}"
                               class="h-11 w-full rounded-sm border border-neutral-300 px-4 font-sans text-sm text-neutral-900 outline-none transition-colors focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30">
                        <span class="field-error mt-1 block font-sans text-[11.5px] text-[#a5333d]"></span>
                    </div>
                    <div>
                        <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="firstName">First name *</label>
                        <input type="text" name="first_name" id="firstName" required value="{{ old('first_name') }}"
                               class="h-11 w-full rounded-sm border border-neutral-300 px-4 font-sans text-sm text-neutral-900 outline-none transition-colors focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30">
                        <span class="field-error mt-1 block font-sans text-[11.5px] text-[#a5333d]"></span>
                    </div>
                </div>

                <div class="mb-4 grid grid-cols-2 gap-4 max-sm:grid-cols-1">
                    <div>
                        <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="middleInitial">Middle initial</label>
                        <input type="text" name="middle_initial" id="middleInitial" maxlength="2" value="{{ old('middle_initial') }}"
                               class="h-11 w-full rounded-sm border border-neutral-300 px-4 font-sans text-sm text-neutral-900 outline-none transition-colors focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30">
                    </div>
                    <div>
                        <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="sex">Sex *</label>
                        <select name="sex" id="sex" required
                                class="h-11 w-full rounded-sm border border-neutral-300 px-4 font-sans text-sm text-neutral-900 outline-none transition-colors focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30">
                            <option value="" disabled {{ old('sex') ? '' : 'selected' }}>Select</option>
                            <option value="male" {{ old('sex') === 'male' ? 'selected' : '' }}>Male</option>
                            <option value="female" {{ old('sex') === 'female' ? 'selected' : '' }}>Female</option>
                        </select>
                        <span class="field-error mt-1 block font-sans text-[11.5px] text-[#a5333d]"></span>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="email">E-mail *</label>
                    <input type="email" name="email" id="email" required value="{{ old('email') }}"
                           class="h-11 w-full rounded-sm border border-neutral-300 px-4 font-sans text-sm text-neutral-900 outline-none transition-colors focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30">
                    <span class="field-error mt-1 block font-sans text-[11.5px] text-[#a5333d]"></span>
                </div>

                <div class="mb-4 grid grid-cols-2 gap-4 max-sm:grid-cols-1">
                    <div>
                        <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="sellerPassword">Password *</label>
                        <div class="relative">
                            <input type="password" name="password" id="sellerPassword" placeholder="At least 8 characters" required minlength="8" autocomplete="new-password"
                                   class="h-11 w-full rounded-sm border border-neutral-300 px-4 pr-10 font-sans text-sm text-neutral-900 outline-none transition-colors focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30">
                            <button type="button" class="absolute right-3 top-1/2 -translate-y-1/2 text-neutral-500 transition-colors hover:text-[#9c5c68]" data-toggle-visibility="sellerPassword" aria-label="Show password">
                                <i data-lucide="eye" width="16" height="16"></i>
                            </button>
                        </div>
                        <span class="field-error mt-1 block font-sans text-[11.5px] text-[#a5333d]"></span>
                    </div>
                    <div>
                        <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="passwordConfirmation">Confirm password *</label>
                        <div class="relative">
                            <input type="password" name="password_confirmation" id="passwordConfirmation" placeholder="Re-enter your password" required autocomplete="new-password"
                                   class="h-11 w-full rounded-sm border border-neutral-300 px-4 pr-10 font-sans text-sm text-neutral-900 outline-none transition-colors focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30">
                            <button type="button" class="absolute right-3 top-1/2 -translate-y-1/2 text-neutral-500 transition-colors hover:text-[#9c5c68]" data-toggle-visibility="passwordConfirmation" aria-label="Show password">
                                <i data-lucide="eye" width="16" height="16"></i>
                            </button>
                        </div>
                        <span class="field-error mt-1 block font-sans text-[11.5px] text-[#a5333d]"></span>
                    </div>
                </div>

                <div class="mb-6 grid grid-cols-[1fr_2fr_70px] gap-4 max-sm:grid-cols-1">
                    <div>
                        <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="contactNo">Contact no. *</label>
                        <input type="tel" name="contact_no" id="contactNo" placeholder="09XXXXXXXXX" required value="{{ old('contact_no') }}"
                               class="h-11 w-full rounded-sm border border-neutral-300 px-4 font-sans text-sm text-neutral-900 outline-none transition-colors focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30">
                        <span class="field-error mt-1 block font-sans text-[11.5px] text-[#a5333d]"></span>
                    </div>
                    <div>
                        <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="birthMonth">Birthday *</label>

                        @php
                            $oldBirthdayParts = old('birthday') ? explode('-', old('birthday')) : [null, null, null];
                        @endphp

                        <div class="grid grid-cols-[1.6fr_0.5fr_0.8fr] gap-2">
                            <select id="birthMonth" aria-label="Birth month"
                                    class="h-11 w-full min-w-0 rounded-sm border border-neutral-300 px-2 font-sans text-[13px] text-neutral-900 outline-none transition-colors focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30">
                                <option value="" disabled selected>Month</option>
                                @foreach(['January','February','March','April','May','June','July','August','September','October','November','December'] as $monthIndex => $monthName)
                                    <option value="{{ sprintf('%02d', $monthIndex + 1) }}" {{ $oldBirthdayParts[1] === sprintf('%02d', $monthIndex + 1) ? 'selected' : '' }}>{{ $monthName }}</option>
                                @endforeach
                            </select>
                            <select id="birthDay" aria-label="Birth day"
                                    class="h-11 w-full min-w-0 rounded-sm border border-neutral-300 px-2 font-sans text-[13px] text-neutral-900 outline-none transition-colors focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30">
                                <option value="" disabled selected>Day</option>
                                @for ($d = 1; $d <= 31; $d++)
                                    <option value="{{ $d }}" {{ (int) ($oldBirthdayParts[2] ?? 0) === $d ? 'selected' : '' }}>{{ $d }}</option>
                                @endfor
                            </select>
                            <select id="birthYear" aria-label="Birth year"
                                    class="h-11 w-full min-w-0 rounded-sm border border-neutral-300 px-2 font-sans text-[13px] text-neutral-900 outline-none transition-colors focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30">
                                <option value="" disabled selected>Year</option>
                                @for ($y = (int) date('Y'); $y >= 1900; $y--)
                                    <option value="{{ $y }}" {{ (int) ($oldBirthdayParts[0] ?? 0) === $y ? 'selected' : '' }}>{{ $y }}</option>
                                @endfor
                            </select>
                        </div>
                        <input type="hidden" name="birthday" id="birthday" value="{{ old('birthday') }}">
                        <span class="field-error mt-1 block font-sans text-[11.5px] text-[#a5333d]" id="birthdayError"></span>
                    </div>
                    <div>
                        <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="sellerAge">Age</label>
                        <input type="text" id="sellerAge" readonly placeholder="—"
                               class="h-11 w-full rounded-sm border border-neutral-200 bg-neutral-50 px-3 text-center font-sans text-sm text-neutral-500">
                    </div>
                </div>

                <button type="button" id="sellerNextStep1" class="h-11 w-full cursor-pointer rounded-sm bg-neutral-900 font-sans text-[12.5px] font-semibold uppercase tracking-[1.4px] text-white transition-colors hover:bg-[#9c5c68]">
                    Next
                </button>
            </section>

            {{-- ---- Panel 2: Address (sage/tertiary panel, matches buyer modal palette) ---- --}}
            <section class="seller-panel mt-6 hidden" data-panel="2">
                <p class="mb-1 font-sans text-[11px] font-semibold uppercase tracking-[1px] text-neutral-500">Business address</p>
                <p class="mb-4 font-sans text-xs text-neutral-500">Choose your location, then add your exact street address.</p>

                <div class="rounded-lg border border-[#ADC7AD]/50 bg-[#F4F8F4] p-6">
                    <div class="mb-3 flex justify-end">
                        <button type="button" class="font-sans text-xs font-semibold text-[#9c5c68] hover:text-[#7a4550]" id="sellerToggleManualAddress">Enter address manually</button>
                    </div>

                    <div class="grid grid-cols-2 gap-4 max-sm:grid-cols-1" id="sellerApiAddressFields">
                        <div>
                            <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="sellerProvince">Province *</label>
                            <select name="province" id="sellerProvince" required
                                    class="h-11 w-full rounded-sm border border-neutral-300 bg-white px-4 font-sans text-sm text-neutral-900 outline-none focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30">
                                <option value="" disabled selected>Loading provinces...</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="sellerMunicipality">Municipality / city *</label>
                            <select name="municipality" id="sellerMunicipality" required disabled
                                    class="h-11 w-full rounded-sm border border-neutral-300 bg-white px-4 font-sans text-sm text-neutral-900 outline-none focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30 disabled:bg-neutral-100 disabled:text-neutral-400">
                                <option value="" disabled selected>Select province first</option>
                            </select>
                        </div>
                        <div class="col-span-2 max-sm:col-span-1">
                            <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="sellerBarangay">Barangay *</label>
                            <select name="barangay" id="sellerBarangay" required disabled
                                    class="h-11 w-full rounded-sm border border-neutral-300 bg-white px-4 font-sans text-sm text-neutral-900 outline-none focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30 disabled:bg-neutral-100 disabled:text-neutral-400">
                                <option value="" disabled selected>Select municipality first</option>
                            </select>
                        </div>
                    </div>

                    <div class="mt-4 hidden grid grid-cols-1 gap-4" id="sellerManualAddressFields">
                        <div class="grid grid-cols-2 gap-4 max-sm:grid-cols-1">
                            <div>
                                <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="sellerManualProvince">Province</label>
                                <input type="text" id="sellerManualProvince" placeholder="Province name" value="{{ old('province_name') }}"
                                       class="h-11 w-full rounded-sm border border-neutral-300 bg-white px-4 font-sans text-sm text-neutral-900 outline-none focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30">
                            </div>
                            <div>
                                <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="sellerManualMunicipality">Municipality / City</label>
                                <input type="text" id="sellerManualMunicipality" placeholder="Municipality or city" value="{{ old('municipality_name') }}"
                                       class="h-11 w-full rounded-sm border border-neutral-300 bg-white px-4 font-sans text-sm text-neutral-900 outline-none focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30">
                            </div>
                            <div class="col-span-2 max-sm:col-span-1">
                                <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="sellerManualBarangay">Barangay</label>
                                <input type="text" id="sellerManualBarangay" placeholder="Barangay name" value="{{ old('barangay_name') }}"
                                       class="h-11 w-full rounded-sm border border-neutral-300 bg-white px-4 font-sans text-sm text-neutral-900 outline-none focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30">
                            </div>
                        </div>
                    </div>

                    {{-- Street / house number — always visible in BOTH modes. --}}
                    <div class="mt-4 grid grid-cols-1 gap-4">
                        <div>
                            <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="sellerStreet">Street / house number *</label>
                            <input type="text" name="street" id="sellerStreet" required
                                   placeholder="e.g. 123 Rizal St., Purok 2"
                                   value="{{ old('street') }}"
                                   class="h-11 w-full rounded-sm border border-neutral-300 bg-white px-4 font-sans text-sm text-neutral-900 outline-none focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30">
                            <span class="field-error mt-1 block font-sans text-[11.5px] text-[#a5333d]" id="sellerStreetError"></span>
                        </div>
                        <div>
                            <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="sellerAddressDetail">Additional details</label>
                            <input type="text" name="address_detail" id="sellerAddressDetail"
                                   placeholder="Building, unit, landmark, etc."
                                   value="{{ old('address_detail') }}"
                                   class="h-11 w-full rounded-sm border border-neutral-300 bg-white px-4 font-sans text-sm text-neutral-900 outline-none focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30">
                        </div>
                    </div>
                    <span class="field-error mt-3 block font-sans text-[#a5333d] font-sans text-[11.5px]" id="sellerAddressError"></span>
                </div>

                <div class="mt-6 flex gap-3">
                    <button type="button" id="sellerBackStep2" class="h-11 flex-1 cursor-pointer rounded-sm border border-neutral-300 font-sans text-[12.5px] font-semibold uppercase tracking-[1.4px] text-neutral-700 transition-colors hover:bg-neutral-50">Back</button>
                    <button type="button" id="sellerNextStep2" class="h-11 flex-[2] cursor-pointer rounded-sm bg-neutral-900 font-sans text-[12.5px] font-semibold uppercase tracking-[1.4px] text-white transition-colors hover:bg-[#9c5c68]">Next</button>
                </div>
            </section>

            {{-- ---- Panel 3: Business & Documents ---- --}}
            <section class="seller-panel mt-6 hidden" data-panel="3">
                <p class="mb-1 font-sans text-[11px] font-semibold uppercase tracking-[1px] text-neutral-500">Business & Documents</p>
                <p class="mb-4 font-sans text-xs text-neutral-500">Provide the details of your store and the required documents for admin review.</p>

                <div class="mb-5">
                    <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="sellerBusinessName">Business / store name *</label>
                    <input type="text" name="business_name" id="sellerBusinessName" required value="{{ old('business_name') }}"
                           class="h-11 w-full rounded-sm border border-neutral-300 px-4 font-sans text-sm text-neutral-900 outline-none transition-colors focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30">
                    <span class="field-error mt-1 block font-sans text-[11.5px] text-[#a5333d]" id="server-business_name"></span>
                </div>

                <div class="mb-4">
                    <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="sellerLineOfBusiness">Primary category *</label>
                    <select name="line_of_business_id" id="sellerLineOfBusiness" required
                            class="h-11 w-full rounded-sm border border-neutral-300 px-4 font-sans text-sm text-neutral-900 outline-none transition-colors focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30">
                        <option value="" disabled {{ old('line_of_business_id') ? '' : 'selected' }}>Select a category</option>
                        @php $categories = \App\Models\Shared\Category::all(); @endphp
                        @foreach($categories as $cat)
                            <option value="{{ $cat->id }}" {{ old('line_of_business_id') == $cat->id ? 'selected' : '' }}>{{ $cat->label }}</option>
                        @endforeach
                    </select>
                    <span class="field-error mt-1 block font-sans text-[11.5px] text-[#a5333d]" id="server-line_of_business_id"></span>
                </div>

                <div class="mb-5 rounded-sm bg-[#F7D6D0]/50 px-4 py-3 font-sans text-xs text-[#7a4550]">
                    After you submit this form, our team will verify your details and notify you via email. Please allow some time for review.
                </div>

                <div class="mb-5">
                    <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="sellerUploadId">Upload valid ID *</label>
                    <div class="mb-1 flex items-center gap-3 rounded-sm border border-dashed border-neutral-300 bg-neutral-50 p-4 transition-colors hover:border-[#9c5c68]">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-sm bg-white text-[#9c5c68] shadow-sm"><i data-lucide="upload" width="18" height="18"></i></span>
                        <span>
                            <span class="block font-sans text-sm font-semibold text-neutral-800" id="sellerUploadIdName">Choose a valid ID</span>
                            <span class="block font-sans text-xs text-neutral-500">JPG, PNG, or PDF · Max 5MB</span>
                        </span>
                    </div>
                    <input type="file" name="upload_id" id="sellerUploadId" accept="image/*,.pdf" required class="hidden">
                    <span class="field-error mt-1 block font-sans text-[11.5px] text-[#a5333d]" id="server-upload_id"></span>
                </div>

                <div class="mb-6">
                    <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="sellerBusinessPermit">Upload business permit *</label>
                    <div class="mb-1 flex items-center gap-3 rounded-sm border border-dashed border-neutral-300 bg-neutral-50 p-4 transition-colors hover:border-[#9c5c68]">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-sm bg-white text-[#9c5c68] shadow-sm"><i data-lucide="file-text" width="18" height="18"></i></span>
                        <span>
                            <span class="block font-sans text-sm font-semibold text-neutral-800" id="sellerBusinessPermitName">Choose a business permit</span>
                            <span class="block font-sans text-xs text-neutral-500">JPG, PNG, or PDF · Max 5MB</span>
                        </span>
                    </div>
                    <input type="file" name="business_permit" id="sellerBusinessPermit" accept="image/*,.pdf" required class="hidden">
                    <span class="field-error mt-1 block font-sans text-[11.5px] text-[#a5333d]" id="server-business_permit"></span>
                </div>

                <div class="flex gap-3">
                    <button type="button" id="sellerBackStep3" class="h-11 flex-1 cursor-pointer rounded-sm border border-neutral-300 font-sans text-[12.5px] font-semibold uppercase tracking-[1.4px] text-neutral-700 transition-colors hover:bg-neutral-50">Back</button>
                    <button type="button" id="submitSellerRegistration" class="h-11 flex-[2] cursor-pointer rounded-sm bg-neutral-900 font-sans text-[12.5px] font-semibold uppercase tracking-[1.4px] text-white transition-colors hover:bg-[#9c5c68]">Submit</button>
                </div>
            </section>

        {{-- ---- Panel 4: OTP (separate small form — registration already submitted by here) ---- --}}
        <section class="seller-panel mt-6 hidden" data-panel="4">
            <p class="mb-1 font-sans text-[11px] font-semibold uppercase tracking-[1px] text-neutral-500">Verify your email</p>
            <p class="mb-5 font-sans text-xs text-neutral-500">We sent a 6-digit code to <strong class="text-neutral-700" id="sellerOtpEmail">your email</strong>.</p>

            <div class="mb-4 hidden rounded-sm border border-[#e8a49c] bg-[#fbe9e7] px-4 py-3 font-sans text-sm text-[#a5333d]" id="sellerOtpErrorSummary"></div>

            <form id="sellerOtpForm" novalidate>
                <input type="hidden" id="sellerOtpId" value="">
                <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600">Verification code *</label>
                <div class="mb-1 flex justify-between gap-2" id="sellerOtpInputs">
                    @for($i = 0; $i < 6; $i++)
                        <input type="text" inputmode="numeric" pattern="\d" maxlength="1" data-otp-index="{{ $i }}" {{ $i === 0 ? 'autocomplete=one-time-code' : '' }}
                               class="seller-otp-input h-12 w-full rounded-sm border border-neutral-300 text-center font-sans text-lg font-semibold text-neutral-900 outline-none transition-colors focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30">
                    @endfor
                </div>
                <input type="hidden" id="sellerOtpHidden" value="">
                <span class="mb-5 block font-sans text-[11.5px] text-[#a5333d]" id="sellerOtpFieldError"></span>

                <p class="mb-6 font-sans text-xs text-neutral-500">
                    Didn't get a code?
                    <button type="button" class="font-semibold text-[#9c5c68] hover:text-[#7a4550]" id="sellerOtpResendBtn">Resend code</button>
                    <span class="ml-1 text-neutral-400" id="sellerOtpResendStatus" aria-live="polite"></span>
                </p>

                <div class="flex gap-3">
                    <button type="button" id="sellerBackStep4" class="h-11 flex-1 cursor-pointer rounded-sm border border-neutral-300 font-sans text-[12.5px] font-semibold uppercase tracking-[1.4px] text-neutral-700 transition-colors hover:bg-neutral-50">Back</button>
                    <button type="submit" id="sellerOtpSubmit" class="h-11 flex-[2] cursor-pointer rounded-sm bg-neutral-900 font-sans text-[12.5px] font-semibold uppercase tracking-[1.4px] text-white transition-colors hover:bg-[#9c5c68]">Verify</button>
                </div>
            </form>
        </section>

        <p class="mt-6 text-center font-sans text-[13px] text-neutral-500">Already have an account? <a href="{{ route('login') }}" class="font-semibold text-[#9c5c68] hover:text-[#7a4550]">Sign in</a></p>
    </div>
</div>

@if ($errors->any())
    <script>window.__sellerModalHasServerErrors = true;</script>
@endif

