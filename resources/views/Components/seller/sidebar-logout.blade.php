{{-- Seller-scoped logout form. Submits the unified logout route so it also
     clears the buyer guard on shared sessions. --}}

<form method="POST" action="{{ route('unified.logout') }}" class="mt-2">
    @csrf
    <button
        type="submit"
        class="flex items-center gap-3 px-3 py-2 rounded-md text-[var(--sidebar-muted)] font-sans text-[13.5px] font-medium no-underline bg-transparent border-none w-full text-left cursor-pointer transition-colors duration-150 hover:bg-[var(--sidebar-hover)] hover:text-[var(--sidebar-text)] [.is-collapsed_&]:justify-center [.is-collapsed_&]:px-0 [.is-collapsed_&]:gap-0 [.is-collapsed_&]:min-w-[40px] [.is-collapsed_&]:min-h-[40px]"
        aria-label="Log out"
        data-tooltip="Log out"
    >
        <i data-lucide="log-out" width="18" height="18" class="w-[18px] h-[18px] flex-shrink-0"></i>
        <span class="text-inherit [.is-collapsed_&]:hidden" aria-hidden="true">Log out</span>
    </button>
</form>
