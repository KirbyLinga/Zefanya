{{-- resources/views/Components/buyer-register-modal.blade.php
     Single modal, 4 steps: Personal -> Address -> Verification (ID) -> OTP.
     Restyled to match login-modal.blade.php: centered brand mark, serif
     (Playfair Display) title, uppercase tracked labels, dark CTA button.
     Palette: "Ethereal Grace"
       primary   #E2B4BD (dusty pink)   — soft accents / progress fill
       primary-d #9C5C68 (darker pink)  — links, focus, active states
       secondary #F7D6D0 (peach)        — notice / info panels
       tertiary  #ADC7AD (sage green)   — address panel
       neutral   #7C7676 (gray)         — secondary text / borders

     Include once, e.g. in Auth/Register-Type.blade.php:
       @include('Components.buyer-register-modal')
     Trigger with:
       <a href="#" data-buyer-register-trigger>REGISTER AS BUYER</a>

     Backend contract unchanged:
       POST {{ route('register.buyer.store') }}  -> { ok, buyer_id, email }  (fires OTP email)
       POST /register/buyer/verify-otp/{buyer}          -> { ok, redirect }
       POST /register/buyer/verify-otp/{buyer}/resend    -> { ok, message } --}}

<div class="buyer-modal-overlay" id="buyerModalOverlay" data-psgc-base="{{ url('https://psgc.gitlab.io/api') }}" aria-hidden="true">
    <div class="buyer-modal" role="dialog" aria-modal="true" aria-labelledby="buyerModalTitle">

        <button type="button" class="buyer-modal__close" id="buyerModalClose" aria-label="Close registration form">
            <i data-lucide="x" width="18" height="18"></i>
        </button>

        <div class="buyer-modal__busy" id="buyerModalBusy">
            <div class="buyer-modal__busy-card" role="status" aria-live="polite">
                <span class="buyer-modal__spinner" aria-hidden="true"></span>
                <p class="buyer-modal__busy-title">Uploading your ID</p>
                <p class="buyer-modal__busy-copy">Creating your account and sending a verification code…</p>
            </div>
        </div>

        {{-- ===== Brand + header (centered, same treatment as the login modal) ===== --}}
        <div class="buyer-modal__header">
            <div class="flex h-12 w-12 items-center justify-center rounded-full bg-[#F7D6D0] text-[#7a4550] shadow-sm mx-auto" id="buyerModalIcon">
                <i data-lucide="shopping-bag" width="20" height="20"></i>
            </div>
        </div>
        <h2 class="buyer-modal__title" id="buyerModalTitle">Create Your Buyer Account</h2>
        <p class="buyer-modal__subtitle">Four quick steps and you're ready to shop.</p>

        {{-- ===== Stepper ===== --}}
        <div class="mt-6 rounded-lg border border-neutral-100 bg-neutral-50 p-4">
            <div class="mb-3 flex items-center justify-between">
                <span class="font-sans text-[11px] font-semibold uppercase tracking-[1.2px] text-neutral-400">Registration progress</span>
                <span class="font-sans text-[11px] font-semibold text-[#9c5c68]">4 steps</span>
            </div>
            <div class="flex items-center" id="buyerStepper">
                @foreach(['Personal','Address','Verification','OTP'] as $i => $label)
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

        @if ($errors->any())
            <div class="mt-4 rounded-sm border border-[#e8a49c] bg-[#fbe9e7] px-4 py-3 font-sans text-[12.5px] text-[#a5333d]">{{ $errors->first() }}</div>
        @endif

        {{-- ===== Steps 1-3: one multipart form so all field values persist across panels ===== --}}
        <form id="buyerRegisterForm" method="POST"
              action="{{ Route::has('register.buyer.store') ? route('register.buyer.store') : '#' }}"
              enctype="multipart/form-data" novalidate>
            @csrf

            <input type="hidden" name="address_mode" id="addressMode" value="{{ old('address_mode', 'api') }}">
            <input type="hidden" name="province_name" id="provinceNameField" value="{{ old('province_name') }}">
            <input type="hidden" name="municipality_name" id="municipalityNameField" value="{{ old('municipality_name') }}">
            <input type="hidden" name="barangay_name" id="barangayNameField" value="{{ old('barangay_name') }}">

            {{-- ---- Panel 1: Personal (shared partial) ---- --}}
            @php
                $oldBirthdayParts = old('birthday') ? explode('-', old('birthday')) : [null, null, null];
            @endphp
            @include('Components.registration-personal-step', ['role' => 'buyer', 'oldBirthdayParts' => $oldBirthdayParts])

            {{-- ---- Panel 2: Address (sage/tertiary panel, matches the palette's tertiary swatch) ---- --}}
            <section class="buyer-panel mt-6 hidden" data-panel="2">
                <p class="mb-1 font-sans text-[11px] font-semibold uppercase tracking-[1px] text-neutral-500">Delivery address</p>
                <p class="mb-4 font-sans text-xs text-neutral-500">Choose your location, then add your exact street address.</p>

                <div class="rounded-lg border border-[#ADC7AD]/50 bg-[#F4F8F4] p-6">
                    {{-- Province / municipality / barangay: dropdowns only (no free-text). --}}
                    <div class="grid grid-cols-2 gap-4 max-sm:grid-cols-1" id="apiAddressFields">
                        <div>
                            <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="province">Province *</label>
                            <select name="province" id="province" required
                                    class="h-11 w-full rounded-sm border border-neutral-300 bg-white px-4 font-sans text-sm text-neutral-900 outline-none focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30">
                                <option value="" disabled selected>Loading provinces...</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="municipality">Municipality / city *</label>
                            <select name="municipality" id="municipality" required disabled
                                    class="h-11 w-full rounded-sm border border-neutral-300 bg-white px-4 font-sans text-sm text-neutral-900 outline-none focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30 disabled:bg-neutral-100 disabled:text-neutral-400">
                                <option value="" disabled selected>Select province first</option>
                            </select>
                        </div>
                        <div class="col-span-2 max-sm:col-span-1">
                            <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="barangay">Barangay *</label>
                            <select name="barangay" id="barangay" required disabled
                                    class="h-11 w-full rounded-sm border border-neutral-300 bg-white px-4 font-sans text-sm text-neutral-900 outline-none focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30 disabled:bg-neutral-100 disabled:text-neutral-400">
                                <option value="" disabled selected>Select municipality first</option>
                            </select>
                        </div>
                    </div>

                    {{-- Street / house number — free text only for these fields. --}}
                    <div class="mt-4 grid grid-cols-1 gap-4">
                        <div>
                            <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="street">Street / house number *</label>
                            <input type="text" name="street" id="street" required
                                   placeholder="e.g. 123 Rizal St., Purok 2"
                                   value="{{ old('street') }}"
                                   class="h-11 w-full rounded-sm border border-neutral-300 bg-white px-4 font-sans text-sm text-neutral-900 outline-none focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30">
                            <span class="field-error mt-1 block font-sans text-[11.5px] text-[#a5333d]" id="streetError"></span>
                        </div>
                        <div>
                            <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="addressDetail">Additional details</label>
                            <input type="text" name="address_detail" id="addressDetail"
                                   placeholder="Building, unit, landmark, etc."
                                   value="{{ old('address_detail') }}"
                                   class="h-11 w-full rounded-sm border border-neutral-300 bg-white px-4 font-sans text-sm text-neutral-900 outline-none focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30">
                        </div>
                        <div>
                            <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="postalCode">Postal code</label>
                            <input type="text" name="postal_code" id="postalCode"
                                   inputmode="numeric" maxlength="10"
                                   placeholder="e.g. 4030"
                                   value="{{ old('postal_code') }}"
                                   class="h-11 w-full rounded-sm border border-neutral-300 bg-white px-4 font-sans text-sm text-neutral-900 outline-none focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30">
                        </div>
                    </div>
                    <span class="field-error mt-3 block font-sans text-[11.5px] text-[#a5333d]" id="addressError"></span>
                </div>

                <div class="mt-6 flex gap-3">
                    <button type="button" id="backStep2" class="h-11 flex-1 cursor-pointer rounded-sm border border-neutral-300 font-sans text-[12.5px] font-semibold uppercase tracking-[1.4px] text-neutral-700 transition-colors hover:bg-neutral-50">Back</button>
                    <button type="button" id="nextStep2" class="h-11 flex-[2] cursor-pointer rounded-sm bg-neutral-900 font-sans text-[12.5px] font-semibold uppercase tracking-[1.4px] text-white transition-colors hover:bg-[#9c5c68]">Next</button>
                </div>
            </section>

            {{-- ---- Panel 3: Verification (ID upload) ---- --}}
            <section class="buyer-panel mt-6 hidden" data-panel="3">
                <p class="mb-1 font-sans text-[11px] font-semibold uppercase tracking-[1px] text-neutral-500">Verify your identity</p>
                <p class="mb-4 font-sans text-xs text-neutral-500">Upload one clear valid ID to help keep Zefanya secure.</p>

                <label for="uploadId" class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600">Upload valid ID *</label>
                <label for="uploadId" class="mb-4 flex cursor-pointer items-center gap-3 rounded-sm border border-dashed border-neutral-300 bg-neutral-50 p-4 transition-colors hover:border-[#9c5c68]">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-sm bg-white text-[#9c5c68] shadow-sm"><i data-lucide="upload" width="18" height="18"></i></span>
                    <span>
                        <span class="block font-sans text-sm font-semibold text-neutral-800" id="uploadIdName">Choose a valid ID</span>
                        <span class="block font-sans text-xs text-neutral-500">JPG, JPEG, PNG or PDF · Max 5MB</span>
                    </span>
                </label>
                <input type="file" name="upload_id" id="uploadId" accept="image/jpeg,image/png,application/pdf,.jpg,.jpeg,.png,.pdf" required class="hidden">
                <span class="field-error mb-4 block font-sans text-[11.5px] text-[#a5333d]"></span>

                <p class="mb-6 rounded-sm bg-[#F7D6D0]/50 px-4 py-3 font-sans text-xs italic text-[#7a4550]">
                    After verifying your email, please wait for the administrator's approval — you'll be notified by email.
                </p>

                <div class="mb-6">
                    <div class="flex items-start gap-3">
                        <input type="checkbox"
                               name="terms"
                               id="buyerTerms"
                               value="1"
                               required
                               data-validate-rule="terms"
                               class="mt-0.5 h-4 w-4 shrink-0 cursor-pointer rounded-sm border-neutral-300 text-[#9c5c68] accent-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30">
                        <p class="font-sans text-xs leading-5 text-neutral-700">
                            <label for="buyerTerms" class="cursor-pointer">I agree to the</label>
                            <a href="{{ Route::has('buyer.pages.terms-of-service') ? route('buyer.pages.terms-of-service') : '#' }}"
                               target="_blank"
                               rel="noopener noreferrer"
                               class="font-semibold text-[#9c5c68] underline-offset-2 hover:underline">Terms of Service</a>
                            <label for="buyerTerms" class="cursor-pointer">and</label>
                            <a href="{{ Route::has('buyer.pages.privacy-policy') ? route('buyer.pages.privacy-policy') : '#' }}"
                               target="_blank"
                               rel="noopener noreferrer"
                               class="font-semibold text-[#9c5c68] underline-offset-2 hover:underline">Privacy Policy</a>.
                        </p>
                    </div>
                    <span class="field-error mt-1 block font-sans text-[11.5px] text-[#a5333d]" data-error-for="buyerTerms"></span>
                </div>

                <div class="flex gap-3">
                    <button type="button" id="backStep3" class="h-11 flex-1 cursor-pointer rounded-sm border border-neutral-300 font-sans text-[12.5px] font-semibold uppercase tracking-[1.4px] text-neutral-700 transition-colors hover:bg-neutral-50">Back</button>
                    <button type="button" id="submitRegistration" class="h-11 flex-[2] cursor-pointer rounded-sm bg-neutral-900 font-sans text-[12.5px] font-semibold uppercase tracking-[1.4px] text-white transition-colors hover:bg-[#9c5c68]">Next</button>
                </div>
            </section>
        </form>

        {{-- ---- Panel 4: OTP (separate small form — registration already submitted by here) ---- --}}
        <section class="buyer-panel mt-6 hidden" data-panel="4">
            <p class="mb-1 font-sans text-[11px] font-semibold uppercase tracking-[1px] text-neutral-500">Verify your email</p>
            <p class="mb-5 font-sans text-xs text-neutral-500">We sent a 6-digit code to <strong class="text-neutral-700" id="buyerOtpEmail">your email</strong>.</p>

            <div class="mb-4 hidden rounded-sm border border-[#e8a49c] bg-[#fbe9e7] px-4 py-3 font-sans text-sm text-[#a5333d]" id="buyerOtpErrorSummary"></div>

            <form id="buyerOtpForm" novalidate>
                <input type="hidden" id="buyerOtpId" value="">
                <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600">Verification code *</label>
                <div class="mb-1 flex justify-between gap-2" id="buyerOtpInputs">
                    @for($i = 0; $i < 6; $i++)
                        <input type="text" inputmode="numeric" pattern="\d" maxlength="1" data-otp-index="{{ $i }}" {{ $i === 0 ? 'autocomplete=one-time-code' : '' }}
                               class="buyer-otp-input h-12 w-full rounded-sm border border-neutral-300 text-center font-sans text-lg font-semibold text-neutral-900 outline-none transition-colors focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30">
                    @endfor
                </div>
                <input type="hidden" id="buyerOtpHidden" value="">
                <span class="mb-5 block font-sans text-[11.5px] text-[#a5333d]" id="buyerOtpFieldError"></span>

                <p class="mb-6 font-sans text-xs text-neutral-500">
                    Didn't get a code?
                    <button type="button" class="font-semibold text-[#9c5c68] hover:text-[#7a4550]" id="buyerOtpResendBtn">Resend code</button>
                    <span class="ml-1 text-neutral-400" id="buyerOtpResendStatus" aria-live="polite"></span>
                </p>

                <div class="flex gap-3">
                    <button type="button" id="backStep4" class="h-11 flex-1 cursor-pointer rounded-sm border border-neutral-300 font-sans text-[12.5px] font-semibold uppercase tracking-[1.4px] text-neutral-700 transition-colors hover:bg-neutral-50">Back</button>
                    <button type="submit" id="buyerOtpSubmit" class="h-11 flex-[2] cursor-pointer rounded-sm bg-neutral-900 font-sans text-[12.5px] font-semibold uppercase tracking-[1.4px] text-white transition-colors hover:bg-[#9c5c68]">Verify</button>
                </div>
            </form>
        </section>

        <p class="mt-6 text-center font-sans text-[13px] text-neutral-500">Already have an account? <a href="{{ route('home', ['login' => 1]) }}" class="font-semibold text-[#9c5c68] hover:text-[#7a4550]">Sign in</a></p>
    </div>
</div>

@if ($errors->any())
    <script>window.__buyerModalHasServerErrors = true;</script>
@endif