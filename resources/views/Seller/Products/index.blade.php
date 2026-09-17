@extends('Layouts.seller')

@section('title', 'Products')

@section('content')

@if (session('success'))
    <div class="sp-toast">{{ session('success') }}</div>
@endif

<div class="sp-header">
    <div>
        <h1 class="admin-page-title">Products</h1>
        <p class="admin-page-subtitle">Manage your product listings.</p>
    </div>
    <a href="{{ route('seller.products.create') }}" class="sp-btn sp-btn--primary">
        <i data-lucide="plus" width="16" height="16"></i>
        Add Product
    </a>
</div>

<form method="GET" class="sp-filters">
    <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Search products...">
    <select name="status" onchange="this.form.submit()">
        <option value="">All statuses</option>
        <option value="active" {{ ($filters['status'] ?? '') === 'active' ? 'selected' : '' }}>Active</option>
        <option value="draft" {{ ($filters['status'] ?? '') === 'draft' ? 'selected' : '' }}>Draft</option>
        <option value="inactive" {{ ($filters['status'] ?? '') === 'inactive' ? 'selected' : '' }}>Inactive</option>
    </select>
    <button type="submit" class="sp-btn sp-btn--outline">Search</button>
</form>

@if ($products->isEmpty())
    <div class="sp-empty">
        <i data-lucide="package" width="32" height="32"></i>
        <p>No products yet. Add your first one to start selling.</p>
    </div>
@else
    <div class="sp-grid">
        @foreach ($products as $product)
            @php $primary = $product->images->firstWhere('is_primary', true) ?? $product->images->first(); @endphp
            <div class="sp-card">
                <div class="sp-card__image">
                    @if ($primary)
                        <img src="{{ Storage::url($primary->path) }}" alt="{{ $product->name }}">
                    @else
                        <div class="sp-card__no-image"><i data-lucide="image" width="24" height="24"></i></div>
                    @endif
                    <span class="sp-status-badge sp-status-badge--{{ $product->status }}">{{ ucfirst($product->status) }}</span>
                </div>
                <div class="sp-card__body">
                    <h3>{{ $product->name }}</h3>
                    <span class="sp-card__price">₱{{ number_format($product->price, 2) }}</span>
                    <span class="sp-card__stock {{ $product->isOutOfStock() ? 'is-out' : ($product->isLowStock() ? 'is-low' : '') }}">
                        @if ($product->isOutOfStock())
                            Out of stock
                        @elseif ($product->isLowStock())
                            Low stock: {{ $product->stock_quantity }}
                        @else
                            {{ $product->stock_quantity }} in stock
                        @endif
                    </span>
                </div>
                <div class="sp-card__actions">
                    <a href="{{ route('seller.products.edit', $product) }}" class="sp-btn sp-btn--outline sp-btn--sm">Edit</a>
                    <form method="POST" action="{{ route('seller.products.destroy', $product) }}"
                          onsubmit="return confirm('Delete &quot;{{ $product->name }}&quot;? This cannot be undone.');">
                        @csrf @method('DELETE')
                        <button type="submit" class="sp-btn sp-btn--danger sp-btn--sm">Delete</button>
                    </form>
                </div>
            </div>
        @endforeach
    </div>

    <div class="sp-pagination">
        {{ $products->links() }}
    </div>
@endif

@endsection