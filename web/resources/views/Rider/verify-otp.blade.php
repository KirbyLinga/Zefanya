@extends('Layouts.footer')
@push('styles') @vite(['resources/css/auth.css']) @endpush
@section('content')
<main class="auth-hero"><div class="auth-hero__intro"><h1 class="auth-hero__title">Verify your email</h1><p class="auth-hero__subtitle">We sent a 6-digit code to <strong>{{ $emailMasked }}</strong>. After verification, your application awaits Logistics approval.</p>
@if(session('success'))<p class="mt-4 text-green-700">{{ session('success') }}</p>@endif
<form method="POST" action="{{ $verifyUrl }}" class="mt-4">@csrf<label for="otp" class="font-sans text-sm">Verification code</label><input name="otp" id="otp" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required class="my-2 block h-11 w-full rounded-sm border border-neutral-300 text-center tracking-[4px]">@error('otp')<p class="font-sans text-sm text-[#a5333d]">{{ $message }}</p>@enderror<button type="submit" class="h-11 w-full rounded-sm bg-neutral-900 font-sans text-sm text-white hover:bg-[#9c5c68]">Verify</button></form>
<form method="POST" action="{{ $resendUrl }}" class="mt-3">@csrf<button type="submit" class="font-sans text-sm font-semibold text-[#9c5c68]">Resend code</button></form>
</div></main>
@endsection
