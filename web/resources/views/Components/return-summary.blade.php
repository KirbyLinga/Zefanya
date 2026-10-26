@props(['record'])
<div class="space-y-2 font-sans text-xs text-[var(--text-muted)]">
    <p class="font-mono font-semibold text-[var(--text-ink)]">{{ $record->sellerOrder->tracking_number ?? 'Tracking requires review' }}</p>
    <p class="font-semibold text-[var(--text-ink)]">{{ $record->status->label() }}</p>
    <p>{{ $record->reason }}</p>
    <p>Current accountable hub: {{ $record->sellerOrder->custodyHub?->name ?? $record->sellerOrder->originStation?->name ?? 'Needs verification' }}</p>
    <p>Original handover hub: {{ $record->originHub?->name ?? 'Needs Admin verification' }}</p>
    <p>Return destination: {{ $record->seller_address['full'] ?? 'Original pickup address requires review' }}</p>
    <p>Requested {{ $record->requested_at->format('M j, Y g:i A') }}</p>
    @if ($record->seller_received_at)<p>Seller receipt: {{ $record->seller_received_at->format('M j, Y g:i A') }}</p>@endif
</div>
