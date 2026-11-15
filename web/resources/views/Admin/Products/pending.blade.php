@extends('Admin.Layouts.app')

@section('title', 'Pending Products')

@section('content')
<div class="mb-6 flex flex-wrap items-end justify-between gap-3">
    <div>
        <p class="text-sm text-neutral-500">Marketplace / Catalog review</p>
        <h1 class="text-[28px] font-bold text-neutral-950">Pending Products</h1>
        <p class="text-neutral-600">Approve a listing to publish it. Reject it with a reason so the seller can edit and resubmit. A revision does not replace the live listing until you approve it.</p>
    </div>
    <a href="{{ route('admin.products.index') }}" class="text-sm font-medium text-primary-700">All products</a>
</div>

<x-flash type="success" :message="session('success')" />
@if ($errors->any())
    <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700" role="alert">{{ $errors->first() }}</div>
@endif

@forelse ($products as $product)
    @php
        $revision = $product->latestRevision;
        $pendingRevision = $revision !== null && $revision->status === 'pending' ? $revision : null;
        $proposal = $pendingRevision?->payload ?? [];
        $image = $product->images->first();
    @endphp
    <article class="mb-6 rounded-lg border border-neutral-200 bg-white p-5">
        <div class="flex flex-wrap gap-5">
            @if ($image)
                <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($image->path) }}" alt="" class="h-28 w-28 rounded border border-neutral-200 object-cover">
            @endif
            <div class="min-w-0 flex-1">
                <h2 class="text-lg font-semibold text-neutral-950">{{ $proposal['name'] ?? $product->name }}</h2>
                <p class="text-sm text-neutral-600">{{ $product->seller?->business_name ?? 'Unknown seller' }} · {{ $product->seller?->email }} · {{ $product->category?->label ?? 'Uncategorized' }}</p>
                <p class="mt-2 text-sm text-neutral-800">{{ \Illuminate\Support\Str::limit($proposal['description'] ?? $product->description, 220) }}</p>
                <dl class="mt-3 grid gap-2 text-sm sm:grid-cols-3">
                    <div><dt class="text-neutral-500">Price</dt><dd class="font-medium">₱{{ number_format((float) ($proposal['price'] ?? $product->price), 2) }}</dd></div>
                    <div><dt class="text-neutral-500">Stock</dt><dd class="font-medium">{{ $product->variants->sum('stock_quantity') }}</dd></div>
                    <div><dt class="text-neutral-500">Queue</dt><dd class="font-medium">{{ $pendingRevision ? 'Edit awaiting approval' : 'New product' }}</dd></div>
                </dl>
                @if ($product->variants->isNotEmpty())
                    <ul class="mt-3 space-y-1 text-sm text-neutral-700">
                        @foreach ($product->variants->take(8) as $variant)
                            <li>{{ $variant->label() }} — ₱{{ number_format((float) $variant->price, 2) }} · {{ $variant->stock_quantity }} in stock</li>
                        @endforeach
                    </ul>
                @endif
                @if ($pendingRevision)
                    <p class="mt-3 text-sm text-neutral-600">The current public listing stays unchanged until this edit is approved.</p>
                @endif
            </div>
        </div>
        <div class="mt-4 flex flex-wrap items-end gap-3">
            <form method="POST" action="{{ route('admin.products.approve', $product) }}">
                @csrf
                <button type="submit" class="rounded bg-primary-700 px-4 py-2 text-sm font-semibold text-white">Approve</button>
            </form>
            <form method="POST" action="{{ route('admin.products.reject', $product) }}" class="flex flex-1 flex-wrap items-end gap-2">
                @csrf
                <label class="min-w-[16rem] flex-1 text-sm font-medium text-neutral-700">Rejection reason
                    <input name="reason" required maxlength="1000" class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
                </label>
                <button type="submit" class="rounded border border-red-300 px-4 py-2 text-sm font-semibold text-red-700">Reject</button>
            </form>
            <a href="{{ route('admin.products.show', $product) }}" class="text-sm font-medium text-primary-700">Full details</a>
        </div>
    </article>
@empty
    <p class="rounded-lg border border-neutral-200 bg-white p-8 text-neutral-600">No products are waiting for approval.</p>
@endforelse

{{ $products->links() }}
@endsection
