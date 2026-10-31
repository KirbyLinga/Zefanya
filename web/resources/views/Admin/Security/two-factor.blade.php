@extends('Admin.Layouts.app')

@section('title', 'Two-Factor Authentication — Zefanya Admin')

@section('content')
    <div class="mx-auto max-w-3xl">

        <header class="mb-6">
            <h1 class="text-2xl font-semibold text-neutral-950">Two-Factor Authentication</h1>
            <p class="mt-1 text-sm text-neutral-600">
                Protect this admin account with a time-based authenticator app.
            </p>
        </header>

        @if (session('status'))
            <div role="status" class="mb-5 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                {{ session('status') }}
            </div>
        @endif

        @if ($errors->any())
            <div role="alert" class="mb-5 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                {{ $errors->first() }}
            </div>
        @endif

        @if ($recoveryCodes !== [])
            <div class="mb-5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-4">
                <h2 class="text-sm font-semibold text-amber-900">Save your recovery codes now</h2>
                <p class="mt-1 text-xs text-amber-800">Each code works once. They will not be shown again.</p>
                <div class="mt-3 grid grid-cols-2 gap-2 font-mono text-sm text-amber-950 sm:grid-cols-4">
                    @foreach ($recoveryCodes as $code)
                        <span class="rounded bg-white px-2 py-1 text-center">{{ $code }}</span>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="rounded-xl border border-neutral-200 bg-white p-6 shadow-sm">
            @if ($admin->hasEnabledTwoFactor())
                <div class="flex items-start gap-3">
                    <i data-lucide="shield-check" class="mt-0.5 h-5 w-5 text-emerald-600"></i>
                    <div>
                        <h2 class="text-sm font-semibold text-neutral-950">Two-factor authentication is enabled</h2>
                        <p class="mt-1 text-sm text-neutral-600">
                            Your account requires an authenticator or recovery code at every password sign-in.
                        </p>
                    </div>
                </div>

                <hr class="my-6 border-neutral-200" />

                <h3 class="text-sm font-semibold text-neutral-950">Disable two-factor authentication</h3>
                <p class="mt-1 text-xs text-neutral-600">
                    Confirm your password and a current authenticator (or unused recovery) code.
                </p>

                <form method="POST" action="{{ route('admin.two-factor.disable') }}" class="mt-4 grid gap-4 sm:grid-cols-2">
                    @csrf

                    <label class="flex flex-col gap-1.5 text-xs font-semibold uppercase tracking-wide text-neutral-600">
                        Password
                        <input name="password" type="password" required autocomplete="current-password"
                               class="h-10 rounded-lg border border-neutral-300 px-3 text-sm font-normal normal-case tracking-normal text-neutral-900 focus:border-primary-400 focus:outline-none focus:ring-2 focus:ring-primary-200" />
                    </label>

                    <label class="flex flex-col gap-1.5 text-xs font-semibold uppercase tracking-wide text-neutral-600">
                        Authentication code
                        <input name="code" type="text" required autocomplete="one-time-code"
                               class="h-10 rounded-lg border border-neutral-300 px-3 text-sm font-normal normal-case tracking-normal text-neutral-900 focus:border-primary-400 focus:outline-none focus:ring-2 focus:ring-primary-200" />
                    </label>

                    <div class="sm:col-span-2">
                        <button type="submit"
                                class="inline-flex h-10 items-center justify-center rounded-lg bg-red-600 px-5 text-sm font-semibold text-white transition-colors hover:bg-red-700">
                            Disable two-factor
                        </button>
                    </div>
                </form>
            @else
                <h2 class="text-sm font-semibold text-neutral-950">Set up your authenticator</h2>
                <ol class="mt-2 list-decimal space-y-1 pl-5 text-sm text-neutral-600">
                    <li>Scan the QR code with Google Authenticator, Authy, 1Password, or any TOTP app.</li>
                    <li>Enter the 6-digit code the app shows to confirm enrolment.</li>
                </ol>

                <div class="mt-5 flex flex-col items-start gap-5 sm:flex-row">
                    <div class="rounded-lg border border-neutral-200 bg-white p-3">
                        {!! $qrSvg !!}
                    </div>
                    <div class="text-sm text-neutral-600">
                        <p class="font-semibold text-neutral-950">Can't scan?</p>
                        <p class="mt-1">Enter this key manually:</p>
                        <code class="mt-2 block break-all rounded bg-neutral-100 px-3 py-2 font-mono text-xs text-neutral-900">{{ $secret }}</code>
                    </div>
                </div>

                <form method="POST" action="{{ route('admin.two-factor.enable') }}" class="mt-6 flex flex-col gap-3 sm:flex-row sm:items-end">
                    @csrf

                    <label class="flex flex-1 flex-col gap-1.5 text-xs font-semibold uppercase tracking-wide text-neutral-600">
                        Code from your app
                        <input name="code" type="text" inputmode="numeric" required autocomplete="one-time-code"
                               class="h-10 rounded-lg border border-neutral-300 px-3 text-sm font-normal normal-case tracking-normal text-neutral-900 focus:border-primary-400 focus:outline-none focus:ring-2 focus:ring-primary-200" />
                    </label>

                    <button type="submit"
                            class="inline-flex h-10 items-center justify-center rounded-lg bg-neutral-900 px-5 text-sm font-semibold text-white transition-colors hover:bg-neutral-800">
                        Enable two-factor
                    </button>
                </form>
            @endif
        </div>
    </div>
@endsection
