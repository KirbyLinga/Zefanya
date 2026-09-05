{{-- resources/views/Buyer/register-buyer-check-email.blade.php --}}
@extends('Layouts.footer')

@push('styles')
  @vite(['resources/css/auth.css'])
@endpush

@section('content')

<main class="auth-hero">
  <div class="auth-hero__intro">
    <h1 class="auth-hero__title">Check your email</h1>
    <p class="auth-hero__subtitle">
      We sent a verification link to
      <strong>{{ session('email', 'your email address') }}</strong>.
      Click it to confirm your account — then your registration goes to an
      administrator for approval.
    </p>
  </div>

  <p class="auth-hero__subtitle" style="max-width: 480px; text-align: center;">
    Didn't get it? Check spam, or the link expires in 60 minutes and you'll need to register again.
  </p>

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
