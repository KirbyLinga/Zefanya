{{-- resources/views/Logistics/register-logistics-pending.blade.php --}}
@extends('Layouts.footer')

@push('styles')
  @vite(['resources/css/auth.css'])
@endpush

@section('content')

<main class="auth-hero">
  <div class="auth-hero__intro">
    <h1 class="auth-hero__title">Email verified</h1>
    @if (session('success'))
      <x-flash type="success" :message="session('success')" spacing="" />
    @else
      <p class="auth-hero__subtitle">
        Your email is verified. Please wait for the administrator's approval, which will be sent to your email.
      </p>
    @endif
  </div>

  <div class="auth-divider">
    <span class="auth-divider__line"></span>
    <span class="auth-divider__label">or</span>
    <span class="auth-divider__line"></span>
  </div>

  <div class="guest-wrap">
    <a href="{{ route('home') }}" class="guest-card">
      <div class="guest-card__icon">
        <i data-lucide="home" width="20" height="20"></i>
      </div>
      <h4 class="guest-card__title">Back to home</h4>
      <p class="guest-card__desc">Return to the Zefanya storefront.</p>
    </a>
  </div>
</main>

@endsection

@push('scripts')
  <script>
    if (window.lucide) { lucide.createIcons(); }
  </script>
@endpush
