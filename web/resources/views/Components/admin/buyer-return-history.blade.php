@props(['history', 'buyer' => null])
{{-- Read-only: stored outcomes of this buyer's other return requests. It never flags, scores or restricts the buyer. --}}
<details data-buyer-return-history class="mt-3 rounded-md border border-[var(--border-strong)] bg-[var(--bg-raised)] p-3 text-xs" @if ($history['return_requests'] > 0) open @endif>
    <summary class="cursor-pointer font-semibold text-[var(--text-ink)]">Buyer return history
        <span class="font-normal text-[var(--text-muted)]">· {{ $buyer?->fullName() ?? 'Buyer account unavailable' }} · this case excluded</span>
    </summary>
    @if ($history['return_requests'] === 0)
        <p class="mt-2 text-[var(--text-muted)]">
            No other return requests by this buyer.
            {{ $history['delivered_shipments'] > 0 ? number_format($history['delivered_shipments']).' other delivered '.Str::plural('shipment', $history['delivered_shipments']).' on record.' : 'No other delivered shipments on record.' }}
        </p>
    @else
        <dl class="mt-2 grid gap-x-4 gap-y-1 sm:grid-cols-2">
            <div class="flex justify-between gap-2"><dt class="text-[var(--text-muted)]">Other delivered shipments</dt><dd class="font-semibold text-[var(--text-ink)]">{{ number_format($history['delivered_shipments']) }}</dd></div>
            <div class="flex justify-between gap-2"><dt class="text-[var(--text-muted)]">Other return requests</dt><dd class="font-semibold text-[var(--text-ink)]">{{ number_format($history['return_requests']) }}</dd></div>
            <div class="flex justify-between gap-2 sm:col-span-2"><dt class="text-[var(--text-muted)]">Historical request rate (requests per delivered shipment)</dt>
                <dd class="font-semibold text-[var(--text-ink)]">{{ $history['request_rate'] === null ? 'Not available' : rtrim(rtrim(number_format($history['request_rate'], 1), '0'), '.').'%' }}</dd></div>
            <div class="flex justify-between gap-2"><dt class="text-[var(--text-muted)]">Seller rejections</dt><dd class="font-semibold text-[var(--text-ink)]">{{ number_format($history['seller_rejected']) }}</dd></div>
            <div class="flex justify-between gap-2"><dt class="text-[var(--text-muted)]">Admin kept the rejection</dt><dd class="font-semibold text-[var(--text-ink)]">{{ number_format($history['dispute_upheld']) }}</dd></div>
            <div class="flex justify-between gap-2"><dt class="text-[var(--text-muted)]">Admin approved the return instead</dt><dd class="font-semibold text-[var(--text-ink)]">{{ number_format($history['dispute_overturned']) }}</dd></div>
            <div class="flex justify-between gap-2"><dt class="text-[var(--text-muted)]">Disputes awaiting Admin</dt><dd class="font-semibold text-[var(--text-ink)]">{{ number_format($history['dispute_open']) }}</dd></div>
            @if ($history['receipt_tracked'])
                <div class="flex justify-between gap-2 sm:col-span-2"><dt class="text-[var(--text-muted)]">Seller receipt disputes (open / resolved)</dt>
                    <dd class="font-semibold text-[var(--text-ink)]">{{ number_format($history['receipt_disputes']) }} ({{ number_format($history['receipt_disputes_open']) }} / {{ number_format($history['receipt_disputes'] - $history['receipt_disputes_open']) }})</dd></div>
            @else
                <div class="sm:col-span-2 text-[var(--text-muted)]">Seller receipt disputes are not recorded on this database yet.</div>
            @endif
        </dl>
        @if ($history['request_rate'] === null)
            <p class="mt-1 text-[var(--text-muted)]">No other delivered shipments on record, so no rate is shown.</p>
        @endif
        <p class="mt-2 font-semibold text-[var(--text-ink)]">Latest other requests</p>
        <ul class="mt-1 space-y-1">
            @foreach ($history['recent'] as $earlier)
                <li><a class="font-semibold text-primary-800 underline" href="{{ route('admin.returns.index', ['return' => $earlier->id]) }}">Return #{{ $earlier->id }}</a>
                    · <span class="font-mono">{{ $earlier->history_tracking_number ?? 'No tracking number' }}</span>
                    · {{ $earlier->status->label() }}@if ($earlier->dispute_outcome) · dispute {{ $earlier->dispute_outcome === \App\Models\Logistics\ParcelReturn::DISPUTE_UPHELD ? 'rejection kept' : 'return approved' }}@endif
                    · {{ $earlier->requested_at?->timezone('Asia/Manila')->format('M j, Y') }}</li>
            @endforeach
        </ul>
        @if ($history['return_requests'] > count($history['recent']))
            <p class="mt-1 text-[var(--text-muted)]">Showing the latest {{ count($history['recent']) }} of {{ number_format($history['return_requests']) }}. The counts above include all of them.</p>
        @endif
    @endif
    <p class="mt-2 text-[var(--text-muted)]">Counts come from recorded decisions only. They are context for your review, not a finding against the buyer.</p>
    @if ($buyer?->email)
        <a class="mt-1 inline-block font-semibold text-primary-800 underline" href="{{ route('admin.users.index', ['type' => 'buyer', 'search' => $buyer->email]) }}">Open buyer account</a>
    @endif
</details>
