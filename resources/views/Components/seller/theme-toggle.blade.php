{{-- Seller-scoped light/dark toggle. Icon-only, 40px hit area; the moon shows
     in light mode, the sun in dark mode (swap handled in theme.css).
     Behavior: resources/js/seller/theme-toggle.js --}}

<button
    type="button"
    data-theme-toggle
    class="zf-theme-toggle bg-transparent border-none text-[var(--sidebar-muted)] cursor-pointer w-10 h-10 rounded-md flex items-center justify-center flex-shrink-0 transition-colors duration-150 hover:bg-[var(--sidebar-hover)] hover:text-[var(--sidebar-text)]"
    aria-label="Switch to dark mode"
    aria-pressed="false"
>
    <i data-lucide="moon" class="zf-icon-moon w-[18px] h-[18px] block" aria-hidden="true"></i>
    <i data-lucide="sun" class="zf-icon-sun w-[18px] h-[18px] block" aria-hidden="true"></i>
</button>
