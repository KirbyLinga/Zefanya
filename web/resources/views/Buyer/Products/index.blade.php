@extends('Buyer.Layouts.app')
@section('title', 'Shop')
@section('content')
<div class="bg-cream">
    <div class="mx-auto max-w-[1440px] px-4 py-8 sm:px-8 lg:px-16">
        {{-- Page header --}}
        <div class="mb-8">
            <h1 class="font-serif text-[32px] sm:text-[40px] font-semibold text-buyer-ink">Shop All Products</h1>
            <p class="mt-2 text-[14px] text-buyer-ink-soft">Browse our complete collection</p>
        </div>

        {{-- Products grid --}}
        @if ($products->isNotEmpty())
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                @forelse ($products as $product)
                    @include('Components.buyer.product-card', [
                        'product' => $product,
                        'url' => route('buyer.products.show', $product->id),
                    ])
                @empty
                    <div class="col-span-full text-center py-12">
                        <p class="text-[14px] text-buyer-ink-soft">No products found.</p>
                    </div>
                @endforelse
            </div>

            {{-- Pagination --}}
            <div class="mt-12 flex justify-center">
                {{ $products->links() }}
            </div>
        @else
            <div class="rounded-2xl border-2 border-dashed border-buyer-line bg-white p-12 text-center">
                <i data-lucide="shopping-bag" width="48" height="48" class="mx-auto mb-4 text-buyer-ink-soft"></i>
                <p class="font-serif text-[20px] text-buyer-ink mb-2">No products available</p>
                <p class="text-[14px] text-buyer-ink-soft">Check back soon for new items!</p>
            </div>
        @endif
    </div>
</div>
@endsection
