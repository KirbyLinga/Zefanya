{{-- R01 shares the existing Logistics modal skin and shared registration steps. --}}
<div class="buyer-modal-overlay fixed inset-0 z-[999] hidden items-center justify-center bg-black/55 p-4 backdrop-blur-[2px] [&.is-open]:flex" id="riderModalOverlay" data-address-prefix="rider" data-psgc-base="https://psgc.gitlab.io/api" aria-hidden="true">
    <div class="relative max-h-[92vh] w-full max-w-[640px] overflow-y-auto rounded-lg border border-neutral-200 bg-white pb-8 pt-10 px-8 shadow-[0_24px_60px_rgba(15,23,42,0.25)] max-sm:px-6 max-sm:pt-8" role="dialog" aria-modal="true" aria-labelledby="riderModalTitle">
        <button type="button" class="absolute right-4 top-4 inline-flex h-8 w-8 cursor-pointer items-center justify-center rounded-full text-neutral-500 transition-colors hover:bg-[#F6DEE2] hover:text-[#7a4550]" id="riderModalClose" aria-label="Close registration form"><i data-lucide="x" width="18" height="18"></i></button>
        <div class="mb-5 flex justify-center"><div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-[#F7D6D0] text-[#7a4550] shadow-sm" id="riderModalIcon"><i data-lucide="bike" width="20" height="20"></i></div></div>
        <h2 class="text-center font-serif text-[26px] font-normal text-neutral-900" id="riderModalTitle">Create Your Rider Account</h2>
        <p class="mt-2 text-center font-sans text-[13.5px] text-neutral-500">Apply to ride with a Zefanya Logistics provider.</p>
        <div class="mt-6 rounded-lg border border-neutral-100 bg-neutral-50 p-4">
            <div class="mb-3 flex items-center justify-between"><span class="font-sans text-[11px] font-semibold uppercase tracking-[1.2px] text-neutral-400">Registration progress</span><span class="font-sans text-[11px] font-semibold text-[#9c5c68]">4 steps</span></div>
            <div class="flex items-center" id="riderStepper">
                @foreach(['Personal', 'Address', 'Vehicle', 'OTP'] as $i => $label)
                    <div class="flex flex-1 items-center {{ $loop->last ? 'flex-none' : '' }}">
                        <div class="flex flex-col items-center gap-1.5"><div data-step-circle="{{ $i + 1 }}" class="flex h-8 w-8 items-center justify-center rounded-full border-2 border-neutral-200 bg-white font-sans text-xs font-semibold text-neutral-400 transition-colors">{{ $i + 1 }}</div><span class="max-sm:hidden font-sans text-[11px] font-medium text-neutral-400">{{ $label }}</span></div>
                        @unless($loop->last)<div data-step-line="{{ $i + 1 }}" class="mx-2 h-0.5 flex-1 bg-neutral-200"></div>@endunless
                    </div>
                @endforeach
            </div>
        </div>
        <div id="riderErrorSummary" role="alert" class="mt-4 hidden rounded-sm border border-[#e8a49c] bg-[#fbe9e7] px-4 py-3 font-sans text-sm text-[#a5333d]"></div>
        <form id="riderRegisterForm" action="{{ route('register.rider.store') }}" method="POST" enctype="multipart/form-data" novalidate>
            @csrf
            <input type="hidden" name="address_mode" id="riderAddressMode" value="api">
            @foreach(['province', 'municipality', 'barangay'] as $part)<input type="hidden" name="{{ $part }}_name" id="rider{{ ucfirst($part) }}NameField">@endforeach
            @include('Components.registration-personal-step', ['role' => 'rider', 'oldBirthdayParts' => [null, null, null]])
            @include('Components.registration-address-step', ['role' => 'rider'])
            <section class="rider-panel mt-6 hidden" data-panel="3">
                <p class="mb-1 font-sans text-[11px] font-semibold uppercase tracking-[1px] text-neutral-500">Vehicle &amp; documents</p>
                <p class="mb-4 font-sans text-xs text-neutral-500">Choose the Logistics provider that will review your application.</p>
                <div class="mb-5">
                    <label for="riderProvider" class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600">Logistics provider *</label>
                    <select id="riderProvider" name="logistics_provider_id" required data-validate-rule="required" class="h-11 w-full rounded-sm border border-neutral-300 bg-white px-4 font-sans text-sm text-neutral-900 outline-none focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30">
                        <option value="">Select an approved provider</option>
                        @foreach($riderProviders as $provider)<option value="{{ $provider->id }}">{{ $provider->business_name }}</option>@endforeach
                    </select>
                    <span class="field-error mt-1 block font-sans text-[11.5px] text-[#a5333d]"></span>
                    @if($riderProviders->isEmpty())<p class="mt-2 font-sans text-xs text-neutral-500">No approved Logistics providers are accepting applications yet.</p>@endif
                </div>
                <div class="mb-5 grid grid-cols-2 gap-4 max-sm:grid-cols-1">
                    <div><label for="riderVehicleType" class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600">Vehicle type *</label><select id="riderVehicleType" name="vehicle_type" required data-validate-rule="required" class="h-11 w-full rounded-sm border border-neutral-300 bg-white px-4 font-sans text-sm outline-none focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30"><option value="">Choose vehicle type</option>@foreach(\App\Models\Courier::VEHICLE_TYPES as $vehicle)<option value="{{ $vehicle }}">{{ ucfirst($vehicle) }}</option>@endforeach</select><span class="field-error mt-1 block font-sans text-[11.5px] text-[#a5333d]"></span></div>
                    <div><label for="riderPlateNumber" class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600">Plate number *</label><input id="riderPlateNumber" name="plate_number" required data-validate-rule="required" class="h-11 w-full rounded-sm border border-neutral-300 px-4 font-sans text-sm outline-none focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30"><span class="field-error mt-1 block font-sans text-[11.5px] text-[#a5333d]"></span></div>
                </div>
                @foreach(['upload_id' => ['riderUploadId', "Valid ID or driver's license"], 'or_cr_upload' => ['riderOrCr', 'Vehicle OR/CR']] as $name => [$id, $label])
                    <div class="mb-5"><label for="{{ $id }}" class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600">{{ $label }} *</label><label for="{{ $id }}" class="mb-1 flex cursor-pointer items-center gap-3 rounded-sm border border-dashed border-neutral-300 bg-neutral-50 p-4 transition-colors hover:border-[#9c5c68]"><span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-sm bg-white text-[#9c5c68] shadow-sm"><i data-lucide="upload" width="18" height="18"></i></span><span><span class="block font-sans text-sm font-semibold text-neutral-800" data-file-name="{{ $id }}">Choose a document</span><span class="block font-sans text-xs text-neutral-500">JPG, JPEG, PNG or PDF ? Max 5MB</span></span></label><input type="file" id="{{ $id }}" name="{{ $name }}" accept=".jpg,.jpeg,.png,.pdf" required data-validate-rule="doc-file" class="hidden"><span class="field-error mt-1 block font-sans text-[11.5px] text-[#a5333d]" data-error-for="{{ $id }}"></span></div>
                @endforeach
                <div class="mb-6"><div class="flex items-start gap-3"><input type="checkbox" name="terms" id="riderTerms" value="1" required data-validate-rule="terms" class="mt-0.5 h-4 w-4 shrink-0 cursor-pointer rounded-sm border-neutral-300 accent-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30"><p class="font-sans text-xs leading-5 text-neutral-700"><label for="riderTerms">I agree to the</label> <a href="{{ route('buyer.pages.terms-of-service') }}" target="_blank" rel="noopener noreferrer" class="font-semibold text-[#9c5c68] hover:underline">Terms of Service</a> and <a href="{{ route('buyer.pages.privacy-policy') }}" target="_blank" rel="noopener noreferrer" class="font-semibold text-[#9c5c68] hover:underline">Privacy Policy</a>.</p></div><span class="field-error mt-1 block font-sans text-[11.5px] text-[#a5333d]" data-error-for="riderTerms"></span></div>
                <div class="flex gap-3"><button type="button" id="riderBackStep3" class="h-11 flex-1 cursor-pointer rounded-sm border border-neutral-300 font-sans text-[12.5px] font-semibold uppercase tracking-[1.4px] text-neutral-700 transition-colors hover:bg-neutral-50">Back</button><button type="submit" id="riderSubmit" class="h-11 flex-[2] cursor-pointer rounded-sm bg-neutral-900 font-sans text-[12.5px] font-semibold uppercase tracking-[1.4px] text-white transition-colors hover:bg-[#9c5c68] disabled:opacity-50">Submit</button></div>
            </section>
        </form>
        <section class="rider-panel mt-6 hidden" data-panel="4">
            <p class="mb-1 font-sans text-[11px] font-semibold uppercase tracking-[1px] text-neutral-500">Verify your email</p><p class="mb-5 font-sans text-xs text-neutral-500">We sent a 6-digit code to <strong id="riderOtpEmail">your email</strong>.</p>
            <form id="riderOtpForm" novalidate>
                @csrf
                <label for="riderOtp0" class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600">Verification code *</label>
                <div class="mb-5 flex justify-between gap-2">@for($i = 0; $i < 6; $i++)<input id="riderOtp{{ $i }}" aria-label="Code digit {{ $i + 1 }}" data-otp-index="{{ $i }}" inputmode="numeric" maxlength="1" @if($i === 0) autocomplete="one-time-code" @endif class="h-12 w-full min-w-0 rounded-sm border border-neutral-300 text-center font-sans text-lg font-semibold outline-none focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30">@endfor</div>
                <p class="mb-6 font-sans text-xs text-neutral-500">Didn't get a code? <button type="button" id="riderOtpResend" class="font-semibold text-[#9c5c68]">Resend code</button><span id="riderOtpStatus" aria-live="polite"></span></p>
                <button type="submit" class="h-11 w-full cursor-pointer rounded-sm bg-neutral-900 font-sans text-[12.5px] font-semibold uppercase tracking-[1.4px] text-white transition-colors hover:bg-[#9c5c68] disabled:opacity-50">Verify</button>
            </form>
        </section>
        <p class="mt-6 text-center font-sans text-[13px] text-neutral-500">Already have an account? <a href="{{ route('home', ['login' => 1]) }}" class="font-semibold text-[#9c5c68]">Sign in</a></p>
    </div>
</div>
