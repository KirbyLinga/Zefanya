{{-- Seller-scoped layout component.
     Lives under resources/views/components/seller/ and is referenced only as
     <x-seller.sidebar-brand>. Not intended for reuse outside the seller module. --}}

<div class="px-5 py-4 border-b border-[var(--sidebar-border)] flex items-center gap-3 [.is-collapsed_&]:px-2 [.is-collapsed_&]:justify-center">
    <span class="w-9 h-9 rounded-full bg-[var(--sidebar-active-bg)] flex items-center justify-center shrink-0 [.is-collapsed_&]:hidden">
        <img src="{{ asset('Images/Zefanya-Logo.png') }}"
             alt="Zefanya logo"
             class="seller-sidebar__brand-mark w-5 h-5 rounded-full object-cover">
    </span>
    <div class="flex-1 min-w-0 [.is-collapsed_&]:hidden">
        <p class="font-sans text-[14px] font-semibold text-[var(--sidebar-text)]">Zefanya</p>
        <p class="font-sans text-[11px] text-[var(--sidebar-muted)]">Seller Panel</p>
    </div>
    {{-- aria-label and data-tooltip are updated dynamically by sidebar.blade.php JS --}}
    <button
        type="button"
        id="sellerSidebarToggle"
        class="bg-transparent border-none text-[var(--sidebar-muted)] cursor-pointer p-2 rounded-md flex items-center justify-center flex-shrink-0 transition-colors duration-150 hover:bg-[var(--sidebar-hover)] hover:text-[var(--sidebar-text)]"
        aria-label="Collapse sidebar"
        aria-expanded="true"
        aria-controls="sellerNav"
        data-tooltip="Collapse sidebar"
    >
        <i data-lucide="menu" class="w-[18px] h-[18px] block"></i>
    </button>
</div>
