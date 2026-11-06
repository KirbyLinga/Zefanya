@extends('Admin.Layouts.app')

@section('title', 'Product Oversight')

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

<div class="mb-8">
    <h1 class="text-[28px] font-bold text-neutral-950 mb-1">Product Oversight</h1>
    <p class="text-base text-neutral-600">Review listings from all sellers. Restrict prohibited products with a required reason; restricted products are hidden from buyers and cannot be edited by the seller.</p>
</div>

<div class="mb-6 bg-white border border-neutral-200 rounded-lg p-5">
    <form method="GET" action="{{ route('admin.products.index') }}" class="flex flex-col lg:flex-row lg:items-end gap-4">
        <div class="flex-1 lg:flex-2">
            <label for="search" class="block text-[11px] font-semibold text-neutral-600 uppercase tracking-[0.5px] mb-2">Search</label>
            <input type="text" name="search" id="search" value="{{ $search }}" placeholder="Product name, description…"
                   class="w-full h-10 px-3 border border-neutral-300 rounded-lg text-base focus:ring-2 focus:ring-primary-400 focus:border-transparent" />
        </div>
        <div class="lg:w-52">
            <label for="seller" class="block text-[11px] font-semibold text-neutral-600 uppercase tracking-[0.5px] mb-2">Seller</label>
            <select name="seller" id="seller" class="w-full h-10 px-3 border border-neutral-300 rounded-lg text-base">
                <option value="">All sellers</option>
                @foreach ($sellers as $seller)
                    <option value="{{ $seller->id }}" {{ $sellerId == $seller->id ? 'selected' : '' }}>{{ $seller->business_name }}</option>
                @endforeach
            </select>
        </div>
        <div class="lg:w-48">
            <label for="status" class="block text-[11px] font-semibold text-neutral-600 uppercase tracking-[0.5px] mb-2">Listing Status</label>
            <select name="status" id="status" class="w-full h-10 px-3 border border-neutral-300 rounded-lg text-base">
                <option value="">All statuses</option>
                <option value="active" {{ $status === 'active' ? 'selected' : '' }}>Active</option>
                <option value="inactive" {{ $status === 'inactive' ? 'selected' : '' }}>Inactive</option>
                <option value="draft" {{ $status === 'draft' ? 'selected' : '' }}>Draft</option>
            </select>
        </div>
        <div class="lg:w-48">
            <label for="restricted" class="block text-[11px] font-semibold text-neutral-600 uppercase tracking-[0.5px] mb-2">Restriction</label>
            <select name="restricted" id="restricted" class="w-full h-10 px-3 border border-neutral-300 rounded-lg text-base">
                <option value="">All</option>
                <option value="1" {{ $restricted === '1' ? 'selected' : '' }}>Restricted only</option>
                <option value="0" {{ $restricted === '0' ? 'selected' : '' }}>Not restricted</option>
            </select>
        </div>
        <button type="submit" class="h-10 px-5 bg-primary-800 text-white font-semibold text-[13px] rounded-lg hover:bg-primary-900 transition-colors">
            Filter
        </button>
    </form>
</div>
<div class="bg-white border border-neutral-200 rounded-lg overflow-hidden">
    @if ($products->count() > 0)
        <table class="w-full text-base">
            <thead class="bg-neutral-50 border-b border-neutral-200">
                <tr>
                    <th class="px-6 py-4 text-left font-semibold text-neutral-700">Product</th>
                    <th class="px-6 py-4 text-left font-semibold text-neutral-700">Seller</th>
                    <th class="px-6 py-4 text-left font-semibold text-neutral-700">Category</th>
                    <th class="px-6 py-4 text-left font-semibold text-neutral-700">Status</th>
                    <th class="px-6 py-4 text-left font-semibold text-neutral-700">Restriction</th>
                    <th class="px-6 py-4 text-right font-semibold text-neutral-700">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
                @foreach ($products as $product)
                    <tr>
                        <td class="px-6 py-4">
                            <div class="font-medium text-neutral-900">{{ $product->name }}</div>
                            <div class="text-xs text-neutral-500">#{{ $product->id }} · {{ $product->slug }}</div>
                        </td>
                        <td class="px-6 py-4">
                            <div class="font-medium text-neutral-900">{{ $product->seller?->business_name ?? 'Unknown' }}</div>
                            <div class="text-xs text-neutral-500">{{ $product->seller->status ?? '—' }}</div>
                        </td>
                        <td class="px-6 py-4 text-neutral-600">
                            {{ $product->category?->label ?? '—' }}
                        </td>
                        <td class="px-6 py-4">
                            <span class="inline-flex px-3 py-1 rounded-full text-xs font-semibold
                                @if ($product->status === 'active') bg-green-50 text-green-700
                                @elseif ($product->status === 'inactive') bg-red-50 text-red-700
                                @else bg-neutral-100 text-neutral-700 @endif">
                                {{ ucfirst($product->status) }}
                            </span>
                        </td>
                        <td class="px-6 py-4">
                            @if ($product->isRestricted())
                                <span class="inline-flex px-3 py-1 rounded-full text-xs font-semibold bg-orange-50 text-orange-700">
                                    Restricted
                                </span>
                                <div class="mt-1 text-xs text-neutral-500 max-w-xs truncate" title="{{ $product->restriction_reason }}">{{ $product->restriction_reason }}</div>
                            @else
                                <span class="inline-flex px-3 py-1 rounded-full text-xs font-semibold bg-green-50 text-green-700">
                                    Clear
                                </span>
                            @endif
                        </td>
                        <td class="px-6 py-4 text-right">
                            <a href="{{ route('admin.products.show', $product) }}" class="text-sm font-medium text-primary-700 hover:text-primary-900">View</a>
                            @if ($product->isRestricted())
                                <form method="POST" action="{{ route('admin.products.unrestrict', $product) }}" class="inline" onsubmit="return confirm('Unrestrict this product? Buyers will be able to see and purchase it again.')">
                                    @csrf
                                    <button type="submit" class="px-3 py-1.5 text-xs font-semibold text-green-700 bg-green-50 rounded-lg hover:bg-green-100">Unrestrict</button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('admin.products.restrict', $product) }}" class="inline" onsubmit="return confirm('Restrict this product? It will be hidden from buyers and the seller cannot edit it.')">
                                    @csrf
                                    <label for="restrict-reason-{{ $product->id }}" class="sr-only">Restriction reason</label>
                                    <input id="restrict-reason-{{ $product->id }}" name="reason" required maxlength="1000" placeholder="Reason for restriction"
                                           class="mb-2 w-full h-10 px-3 border border-neutral-300 rounded-lg text-sm" />
                                    <button type="submit" class="px-3 py-1.5 text-xs font-semibold text-orange-700 bg-orange-50 rounded-lg hover:bg-orange-100">Restrict</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <div class="px-6 py-4 border-t border-neutral-200">
            {{ $products->links() }}
        </div>
    @else
        <div class="px-6 py-16 text-center text-neutral-500">No products match your filters.</div>
    @endif
</div>

@endsection