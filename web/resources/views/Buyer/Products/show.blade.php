@extends('Buyer.Layouts.app')

@section('title', $product->name)

@section('content')
<div class="bg-cream min-h-screen">
  <div class="mx-auto max-w-[1440px] px-4 py-6 sm:px-8 lg:px-16">

    {{-- Breadcrumb --}}
    <nav class="mb-6 flex flex-wrap items-center gap-1.5 text-[11.5px] text-neutral-400" aria-label="Breadcrumb">
      @foreach ($breadcrumb as $crumb)
        @if ($loop->last)
          <span class="truncate font-medium text-buyer-ink">{{ $crumb['label'] }}</span>
        @else
          @if (isset($crumb['url']))
            <a href="{{ $crumb['url'] }}" class="transition-colors hover:text-primary-800">{{ $crumb['label'] }}</a>
          @else
            <span class="truncate text-buyer-ink">{{ $crumb['label'] }}</span>
          @endif
          <i data-lucide="chevron-right" width="12" height="12" class="shrink-0 text-neutral-300"></i>
        @endif
      @endforeach
    </nav>

    {{-- ── Two-column hero ── --}}
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[1fr_420px] xl:grid-cols-2">
      @include('Buyer.Products._gallery')
      @include('Buyer.Products._buybox')
    </div>

    {{-- ── Seller strip ── --}}
    @include('Buyer.Products._seller-strip')

    {{-- ── Product details --}}
    @include('Buyer.Products._tabs')

    {{-- ── Related products carousel --}}
    @include('Buyer.Products._related')

  </div>
</div>
@endsection

@push('scripts')
  @vite(['resources/js/buyer/product-show.js'])
@endpush
