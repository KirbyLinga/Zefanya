@extends('Buyer.Layouts.app')
@section('title', 'Categories')
@section('content')
<div class="min-h-screen bg-gradient-to-b from-neutral-50 to-primary-50">
    <div class="mx-auto max-w-[1440px] px-4 py-8 sm:px-8 lg:px-12">
        <div class="mb-10">
            <div class="mb-4 flex items-center gap-2 text-[13px] text-buyer-ink-soft">
                <a href="{{ route('buyer.products.index') }}" class="transition-colors hover:text-primary-800">Shop</a>
                <span>/</span>
                <span class="font-medium text-buyer-ink">Categories</span>
            </div>
            <h1 class="mb-2 font-serif text-[42px] font-normal text-buyer-ink sm:text-[48px]">All Categories</h1>
            <p class="text-[14px] text-buyer-ink-soft">Browse every department on Zefanya.</p>
        </div>

        @if ($categories->isEmpty())
            <p class="rounded-lg border border-buyer-line bg-white px-6 py-10 text-center text-[14px] text-buyer-ink-soft">
                Categories will appear here once they are available.
            </p>
        @else
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5">
                @foreach ($categories as $category)
                    <a href="{{ route('buyer.categories.show', $category) }}"
                       class="group flex min-h-[140px] flex-col items-center justify-center rounded-2xl border border-buyer-line bg-white px-4 py-6 text-center transition-colors hover:border-primary-700 hover:shadow-[0_12px_28px_-8px_rgba(58,37,41,0.16)]">
                        <div class="mb-3 inline-flex h-14 w-14 items-center justify-center rounded-full bg-secondary-100 transition-transform duration-300 group-hover:scale-110">
                            <i data-lucide="{{ $category->icon }}" width="24" height="24" class="text-primary-800"></i>
                        </div>
                        <span class="w-full text-center text-[11px] font-semibold uppercase leading-[1.4] tracking-[1.1px] text-buyer-ink">
                            {{ $category->label }}
                        </span>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</div>
@endsection
