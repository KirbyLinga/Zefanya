{{-- Rider registration follows the Buyer modal shell and shared personal step. --}}
@php($riderToday = \App\Support\RiderValidation::today()->toDateString())
<div class="buyer-modal-overlay" id="riderModalOverlay" data-psgc-base="{{ url('https://psgc.gitlab.io/api') }}" data-resume-url="{{ route('api.rider.resume') }}" aria-hidden="true">
    <div class="buyer-modal" role="dialog" aria-modal="true" aria-labelledby="riderModalTitle">
        <button type="button" class="buyer-modal__close" id="riderModalClose" aria-label="Close registration form">
            <i data-lucide="x" width="18" height="18"></i>
        </button>

        <div class="buyer-modal__busy" id="riderModalBusy">
            <div class="buyer-modal__busy-card" role="status" aria-live="polite">
                <span class="buyer-modal__spinner" aria-hidden="true"></span>
                <p class="buyer-modal__busy-title">Submitting your application</p>
                <p class="buyer-modal__busy-copy">Saving your details and sending a verification code…</p>
            </div>
        </div>

        <div class="buyer-modal__header">
            <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-[#F7D6D0] text-[#7a4550] shadow-sm" id="riderModalIcon">
                <i data-lucide="bike" width="20" height="20"></i>
            </div>
        </div>
        <h2 class="buyer-modal__title" id="riderModalTitle">Create Your Rider Account</h2>
        <p class="buyer-modal__subtitle">Four quick steps to submit your Rider application.</p>

        <div class="mt-6 rounded-lg border border-neutral-100 bg-neutral-50 p-4">
            <div class="mb-3 flex items-center justify-between">
                <span class="font-sans text-[11px] font-semibold uppercase tracking-[1.2px] text-neutral-400">Registration progress</span>
                <span class="font-sans text-[11px] font-semibold text-[#9c5c68]">4 steps</span>
            </div>
            <div class="flex items-center" id="riderStepper">
                @foreach(['Personal', 'Address', 'Verification', 'OTP'] as $i => $label)
                    <div class="flex flex-1 items-center {{ $loop->last ? 'flex-none' : '' }}">
                        <div class="flex flex-col items-center gap-1.5">
                            <div class="flex h-8 w-8 items-center justify-center rounded-full border-2 border-neutral-200 bg-white font-sans text-xs font-semibold text-neutral-400 transition-colors" data-rider-step-circle="{{ $i + 1 }}">{{ $i + 1 }}</div>
                            <span class="font-sans text-[11px] font-medium text-neutral-400 max-sm:hidden" data-rider-step-label="{{ $i + 1 }}">{{ $label }}</span>
                        </div>
                        @unless($loop->last)
                            <div class="mx-2 h-0.5 flex-1 bg-neutral-200" data-rider-step-line="{{ $i + 1 }}"></div>
                        @endunless
                    </div>
                @endforeach
            </div>
        </div>

        <p id="riderMessage" class="buyer-modal__error-summary hidden" role="alert" aria-live="polite"></p>

        @php($oldBirthdayParts = old('birthday') ? explode('-', old('birthday')) : [null, null, null])
        <form id="riderRegisterForm" class="buyer-modal__form" method="POST" action="{{ route('register.rider.store') }}" enctype="multipart/form-data" novalidate>
            @csrf
            <input type="hidden" name="address_mode" id="riderAddressMode" data-address-mode value="api">
            <input type="hidden" name="province_name" id="riderProvinceNameField" data-address-province-name>
            <input type="hidden" name="municipality_name" id="riderMunicipalityNameField" data-address-municipality-name>
            <input type="hidden" name="barangay_name" id="riderBarangayNameField" data-address-barangay-name>

            @include('Components.registration-personal-step', ['role' => 'rider', 'oldBirthdayParts' => $oldBirthdayParts])

            {{-- Recovery for an application whose modal was closed or whose OTP
                 never arrived: resume with the email + password already entered
                 above, then request a fresh code. Uses the API resume route, so
                 the old signed OTP links do not have to still be valid. --}}
            <div class="mt-4" data-panel="1">
                <p class="buyer-modal__otp-resend">
                    Already applied?
                    <button type="button" class="buyer-modal__otp-resend-btn" id="riderResumeBtn">Resend code</button>
                </p>
                <span class="buyer-modal__otp-resend-status" id="riderResumeStatus" role="status" aria-live="polite"></span>
            </div>

            <section class="rider-panel hidden" data-panel="2">
                <p class="mb-1 font-sans text-[11px] font-semibold uppercase tracking-[1px] text-neutral-500">Address information</p>
                <p class="mb-4 font-sans text-xs text-neutral-500">Choose your location, then add the street and delivery details.</p>

                <div class="buyer-modal__address">
                    <div class="mb-5 grid grid-cols-2 gap-5 max-sm:grid-cols-1" id="riderApiAddressFields" data-address-api-fields>
                        <div class="buyer-modal__field">
                            <label for="riderProvince">Province *</label>
                            <select name="province" id="riderProvince" data-address-province required data-validate-rule="required"><option value="" disabled selected>Loading provinces...</option></select>
                            <span class="buyer-modal__field-error field-error" data-error-for="riderProvince"></span>
                        </div>
                        <div class="buyer-modal__field">
                            <label for="riderMunicipality">City / Municipality *</label>
                            <select name="municipality" id="riderMunicipality" data-address-municipality required disabled data-validate-rule="required"><option value="" disabled selected>Select province first</option></select>
                            <span class="buyer-modal__field-error field-error" data-error-for="riderMunicipality"></span>
                        </div>
                        <div class="buyer-modal__field col-span-2 max-sm:col-span-1">
                            <label for="riderBarangay">Barangay *</label>
                            <select name="barangay" id="riderBarangay" data-address-barangay required disabled data-validate-rule="required"><option value="" disabled selected>Select municipality first</option></select>
                            <span class="buyer-modal__field-error field-error" data-error-for="riderBarangay"></span>
                        </div>
                    </div>

                    <div class="buyer-modal__field mb-5">
                        <label for="riderStreet">Street address *</label>
                        <input type="text" name="street" id="riderStreet" required placeholder="Street, subdivision, or purok" data-validate-rule="required">
                        <span class="buyer-modal__field-error field-error" data-error-for="riderStreet"></span>
                    </div>
                    <div class="buyer-modal__row buyer-modal__manual-fields">
                        <div class="buyer-modal__field">
                            <label for="riderHouseNumber">House / building number</label>
                            <input type="text" name="house_number" id="riderHouseNumber" placeholder="Optional">
                        </div>
                        <div class="buyer-modal__field">
                            <label for="riderAddressDetail">Landmark / address details</label>
                            <input type="text" name="address_detail" id="riderAddressDetail" placeholder="Optional">
                        </div>
                        <div class="buyer-modal__field">
                            <label for="riderPostalCode">Postal code</label>
                            <input type="text" name="postal_code" id="riderPostalCode" inputmode="numeric" maxlength="10" placeholder="Optional">
                            <span class="buyer-modal__field-error field-error" data-error-for="riderPostalCode"></span>
                        </div>
                    </div>
                </div>

                <div class="mt-6 flex gap-3">
                    <button type="button" id="riderBackStep2" class="h-11 flex-1 cursor-pointer rounded-sm border border-neutral-300 font-sans text-[12.5px] font-semibold uppercase tracking-[1.4px] text-neutral-700 transition-colors hover:bg-neutral-50">Back</button>
                    <button type="button" id="riderNextStep2" class="h-11 flex-[2] cursor-pointer rounded-sm bg-neutral-900 font-sans text-[12.5px] font-semibold uppercase tracking-[1.4px] text-white transition-colors hover:bg-[#9c5c68]">Next</button>
                </div>
            </section>

            <section class="rider-panel hidden" data-panel="3">
                <p class="mb-1 font-sans text-[11px] font-semibold uppercase tracking-[1px] text-neutral-500">Rider verification</p>
                <p class="mb-4 font-sans text-xs text-neutral-500">Provide the required vehicle and identity documents for Logistics review.</p>

                <div class="mb-5 grid grid-cols-2 gap-5 max-sm:grid-cols-1">
                    <div class="buyer-modal__field">
                        <label for="riderLicenseNo">Driver license number *</label>
                        <input type="text" name="license_no" id="riderLicenseNo" required placeholder="A00-00-000000" data-validate-rule="drivers-license">
                        <span class="buyer-modal__field-error field-error" data-error-for="riderLicenseNo"></span>
                    </div>
                    <div class="buyer-modal__field">
                        <label for="riderLicenseExpiry">License expiry *</label>
                        <input type="date" name="license_expiry" id="riderLicenseExpiry" required min="{{ $riderToday }}" data-today="{{ $riderToday }}" data-validate-rule="license-expiry">
                        <span class="buyer-modal__field-error field-error" data-error-for="riderLicenseExpiry"></span>
                    </div>
                    <div class="buyer-modal__field">
                        <label for="riderVehicleType">Vehicle type *</label>
                        <select name="vehicle_type" id="riderVehicleType" required data-validate-rule="required">
                            <option value="" disabled selected>Select vehicle type</option>
                            @foreach(['Motorcycle', 'Car', 'Van'] as $vehicle)<option value="{{ $vehicle }}">{{ $vehicle }}</option>@endforeach
                        </select>
                        <span class="buyer-modal__field-error field-error" data-error-for="riderVehicleType"></span>
                    </div>
                    <div class="buyer-modal__field">
                        <label for="riderPlateNumber">Plate number *</label>
                        <input type="text" name="plate_number" id="riderPlateNumber" required data-validate-rule="required">
                        <span class="buyer-modal__field-error field-error" data-error-for="riderPlateNumber"></span>
                    </div>
                    <div class="buyer-modal__field col-span-2 max-sm:col-span-1">
                        <label for="riderLogisticsProvider">Logistics provider *</label>
                        <select name="logistics_provider_id" id="riderLogisticsProvider" required data-validate-rule="required">
                            <option value="" disabled selected>Select logistics provider</option>
                            @foreach($riderProviders as $provider)<option value="{{ $provider->id }}">{{ $provider->business_name }}</option>@endforeach
                        </select>
                        @if($riderProviders->isEmpty())<p class="mt-2 rounded-sm bg-[#F7D6D0]/50 px-4 py-3 font-sans text-xs text-[#7a4550]">No logistics providers are currently available.</p>@endif
                        <span class="buyer-modal__field-error field-error" data-error-for="riderLogisticsProvider"></span>
                    </div>
                </div>

                <div class="mb-5 grid grid-cols-1 gap-5">
                    @foreach(['drivers_license' => 'Driver license document', 'or_cr' => 'Vehicle OR/CR document'] as $name => $label)
                        <div class="buyer-modal__field">
                            <label class="buyer-modal__label-plain">{{ $label }} *</label>
                            <div class="buyer-modal__file-wrap">
                                <label for="rider-{{ $name }}" class="buyer-modal__file-btn"><i data-lucide="upload" width="16" height="16"></i>Choose file</label>
                                <span class="buyer-modal__file-name" id="rider-{{ $name }}Name">No file selected</span>
                            </div>
                            <input type="file" name="{{ $name }}" id="rider-{{ $name }}" accept="image/jpeg,image/png,application/pdf,.jpg,.jpeg,.png,.pdf" required data-validate-rule="doc-file" class="buyer-modal__file-input">
                            <span class="buyer-modal__field-error field-error" data-error-for="rider-{{ $name }}"></span>
                            <span class="mt-1 font-sans text-[11.5px] text-neutral-500">JPG, JPEG, PNG, or PDF · Max 5 MB</span>
                        </div>
                    @endforeach
                </div>

                <div class="mb-5 rounded-sm border border-neutral-100 bg-neutral-50 p-4">
                    <p class="mb-3 font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-500">Review your information</p>
                    <dl class="grid grid-cols-2 gap-x-5 gap-y-3 font-sans text-xs max-sm:grid-cols-1">
                        @foreach(['riderReviewName' => 'Name', 'riderReviewEmail' => 'Email', 'riderReviewContact' => 'Contact', 'riderReviewAddress' => 'Address', 'riderReviewVehicle' => 'Vehicle', 'riderReviewPlate' => 'Plate number', 'riderReviewProvider' => 'Logistics provider'] as $id => $label)
                            <div><dt class="font-semibold text-neutral-500">{{ $label }}</dt><dd id="{{ $id }}" class="mt-0.5 break-words text-neutral-900">-</dd></div>
                        @endforeach
                    </dl>
                </div>

                <p class="buyer-modal__notice mb-5">After email verification, your Rider registration will be pending Logistics approval. Rider delivery operations are mobile-only.</p>
                <div class="mb-6">
                    <div class="flex items-start gap-3">
                        <input type="checkbox" name="terms" id="riderTerms" value="1" required data-validate-rule="terms" class="mt-0.5 h-4 w-4 shrink-0 cursor-pointer rounded-sm border-neutral-300 text-[#9c5c68] accent-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30">
                        <p class="font-sans text-xs leading-5 text-neutral-700">
                            <label for="riderTerms" class="cursor-pointer">I agree to the</label>
                            <a href="{{ route('buyer.pages.terms-of-service') }}" target="_blank" rel="noopener noreferrer" class="font-semibold text-[#9c5c68] underline-offset-2 hover:underline">Terms of Service</a>
                            <label for="riderTerms" class="cursor-pointer">and</label>
                            <a href="{{ route('buyer.pages.privacy-policy') }}" target="_blank" rel="noopener noreferrer" class="font-semibold text-[#9c5c68] underline-offset-2 hover:underline">Privacy Policy</a>.
                        </p>
                    </div>
                    <span class="buyer-modal__field-error field-error" data-error-for="riderTerms"></span>
                </div>

                <div class="flex gap-3">
                    <button type="button" id="riderBackStep3" class="h-11 flex-1 cursor-pointer rounded-sm border border-neutral-300 font-sans text-[12.5px] font-semibold uppercase tracking-[1.4px] text-neutral-700 transition-colors hover:bg-neutral-50">Back</button>
                    <button type="submit" id="riderSubmitRegistration" data-rider-register @disabled($riderProviders->isEmpty()) class="buyer-modal__submit h-11 flex-[2]">Next</button>
                </div>
            </section>
        </form>

        <section class="rider-panel mt-6 hidden" data-panel="4" id="riderOtpPanel">
            <p class="mb-1 font-sans text-[11px] font-semibold uppercase tracking-[1px] text-neutral-500">Verify your email</p>
            <p class="mb-5 font-sans text-xs text-neutral-500">We sent a 6-digit code to <strong class="text-neutral-700" id="riderOtpEmail">your email</strong>.</p>
            <div class="buyer-modal__error-summary hidden" id="riderOtpErrorSummary" role="alert" aria-live="polite"></div>
            <form id="riderOtpForm" novalidate>
                <label class="buyer-modal__label-plain mb-2 block">Verification code *</label>
                <div class="buyer-otp-inputs" id="riderOtpInputs">
                    @for($i = 0; $i < 6; $i++)
                        <input type="text" inputmode="numeric" pattern="\d" maxlength="1" data-rider-otp-index="{{ $i }}" {{ $i === 0 ? 'autocomplete=one-time-code' : '' }} class="buyer-otp-input">
                    @endfor
                </div>
                <span class="mb-5 block font-sans text-[11.5px] text-[#a5333d]" id="riderOtpFieldError"></span>
                <p class="buyer-modal__otp-resend">
                    Didn't get a code?
                    <button type="button" class="buyer-modal__otp-resend-btn" id="riderOtpResendBtn">Resend code</button>
                    <span class="buyer-modal__otp-resend-status" id="riderOtpResendStatus" aria-live="polite"></span>
                </p>
                <button type="submit" id="riderOtpSubmit" class="buyer-modal__submit mt-4 w-full">Verify</button>
            </form>
            <div id="riderPendingSection" class="buyer-modal__notice mt-5 hidden" role="status" tabindex="-1">Your Rider registration has been submitted. Please wait for Logistics approval.</div>
        </section>

        <p class="mt-6 text-center font-sans text-[13px] text-neutral-500">Already have an account? <a href="{{ route('home', ['login' => 1]) }}" class="font-semibold text-[#9c5c68] hover:text-[#7a4550]">Sign in</a></p>
    </div>
</div>