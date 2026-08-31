{{-- resources/views/Auth/register-type.blade.php --}}
@extends('Layouts.footer')

@push('styles')
  @vite(['resources/css/auth.css'])
@endpush

@section('content')

<main class="auth-hero">
  <div class="auth-hero__intro">
    <h1 class="auth-hero__title">How would you like to get started?</h1>
    <p class="auth-hero__subtitle">Choose an account type that best fits how you want to use Zefanya.</p>
  </div>

  <div class="role-grid">

    <a href="#" data-buyer-register-trigger class="role-card">
      <div class="role-card__icon">
        <i data-lucide="shopping-bag" width="24" height="24"></i>
      </div>
      <h3 class="role-card__title">Buyer</h3>
      <p class="role-card__desc">Shop products, manage your orders, save your favorite items, and enjoy a personalized shopping experience.</p>
      <span class="role-card__cta">REGISTER AS BUYER</span>
    </a>

    <a href="{{ route('register.seller') }}" class="role-card">
      <div class="role-card__icon">
        <i data-lucide="store" width="24" height="24"></i>
      </div>
      <h3 class="role-card__title">Seller</h3>
      <p class="role-card__desc">Create your store, manage products and inventory, process orders, and grow your business.</p>
      <span class="role-card__cta">REGISTER AS SELLER</span>
    </a>

    <a href="{{ route('register.logistics') }}" class="role-card">
      <div class="role-card__icon">
        <i data-lucide="truck" width="24" height="24"></i>
      </div>
      <h3 class="role-card__title">Logistics</h3>
      <p class="role-card__desc">Manage courier operations, assignments, verification, delivery monitoring, and marketplace logistics.</p>
      <span class="role-card__cta">REGISTER AS LOGISTICS</span>
    </a>

  </div>

  <div class="auth-divider">
    <span class="auth-divider__line"></span>
    <span class="auth-divider__label">or</span>
    <span class="auth-divider__line"></span>
  </div>

  <div class="guest-wrap">
    <p class="guest-wrap__lead">Not ready to create an account?</p>
    <a href="{{ route('shop.browse') }}" class="guest-card">
      <div class="guest-card__icon">
        <i data-lucide="user-round" width="20" height="20"></i>
      </div>
      <h4 class="guest-card__title">Continue as Guest</h4>
      <p class="guest-card__desc">Browse products and checkout without creating a permanent account.</p>
      <span class="guest-card__cta">CONTINUE AS GUEST</span>
    </a>
  </div>
</main>

@include('Components.buyer-register-modal')

@endsection

@push('scripts')
  <script>
    if (window.lucide) { lucide.createIcons(); }
  </script>
@endpush    