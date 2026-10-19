{{--
    resources/views/Components/registration-personal-step.blade.php
    Shared Step 1 (Personal Information) for buyer, seller, and logistics registration.

    Usage:
    @include('Components.registration-personal-step', [
        'role' => 'buyer|seller|logistics',
        'oldBirthdayParts' => explode('-', old('birthday') ?: 'null-null-null'),
    ])

    Supports:
    - Password eye toggles (client-side, via shared JS)
    - Live password strength meter (4 bars, client-side)
    - Live field validation (on blur, then on input after touched)
    - Birthday dropdowns with leap year handling
    - Age calculation and display
    - Contact number formatting (09XXXXXXXXX)

    Roles determine:
    - Input ID prefixes (e.g., firstName vs sellerFirstName vs logisticsFirstName)
    - Password requirements for the strength meter
--}}

@php
    $role = $role ?? 'buyer';
    $oldBirthdayParts = $oldBirthdayParts ?? [null, null, null];

    // Map role to input ID prefix and form fields
    $roleConfig = [
        'rider' => ['prefix' => 'rider', 'hasTerms' => false, 'passwordReqsLevel' => 'strict'],
        'buyer' => [
            'prefix' => '',
            'hasTerms' => false,
            'passwordReqsLevel' => 'strict', // uppercase + lowercase + digit + special
        ],
        'seller' => [
            'prefix' => 'seller',
            'hasTerms' => false,
            'passwordReqsLevel' => 'strict', // same as buyer
        ],
        'logistics' => [
            'prefix' => 'logistics',
            'hasTerms' => false,
            'passwordReqsLevel' => 'basic', // only min 8 chars, no special required
        ],
    ];

    $config = $roleConfig[$role] ?? $roleConfig['buyer'];
    $prefix = $config['prefix'];
    $firstNameId = $prefix ? "{$prefix}FirstName" : 'firstName';
    $lastNameId = $prefix ? "{$prefix}LastName" : 'lastName';
    $middleInitialId = $prefix ? "{$prefix}MiddleInitial" : 'middleInitial';
    $sexId = $prefix ? "{$prefix}Sex" : 'sex';
    $emailId = $prefix ? "{$prefix}Email" : 'email';
    $passwordId = $prefix ? "{$prefix}Password" : 'password';
    $passwordConfirmationId = $prefix ? "{$prefix}PasswordConfirmation" : 'passwordConfirmation';
    $contactNoId = $prefix ? "{$prefix}ContactNo" : 'contactNo';
    $ageId = $prefix ? "{$prefix}Age" : 'age';
    $birthMonthId = $prefix ? "{$prefix}BirthMonth" : 'birthMonth';
    $birthDayId = $prefix ? "{$prefix}BirthDay" : 'birthDay';
    $birthYearId = $prefix ? "{$prefix}BirthYear" : 'birthYear';
    $birthdayId = $prefix ? "{$prefix}Birthday" : 'birthday';
@endphp

<section class="{{ $role }}-panel mt-6" data-panel="1">
    <p class="mb-4 font-sans text-[11px] font-semibold uppercase tracking-[1px] text-neutral-500">Personal information</p>

    {{-- ===== First Name & Last Name ===== --}}
    <div class="mb-4 grid grid-cols-2 gap-4 max-sm:grid-cols-1">
        <div>
            <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="{{ $firstNameId }}">First name *</label>
            <input type="text" name="first_name" id="{{ $firstNameId }}" required value="{{ old('first_name') }}"
                   class="h-11 w-full rounded-sm border border-neutral-300 px-4 font-sans text-sm text-neutral-900 outline-none transition-colors focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30 data-[error]:border-[#e74c3c]"
                   data-validate-rule="letters-spaces-hyphens-apostrophes">
            <span class="field-error mt-1 block font-sans text-[11.5px] text-[#a5333d]"></span>
        </div>
        <div>
            <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="{{ $lastNameId }}">Last name *</label>
            <input type="text" name="last_name" id="{{ $lastNameId }}" required value="{{ old('last_name') }}"
                   class="h-11 w-full rounded-sm border border-neutral-300 px-4 font-sans text-sm text-neutral-900 outline-none transition-colors focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30 data-[error]:border-[#e74c3c]"
                   data-validate-rule="letters-spaces-hyphens-apostrophes">
            <span class="field-error mt-1 block font-sans text-[11.5px] text-[#a5333d]"></span>
        </div>
    </div>

    {{-- ===== Middle Initial & Sex ===== --}}
    <div class="mb-4 grid grid-cols-2 gap-4 max-sm:grid-cols-1">
        <div>
            <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="{{ $middleInitialId }}">Middle initial</label>
            <input type="text" name="middle_initial" id="{{ $middleInitialId }}" maxlength="2" value="{{ old('middle_initial') }}"
                   class="h-11 w-full rounded-sm border border-neutral-300 px-4 font-sans text-sm text-neutral-900 outline-none transition-colors focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30"
                   data-validate-rule="letter-max-2">
        </div>
        <div>
            <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="{{ $sexId }}">Sex *</label>
            <select name="sex" id="{{ $sexId }}" required
                    class="h-11 w-full rounded-sm border border-neutral-300 px-4 font-sans text-sm text-neutral-900 outline-none transition-colors focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30 data-[error]:border-[#e74c3c]"
                    data-validate-rule="required">
                <option value="" disabled {{ old('sex') ? '' : 'selected' }}>Select</option>
                <option value="male" {{ old('sex') === 'male' ? 'selected' : '' }}>Male</option>
                <option value="female" {{ old('sex') === 'female' ? 'selected' : '' }}>Female</option>
            </select>
            <span class="field-error mt-1 block font-sans text-[11.5px] text-[#a5333d]"></span>
        </div>
    </div>

    {{-- ===== Email ===== --}}
    <div class="mb-4">
        <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="{{ $emailId }}">E-mail *</label>
        <input type="email" name="email" id="{{ $emailId }}" required value="{{ old('email') }}"
               class="h-11 w-full rounded-sm border border-neutral-300 px-4 font-sans text-sm text-neutral-900 outline-none transition-colors focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30 data-[error]:border-[#e74c3c]"
               data-validate-rule="email">
        <span class="field-error mt-1 block font-sans text-[11.5px] text-[#a5333d]"></span>
    </div>

    {{-- ===== Password & Confirm Password ===== --}}
    <div class="mb-4 grid grid-cols-2 gap-4 max-sm:grid-cols-1">
        <div>
            <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="{{ $passwordId }}">Password *</label>
            <div class="relative">
                <input type="password" name="password" id="{{ $passwordId }}" placeholder="At least 8 characters" required minlength="8" autocomplete="new-password"
                       class="h-11 w-full rounded-sm border border-neutral-300 px-4 pr-10 font-sans text-sm text-neutral-900 outline-none transition-colors focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30 data-[error]:border-[#e74c3c]"
                       data-validate-rule="password"
                       data-password-level="{{ $config['passwordReqsLevel'] }}">
                <button type="button" class="absolute right-3 top-1/2 -translate-y-1/2 text-neutral-500 transition-colors hover:text-[#9c5c68]"
                        data-toggle-visibility="{{ $passwordId }}" aria-label="Show password">
                    <i data-lucide="eye" width="16" height="16"></i>
                </button>
            </div>

            {{-- Password Strength Meter --}}
            <div class="mt-2 flex gap-1" data-password-meter="{{ $passwordId }}">
                <div class="h-1.5 flex-1 rounded-full bg-neutral-200 transition-colors" data-bar="1"></div>
                <div class="h-1.5 flex-1 rounded-full bg-neutral-200 transition-colors" data-bar="2"></div>
                <div class="h-1.5 flex-1 rounded-full bg-neutral-200 transition-colors" data-bar="3"></div>
                <div class="h-1.5 flex-1 rounded-full bg-neutral-200 transition-colors" data-bar="4"></div>
            </div>
            <div class="mt-1 flex items-center justify-between">
                <span class="font-sans text-[11px] text-neutral-500" data-password-label="{{ $passwordId }}"></span>
                <span class="font-sans text-[11px] text-[#a5333d]" data-password-hint="{{ $passwordId }}"></span>
            </div>

            {{-- Password Weakness Warning --}}
            <div class="mt-2 hidden rounded-sm border border-[#f39c12]/50 bg-[#fef3e2] px-3 py-2 font-sans text-[11px] text-[#d68910]"
                 data-password-warning="{{ $passwordId }}" role="alert">
            </div>

            <span class="field-error mt-1 block font-sans text-[11.5px] text-[#a5333d]"></span>
        </div>

        <div>
            <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="{{ $passwordConfirmationId }}">Confirm password *</label>
            <div class="relative">
                <input type="password" name="password_confirmation" id="{{ $passwordConfirmationId }}" placeholder="Re-enter your password" required autocomplete="new-password"
                       class="h-11 w-full rounded-sm border border-neutral-300 px-4 pr-10 font-sans text-sm text-neutral-900 outline-none transition-colors focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30 data-[error]:border-[#e74c3c]"
                       data-validate-rule="password-confirm"
                       data-password-confirm-for="{{ $passwordId }}">
                <button type="button" class="absolute right-3 top-1/2 -translate-y-1/2 text-neutral-500 transition-colors hover:text-[#9c5c68]"
                        data-toggle-visibility="{{ $passwordConfirmationId }}" aria-label="Show password">
                    <i data-lucide="eye" width="16" height="16"></i>
                </button>
            </div>
            <span class="field-error mt-1 block font-sans text-[11.5px] text-[#a5333d]"></span>
        </div>
    </div>

    {{-- ===== Contact No. & Age ===== --}}
    <div class="mb-6 grid grid-cols-2 gap-4 max-sm:grid-cols-1">
        <div>
            <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="{{ $contactNoId }}">Contact no. *</label>
            <input type="tel" name="contact_no" id="{{ $contactNoId }}" placeholder="09XXXXXXXXX" required value="{{ old('contact_no') }}" inputmode="numeric" pattern="[0-9]*"
                   class="h-11 w-full rounded-sm border border-neutral-300 px-4 font-sans text-sm text-neutral-900 outline-none transition-colors focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30 data-[error]:border-[#e74c3c]"
                   data-validate-rule="contact-no">
            <span class="field-error mt-1 block font-sans text-[11.5px] text-[#a5333d]"></span>
        </div>
        <div>
            <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="{{ $ageId }}">Age</label>
            <input type="text" id="{{ $ageId }}" readonly placeholder="—"
                   class="h-11 w-full rounded-sm border border-neutral-200 bg-neutral-50 px-3 text-center font-sans text-sm text-neutral-500">
        </div>
    </div>

    {{-- ===== Birthday ===== --}}
    <div class="mb-6">
        <label class="mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600">Birthday *</label>
        <div class="grid grid-cols-[1.6fr_0.5fr_0.8fr] gap-2">
            <select id="{{ $birthMonthId }}" aria-label="Birth month"
                    class="h-11 w-full min-w-0 rounded-sm border border-neutral-300 px-2 font-sans text-[13px] text-neutral-900 outline-none transition-colors focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30 data-[error]:border-[#e74c3c]"
                    data-validate-rule="birthday-month">
                <option value="" disabled selected>Month</option>
                @foreach(['January','February','March','April','May','June','July','August','September','October','November','December'] as $monthIdx => $monthName)
                    <option value="{{ sprintf('%02d', $monthIdx + 1) }}" {{ $oldBirthdayParts[1] === sprintf('%02d', $monthIdx + 1) ? 'selected' : '' }}>{{ $monthName }}</option>
                @endforeach
            </select>
            <select id="{{ $birthDayId }}" aria-label="Birth day"
                    class="h-11 w-full min-w-0 rounded-sm border border-neutral-300 px-2 font-sans text-[13px] text-neutral-900 outline-none transition-colors focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30 data-[error]:border-[#e74c3c]"
                    data-validate-rule="birthday-day">
                <option value="" disabled selected>Day</option>
                @for ($day = 1; $day <= 31; $day++)
                    <option value="{{ $day }}" {{ (int) ($oldBirthdayParts[2] ?? 0) === $day ? 'selected' : '' }}>{{ $day }}</option>
                @endfor
            </select>
            <select id="{{ $birthYearId }}" aria-label="Birth year"
                    class="h-11 w-full min-w-0 rounded-sm border border-neutral-300 px-2 font-sans text-[13px] text-neutral-900 outline-none transition-colors focus:border-[#9c5c68] focus:ring-2 focus:ring-[#E2B4BD]/30 data-[error]:border-[#e74c3c]"
                    data-validate-rule="birthday-year">
                <option value="" disabled selected>Year</option>
                @for ($year = (int) date('Y'); $year >= 1900; $year--)
                    <option value="{{ $year }}" {{ (int) ($oldBirthdayParts[0] ?? 0) === $year ? 'selected' : '' }}>{{ $year }}</option>
                @endfor
            </select>
        </div>
        <input type="hidden" name="birthday" id="{{ $birthdayId }}" value="{{ old('birthday') }}">
        <span class="field-error mt-1 block font-sans text-[11.5px] text-[#a5333d]"></span>
    </div>

    {{-- ===== Next Button ===== --}}
    @php
        $nextButtonId = $prefix ? "{$prefix}NextStep1" : 'nextStep1';
    @endphp
    <button type="button" id="{{ $nextButtonId }}" class="next-step-btn h-11 w-full cursor-pointer rounded-sm bg-neutral-900 font-sans text-[12.5px] font-semibold uppercase tracking-[1.4px] text-white transition-colors hover:bg-[#9c5c68]">
        Next
    </button>
</section>
