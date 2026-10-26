{{--
    resources/views/Components/seller-register-modal.blade.php
    Single modal, 4 steps: Personal -> Address -> Business & Documents -> OTP.
    Mirrors Components/buyer-register-modal.blade.php: same layout,
    stepper, Tailwind styling, exclusive-class state toggles, and
    validation helpers.

    Trigger: <a href="#" data-seller-register-trigger>REGISTER AS SELLER</a>

    Backend contract (unchanged):
      POST {{ route('register.seller.store') }}       -> 201 { seller_id, email }
      POST /register/seller/verify-otp              -> { message } | 422 { message }
      POST /register/seller/verify-otp/resend       -> { message } | 422 { message }

    Behavior: resources/js/seller/seller-register-modal.js
--}}
<div class="seller-modal-overlay" id="sellerModalOverlay" data-psgc-base="{{ url('https://psgc.gitlab.io/api') }}" aria-hidden="true">
    <div class="seller-modal" role="dialog" aria-modal="true" aria-labelledby="sellerModalTitle">

        <button type="button" class="seller-modal__close" id="sellerModalClose" aria-label="Close registration form">
            <i data-lucide="x" width="18" height="18"></i>
        </button>

        {{-- ===== Brand + header (centered, same treatment as the buyer modal) ===== --}}
        <div class="seller-modal__header">
            <div class="flex h-12 w-12 items-center justify-center rounded-full bg-[#F7D6D0] text-[#7a4550] shadow-sm mx-auto" id="sellerModalIcon">
                <i data-lucide="store" width="20" height="20"></i>
            </div>
        </div>
        <h2 class="seller-modal__title" id="sellerModalTitle">Create Your Seller Account</h2>
        <p class="seller-modal__subtitle">Join as a seller and reach customers on Zefanya.</p>

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

            {{-- ---- Panel 1: Personal (shared partial) ---- --}}
            @php
                $oldBirthdayParts = old('birthday') ? explode('-', old('birthday')) : [null, null, null];
            @endphp
            @include('Components.registration-personal-step', ['role' => 'seller', 'oldBirthdayParts' => $oldBirthdayParts])

            {{-- ---- Panel 2: Address (sage/tertiary panel, matches buyer modal palette) ---- --}}
            <section class="seller-panel mt-6 hidden" data-panel="2">
                <p class="mb-1 font-sans text-[11px] font-semibold uppercase tracking-[1px] text-neutral-500">Business address</p>
                <p class="mb-4 font-sans text-xs text-neutral-500">Choose your location, then add your exact street address.</p>

                <div class="rounded-lg border border-[#ADC7AD]/50 bg-[#F4F8F4] p-6">
                    {{-- Province / municipality / barangay: dropdowns only (no free-text). --}}
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

                    {{-- Street / house number — free text only for these fields. --}}
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
                        <div>
                            <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="sellerPostalCode">Postal code</label>
                            <input type="text" name="postal_code" id="sellerPostalCode"
                                   inputmode="numeric" maxlength="10"
                                   placeholder="e.g. 4030"
                                   value="{{ old('postal_code') }}"
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
        </form>

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

        <p class="mt-6 text-center font-sans text-[13px] text-neutral-500">Already have an account? <a href="{{ route('home', ['login' => 1]) }}" class="font-semibold text-[#9c5c68] hover:text-[#7a4550]">Sign in</a></p>
    </div>
</div>

@if ($errors->any())
    <script>window.__sellerModalHasServerErrors = true;</script>
@endif

