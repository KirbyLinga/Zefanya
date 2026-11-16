<span class="flex flex-wrap items-center gap-1.5">
    @if ($hub->isPending())
        <span class="rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 font-sans text-[9px] font-semibold tracking-wide text-amber-900">Pending Approval</span>
    @elseif ($hub->isRejected())
        <span class="rounded-full border border-red-200 bg-red-50 px-2 py-0.5 font-sans text-[9px] font-semibold tracking-wide text-red-900">Rejected</span>
    @else
        <span class="rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 font-sans text-[9px] font-semibold tracking-wide text-emerald-900">Approved</span>
    @endif
    <span class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 font-sans text-[9px] font-semibold tracking-wide {{ $hub->is_active ? 'border-emerald-200 bg-emerald-50 text-emerald-900' : 'border-[var(--border-strong)] bg-[var(--bg-page)] text-[var(--text-muted)]' }}"><span class="size-1 rounded-full {{ $hub->is_active ? 'bg-emerald-600' : 'bg-neutral-400' }}"></span>{{ $hub->is_active ? 'Active' : 'Inactive' }}</span>
    @if ($hub->hub_type === \App\Models\Logistics\LogisticsHub::STATION && $hub->is_active && ! $hub->hasValidBarangayCoverage())
        <span class="rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 font-sans text-[10px] font-semibold text-amber-900">Coverage required—new assignments blocked</span>
    @endif
</span>
