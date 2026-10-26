@props(['handoff'])
<dl class="mt-3 grid grid-cols-1 gap-2 border-t border-[var(--border-strong)] p-3 font-sans text-[11px] text-[var(--text-muted)] sm:grid-cols-2">
    <div><dt class="font-semibold">Dispatch</dt><dd>{{ $handoff->dispatched_at?->format('M j, Y g:i A') ?? 'Awaiting dispatch' }}</dd></div>
    <div><dt class="font-semibold">Receiving</dt><dd>{{ $handoff->received_at?->format('M j, Y g:i A') ?? 'Awaiting verified receipt' }}</dd></div>
    <div><dt class="font-semibold">Current custody</dt><dd>{{ $handoff->sellerOrder?->custodyProvider?->business_name ?? $handoff->sellerOrder?->carrierProvider?->business_name ?? 'Not established' }}</dd></div>
    <div><dt class="font-semibold">Request</dt><dd>{{ $handoff->statusLabel() }} · {{ $handoff->requested_at?->format('M j, Y g:i A') }}</dd></div>
</dl>
