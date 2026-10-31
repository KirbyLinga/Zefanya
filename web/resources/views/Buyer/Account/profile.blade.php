@extends('Buyer.Layouts.app')

@section('title', 'My Account — Profile')

@section('content')
@php
    // old() wins after a validation error so the user doesn't retype everything.
    $val = fn (string $field, $default = '') => old($field, $buyer->$field ?? $default);
    $birthday = old('birthday', $buyer->birthday?->format('Y-m-d'));
    [$bYear, $bMonth, $bDay] = $birthday ? explode('-', $birthday) : [null, null, null];

    // Shared with the buyer registration modal (Components/buyer-register-modal).
    $labelCls = 'mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-[var(--text-label)]';
    $inputCls = 'h-11 w-full rounded-md border border-neutral-300 px-4 font-sans text-sm text-neutral-900 outline-none transition-colors focus:border-[var(--brand)] focus:ring-2 focus:ring-[var(--hover-rose)]';
    $errorCls = 'mt-1 block font-sans text-[11.5px] text-[var(--status-danger)]';
@endphp

<div class="bg-[var(--bg-page)] py-8 sm:py-10">
    <div class="mx-auto w-full max-w-[1240px] px-4 sm:px-6 lg:px-8">
        <div class="grid grid-cols-1 gap-8 lg:grid-cols-[280px_1fr]">

            {{-- ── Left Sidebar ── --}}
            @include('Components.buyer.account-sidebar', ['active' => 'profile'])

            {{-- ── Right Column ── --}}
            <main>
                @include('Components.buyer.account-tabs', ['active' => 'profile'])

                <div class="mb-6">
                    <h1 class="font-serif text-[28px] font-normal leading-tight text-[var(--text-ink)] sm:text-[32px]">
                        Account Settings
                    </h1>
                    <p class="mt-1 font-sans text-[13px] text-[var(--text-muted)]">
                        Manage your personal information, contact credentials, and communication preferences.
                    </p>
                </div>

                @if (session('success'))
                    <div class="mb-6 flex items-center gap-2.5 rounded-lg border border-[var(--pill-success-border)] bg-[var(--pill-success-bg)] px-3.5 py-2.5 font-sans text-[12.5px] font-medium text-[var(--status-success)]" role="status">
                        <i data-lucide="check-circle-2" width="15" height="15" class="shrink-0"></i>
                        <span class="text-[var(--status-success)]">{{ session('success') }}</span>
                    </div>
                @endif

                <section class="rounded-2xl border border-[var(--border)] bg-[var(--bg-surface)] p-5 shadow-sm sm:p-7">
                    <div class="mb-6 flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <h2 class="font-serif text-[20px] font-normal leading-tight text-[var(--text-ink)]">Personal Profile</h2>
                            <p class="mt-1 font-sans text-[12px] text-[var(--text-muted)]">Used for deliveries and invoices.</p>
                        </div>
                        @if ($buyer->isApproved())
                            <span class="inline-flex w-fit shrink-0 items-center gap-1.5 rounded-full border border-[var(--pill-success-border)] bg-[var(--pill-success-bg)] px-2.5 py-1 font-sans text-[10.5px] font-semibold text-[var(--status-success)]">
                                <i data-lucide="check-circle-2" width="12" height="12" class="shrink-0"></i>
                                <span class="text-[var(--status-success)]">Verified Account</span>
                            </span>
                        @endif
                    </div>

                    <form method="POST" action="{{ route('buyer.account.update') }}">
                        @csrf
                        @method('PATCH')

                        @if ($errors->any())
                            <div class="mb-5 flex items-center gap-2.5 rounded-lg border border-[var(--border-error-soft)] bg-[var(--bg-error-soft)] px-3.5 py-2.5 font-sans text-[12.5px] font-medium text-[var(--status-danger)]" role="alert">
                                <i data-lucide="alert-circle" width="15" height="15" class="shrink-0"></i>
                                <span class="text-[var(--status-danger)]">{{ $errors->first() }}</span>
                            </div>
                        @endif

                        {{-- Name — same 2-col grid as registration modal panel 1 --}}
                        <div class="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <label class="{{ $labelCls }}" for="firstName">First name *</label>
                                <input type="text" name="first_name" id="firstName" required value="{{ $val('first_name') }}"
                                       class="{{ $inputCls }}">
                                @error('first_name')<span class="{{ $errorCls }}">{{ $message }}</span>@enderror
                            </div>
                            <div>
                                <label class="{{ $labelCls }}" for="lastName">Last name *</label>
                                <input type="text" name="last_name" id="lastName" required value="{{ $val('last_name') }}"
                                       class="{{ $inputCls }}">
                                @error('last_name')<span class="{{ $errorCls }}">{{ $message }}</span>@enderror
                            </div>
                        </div>

                        <div class="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <label class="{{ $labelCls }}" for="middleInitial">Middle initial</label>
                                <input type="text" name="middle_initial" id="middleInitial" maxlength="2" value="{{ $val('middle_initial') }}"
                                       class="{{ $inputCls }}">
                                @error('middle_initial')<span class="{{ $errorCls }}">{{ $message }}</span>@enderror
                            </div>
                            <div>
                                <label class="{{ $labelCls }}" for="sex">Sex *</label>
                                <div class="relative">
                                    <select name="sex" id="sex" required class="{{ $inputCls }} appearance-none pr-10">
                                        <option value="" disabled {{ old('sex', $buyer->sex) ? '' : 'selected' }}>Select</option>
                                        <option value="male" {{ old('sex', $buyer->sex) === 'male' ? 'selected' : '' }}>Male</option>
                                        <option value="female" {{ old('sex', $buyer->sex) === 'female' ? 'selected' : '' }}>Female</option>
                                    </select>
                                    <i data-lucide="chevron-down" width="15" height="15" class="pointer-events-none absolute right-3.5 top-1/2 -translate-y-1/2 text-[var(--text-placeholder)]"></i>
                                </div>
                                @error('sex')<span class="{{ $errorCls }}">{{ $message }}</span>@enderror
                            </div>
                        </div>

                        {{-- Email is the verified login identity — shown read-only --}}
                        <div class="mb-4">
                            <label class="{{ $labelCls }}" for="email">E-mail address *</label>
                            <div class="relative">
                                <input type="email" id="email" value="{{ $buyer->email }}" readonly aria-readonly="true"
                                       class="h-11 w-full cursor-not-allowed rounded-md border border-neutral-300 bg-[var(--bg-surface)] px-4 font-sans text-sm text-[var(--text-muted)] outline-none">
                            </div>
                            <span class="mt-1.5 flex items-center gap-1.5 font-sans text-[11px] text-[var(--text-muted)]">
                                <i data-lucide="info" width="12" height="12" class="shrink-0"></i>
                                <span>This is your sign-in email. Use "Change email" below to update it.</span>
                            </span>
                        </div>

                        <div class="mb-4">
                            <label class="{{ $labelCls }}" for="contactNo">Contact no. *</label>
                            <div class="flex items-stretch gap-2">
                                <span class="flex w-[52px] shrink-0 flex-col items-center justify-center rounded-md border border-neutral-300 bg-[var(--bg-thumb)] px-1 py-1 text-center font-sans text-[9.5px] font-semibold uppercase leading-tight text-[var(--text-muted-2)] sm:w-[56px] sm:px-2 sm:text-[10px]" aria-hidden="true">+63<br>(PH)</span>
                                <input type="tel" name="contact_no" id="contactNo" placeholder="09XXXXXXXXX" required value="{{ $val('contact_no') }}"
                                       class="{{ $inputCls }} min-w-0 flex-1">
                            </div>
                            @error('contact_no')<span class="{{ $errorCls }}">{{ $message }}</span>@enderror
                        </div>

                        {{-- Birthday --}}
                        <div class="mb-6">
                            <label class="{{ $labelCls }}" for="birthMonth">Birthday *</label>
                            <div class="grid grid-cols-[1.3fr_0.8fr_0.9fr] gap-2 sm:grid-cols-[1.4fr_0.6fr_0.8fr]">
                                <div class="relative min-w-0">
                                    <select name="birthday_month" id="birthMonth" aria-label="Birth month" class="{{ $inputCls }} appearance-none px-2.5 pr-7 text-[12.5px] sm:px-4 sm:pr-9 sm:text-[13px]">
                                        <option value="" disabled {{ $bMonth ? '' : 'selected' }}>Month</option>
                                        @foreach(['January','February','March','April','May','June','July','August','September','October','November','December'] as $monthIndex => $monthName)
                                            <option value="{{ sprintf('%02d', $monthIndex + 1) }}" {{ $bMonth === sprintf('%02d', $monthIndex + 1) ? 'selected' : '' }}>{{ $monthName }}</option>
                                        @endforeach
                                    </select>
                                    <i data-lucide="chevron-down" width="14" height="14" class="pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2 text-[var(--text-placeholder)] sm:right-3.5 sm:h-[15px] sm:w-[15px]"></i>
                                </div>
                                <div class="relative min-w-0">
                                    <select name="birthday_day" id="birthDay" aria-label="Birth day" class="{{ $inputCls }} appearance-none px-2.5 pr-7 text-[12.5px] sm:px-4 sm:pr-9 sm:text-[13px]">
                                        <option value="" disabled {{ $bDay ? '' : 'selected' }}>Day</option>
                                        @for ($d = 1; $d <= 31; $d++)
                                            <option value="{{ $d }}" {{ (int) $bDay === $d ? 'selected' : '' }}>{{ $d }}</option>
                                        @endfor
                                    </select>
                                    <i data-lucide="chevron-down" width="14" height="14" class="pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2 text-[var(--text-placeholder)] sm:right-3.5 sm:h-[15px] sm:w-[15px]"></i>
                                </div>
                                <div class="relative min-w-0">
                                    <select name="birthday_year" id="birthYear" aria-label="Birth year" class="{{ $inputCls }} appearance-none px-2.5 pr-7 text-[12.5px] sm:px-4 sm:pr-9 sm:text-[13px]">
                                        <option value="" disabled {{ $bYear ? '' : 'selected' }}>Year</option>
                                        @for ($y = (int) date('Y'); $y >= (int) date('Y') - 100; $y--)
                                            <option value="{{ $y }}" {{ (int) $bYear === $y ? 'selected' : '' }}>{{ $y }}</option>
                                        @endfor
                                    </select>
                                    <i data-lucide="chevron-down" width="14" height="14" class="pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2 text-[var(--text-placeholder)] sm:right-3.5 sm:h-[15px] sm:w-[15px]"></i>
                                </div>
                            </div>
                            <input type="hidden" name="birthday" id="birthday" value="{{ $birthday }}">
                            @error('birthday')<span class="{{ $errorCls }}">{{ $message }}</span>@enderror
                        </div>

                        <div class="mt-7 flex flex-col gap-3 border-t border-[var(--border-faint)] pt-5 sm:flex-row sm:items-center sm:gap-x-5">
                            <button type="submit"
                                    class="inline-flex w-full items-center justify-center rounded-full bg-[var(--btn-primary-bg)] px-7 py-2.5 font-sans text-[13px] font-semibold text-[var(--btn-primary-text)] transition-colors hover:bg-[var(--btn-primary-bg-hover)] sm:w-auto">
                                Save changes
                            </button>
                            <a href="{{ route('buyer.account.index') }}" class="text-center font-sans text-[13px] font-medium text-[var(--text-muted)] transition-colors hover:text-[var(--link)] sm:text-left">Discard</a>
                            <span class="text-center font-sans text-[11.5px] text-[var(--text-muted)] sm:ml-auto sm:text-left">Last updated: {{ optional($buyer->updated_at)->format('M d, Y') ?? '—' }}</span>
                        </div>
                    </form>
                </section>

                {{-- Change email: its own forms, since the profile form above cannot nest them. --}}
                <section class="mt-6 rounded-2xl border border-[var(--border)] bg-[var(--bg-surface)] p-5 shadow-sm sm:p-7">
                    <h2 class="font-serif text-[20px] font-normal leading-tight text-[var(--text-ink)]">Change email</h2>
                    <p class="mt-1 font-sans text-[12px] text-[var(--text-muted)]">We send a code to the new address. Your current email keeps working until you enter it.</p>

                    @if ($buyer->pending_email)
                        <p class="mt-4 font-sans text-[13px] text-[var(--text-ink)]">Code sent to <strong>{{ $buyer->pending_email }}</strong>.</p>
                        @error('email', 'emailChange')<span class="{{ $errorCls }}" role="alert">{{ $message }}</span>@enderror

                        <form method="POST" action="{{ route('buyer.account.email.verify') }}" class="mt-4">
                            @csrf
                            <label class="{{ $labelCls }}" for="emailChangeOtp">6-digit code *</label>
                            <input type="text" name="otp" id="emailChangeOtp" required inputmode="numeric" pattern="\d{6}" maxlength="6" autocomplete="one-time-code"
                                   class="{{ $inputCls }} sm:max-w-[220px]">
                            @error('otp', 'emailChange')<span class="{{ $errorCls }}" role="alert">{{ $message }}</span>@enderror
                            <button type="submit"
                                    class="mt-4 inline-flex w-full items-center justify-center rounded-full bg-[var(--btn-primary-bg)] px-7 py-2.5 font-sans text-[13px] font-semibold text-[var(--btn-primary-text)] transition-colors hover:bg-[var(--btn-primary-bg-hover)] sm:w-auto">
                                Confirm new email
                            </button>
                        </form>

                        <div class="mt-4 flex flex-wrap items-center gap-x-5 gap-y-2">
                            <form method="POST" action="{{ route('buyer.account.email.resend') }}">
                                @csrf
                                <button type="submit" class="font-sans text-[13px] font-medium text-[var(--link)]">Resend code</button>
                            </form>
                            <form method="POST" action="{{ route('buyer.account.email.cancel') }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="font-sans text-[13px] font-medium text-[var(--text-muted)] transition-colors hover:text-[var(--link)]">Cancel change</button>
                            </form>
                        </div>
                    @else
                        <form method="POST" action="{{ route('buyer.account.email.request') }}" class="mt-4">
                            @csrf
                            <label class="{{ $labelCls }}" for="newEmail">New e-mail address *</label>
                            <input type="email" name="email" id="newEmail" required maxlength="255" value="{{ old('email') }}"
                                   class="{{ $inputCls }}">
                            @error('email', 'emailChange')<span class="{{ $errorCls }}" role="alert">{{ $message }}</span>@enderror
                            <button type="submit"
                                    class="mt-4 inline-flex w-full items-center justify-center rounded-full bg-[var(--btn-primary-bg)] px-7 py-2.5 font-sans text-[13px] font-semibold text-[var(--btn-primary-text)] transition-colors hover:bg-[var(--btn-primary-bg-hover)] sm:w-auto">
                                Send code
                            </button>
                        </form>
                    @endif
                </section>
            </main>
        </div>
    </div>
</div>
@endsection
