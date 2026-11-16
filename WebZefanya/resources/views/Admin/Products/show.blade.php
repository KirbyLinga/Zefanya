@extends('Admin.Layouts.app')

@section('title', 'Product Details')

@section('content')

<x-flash type="success" :message="session('success')" spacing="mb-6" />
<x-flash type="error" :message="session('error')" spacing="mb-6" />

<div class="mb-6 flex items-center justify-between">
    <div>
        <h1 class="text-[28px] font-bold text-neutral-950 mb-1">{{ $product->name }}</h1>
        <p class="text-base text-neutral-600">Product #{{ $product->id }} &mdash; read-only oversight view. No inventory or financial records are modified here.</p>
    </div>
    <a href="{{ route('admin.products.index') }}" class="text-sm font-medium text-primary-700 hover:text-primary-900">&larr; Back to product list</a>
</div>

@php($pendingEdit = \Illuminate\Support\Facades\Schema::hasTable('product_revisions') && $product->relationLoaded('latestRevision') && $product->latestRevision !== null && $product->latestRevision->status === 'pending')
@if ($product->status === 'pending' || $product->status === 'rejected' || $pendingEdit)
    <section class="mb-6 rounded-lg border border-neutral-200 bg-white p-5">
        <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-neutral-600">Approval</h2>
        <p class="mb-4 text-sm text-neutral-700">{{ $pendingEdit ? 'A seller edit is waiting. The live listing does not change until you approve it.' : 'This product is not visible to buyers.' }}</p>
        @if ($product->rejection_reason)
            <p class="mb-4 text-sm text-neutral-700"><span class="font-semibold">Last reason:</span> {{ $product->rejection_reason }}</p>
        @endif
        <div class="flex flex-wrap items-end gap-3">
            <form method="POST" action="{{ route('admin.products.approve', $product) }}">@csrf<button type="submit" class="rounded bg-primary-700 px-4 py-2 text-sm font-semibold text-white">Approve</button></form>
            <form method="POST" action="{{ route('admin.products.reject', $product) }}" class="flex flex-1 flex-wrap items-end gap-2">
                @csrf
                <label class="min-w-[16rem] flex-1 text-sm">Rejection reason<input name="reason" required maxlength="1000" class="mt-1 w-full rounded border border-neutral-300 px-3 py-2"></label>
                <button type="submit" class="rounded border border-red-300 px-4 py-2 text-sm font-semibold text-red-700">Reject</button>
            </form>
        </div>
        @if ($product->relationLoaded('approvalEvents') && $product->approvalEvents->isNotEmpty())
            <ul class="mt-4 space-y-1 text-sm text-neutral-600">
                @foreach ($product->approvalEvents as $event)
                    <li>{{ $event->created_at?->timezone(\App\Models\Seller\StoreSale::TIMEZONE)->format('M j, Y g:i A') }} — {{ ucfirst($event->decision) }} by {{ $event->admin_name }}@if ($event->reason) — {{ $event->reason }}@endif</li>
                @endforeach
            </ul>
        @endif
    </section>
@endif

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
                <dd class="text-neutral-900">{{ manila($product->created_at, 'M d, Y H:i', '—') }}</dd>
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
            <div class="overflow-x-auto">
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
        </div>
    @endif
</div>

@endsection
