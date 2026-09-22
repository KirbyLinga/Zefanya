{{-- resources/views/Logistics/register-logistics-verify-otp.blade.php
     Legacy no-JS OTP page. The modal flow (Components/logistics-register-modal.blade.php)
     verifies the code inline via JSON; this page is kept for direct deep-links and
     mirrors Seller/register-seller-verify-otp.blade.php. --}}
@extends('Layouts.footer')

@push('styles')
  @vite(['resources/css/auth.css'])
@endpush

@section('content')

<main class="auth-hero">
  <div class="auth-hero__intro">
    <h1 class="auth-hero__title">Verify your email</h1>
    <p class="auth-hero__subtitle">
      We sent a 6-digit code to <strong>{{ $logisticsProvider->email }}</strong>.
      Enter it below to continue — your registration then goes to an administrator for approval.
    </p>

    @if (session('success'))
      <p style="color: green;">{{ session('success') }}</p>
    @endif

    <form method="POST" action="{{ route('register.logistics.verify-otp.store', $logisticsProvider) }}" style="margin-top: 16px;">
      @csrf
      <label for="otp">Verification code</label>
      <input type="text" name="otp" id="otp" inputmode="numeric" maxlength="6" required
             style="display: block; width: 100%; margin: 8px 0; padding: 10px; text-align: center; letter-spacing: 4px;">

      @error('otp')
        <p style="color: #a5333d;">{{ $message }}</p>
      @enderror

      <button type="submit">Verify</button>
    </form>

    <form method="POST" action="{{ route('register.logistics.verify-otp.resend', $logisticsProvider) }}" style="margin-top: 12px;">
      @csrf
      <button type="submit">Resend code</button>
    </form>
  </div>
</main>

@endsection
