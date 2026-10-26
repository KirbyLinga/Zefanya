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

  @if ($message = session('auth.status_message'))
    <div class="alert alert-info alert-dismissible fade show mb-4" role="alert">
      {{ $message }}
      @if (session('auth.resume_url'))
        <a href="{{ session('auth.resume_url') }}" class="alert-link">{{ session('auth.resume_label') ?? 'Verify your email' }}</a>
      @endif
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
  @endif

  <div class="role-grid">

    <a href="#" data-buyer-register-trigger class="role-card">
      <div class="role-card__icon">
        <i data-lucide="shopping-bag" width="24" height="24"></i>
      </div>
      <h3 class="role-card__title">Buyer</h3>
      <p class="role-card__desc">Shop products, manage your orders, save your favorite items, and enjoy a personalized shopping experience.</p>
      <span class="role-card__cta">REGISTER AS BUYER</span>
    </a>

    <a href="#" data-seller-register-trigger class="role-card">
      <div class="role-card__icon">
        <i data-lucide="store" width="24" height="24"></i>
      </div>
      <h3 class="role-card__title">Seller</h3>
      <p class="role-card__desc">Create your store, manage products and inventory, process orders, and grow your business.</p>
      <span class="role-card__cta">REGISTER AS SELLER</span>
    </a>

    <a href="#" data-logistics-register-trigger class="role-card">
      <div class="role-card__icon">
        <i data-lucide="truck" width="24" height="24"></i>
      </div>
      <h3 class="role-card__title">Logistics</h3>
      <p class="role-card__desc">Manage courier operations, assignments, verification, delivery monitoring, and marketplace logistics.</p>
      <span class="role-card__cta">REGISTER AS LOGISTICS</span>
    </a>

    <a href="{{ route('register.rider') }}" data-rider-register-trigger aria-haspopup="dialog" aria-controls="riderModalOverlay"
       class="group flex flex-col items-center rounded-lg border border-neutral-200 bg-primary-50 px-6 py-10 text-center transition hover:-translate-y-1 hover:border-primary-800 hover:shadow-md focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary-800">
      <span class="mb-5 flex size-14 items-center justify-center rounded-full bg-secondary-300 text-primary-800 transition group-hover:bg-white"><i data-lucide="bike" width="24" height="24"></i></span>
      <h3 class="mb-4 font-sans text-[13px] font-bold uppercase tracking-[1.6px] text-neutral-950">Rider</h3>
      <p class="mb-6 min-h-[66px] font-sans text-[13.5px] leading-relaxed text-neutral-600">Apply to join a logistics provider as a rider. Verify your email and await approval, then use the mobile app.</p>
      <span class="w-full rounded-sm bg-neutral-950 py-[15px] font-sans text-xs font-semibold tracking-[1.4px] text-white transition group-hover:bg-primary-800">REGISTER AS RIDER</span>
    </a>

  </div>

  <div class="auth-divider">
    <span class="auth-divider__line"></span>
    <span class="auth-divider__label">or</span>
    <span class="auth-divider__line"></span>
  </div>

  <div class="guest-wrap">
    <p class="guest-wrap__lead">Not ready to create an account?</p>
    <a href="{{ route('buyer.products.index') }}" class="guest-card">
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
@include('Components.seller-register-modal')
@include('Components.logistics-register-modal')
@include('Components.rider-register-modal')

@endsection

@push('scripts')
  {{-- address-lookup.js (the shared PSGC address module) must be listed
       BEFORE buyer-register-modal.js: the modal resolves window.zfAddressLookup
       at init time and silently skips the province/municipality/barangay
       dropdowns when the module has not loaded yet. --}}
  @vite(['resources/js/buyer/address-lookup.js', 'resources/js/buyer/buyer-register-modal.js', 'resources/js/seller/seller-register-modal.js', 'resources/js/logistics/logistics-register-modal.js', 'resources/js/rider/rider-register-modal.js'])
  <script>
    if (window.lucide) { lucide.createIcons(); }
  </script>
@endpush