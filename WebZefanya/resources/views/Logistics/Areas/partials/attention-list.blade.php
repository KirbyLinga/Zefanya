<ul class="divide-y divide-amber-200/60">
    @foreach ($attentionAreas as $area)
        @php $isLegacy = blank($area->barangay_code); @endphp
        <li class="grid grid-cols-1 items-start gap-4 bg-[var(--bg-surface)] px-4 py-5 font-sans text-sm md:grid-cols-2 xl:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)_minmax(0,1.2fr)_auto]">
            <div>
                <p class="font-semibold text-[var(--text-ink)]">{{ $area->barangay_name ?? $area->name }}</p>
                @if ($isLegacy)
                    <span class="mt-1 inline-flex rounded-sm bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-900">Legacy</span>
                @endif
                @unless ($area->is_active)
                    <span class="mt-1 inline-flex text-xs text-[var(--status-warning)]">Inactive</span>
                @endunless
            </div>
            <p class="text-[var(--text-ink)]">{{ $area->municipality }} <span class="text-[var(--text-muted)]">&middot; {{ $area->barangay_code ?? '—' }}</span></p>
            <p class="text-[var(--text-ink)]">{{ $area->station?->name ?? 'Unassigned' }}</p>
            <div class="md:col-span-2 xl:col-span-3">
                @if ($isLegacy)
                    <span class="text-xs text-[var(--text-muted)]">Conversion required; excluded from serviceable counts.</span>
                @elseif ((int) $area->couriers_count === 0)
                    <span class="inline-flex rounded-sm bg-amber-100 px-2 py-1 text-xs font-semibold text-amber-900">No rider assigned</span>
                @else
                    <span class="text-xs text-[var(--text-muted)]">{{ $area->couriers_count }} {{ (int) $area->couriers_count === 1 ? 'rider' : 'riders' }}</span>
                @endif
            </div>
            <div class="flex flex-wrap items-center justify-start gap-2 md:col-span-2 xl:col-span-1 xl:col-start-4 xl:row-start-1 xl:row-span-2 xl:justify-end">
                @if ($isLegacy)
                    <a href="{{ route('logistics.areas.index', array_filter(['convert' => $area->id, 'station' => $selectedStation?->id])) }}#coverage-form" class="inline-flex min-h-9 items-center rounded-md border border-[var(--border-strong)] bg-[var(--bg-surface)] px-3 text-xs font-semibold text-[var(--text-ink)] hover:bg-[var(--bg-page)]">Convert to barangays</a>
                @else
                    <a href="#rider-links" data-assign-area="{{ $area->id }}" data-assign-station="{{ $area->station_hub_id }}" data-assign-name="{{ $area->barangay_name }}" class="inline-flex min-h-9 items-center rounded-md border border-[var(--border-strong)] bg-[var(--bg-surface)] px-3 text-xs font-semibold text-[var(--text-ink)] hover:bg-[var(--bg-page)]">Assign rider</a>
                @endif
                @include('Logistics.Areas.partials.remove-form', ['buttonClass' => 'inline-flex min-h-9 items-center rounded-md border border-[var(--border-strong)] bg-[var(--bg-surface)] px-3 text-xs font-semibold text-[var(--text-ink)] hover:bg-[var(--bg-page)]'])
            </div>
        </li>
    @endforeach
</ul>
