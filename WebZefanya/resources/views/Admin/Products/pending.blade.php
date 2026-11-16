@extends('Admin.Layouts.app')

@section('title', 'Pending Products')

@php
    $pendingCount = $products->total();
    $peso = fn (int $minor): string => ($minor < 0 ? '−' : '').'₱'.number_format(abs($minor) / 100, 2);
@endphp

@section('content')
    <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div class="min-w-0 flex-1">
            <p class="text-xs font-semibold text-neutral-400 uppercase tracking-wider">Marketplace / Catalog review</p>
            <h1 class="text-[28px] font-serif font-bold text-neutral-950 mt-1 flex items-center gap-2">
                Pending Products
                <span class="inline-flex items-center rounded-full bg-primary-100 px-2.5 py-0.5 text-[11px] font-semibold text-primary-800">{{ $pendingCount }}</span>
            </h1>
            <p class="text-sm text-neutral-600 mt-2">Review submissions before publishing. Approve to publish changes, or reject with a reason so the seller can revise and resubmit.</p>
        </div>
        <a href="{{ route('admin.products.index') }}" class="inline-flex items-center gap-1.5 text-sm font-medium text-primary-700 hover:text-primary-900">
            <i data-lucide="arrow-left" class="size-4"></i>
            All products
        </a>
    </div>

    <x-flash type="success" :message="session('success')" />
    @if ($errors->any())
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700" role="alert">{{ $errors->first() }}</div>
    @endif

    <div class="max-w-4xl mx-auto">

        @forelse ($products as $product)
            @php
                $revision = $product->latestRevision;
                $pendingRevision = $revision !== null && $revision->status === 'pending' ? $revision : null;
                $proposal = $pendingRevision?->payload ?? [];
                $image = $product->images->first();
                $isRevision = $pendingRevision !== null;
                $seller = $product->seller;
                $category = $product->category;
                $stockTotal = $product->variants->sum('stock_quantity');
            @endphp

            <article class="mb-6 rounded-xl border border-neutral-200 bg-white shadow-sm overflow-hidden">
                <div class="grid grid-cols-1 lg:grid-cols-12">
                    
                    <!-- Image column -->
                    <div class="lg:col-span-4 bg-neutral-50 p-5 flex flex-col items-center justify-center min-h-[200px]">
                        @if ($image)
                            <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($image->path) }}" 
                                 alt="{{ $product->name }}" 
                                 class="max-h-[180px] max-w-full rounded-xl border border-neutral-200 object-cover object-center">
                        @else
                            <div class="w-36 h-36 rounded-xl border-2 border-dashed border-neutral-300 flex items-center justify-center text-neutral-400">
                                <i data-lucide="image" class="size-6"></i>
                            </div>
                        @endif
                    </div>

                    <!-- Content column -->
                    <div class="lg:col-span-8 p-5">
                        
                        <!-- Header: Title + Status -->
                        <div class="flex flex-wrap items-start justify-between gap-3 mb-3">
                            <div class="min-w-0 flex-1">
                                <h2 class="text-lg font-semibold text-neutral-950 leading-snug">{{ $proposal['name'] ?? $product->name }}</h2>
                                <p class="text-sm text-neutral-600 mt-1">
                                    {{ $seller?->business_name ?? 'Unknown seller' }} · {{ $category?->label ?? 'Uncategorized' }}
                                </p>
                            </div>
                            <span class="inline-flex items-center rounded-full border px-2.5 py-1 text-[10px] font-semibold tracking-wider
                                {{ $isRevision ? 'bg-amber-50 border-amber-200 text-amber-800' : 'bg-emerald-50 border-emerald-200 text-emerald-800' }}">
                                {{ $isRevision ? 'Revision Pending' : 'New Submission' }}
                            </span>
                        </div>

                        <!-- Details: Price, Stock, Category -->
                        <dl class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm mt-4 mb-4">
                            <div>
                                <dt class="text-neutral-500 text-[10px] uppercase tracking-wider font-medium">Price</dt>
                                <dd class="font-medium text-neutral-900">₱{{ number_format((float) ($proposal['price'] ?? $product->price), 2) }}</dd>
                            </div>
                            <div>
                                <dt class="text-neutral-500 text-[10px] uppercase tracking-wider font-medium">Stock</dt>
                                <dd class="font-medium text-neutral-900">{{ $stockTotal }} units</dd>
                            </div>
                            <div>
                                <dt class="text-neutral-500 text-[10px] uppercase tracking-wider font-medium">Category</dt>
                                <dd class="font-medium text-neutral-900">{{ $category?->label ?? 'Uncategorized' }}</dd>
                            </div>
                            <div>
                                <dt class="text-neutral-500 text-[10px] uppercase tracking-wider font-medium">Queue</dt>
                                <dd class="font-medium text-neutral-900">{{ $isRevision ? 'Edit awaiting approval' : 'New product' }}</dd>
                            </div>
                        </dl><!-- Description (truncated) -->
                        @if (filled($proposal['description'] ?? $product->description))
                            <p class="mt-4 text-sm text-neutral-700 line-clamp-2 mb-4">
                                {{ \Illuminate\Support\Str::limit($proposal['description'] ?? $product->description, 180) }}
                            </p>
                        @endif

                        <!-- Revision notice -->
                        @if ($isRevision)
                            <div class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-3 mb-4">
                                <p class="text-sm text-amber-800 leading-relaxed">
                                    <span class="font-semibold">Existing listing remains published.</span><br>
                                    The proposed revision will only become public after Admin approval.
                                </p>
                            </div>
                        @endif

                        <!-- Action area -->
                        <div class="pt-4 border-t border-neutral-100 flex flex-wrap items-center justify-between gap-3">
                            
                            <!-- Review Details button (opens drawer) -->
                            <button type="button" 
                                    class="flex items-center gap-2 text-sm font-medium text-primary-700 hover:text-primary-900"
                                    onclick="openProductDrawer({{ $product->id }})"
                                    aria-expanded="false"
                                    aria-controls="product-drawer">
                                <i data-lucide="file-text" class="size-4"></i>
                                Review Details
                            </button>

                            <!-- Action buttons -->
                            <div class="flex items-center gap-3 ml-auto">
                                
                                <!-- Reject trigger button -->
                                <button type="button" 
                                        class="flex items-center gap-2 rounded border border-red-200 bg-white px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-50"
                                        onclick="toggleRejectForm({{ $product->id }})"
                                        id="reject-btn-{{ $product->id }}"
                                        aria-expanded="false">
                                    <i data-lucide="x" class="size-4"></i>
                                    Reject
                                </button>

                                <!-- Rejection form (hidden by default) -->
                                <form id="reject-form-{{ $product->id }}"
                                      method="POST" 
                                      action="{{ route('admin.products.reject', $product) }}"
                                      class="hidden mt-3 w-full animate-in fade-in duration-200"
                                      data-app-confirm
                                      data-confirm-title="Reject product"
                                      data-confirm-label="Reject Product"
                                      data-confirm-danger="true"
                                      data-confirm-message="Reject {{ $proposal['name'] ?? $product->name }}? The seller will need to revise and resubmit.">
                                    @csrf
                                    <div class="mb-3">
                                        <label class="block text-[11px] font-semibold text-neutral-600 uppercase tracking-wider mb-1">Rejection reason (required)</label>
                                        <textarea name="reason" required maxlength="1000" rows="3" 
                                                  class="w-full rounded border border-neutral-300 px-3 py-2 text-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 outline-none"
                                                  placeholder="Explain why this submission cannot be approved…"></textarea>
                                        <p class="mt-1 text-[10px] text-neutral-500">Up to 1,000 characters</p>
                                    </div>
                                    <div class="flex items-center justify-between gap-2">
                                        <button type="button" 
                                                class="text-sm font-medium text-neutral-600 hover:text-neutral-900"
                                                onclick="toggleRejectForm({{ $product->id }})">
                                            Cancel
                                        </button>
                                        <button type="submit" 
                                                class="rounded bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">
                                            Reject Product
                                        </button>
                                    </div>
                                </form>

                                <!-- Approve form -->
                                <form method="POST" action="{{ route('admin.products.approve', $product) }}"
                                      class="inline"
                                      data-app-confirm
                                      data-confirm-title="Approve product"
                                      data-confirm-label="Approve Product"
                                      data-confirm-message="Approve {{ $proposal['name'] ?? $product->name }}? This will make the changes public.">
                                    @csrf
                                    <button type="submit" 
                                            class="rounded bg-primary-700 px-5 py-2 text-sm font-semibold text-white hover:bg-primary-800 shadow-sm">
                                        Approve Product
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </article>

        @empty
            <div class="rounded-xl border border-neutral-200 bg-white p-16 text-center">
                <div class="mx-auto flex size-16 items-center justify-center rounded-full bg-neutral-100 text-neutral-400 mb-4">
                    <i data-lucide="inbox" class="size-8"></i>
                </div>
                <h3 class="text-base font-semibold text-neutral-950">No products waiting for approval</h3>
                <p class="mt-1 text-neutral-600">All submissions are up to date. New items will appear here when sellers submit products for review.</p>
            </div>
        @endforelse

        {{ $products->links() }}
    </div>

    <!-- Product Details Drawer (right-side dialog) -->
    {{-- Layout only while open: an inline display would override the browser's hidden state for a closed dialog. --}}
    <style>
        #product-drawer:not([open]) { display: none; }
        #product-drawer[open] { display: flex; flex-direction: column; }
    </style>
    <dialog id="product-drawer" class="backdrop:bg-black/40 backdrop:backdrop-blur-sm"
            style="border: none; border-radius: 0; position: fixed; inset: 0 0 0 auto; width: 100%; max-width: 520px; height: 100%; max-height: 100%; background: white; box-shadow: -4px 0 24px rgba(0,0,0,0.15); padding: 0; margin: 0; overflow: hidden; z-index: 50;">
        
        <form method="dialog" class="flex flex-col h-full">
            <!-- Drawer header -->
            <div class="flex items-center justify-between border-b border-neutral-200 bg-white px-6 py-4 flex-shrink-0">
                <h3 class="text-lg font-semibold text-neutral-950" id="drawer-title">Product Details</h3>
                <button type="button" class="rounded-lg p-2 text-neutral-400 hover:text-neutral-900 hover:bg-neutral-50" 
                        onclick="document.getElementById('product-drawer').close()"
                        aria-label="Close details">
                    <i data-lucide="x" class="size-5"></i>
                </button>
            </div>

            <!-- Drawer content -->
            <div class="flex-1 overflow-y-auto p-6 space-y-6" id="drawer-content">
                <div class="flex flex-col items-center justify-center py-12 text-neutral-500">
                    <i data-lucide="loader-2" class="size-6 animate-spin mb-2"></i>
                    <p>Loading details…</p>
                </div>
            </div>
        </form>
    </dialog>

    <!-- Embedded product data for drawer population -->
    {{-- Built first: the json directive cannot parse a multi-line closure argument. --}}
    @php
        $drawerProducts = $products->getCollection()->map(function ($p) {
            $rev = $p->latestRevision;
            $pendingRev = $rev !== null && $rev->status === 'pending' ? $rev : null;
            return [
                'id' => $p->id,
                'name' => $pendingRev?->payload['name'] ?? $p->name,
                'description' => $pendingRev?->payload['description'] ?? $p->description,
                'price' => $pendingRev?->payload['price'] ?? $p->price,
                'stock' => $p->variants->sum('stock_quantity'),
                'category' => $p->category?->label ?? 'Uncategorized',
                'seller' => $p->seller?->business_name ?? 'Unknown',
                'seller_email' => $p->seller?->email ?? '',
                'is_revision' => $pendingRev !== null,
                'existing_name' => $p->name,
                'existing_price' => $p->price,
                'existing_description' => $p->description,

                'images' => $p->images->map(function ($img) {
                    return [
                        'path' => \Illuminate\Support\Facades\Storage::disk('public')->url($img->path),
                        'is_primary' => $img->is_primary,
                    ];
                }),
                'variants' => $p->variants->map(function ($v) {
                    return [
                        'label' => $v->label(),
                        'price' => $v->price,
                        'stock' => $v->stock_quantity,
                        'available' => $v->is_available && $v->stock_quantity > 0,
                    ];
                }),
            ];
        })->toArray();
    @endphp
    <script id="product-data" type="application/json">
        @json($drawerProducts)
    </script>

    <script>
        // Drawer data and rendering
        const productData = JSON.parse(document.getElementById('product-data').textContent);

        function findProduct(id) {
            return productData.find(p => p.id === id);
        }

        function formatPrice(value) {
            return '₱' + parseFloat(value).toFixed(2);
        }

        function renderDrawerContent(product) {
            const isRev = product.is_revision;
            const imagesHtml = product.images.length > 0
                ? product.images.map(img => `
                    <img src="${img.path}" alt="${product.name}" class="w-full h-24 object-cover rounded border border-neutral-200">
                `).join('')
                : '<div class="h-24 rounded border border-neutral-200 bg-neutral-100 flex items-center justify-center text-neutral-400"><i data-lucide="image" class="size-4"></i></div>';

            const variantsHtml = product.variants.length > 0
                ? `
                <table class="w-full text-sm border-collapse">
                    <thead>
                        <tr class="text-neutral-500 border-b border-neutral-100">
                            <th class="px-2 py-2 text-left">Variant</th>
                            <th class="px-2 py-2 text-right">Price</th>
                            <th class="px-2 py-2 text-right">Stock</th>
                            <th class="px-2 py-2 text-right">Available</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${product.variants.map(v => `
                            <tr class="border-b border-neutral-50">
                                <td class="px-2 py-2 text-neutral-900">${v.label}</td>
                                <td class="px-2 py-2 text-right text-neutral-900">${formatPrice(v.price)}</td>
                                <td class="px-2 py-2 text-right text-neutral-900">${v.stock}</td>
                                <td class="px-2 py-2 text-right">
                                    <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-semibold ${v.available ? 'bg-green-50 text-green-700' : 'bg-neutral-100 text-neutral-500'}">
                                        ${v.available ? 'Yes' : 'No'}
                                    </span>
                                </td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
                `
                : '<p class="text-sm text-neutral-500">No variants defined.</p>';

            const changesHtml = isRev ? `
                <div class="border rounded-lg p-4">
                    <h4 class="text-xs font-semibold text-neutral-500 uppercase tracking-wider mb-3">Proposed Changes vs Published</h4>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <p class="text-[10px] font-semibold text-amber-800 uppercase mb-1">Existing Name</p>
                            <p class="text-sm text-neutral-700 line-clamp-2">${product.existing_name}</p>
                        </div>
                        <div>
                            <p class="text-[10px] font-semibold text-emerald-800 uppercase mb-1">Proposed Name</p>
                            <p class="text-sm text-neutral-900 font-medium line-clamp-2">${product.name}</p>
                        </div>
                        <div>
                            <p class="text-[10px] font-semibold text-amber-800 uppercase mb-1">Existing Price</p>
                            <p class="text-sm text-neutral-700">${formatPrice(product.existing_price)}</p>
                        </div>
                        <div>
                            <p class="text-[10px] font-semibold text-emerald-800 uppercase mb-1">Proposed Price</p>
                            <p class="text-sm text-neutral-900 font-medium">${formatPrice(product.price)}</p>
                        </div>
                    </div>
                    ${product.existing_description !== product.description ? `
                    <div class="mt-4 border-t border-neutral-200 pt-4">
                        <p class="text-[10px] font-semibold text-neutral-500 uppercase mb-2">Description Changes</p>
                        <div class="bg-neutral-50 rounded p-3">
                            <p class="text-xs text-neutral-600 line-clamp-3">${product.description}</p>
                        </div>
                    </div>
                    ` : ''}
                </div>
            ` : `
                <div class="bg-emerald-50 rounded-lg p-4">
                    <p class="text-sm text-emerald-800">This is a new product submission. All details above are the proposed listing.</p>
                </div>
            `;

            return `
                <div class="space-y-5">
                    <!-- Images -->
                    <div>
                        <h4 class="text-xs font-semibold text-neutral-500 uppercase tracking-wider mb-3">Product Images</h4>
                        <div class="grid grid-cols-2 gap-2">
                            ${imagesHtml}
                        </div>
                    </div>

                    <!-- Variants -->
                    <div>
                        <h4 class="text-xs font-semibold text-neutral-500 uppercase tracking-wider mb-3">Variants (${product.variants.length})</h4>
                        ${variantsHtml}
                    </div>

                    <!-- Changes / New submission info -->
                    <div>
                        <h4 class="text-xs font-semibold text-neutral-500 uppercase tracking-wider mb-3">${isRev ? 'Submitted Changes' : 'New Submission'}</h4>
                        ${changesHtml}
                    </div>

                    <!-- Description -->
                    <div>
                        <h4 class="text-xs font-semibold text-neutral-500 uppercase tracking-wider mb-3">Description</h4>
                        <div class="text-sm text-neutral-700 whitespace-pre-line line-clamp-4">${product.description || 'No description provided.'}</div>
                    </div>

                    <!-- Seller info -->
                    <div class="border rounded-lg p-4 bg-neutral-50">
                        <h4 class="text-xs font-semibold text-neutral-500 uppercase tracking-wider mb-3">Seller Information</h4>
                        <p class="text-sm text-neutral-700"><span class="font-semibold">Business:</span> ${product.seller}</p>
                        <p class="text-sm text-neutral-700"><span class="font-semibold">Email:</span> ${product.seller_email}</p>
                    </div>
                </div>
            `;
        }

        function openProductDrawer(productId) {
            const product = findProduct(productId);
            const drawer = document.getElementById('product-drawer');
            const title = document.getElementById('drawer-title');
            const content = document.getElementById('drawer-content');

            if (!product) {
                title.textContent = 'Product not found';
                content.innerHTML = '<p class="text-neutral-500">Product data not available.</p>';
                drawer.showModal();
                return;
            }

            title.textContent = product.name;
            content.innerHTML = renderDrawerContent(product);

            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }
            drawer.showModal();
        }

        function toggleRejectForm(productId) {
            const btn = document.getElementById('reject-btn-' + productId);
            const form = document.getElementById('reject-form-' + productId);
            const isExpanded = btn.getAttribute('aria-expanded') === 'true';

            if (isExpanded) {
                btn.setAttribute('aria-expanded', 'false');
                form.classList.add('hidden');
            } else {
                btn.setAttribute('aria-expanded', 'true');
                form.classList.remove('hidden');
                const textarea = form.querySelector('textarea');
                if (textarea) textarea.focus();
            }
        }

        // Close drawer when clicking backdrop
        document.getElementById('product-drawer')?.addEventListener('click', function (e) {
            if (e.target === this) this.close();
        });

        // Initialize Lucide icons
        if (typeof lucide !== 'undefined') {
            lucide.createIcons();
        }
    </script>
@endsection
