@extends('Layouts.seller')

@section('title', 'Products')

@push('styles')
    @vite('resources/css/seller/products-index.css')
@endpush

@section('content')
@php
    /**
     * Low-stock limit for the STOCK pill — single source of truth for this page.
     * 0 → "0 in stock" (rose), 1..$lowStockLimit → "N left (Low)" (amber), above → "N in stock" (green).
     * Mirrors the products.low_stock_threshold column default.
     */
    $lowStockLimit = 5;

    $statusOptions = ['active' => 'Active', 'draft' => 'Draft', 'inactive' => 'Inactive'];

    // Deliberately the screenshot's filter set (the controller still accepts its full list).
    $sortOptions = [
        'newest' => 'Newest',
        'oldest' => 'Oldest',
        'price_desc' => 'Price high-low',
        'price_asc' => 'Price low-high',
        'name_asc' => 'Name A-Z',
    ];

    $searchTerm = $filters['q'] ?? null;
    $statusFilter = $filters['status'] ?? null;
    $categoryFilter = $filters['category'] ?? null;
    $categoryFilterLabel = $categoryFilter
        ? optional($categories->firstWhere('id', (int) $categoryFilter))->label
        : null;
    $hasFilters = filled($searchTerm) || filled($statusFilter) || filled($categoryFilter);

    $total = $products->total();
    $currentPage = $products->currentPage();
    $lastPage = $products->lastPage();
    $pageWindow = $products->getUrlRange(max(1, $currentPage - 2), min($lastPage, $currentPage + 2));
@endphp

<div class="sp-page">
    @if (session('success'))
        <div class="sp-flash" role="status">{{ session('success') }}</div>
    @endif

    <header class="sp-header">
        <div class="sp-header__titles">
            <h1 class="sp-title">Products</h1>
            <p class="sp-subtitle">Manage your product listings.</p>
        </div>

        <div class="sp-header__actions">
            {{-- UI only: no export route exists yet. --}}
            <button type="button" class="sp-btn sp-btn--outline" data-export-csv>
                <i data-lucide="upload" width="15" height="15"></i>
                Export CSV
            </button>

            <div class="sp-dropdown" data-dropdown>
                <button type="button" class="sp-btn sp-btn--outline" id="spBulkToggle" data-dropdown-toggle data-bulk-toggle
                        aria-haspopup="true" aria-expanded="false" disabled>
                    Bulk Actions
                    <i data-lucide="chevron-down" width="15" height="15"></i>
                </button>
                {{-- UI only: no bulk route exists; these items are not wired to anything. --}}
                <div class="sp-dropdown__menu sp-dropdown__menu--right" data-dropdown-menu role="menu" aria-labelledby="spBulkToggle" hidden>
                    <button type="button" role="menuitem" class="sp-dropdown__item" data-bulk-action="activate">
                        <i data-lucide="circle-check" width="14" height="14"></i>Set Active
                    </button>
                    <button type="button" role="menuitem" class="sp-dropdown__item" data-bulk-action="inactivate">
                        <i data-lucide="circle-pause" width="14" height="14"></i>Set Inactive
                    </button>
                    <button type="button" role="menuitem" class="sp-dropdown__item sp-dropdown__item--danger" data-bulk-action="delete">
                        <i data-lucide="trash-2" width="14" height="14"></i>Delete Selected
                    </button>
                </div>
            </div>

            <button type="button" data-product-create-open class="sp-btn sp-btn--solid">
                <i data-lucide="plus" width="16" height="16"></i>
                Add Product
            </button>
        </div>
    </header>

    <section class="sp-card">
        <form method="GET" action="{{ route('seller.products.index') }}" class="sp-filters" id="spProductFilters" role="search">
            <label class="sp-search">
                <i data-lucide="search" width="15" height="15" aria-hidden="true"></i>
                <span class="sp-visually-hidden">Search products</span>
                <input type="search" name="q" value="{{ $searchTerm }}" placeholder="Search products..." data-auto-submit>
            </label>

            <select name="category" class="sp-select" aria-label="Filter by category" data-auto-submit>
                <option value="">All categories</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}" @selected((int) $categoryFilter === (int) $category->id)>{{ $category->label }}</option>
                @endforeach
            </select>

            <select name="status" class="sp-select" aria-label="Filter by status" data-auto-submit>
                <option value="">All statuses</option>
                @foreach ($statusOptions as $value => $label)
                    <option value="{{ $value }}" @selected($statusFilter === $value)>{{ $label }}</option>
                @endforeach
            </select>

            <select name="sort" class="sp-select" aria-label="Sort products" data-auto-submit>
                @foreach ($sortOptions as $value => $label)
                    <option value="{{ $value }}" @selected($sort === $value)>{{ $label }}</option>
                @endforeach
                @if (! array_key_exists($sort, $sortOptions))
                    {{-- The controller still accepts sorts outside the screenshot's set; keep the real one visible. --}}
                    <option value="{{ $sort }}" selected>{{ ucwords(str_replace('_', ' ', $sort)) }}</option>
                @endif
            </select>

            <button type="submit" class="sp-btn sp-btn--outline">Search</button>
        </form>

        <div class="sp-chips">
            <span class="sp-chips__label">Active filter:</span>

            @if ($categoryFilterLabel)
                <span class="sp-chip sp-chip--active">
                    Category: {{ $categoryFilterLabel }}
                    <a href="{{ request()->fullUrlWithQuery(['category' => null, 'page' => null]) }}" class="sp-chip__remove" aria-label="Remove the category filter">&times;</a>
                </span>
            @else
                <span class="sp-chip">Category: All</span>
            @endif

            @if ($statusFilter)
                <span class="sp-chip sp-chip--active">
                    Status: {{ $statusOptions[$statusFilter] ?? ucfirst($statusFilter) }}
                    <a href="{{ request()->fullUrlWithQuery(['status' => null, 'page' => null]) }}" class="sp-chip__remove" aria-label="Remove the status filter">&times;</a>
                </span>
            @else
                <span class="sp-chip">Status: All</span>
            @endif

            @if (filled($searchTerm))
                <span class="sp-chip sp-chip--active">
                    Search: “{{ $searchTerm }}”
                    <a href="{{ request()->fullUrlWithQuery(['q' => null, 'page' => null]) }}" class="sp-chip__remove" aria-label="Clear the search term">&times;</a>
                </span>
            @endif

            <a href="{{ route('seller.products.index') }}" class="sp-chips__reset">Reset</a>
        </div>

        @if ($products->isEmpty())
            <div class="sp-empty">
                <span class="sp-empty__icon"><i data-lucide="{{ $hasFilters ? 'search-x' : 'package-open' }}" width="26" height="26"></i></span>
                <p class="sp-empty__title">{{ $hasFilters ? 'No products match your filters' : 'No products yet' }}</p>
                <p class="sp-empty__text">
                    {{ $hasFilters
                        ? 'Try a different search term, or reset the filters to see every product.'
                        : 'Add your first product to start selling in your store.' }}
                </p>
                @if ($hasFilters)
                    <a href="{{ route('seller.products.index') }}" class="sp-btn sp-btn--solid sp-btn--sm">Reset filters</a>
                @else
                    <button type="button" data-product-create-open class="sp-btn sp-btn--solid sp-btn--sm">
                        <i data-lucide="plus" width="15" height="15"></i>
                        Create your first product
                    </button>
                @endif
            </div>
        @else
            <div class="sp-table-wrap">
                <table class="sp-table">
                    <thead>
                        <tr>
                            <th scope="col" class="sp-table__check">
                                <input type="checkbox" data-select-all aria-label="Select all products on this page">
                            </th>
                            <th scope="col">Product</th>
                            <th scope="col" class="sp-col--category">Category</th>
                            <th scope="col" class="sp-table__num">Price</th>
                            <th scope="col">Stock</th>
                            <th scope="col">Status</th>
                            <th scope="col" class="sp-table__actions"><span class="sp-visually-hidden">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($products as $product)
                            @php
                                $stock = (int) $product->stock_quantity;
                                $isOutOfStock = $stock <= 0;
                                $isLowStock = ! $isOutOfStock && $stock <= $lowStockLimit;

                                $stockPillClass = $isOutOfStock ? 'sp-pill--rose' : ($isLowStock ? 'sp-pill--amber' : 'sp-pill--green');
                                $stockLabel = $isOutOfStock
                                    ? '0 in stock'
                                    : ($isLowStock ? $stock.' left (Low)' : $stock.' in stock');

                                // Colour contract shared with public/js/seller/products-table.js:
                                // status tones are THEME-AWARE classes (sp-tone-*/sp-dot-*)
                                // styled once in products-index.css — no raw hex utilities.
                                $statusTone = $isOutOfStock
                                    ? 'stock'
                                    : ($product->status === 'active' ? 'active' : ($product->status === 'inactive' ? 'inactive' : 'draft'));
                                $statusLabel = $isOutOfStock ? 'Out of Stock' : ucfirst($product->status);
                            @endphp

                            <tr data-product-row="{{ $product->id }}" class="sp-table__row">
                                <td class="sp-table__check">
                                    <input type="checkbox" class="sp-row-check" name="product_ids[]" value="{{ $product->id }}"
                                           aria-label="Select {{ $product->name }}">
                                </td>
                                <td>
                                    <div class="sp-product">
                                        @if ($product->catalogImageUrl())
                                            <img src="{{ $product->catalogImageUrl() }}" alt="" width="36" height="36" class="sp-product__thumb">
                                        @else
                                            <span class="sp-product__thumb sp-product__thumb--empty"><i data-lucide="package" width="16" height="16"></i></span>
                                        @endif
                                        <span class="sp-product__name">{{ $product->name }}</span>
                                    </div>
                                </td>
                                <td class="sp-col--category sp-product__category">{{ $product->category->label ?? '—' }}</td>
                                <td class="sp-table__num"><span class="sp-mono">{{ $product->formattedPrice() }}</span></td>
                                <td><span class="sp-pill {{ $stockPillClass }}">{{ $stockLabel }}</span></td>
                                <td>
                                    <span class="sp-status sp-tone-{{ $statusTone }}" data-status-badge="{{ $product->id }}">
                                        <span class="sp-status__dot sp-dot-{{ $statusTone }}" data-status-dot="{{ $product->id }}"></span>
                                        <span data-status-label="{{ $product->id }}">{{ $statusLabel }}</span>
                                    </span>
                                </td>
                                <td class="sp-table__actions">
                                    <div class="sp-actions">
                                        <a href="{{ route('seller.products.edit', $product) }}" data-product-edit-open="{{ $product->id }}"
   data-update-url="{{ route('seller.products.update', $product) }}"
   class="sp-icon-btn" title="Edit" aria-label="Edit {{ $product->name }}">
                                            <i data-lucide="pencil" width="15" height="15"></i>
                                        </a>

                                        <div class="sp-dropdown" data-dropdown>
                                            <button type="button" class="sp-icon-btn" data-dropdown-toggle aria-haspopup="true" aria-expanded="false"
                                                    aria-label="More actions for {{ $product->name }}">
                                                <i data-lucide="ellipsis-vertical" width="15" height="15"></i>
                                            </button>
                                            <div class="sp-dropdown__menu sp-dropdown__menu--right" data-dropdown-menu role="menu" hidden>
                                                <a href="{{ route('seller.products.edit', $product) }}" role="menuitem" class="sp-dropdown__item"
   data-product-edit-open="{{ $product->id }}"
   data-update-url="{{ route('seller.products.update', $product) }}">
                                                    <i data-lucide="pencil" width="14" height="14"></i>Edit
                                                </a>
                                                @if ($product->status !== 'active')
                                                    <button type="button" role="menuitem" class="sp-dropdown__item"
                                                            data-status-set="{{ $product->id }}"
                                                            data-url="{{ route('seller.products.toggleStatus', $product) }}"
                                                            data-to="active">
                                                        <i data-lucide="circle-check" width="14" height="14"></i>Set Active
                                                    </button>
                                                @endif
                                                @if ($product->status !== 'inactive')
                                                    <button type="button" role="menuitem" class="sp-dropdown__item"
                                                            data-status-set="{{ $product->id }}"
                                                            data-url="{{ route('seller.products.toggleStatus', $product) }}"
                                                            data-to="inactive">
                                                        <i data-lucide="circle-pause" width="14" height="14"></i>Set Inactive
                                                    </button>
                                                @endif
                                                <button type="button" role="menuitem" class="sp-dropdown__item sp-dropdown__item--danger"
                                                        data-delete="{{ $product->id }}"
                                                        data-url="{{ route('seller.products.destroy', $product) }}"
                                                        data-name="{{ $product->name }}">
                                                    <i data-lucide="trash-2" width="14" height="14"></i>Delete
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="sp-footer">
                <div class="sp-footer__meta">
                    <p class="sp-footer__count">
                        Showing {{ $products->firstItem() }}-{{ $products->lastItem() }} of {{ $total }} {{ Str::plural('product', $total) }}
                    </p>
                    <label class="sp-rows">
                        <span>Rows:</span>
                        <select name="per_page" form="spProductFilters" class="sp-select sp-select--sm"
                                aria-label="Rows per page" data-auto-submit>
                            @foreach ($perPageOptions as $option)
                                <option value="{{ $option }}" @selected($perPage === (int) $option)>{{ $option }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>

                <nav class="sp-pagination" aria-label="Pagination">
                    @if ($products->onFirstPage())
                        <span class="sp-pager sp-pager--edge sp-pager--disabled" aria-disabled="true">Previous</span>
                    @else
                        <a href="{{ $products->previousPageUrl() }}" rel="prev" class="sp-pager sp-pager--edge">Previous</a>
                    @endif

                    @if ($currentPage > 3)
                        <a href="{{ $products->url(1) }}" class="sp-pager">1</a>
                        @if ($currentPage > 4)
                            <span class="sp-pager__gap" aria-hidden="true">…</span>
                        @endif
                    @endif

                    @foreach ($pageWindow as $page => $url)
                        @if ($page === $currentPage)
                            <span class="sp-pager sp-pager--active" aria-current="page">{{ $page }}</span>
                        @else
                            <a href="{{ $url }}" class="sp-pager">{{ $page }}</a>
                        @endif
                    @endforeach

                    @if ($currentPage < $lastPage - 2)
                        @if ($currentPage < $lastPage - 3)
                            <span class="sp-pager__gap" aria-hidden="true">…</span>
                        @endif
                        <a href="{{ $products->url($lastPage) }}" class="sp-pager">{{ $lastPage }}</a>
                    @endif

                    @if ($products->hasMorePages())
                        <a href="{{ $products->nextPageUrl() }}" rel="next" class="sp-pager sp-pager--edge">Next</a>
                    @else
                        <span class="sp-pager sp-pager--edge sp-pager--disabled" aria-disabled="true">Next</span>
                    @endif
                </nav>
            </div>
        @endif
    </section>
</div>

@include('Components.seller.product-create-modal')

@endsection

@push('scripts')
    <div id="sp-csrf" class="hidden">{{ csrf_token() }}</div>
    @vite(['resources/js/seller/products-index.js', 'resources/js/seller/product-create-modal.js'])
    <script src="{{ asset('js/seller/products-table.js') }}"></script>
@endpush
