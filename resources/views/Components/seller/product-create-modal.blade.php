{{--
    resources/views/Components/seller/product-create-modal.blade.php
    Shared "Add Product" / "Edit Product" modal used by Seller/Products/index.blade.php.

    One dialog, two modes:
      - create: server-rendered Seller/Products/_form for a new product, posted
        (multipart, no AJAX) to seller.products.store.
      - edit:   the JS fetches GET seller.products.edit (X-Requested-With) and injects
        the same _form partial for that product; the form posts to seller.products.update
        via @method('PUT'). A failed update() POST redirects back here and the index
        re-renders this modal in edit mode (old('_modal') === 'product-edit').

    The hidden _modal field lets the server know a failed validation came from this
    modal so it reopens with errors and old() input.

    Triggers: [data-product-create-open], ?create=1, [data-product-edit-open="{id}"]
    (with href = edit URL + data-update-url), ?edit={id}, or a failed store()/update() POST.
    Behavior: resources/js/seller/product-create-modal.js
--}}
@php
    $editProduct = $editProduct ?? null;
    $editMode = $editProduct !== null;
    $createAutoOpen = ($errors->any() && old('_modal') === 'product-create')
        || ($openCreateModal ?? request()->boolean('create'));
    $autoOpen = $createAutoOpen || $editMode;
    $productModalProduct = $editProduct
        ?? ($newProduct ?? new \App\Models\Product(['status' => 'draft', 'low_stock_threshold' => 5]));
@endphp
<div id="productModalOverlay"
     class="buyer-modal-overlay fixed inset-0 z-[999] hidden items-center justify-center bg-[var(--overlay)] p-4 backdrop-blur-[2px] [&.is-open]:flex"
     data-auto-open="{{ $autoOpen ? '1' : '0' }}"
     data-auto-edit-id="{{ request()->input('edit') }}"
     data-edit-template="{{ route('seller.products.edit', ['product' => '__ID__']) }}"
     data-update-template="{{ route('seller.products.update', ['product' => '__ID__']) }}"
     aria-hidden="true">
    <div role="dialog" aria-modal="true" aria-labelledby="productModalTitle"
         class="flex max-h-[90vh] w-full max-w-[680px] flex-col overflow-hidden rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] shadow-[0_24px_60px_rgba(15,23,42,0.25)] max-sm:max-h-[94vh]">

        <header class="relative shrink-0 border-b border-[var(--border-hairline)] px-8 pb-4 pt-7 max-sm:px-5">
            <button type="button" id="productModalClose" aria-label="Close product form"
                    class="absolute right-4 top-4 inline-flex h-8 w-8 cursor-pointer items-center justify-center rounded-full text-[var(--text-muted)] transition-colors hover:bg-[var(--hover-rose)] hover:text-[var(--hover-rose-text)]">
                <i data-lucide="x" width="18" height="18"></i>
            </button>

            <h2 id="productModalTitle" class="font-serif text-[24px] font-normal text-[var(--text-ink)]">
                {{ $editMode ? 'Edit Product' : 'Add Product' }}
            </h2>
            <p id="productModalSubtitle" class="mt-1 font-sans text-[13px] text-[var(--text-muted)]">
                {{ $editMode ? $productModalProduct->name : 'List a new item in your store.' }}
            </p>
        </header>

        <form id="productModalForm" method="POST" enctype="multipart/form-data"
              action="{{ $editMode ? route('seller.products.update', $productModalProduct) : route('seller.products.store') }}"
              class="flex min-h-0 flex-1 flex-col">
            <input type="hidden" name="_modal" id="productModalFlag" value="{{ $editMode ? 'product-edit' : 'product-create' }}">
            <input type="hidden" name="_product_id" id="productModalProductId" value="{{ $editMode ? $productModalProduct->id : '' }}">

            <div id="productModalBody" data-mode="{{ $editMode ? 'edit' : 'create' }}"
                 class="flex min-h-0 flex-1 flex-col gap-[18px] overflow-y-auto overscroll-contain px-8 py-6 max-sm:px-5">
                @include('Seller.Products._form', ['product' => $productModalProduct, 'lockedCategory' => $lockedCategory ?? null])
            </div>
        </form>

        {{-- Sticky footer: the submit button lives outside the scroll area and is
            associated with the form through the form="" attribute. --}}
        <footer class="flex shrink-0 items-center justify-end gap-3 border-t border-[var(--border-hairline)] px-8 py-4 max-sm:px-5">
            <button type="button" data-modal-cancel
                    class="inline-flex h-10 items-center justify-center rounded-sm border border-[var(--border-cancel)] bg-[var(--bg-surface)] px-5 font-sans text-[12px] font-semibold uppercase tracking-[0.8px] text-[var(--btn-cancel-text)] transition-colors hover:bg-[var(--bg-raised)]">
                Cancel
            </button>
            <button type="submit" form="productModalForm"
                    class="inline-flex h-10 items-center justify-center gap-2 rounded-sm border-none bg-[var(--btn-primary-bg)] px-5 font-sans text-[12px] font-semibold uppercase tracking-[0.8px] text-[var(--btn-primary-text)] cursor-pointer transition-colors hover:bg-[var(--btn-primary-bg-hover-soft)]">
                <span id="productModalSubmitLabel">{{ $editMode ? 'Save Changes' : 'Create Product' }}</span>
            </button>
        </footer>
    </div>
</div>