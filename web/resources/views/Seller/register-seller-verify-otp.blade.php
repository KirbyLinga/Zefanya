@extends('Layouts.footer')

@push('styles')
    @vite('resources/css/auth.css') {{-- adjust to however your other Auth pages include this --}}
@endpush

@section('content')
<div class="auth-hero">
    <div class="auth-card" style="max-width: 420px; margin: 0 auto; text-align: center;">
        <h1>Verify your email</h1>
        <p>
            We sent a 6-digit code to
            <strong>{{ $seller->email }}</strong>.
            Enter it below to continue — your registration then goes to an
            administrator for approval.
        </p>

        @if (session('success'))
            <p style="color: green;">{{ session('success') }}</p>
        @endif

        <form method="POST" action="{{ route('register.seller.verify-otp.store', $seller) }}" novalidate>
            @csrf

            <input
                type="text"
                name="otp"
                inputmode="numeric"
                pattern="[0-9]*"
                maxlength="6"
                autocomplete="one-time-code"
                placeholder="000000"
                style="font-size: 28px; letter-spacing: 8px; text-align: center; width: 100%; margin: 1rem 0;"
                required
                autofocus
            >
            @error('otp')
                <p style="color: red;">{{ $message }}</p>
            @enderror

            <button type="submit" class="buyer-modal__submit" style="width: 100%;">Verify</button>
        </form>

        <form method="POST" action="{{ route('register.seller.verify-otp.resend', $seller) }}" style="margin-top: 1rem;">
            @csrf
            <button type="submit" style="background: none; border: none; text-decoration: underline; cursor: pointer;">
                Resend code
            </button>
        </form>

        <p style="font-size: 0.85em; color: #666; margin-top: 1rem;">Code expires 10 minutes after it's sent.</p>
    </div>
</div>
@endsection