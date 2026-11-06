<details data-area-menu class="relative inline-block text-left">
    <summary class="inline-flex size-9 cursor-pointer list-none items-center justify-center rounded-md border border-[var(--border-strong)] text-[var(--text-ink)] hover:bg-[var(--bg-page)] focus-visible:outline-2 focus-visible:outline-primary-700 [&::-webkit-details-marker]:hidden" aria-label="Actions for {{ $area->barangay_name ?? $area->name }}">
        <i data-lucide="more-horizontal" class="size-4"></i>
    </summary>
    <div data-area-menu-list class="fixed z-30 w-44 rounded-md border border-[var(--border-strong)] bg-[var(--bg-surface)] p-1 shadow-lg">
        @if ($isServiceable && $riderLinksAvailable)
        <a href="#rider-links" data-assign-area="{{ $area->id }}" data-assign-station="{{ $area->station_hub_id }}" data-assign-name="{{ $area->barangay_name }}" class="flex min-h-9 w-full items-center rounded px-3 text-sm text-[var(--text-ink)] hover:bg-[var(--bg-page)]">{{ (int) $area->couriers_count > 0 ? 'Add / change rider' : 'Assign rider' }}</a>
        @endif
        @include('Logistics.Areas.partials.remove-form', ['buttonClass' => 'flex min-h-9 w-full items-center rounded px-3 text-left text-sm text-[var(--status-danger)] hover:bg-[var(--bg-soft)]'])
    </div>
</details>
