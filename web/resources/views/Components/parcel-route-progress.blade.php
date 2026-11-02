@props(['details'])

@if ($details !== null)
<section class="mt-5 rounded-xl border border-[var(--border-strong)] bg-[var(--bg-surface)] p-4" aria-label="Verified route progress">
    <h3 class="text-sm font-semibold text-[var(--text-ink)]">Verified route progress</h3>
    @if ($details['held'])
        <p class="mt-2 text-sm font-semibold text-amber-800" role="status">On hold: an unresolved exception blocks parcel movement.</p>
    @endif
    <dl class="mt-3 grid gap-3 text-xs sm:grid-cols-2">
        <div class="sm:col-span-2 rounded-lg bg-[var(--bg-page)] p-3"><dt class="text-[var(--text-muted)]">Next step</dt><dd class="mt-1 font-semibold text-[var(--text-ink)]">{{ $details['next_step'] }}</dd></div>
        <div class="sm:col-span-2"><dt class="text-[var(--text-muted)]">Last scan</dt><dd class="mt-1 text-[var(--text-ink)]">
            @if ($details['last_scan'] ?? null)
                {{ $details['last_scan']['label'] }} &middot; <time datetime="{{ $details['last_scan']['at']->toIso8601String() }}">{{ $details['last_scan']['at']->format('M d, Y h:i A') }}</time>
            @else
                No verified scan recorded yet
            @endif
        </dd></div>
        <div><dt class="text-[var(--text-muted)]">Current location</dt><dd class="font-semibold text-[var(--text-ink)]">{{ $details['location'] }}</dd></div>
        <div><dt class="text-[var(--text-muted)]">Last verified physical hub</dt><dd>{{ $details['last_verified_hub'] ?? 'No verified hub receipt' }}</dd></div>
        <div><dt class="text-[var(--text-muted)]">Custody provider</dt><dd>{{ $details['custody_provider'] ?? 'Hub custody not yet verified' }}</dd></div>
        <div><dt class="text-[var(--text-muted)]">Transport progress</dt><dd>{{ $details['leg'] }}</dd></div>
        <div><dt class="text-[var(--text-muted)]">Origin hub</dt><dd>{{ $details['origin'] ?? 'No saved route' }}</dd></div>
        <div><dt class="text-[var(--text-muted)]">Final destination hub</dt><dd>{{ $details['destination'] ?? 'No saved route' }}</dd></div>
        <div><dt class="text-[var(--text-muted)]">Next planned destination</dt><dd>{{ $details['next_destination'] ?? 'No further hub transfer' }}@if ($details['mode']) ({{ $details['mode'] }})@endif</dd></div>
        <div><dt class="text-[var(--text-muted)]">Transit status</dt><dd>{{ $details['in_transit'] ? 'In transit' : 'No active transit' }}</dd></div>
    </dl>
    @if ($details['receipts'] !== [])
        <h4 class="mt-4 text-xs font-semibold text-[var(--text-ink)]">Verified receiving history</h4>
        <ul class="mt-2 space-y-1 text-xs text-[var(--text-muted)]">
            @foreach ($details['receipts'] as $receipt)
                <li>{{ $receipt['at']->format('M d, Y H:i') }} &middot; Received at {{ $receipt['hub'] }}</li>
            @endforeach
        </ul>
    @endif
</section>
@endif
