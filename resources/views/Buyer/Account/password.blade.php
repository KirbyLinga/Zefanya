@extends('Buyer.Layouts.app')

@section('title', 'My Account — Password')

@section('content')
@php
    // Shared with profile/address (Components/buyer-register-modal).
    $labelCls = 'mb-2 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-[var(--text-label)]';
    $inputCls = 'h-11 w-full rounded-md border border-neutral-300 px-4 font-sans text-sm text-neutral-900 outline-none transition-colors focus:border-[var(--brand)] focus:ring-2 focus:ring-[var(--hover-rose)]';
    $errorCls = 'mt-1 block font-sans text-[11.5px] text-[var(--status-danger)]';
@endphp

<div class="bg-[var(--bg-page)] py-8 sm:py-10">
    <div class="mx-auto w-full max-w-[1240px] px-4 sm:px-6 lg:px-8">
        <div class="grid grid-cols-1 gap-8 lg:grid-cols-[280px_1fr]">

            {{-- ── Left Sidebar ── --}}
            @include('Components.buyer.account-sidebar', ['active' => 'password'])

            {{-- ── Right Column ── --}}
            <main>
                @include('Components.buyer.account-tabs', ['active' => 'password'])

                <div class="mb-6">
                    <h1 class="font-serif text-[28px] font-normal leading-tight text-[var(--text-ink)] sm:text-[32px]">
                        Password &amp; Security
                    </h1>
                    <p class="mt-1 font-sans text-[13px] text-[var(--text-muted)]">
                        Keep your account secure.
                    </p>
                </div>

                @if (session('success'))
                    <div class="mb-6 flex items-center gap-2.5 rounded-lg border border-[var(--pill-success-border)] bg-[var(--pill-success-bg)] px-3.5 py-2.5 font-sans text-[12.5px] font-medium text-[var(--status-success)]" role="status">
                        <i data-lucide="check-circle-2" width="15" height="15" class="shrink-0"></i>
                        <span class="text-[var(--status-success)]">{{ session('success') }}</span>
                    </div>
                @endif

                <section class="rounded-2xl border border-[var(--border)] bg-[var(--bg-surface)] p-5 shadow-sm sm:p-7">
                    @if ($buyer->pending_password)
                    <h2 class="font-serif text-[20px] font-normal leading-tight text-[var(--text-ink)]">Enter the code from your email</h2>
                    <p class="mt-1 font-sans text-[13px] text-[var(--text-muted)]">We sent a 6-digit code to <strong>{{ $buyer->email }}</strong>. Your new password starts working once you enter it. Your current password works until then.</p>

                    <form method="POST" action="{{ route('buyer.account.password.verify') }}" class="mt-5">
                        @csrf
                        <label class="{{ $labelCls }}" for="passwordChangeOtp">6-digit code *</label>
                        <input type="text" name="otp" id="passwordChangeOtp" required autofocus inputmode="numeric" pattern="\d{6}" maxlength="6" autocomplete="one-time-code"
                               class="{{ $inputCls }} sm:max-w-[220px]">
                        @error('otp', 'passwordChange')<span class="{{ $errorCls }}" role="alert">{{ $message }}</span>@enderror
                        <button type="submit"
                                class="mt-4 inline-flex w-full items-center justify-center rounded-full bg-[var(--btn-primary-bg)] px-8 py-2.5 font-sans text-[13px] font-semibold text-[var(--btn-primary-text)] transition-opacity hover:opacity-90 sm:w-auto">
                            Confirm new password
                        </button>
                    </form>

                    <div class="mt-4 flex flex-wrap items-center gap-x-5 gap-y-2">
                        <form method="POST" action="{{ route('buyer.account.password.resend') }}">
                            @csrf
                            <button type="submit" class="font-sans text-[13px] font-medium text-[var(--link)]">Resend code</button>
                        </form>
                        <form method="POST" action="{{ route('buyer.account.password.cancel') }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="font-sans text-[13px] font-medium text-[var(--text-muted)] transition-colors hover:text-[var(--link)]">Cancel change</button>
                        </form>
                    </div>
                    @else
                    <form method="POST" action="{{ route('buyer.account.password.update') }}">
                        @csrf
                        @method('PATCH')

                        {{-- Current password --}}
                        <div class="mb-4">
                            <label class="{{ $labelCls }}" for="current_password">Current password *</label>
                            <div class="relative">
                                <input type="password" name="current_password" id="current_password" required
                                       class="{{ $inputCls }}" autocomplete="current-password">
                                <button type="button"
                                        class="absolute right-3 top-1/2 -translate-y-1/2 text-neutral-500 transition-colors hover:text-[var(--brand)]"
                                        data-toggle-visibility="current_password" aria-label="Show password">
                                    <i data-lucide="eye" width="16" height="16"></i>
                                </button>
                            </div>
                            @error('current_password')<span class="{{ $errorCls }}">{{ $message }}</span>@enderror
                        </div>

                        {{-- New password --}}
                        <div class="mb-4">
                            <label class="{{ $labelCls }}" for="new_password">New password *</label>
                            <div class="relative">
                                <input type="password" name="new_password" id="new_password" required
                                       class="{{ $inputCls }}" autocomplete="new-password" minlength="8">
                                <button type="button"
                                        class="absolute right-3 top-1/2 -translate-y-1/2 text-neutral-500 transition-colors hover:text-[var(--brand)]"
                                        data-toggle-visibility="new_password" aria-label="Show password">
                                    <i data-lucide="eye" width="16" height="16"></i>
                                </button>
                            </div>
                            @error('new_password')<span class="{{ $errorCls }}">{{ $message }}</span>@enderror
                        </div>

                        {{-- Confirm new password --}}
                        <div class="mb-6">
                            <label class="{{ $labelCls }}" for="new_password_confirmation">Confirm new password *</label>
                            <div class="relative">
                                <input type="password" name="new_password_confirmation" id="new_password_confirmation" required
                                       class="{{ $inputCls }}" autocomplete="new-password" minlength="8">
                                <button type="button"
                                        class="absolute right-3 top-1/2 -translate-y-1/2 text-neutral-500 transition-colors hover:text-[var(--brand)]"
                                        data-toggle-visibility="new_password_confirmation" aria-label="Show password">
                                    <i data-lucide="eye" width="16" height="16"></i>
                                </button>
                            </div>
                            @error('new_password_confirmation')<span class="{{ $errorCls }}">{{ $message }}</span>@enderror
                        </div>

                        <div class="flex flex-col gap-3 border-t border-[var(--border-faint)] pt-5 sm:flex-row sm:items-center sm:gap-x-5">
                            <button type="submit"
                                    class="inline-flex w-full items-center justify-center rounded-full bg-[var(--btn-primary-bg)] px-8 py-2.5 font-sans text-[13px] font-semibold text-[var(--btn-primary-text)] transition-colors hover:bg-[var(--btn-primary-bg-hover)] sm:w-auto">
                                Update password
                            </button>
                        </div>
                    </form>
                    @endif
                </section>
            </main>
        </div>
    </div>
</div>

@push('scripts')
<script>
    document.querySelectorAll('[data-toggle-visibility]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var target = document.getElementById(btn.getAttribute('data-toggle-visibility'));
            if (!target) return;
            var isHidden = target.type === 'password';
            target.type = isHidden ? 'text' : 'password';
            btn.innerHTML = '<i data-lucide="' + (isHidden ? 'eye-off' : 'eye') + '" width="16" height="16"></i>';
            if (window.lucide) lucide.createIcons();
        });
    });
</script>
@endpush
@endsection
