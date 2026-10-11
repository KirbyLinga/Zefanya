@extends('Buyer.Layouts.app')
@section('title', 'My Wishlist')
@section('content')
<div class="bg-cream">
    <div class="mx-auto max-w-[1440px] px-4 py-8 sm:px-8 lg:px-16">
        {{-- Page header --}}
        <div class="mb-8">
            <h1 class="font-serif text-[32px] sm:text-[40px] font-semibold text-buyer-ink">My Wishlist</h1>
            <p class="mt-2 text-[14px] text-buyer-ink-soft">Items you've saved</p>
        </div>

        {{-- Wishlist items or empty state --}}
        @if ($wishlistItems->isNotEmpty())
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                @foreach ($wishlistItems as $item)
                    @include('Components.buyer.product-card', [
                        'product' => $item->product,
                        'url' => route('buyer.products.show', $item->product->id),
                    ])
                @endforeach
            </div>

            {{-- Pagination --}}
            <div class="mt-12 flex justify-center">
                {{ $wishlistItems->links() }}
            </div>
        @else
            <div class="rounded-2xl border-2 border-dashed border-buyer-line bg-white p-12 text-center">
                <i data-lucide="heart" width="48" height="48" class="mx-auto mb-4 text-buyer-ink-soft"></i>
                <p class="font-serif text-[20px] text-buyer-ink mb-2">Your wishlist is empty</p>
                <p class="text-[14px] text-buyer-ink-soft mb-6">Save items you love to come back to them later.</p>
                <a href="{{ route('buyer.products.index') }}"
                   class="inline-flex items-center gap-2 rounded-lg bg-buyer-ink px-6 py-3 text-[13px] font-semibold text-white hover:opacity-90 transition-opacity">
                    Start Shopping
                </a>
            </div>
        @endif
    </div>
</div>
@endsection
