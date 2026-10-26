@props(['shipment'])
<ol class="space-y-3">
    @foreach ($shipment->trackingEvents() as $event)
        <li class="border-l border-[var(--border-strong)] pl-3">
            <p class="font-sans text-xs font-semibold text-[var(--text-ink)]">{{ $event['title'] }}</p>
            <p class="mt-1 font-sans text-[11px] text-[var(--text-muted)]">{{ $event['at']->format('M d, Y h:i A') }}@if ($event['type'] === 'handoff') · Provider handoff @endif</p>
            @if (filled($event['detail'] ?? null))
                <p class="mt-1 font-sans text-xs text-[var(--text-muted)]">{{ $event['detail'] }}</p>
            @endif
        </li>
    @endforeach
</ol>
<p class="mt-3 font-sans text-xs text-[var(--text-muted)]">Current: <span class="font-semibold text-[var(--text-ink)]">{{ $shipment->status->label() }}</span></p>
