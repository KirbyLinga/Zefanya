@extends('Admin.Layouts.app')

@section('title', 'Product Details')

@section('content')

@if (session('success'))
    <div class="mb-6 p-4 bg-green-50 border border-green-200 rounded-lg text-green-700 text-sm">
        {{ session('success') }}
    </div>
@endif
@if (session('error'))
    <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg text-red-700 text-sm">
        {{ session('error') }}
    </div>
@endif

<div class="mb-6 flex items-center justify-between">
    <div>
        <h1 class="text-[28px] font-bold text-neutral-950 mb-1">{{ $product->name }}</h1>
        <p class="text-base text-neutral-600">Product #{{ $product->id }} &mdash; read-only oversight view. No inventory or financial records are modified here.</p>
    </div>
    <a href="{{ route('admin.products.index') }}" class="text-sm font-medium text-primary-700 hover:text-primary-900">&larr; Back to product list</a>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
    <div class="bg-white border border-neutral-200 rounded-lg p-5">
        <h2 class="text-[13px] font-semibold text-neutral-600 uppercase tracking-[0.5px] mb-4">Listing Details</h2>
        <dl class="space-y-3 text-sm">
            <div class="flex justify-between border-b border-neutral-50 pb-2">
                <dt class="text-neutral-500">SKU / ID</dt>
                <dd class="text-neutral-900 font-mono">#{{ $product->id }}</dd>
            </div>
            <div class="flex justify-between border-b border-neutral-50 pb-2">
                <dt class="text-neutral-500">Slug</dt>
                <dd class="text-neutral-900">{{ $product->slug }}</dd>
            </div>
            <div class="flex justify-between border-b border-neutral-50 pb-2">
                <dt class="text-neutral-500">Seller</dt>
                <dd class="text-neutral-900">{{ $product->seller?->business_name ?? 'Unknown' }}</dd>
            </div>
            <div class="flex justify-between border-b border-neutral-50 pb-2">
                <dt class="text-neutral-500">Category</dt>
                <dd class="text-neutral-900">{{ $product->category?->label ?? '—' }}</dd>
            </div>
            <div class="flex justify-between border-b border-neutral-50 pb-2">
                <dt class="text-neutral-500">Listing Status</dt>
                <dd class="text-neutral-900">
                    <span class="inline-flex px-3 py-1 rounded-full text-xs font-semibold
                        @if ($product->status === 'active') bg-green-50 text-green-700
                        @elseif ($product->status === 'inactive') bg-red-50 text-red-700
                        @else bg-neutral-100 text-neutral-700 @endif">
                        {{ ucfirst($product->status) }}
                    </span>
                </dd>
            </div>
            <div class="flex justify-between border-b border-neutral-50 pb-2">
                <dt class="text-neutral-500">Admin Restriction</dt>
                <dd class="text-neutral-900">
                    @if ($product->isRestricted())
                        <span class="inline-flex px-3 py-1 rounded-full text-xs font-semibold bg-orange-50 text-orange-700">
                            Restricted
                        </span>
                        <p class="mt-1 text-xs text-neutral-500">{{ $product->restriction_reason }}</p>
                    @else
                        <span class="inline-flex px-3 py-1 rounded-full text-xs font-semibold bg-green-50 text-green-700">
                            Not restricted
                        </span>
                    @endif
                </dd>
            </div>
            <div class="flex justify-between border-b border-neutral-50 pb-2">
                <dt class="text-neutral-500">Price</dt>
                <dd class="text-neutral-900">{{ $product->formattedPrice() }}</dd>
            </div>
            <div class="flex justify-between border-b border-neutral-50 pb-2">
                <dt class="text-neutral-500">Stock (rollup)</dt>
                <dd class="text-neutral-900">{{ $product->stock_quantity }} units</dd>
            </div>
            <div class="flex justify-between border-b border-neutral-50 pb-2">
                <dt class="text-neutral-500">Low Stock Threshold</dt>
                <dd class="text-neutral-900">{{ $product->low_stock_threshold }}</dd>
            </div>
            <div class="flex justify-between border-b border-neutral-50 pb-2">
                <dt class="text-neutral-500">Variants</dt>
                <dd class="text-neutral-900">{{ $product->variants->count() }}</dd>
            </div>
            <div class="flex justify-between border-b border-neutral-50 pb-2">
                <dt class="text-neutral-500">Created</dt>
                <dd class="text-neutral-900">{{ $product->created_at?->format('M d, Y H:i') ?? '—' }}</dd>
            </div>
        </dl>
    </div>
    <div class="bg-white border border-neutral-200 rounded-lg p-5">
        <h2 class="text-[13px] font-semibold text-neutral-600 uppercase tracking-[0.5px] mb-4">Description</h2>
        <div class="text-neutral-700 whitespace-pre-line">{{ $product->description ?? 'No description provided.' }}</div>
    </div>

    @if ($product->images->count() > 0)
        <div class="bg-white border border-neutral-200 rounded-lg p-5">
            <h2 class="text-[13px] font-semibold text-neutral-600 uppercase tracking-[0.5px] mb-4">Images ({{ $product->images->count() }})</h2>
            <div class="grid grid-cols-3 gap-2">
                @foreach ($product->images->take(9) as $image)
                    <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($image->path) }}"
                         alt="{{ $product->name }}"
                         class="w-full h-24 object-cover rounded border border-neutral-200" />
                @endforeach
            </div>
        </div>
    @endif

    @if ($product->variants->count() > 0)
        <div class="bg-white border border-neutral-200 rounded-lg p-5">
            <h2 class="text-[13px] font-semibold text-neutral-600 uppercase tracking-[0.5px] mb-4">Variants ({{ $product->variants->count() }})</h2>
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-neutral-500 border-b border-neutral-100">
                        <th class="px-3 py-2 text-left">Variant</th>
                        <th class="px-3 py-2 text-right">Price</th>
                        <th class="px-3 py-2 text-right">Stock</th>
                        <th class="px-3 py-2 text-right">Available</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($product->variants as $variant)
                        <tr class="border-b border-neutral-50">
                            <td class="px-3 py-2 text-neutral-900">{{ $variant->label() }}</td>
                            <td class="px-3 py-2 text-right text-neutral-900">{{ $variant->formattedPrice() }}</td>
                            <td class="px-3 py-2 text-right text-neutral-900">{{ $variant->stock_quantity }}</td>
                            <td class="px-3 py-2 text-right">
                                <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-semibold
                                    {{ $variant->is_available && $variant->stock_quantity > 0 ? 'bg-green-50 text-green-700' : 'bg-neutral-100 text-neutral-500' }}">
                                    {{ $variant->is_available && $variant->stock_quantity > 0 ? 'Yes' : 'No' }}
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

@endsection
