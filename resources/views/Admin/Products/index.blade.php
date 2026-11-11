@extends('Admin.Layouts.app')

@section('title', 'Product Oversight')
@section('content-width', 'max-w-none')

@section('content')
@php
    $hasFilters = $search !== '' || $sellerId > 0 || $status !== '' || in_array($restricted, ['0', '1'], true);
@endphp
<section class="product-oversight" aria-labelledby="product-page-title">
    <header class="product-header">
        <p class="product-eyebrow">Marketplace / Catalog review</p>
        <h1 id="product-page-title">Product Oversight</h1>
        <p>Review seller listings and keep the marketplace safe.</p>
    </header>

    @if (session('success'))
        <div class="product-notice product-notice-success" role="status"><i data-lucide="circle-check" aria-hidden="true"></i><p>{{ session('success') }}</p></div>
    @endif
    @if (session('error'))
        <div class="product-notice product-notice-error" role="alert"><i data-lucide="circle-alert" aria-hidden="true"></i><p>{{ session('error') }}</p></div>
    @endif
    @if ($errors->any())
        <div class="product-notice product-notice-error" role="alert">
            <i data-lucide="circle-alert" aria-hidden="true"></i>
            <div><strong>The action could not be completed.</strong><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        </div>
    @endif

    <div class="product-policy"><i data-lucide="shield-check" aria-hidden="true"></i><p><strong>Moderate with a reason.</strong> Restricted products are hidden from buyers and locked for seller edits. Listing status is managed separately.</p></div>

    <section class="product-filter-panel" aria-label="Filter products">
        <form data-admin-auto-filter method="GET" action="{{ route('admin.products.index') }}" class="product-filters">
            <div class="product-search">
                <label for="search">Search products</label>
                <div><i data-lucide="search" aria-hidden="true"></i><input type="search" name="search" id="search" value="{{ $search }}" maxlength="100" placeholder="Name, description or slug…" /></div>
            </div>
            <div>
                <label for="seller">Seller</label>
                <select name="seller" id="seller"><option value="">All sellers</option>@foreach ($sellers as $seller)<option value="{{ $seller->id }}" @selected($sellerId == $seller->id)>{{ $seller->business_name }}</option>@endforeach</select>
            </div>
            <div>
                <label for="status">Listing status</label>
                <select name="status" id="status"><option value="">All statuses</option>@foreach (['active', 'inactive', 'draft'] as $listingStatus)<option value="{{ $listingStatus }}" @selected($status === $listingStatus)>{{ ucfirst($listingStatus) }}</option>@endforeach</select>
            </div>
            <div>
                <label for="restricted">Restriction</label>
                <select name="restricted" id="restricted"><option value="">All products</option><option value="1" @selected($restricted === '1')>Restricted only</option><option value="0" @selected($restricted === '0')>Not restricted</option></select>
            </div>
            <a class="product-button product-reset" href="{{ route('admin.products.index') }}"><i data-lucide="rotate-ccw" aria-hidden="true"></i>Reset</a>
            <noscript><button class="product-button product-primary" type="submit">Apply filters</button></noscript>
        </form>
        <div class="product-results">
            <p>Showing <strong>{{ $products->firstItem() ?? 0 }}–{{ $products->lastItem() ?? 0 }}</strong> of <strong>{{ number_format($products->total()) }}</strong> {{ $hasFilters ? 'matching' : '' }} {{ Str::plural('product', $products->total()) }}</p>
            <p class="product-filter-note">Filters update automatically</p>
        </div>
    </section>

    <section class="product-table-panel" aria-labelledby="product-list-title">
        <div class="product-table-heading"><h2 id="product-list-title">{{ $hasFilters ? 'Filtered listings' : 'All listings' }} <span>{{ number_format($products->total()) }}</span></h2><p><i data-lucide="arrow-down-wide-narrow" aria-hidden="true"></i>Recently updated first</p></div>
        @if ($products->count() > 0)
            <p class="product-scroll-hint" id="product-scroll-hint"><i data-lucide="move-horizontal" aria-hidden="true"></i>Scroll horizontally for listing details and actions</p>
            <div class="product-table-scroll" role="region" aria-label="Product listings" aria-describedby="product-scroll-hint" tabindex="0">
                <table>
                    <caption class="sr-only">Seller listings with listing status, restrictions, and moderation actions</caption>
                    <thead><tr><th scope="col">Product</th><th scope="col">Seller</th><th scope="col">Category</th><th scope="col">Listing status</th><th scope="col">Restriction</th><th scope="col" class="product-actions-heading">Actions</th></tr></thead>
                    <tbody>
                        @foreach ($products as $product)
                            @php($hasReasonError = $errors->has('reason') && (is_scalar(old('product_id')) ? (string) old('product_id') : '') === (string) $product->id)
                            <tr>
                                <th scope="row"><a class="product-name" href="{{ route('admin.products.show', $product) }}">{{ $product->name }}</a><span class="product-secondary">Product #{{ $product->id }}</span></th>
                                <td><span class="product-seller">{{ $product->seller?->business_name ?? 'Unknown seller' }}</span><span class="product-secondary">{{ ucfirst($product->seller?->status ?? 'Unknown status') }}</span></td>
                                <td class="product-category">{{ $product->category?->label ?? 'Uncategorized' }}</td>
                                <td><span class="product-badge product-status-{{ in_array($product->status, ['active', 'inactive', 'draft'], true) ? $product->status : 'draft' }}"><span aria-hidden="true"></span>{{ ucfirst($product->status) }}</span></td>
                                <td>
                                    @if ($product->isRestricted())
                                        <span class="product-badge product-restricted"><i data-lucide="shield-alert" aria-hidden="true"></i>Restricted</span>
                                        <details class="product-reason"><summary>View reason</summary><p>{{ $product->restriction_reason }}</p></details>
                                    @else
                                        <span class="product-clear"><i data-lucide="shield-check" aria-hidden="true"></i>Clear</span>
                                    @endif
                                </td>
                                <td class="product-actions-cell">
                                    <div class="product-actions">
                                        <a class="product-button product-view" href="{{ route('admin.products.show', $product) }}" aria-label="View {{ $product->name }}">View<i data-lucide="arrow-up-right" aria-hidden="true"></i></a>
                                        @if ($product->isRestricted())
                                            <form method="POST" action="{{ route('admin.products.unrestrict', $product) }}" onsubmit="return confirm('Remove this restriction? Listing status stays unchanged. Buyer visibility still depends on listing and seller eligibility.')">
                                                @csrf
                                                <button type="submit" class="product-button product-restore" aria-label="Restore {{ $product->name }}"><i data-lucide="rotate-ccw" aria-hidden="true"></i>Restore</button>
                                            </form>
                                        @else
                                            <details class="product-moderate" @if ($hasReasonError) open @endif>
                                                <summary class="product-button product-restrict"><i data-lucide="shield-alert" aria-hidden="true"></i>Restrict<i class="product-chevron" data-lucide="chevron-down" aria-hidden="true"></i></summary>
                                                <form method="POST" action="{{ route('admin.products.restrict', $product) }}" onsubmit="return confirm('Restrict this product? It will be hidden from buyers and the seller cannot edit it.')">
                                                    @csrf
                                                    <input type="hidden" name="product_id" value="{{ $product->id }}" />
                                                    <label for="restrict-reason-{{ $product->id }}">Reason for restriction <span>(required)</span></label>
                                                    <p id="restrict-help-{{ $product->id }}">This hides the product from buyers and locks seller edits.</p>
                                                    <textarea id="restrict-reason-{{ $product->id }}" name="reason" required maxlength="1000" rows="3" placeholder="Explain the policy issue…" aria-describedby="restrict-help-{{ $product->id }}@if ($hasReasonError) restrict-error-{{ $product->id }}@endif" @if ($hasReasonError) aria-invalid="true" @endif>{{ (is_scalar(old('product_id')) ? (string) old('product_id') : '') === (string) $product->id && is_string(old('reason')) ? old('reason') : '' }}</textarea>
                                                    @if ($hasReasonError)<p id="restrict-error-{{ $product->id }}" class="product-field-error">{{ $errors->first('reason') }}</p>@endif
                                                    <div class="product-form-footer"><span>Up to 1,000 characters</span><button type="submit" class="product-button product-primary">Confirm restriction</button></div>
                                                </form>
                                            </details>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <footer class="product-pagination">
                <p>Page <strong>{{ $products->currentPage() }}</strong> of <strong>{{ $products->lastPage() }}</strong><span>25 products per page</span></p>
                @if ($products->hasPages())
                    <nav aria-label="Product pagination">
                        @if ($products->onFirstPage())<span class="product-button" aria-disabled="true"><i data-lucide="chevron-left" aria-hidden="true"></i>Previous</span>@else<a class="product-button" href="{{ $products->previousPageUrl() }}" rel="prev"><i data-lucide="chevron-left" aria-hidden="true"></i>Previous</a>@endif
                        @if ($products->hasMorePages())<a class="product-button" href="{{ $products->nextPageUrl() }}" rel="next">Next<i data-lucide="chevron-right" aria-hidden="true"></i></a>@else<span class="product-button" aria-disabled="true">Next<i data-lucide="chevron-right" aria-hidden="true"></i></span>@endif
                    </nav>
                @endif
            </footer>
        @else
            <div class="product-empty"><div class="product-empty-icon"><i data-lucide="{{ $hasFilters ? 'search-x' : 'package-open' }}" aria-hidden="true"></i></div><h3>{{ $hasFilters ? 'No products match your filters' : 'No products to review yet' }}</h3><p>{{ $hasFilters ? 'Try a different search or clear your filters to see all listings.' : 'Seller listings will appear here when products are added to the marketplace.' }}</p>@if ($hasFilters)<a class="product-button product-primary" href="{{ route('admin.products.index') }}">Clear filters</a>@endif</div>
        @endif
    </section>
</section>
@endsection
