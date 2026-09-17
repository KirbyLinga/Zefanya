{{-- Seller-scoped layout component.
     Lives under resources/views/components/seller/ and is referenced only as
     <x-seller.sidebar-brand>. Not intended for reuse outside the seller module. --}}

<div class="seller-sidebar__brand">
    <span class="seller-sidebar__brand-mark">Z</span>
    <div class="seller-sidebar__brand-text">
        <p class="seller-sidebar__brand-name">Zefanya</p>
        <p class="seller-sidebar__brand-sub">Seller Panel</p>
    </div>
    <button
        type="button"
        id="sellerSidebarToggle"
        class="seller-sidebar__toggle"
        aria-label="Toggle sidebar navigation"
        aria-expanded="true"
        aria-controls="sellerNav"
    >
        <i data-lucide="menu" class="seller-sidebar__toggle-icon"></i>
    </button>
</div>
