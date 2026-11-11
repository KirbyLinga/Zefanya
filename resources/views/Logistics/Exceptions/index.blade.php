@extends('Layouts.logistics')

@section('title', 'Parcel exceptions')

@section('logistics-toolbar')
    <span hidden data-logistics-hosted-bell></span>
@endsection

@section('content')
@php
    $quietButton = 'inline-flex min-h-11 items-center justify-center gap-2 rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] px-4 font-sans text-[12.5px] font-semibold text-[var(--text-ink)] no-underline transition-colors hover:bg-[var(--bg-raised)]';
    $hasCustomFilters = ($status !== 'active' && $status !== '') || request()->filled('type') || trim((string) request('search', '')) !== '';
@endphp
<section class="min-w-0 font-sans text-[var(--text-ink)] [&_:is(a,button,input,select):focus-visible]:outline-2 [&_:is(a,button,input,select):focus-visible]:outline-offset-4 [&_:is(a,button,input,select):focus-visible]:outline-[var(--link)]" aria-labelledby="exceptions-heading">
<header class="mb-6 flex flex-wrap items-center justify-between gap-4">
    <div>
        <p class="mb-2 text-[11px] font-semibold uppercase tracking-[0.12em] text-[var(--text-muted-2)]">Parcel operations</p>
        <h1 id="exceptions-heading" class="font-serif text-[26px] leading-tight font-semibold text-[var(--text-ink)] sm:text-[30px]">Parcel exceptions</h1>
        <p class="mt-2 max-w-3xl text-[14px] leading-relaxed text-[var(--text-muted-2)]">Missing, damaged, wrong-hub, inactive-connection and failed-transfer cases for parcels held by your company.</p>
    </div>
    <x-logistics.notification-bell />
</header>

<x-logistics-flash :messages="['success', 'warning', 'error']" />

<x-logistics.overdue-transfers :transfers="$overdueTransfers" :total="$overdueTransferCount" />

<form id="exception-filters" method="GET" action="{{ route('logistics.exceptions.index') }}" class="mb-6 grid items-end gap-4 rounded-xl border border-[var(--border-strong)] bg-[var(--bg-surface)] p-4 shadow-sm sm:grid-cols-2 sm:p-[22px] xl:grid-cols-[minmax(130px,1fr)_minmax(160px,1fr)_minmax(220px,2fr)_auto]">
    <div>
        <label for="status" class="mb-1 block text-xs font-semibold text-[var(--text-muted-2)]">Status</label>
        <select id="status" name="status" class="min-h-11 w-full min-w-0 rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] px-3 text-[13px] text-[var(--text-ink)]">
            @foreach (['active' => 'Active', 'open' => 'Open', 'under_review' => 'Under review', 'resolved' => 'Resolved', 'all' => 'All'] as $value => $label)
                <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label for="type" class="mb-1 block text-xs font-semibold text-[var(--text-muted-2)]">Type</label>
        <select id="type" name="type" class="min-h-11 w-full min-w-0 rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] px-3 text-[13px] text-[var(--text-ink)]">
            <option value="">All types</option>
            @foreach ($types as $value => $label)
                @if (in_array($value, \App\Services\Logistics\ParcelIncidentService::ACTIVE_TYPES, true))
                    <option value="{{ $value }}" @selected(request('type') === $value)>{{ $label }}</option>
                @endif
            @endforeach
        </select>
    </div>
    <div>
        <label for="search" class="mb-1 block text-xs font-semibold text-[var(--text-muted-2)]">Search</label>
        <input type="search" id="search" name="search" value="{{ request('search') }}" maxlength="100" placeholder="Tracking or parcel #" class="min-h-11 w-full min-w-0 rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] px-3 text-[13px] text-[var(--text-ink)]">
    </div>
    <div class="flex items-end gap-2">
        <a href="{{ route('logistics.exceptions.index') }}" data-exception-reset class="{{ $quietButton }}">Reset</a>
        <noscript><button type="submit" class="{{ $quietButton }}">Apply filters</button></noscript>
    </div>
    <p id="exception-filter-feedback" role="status" aria-live="polite" class="text-[11px] text-[var(--text-muted-2)] sm:col-span-2 xl:col-span-4">Filters apply automatically.</p>
</form>

<section aria-labelledby="exception-queue-heading" class="overflow-hidden rounded-xl border border-[var(--border-strong)] bg-[var(--bg-surface)] shadow-sm">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border-strong)] px-4 py-[18px] sm:px-[22px]">
        <h2 id="exception-queue-heading" class="font-sans text-[14px] font-semibold text-[var(--text-ink)]">Exception review queue</h2>
        <p class="text-[12px] text-[var(--text-muted-2)]">{{ number_format($exceptions->total()) }} {{ \Illuminate\Support\Str::plural('exception', $exceptions->total()) }} matching filters &middot; Newest first</p>
    </div>
    @if ($exceptions->count() > 0)
        <p class="border-b border-[var(--border-strong)] bg-[var(--bg-raised)] px-4 py-4 text-[11px] text-[var(--text-muted-2)] sm:px-[22px]">Scroll horizontally to see all exception details and actions.</p>
        <div role="region" aria-label="Parcel exceptions table" tabindex="0" class="overflow-x-auto overscroll-x-contain focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-[var(--link)]">
        <table class="w-full min-w-[1000px] table-fixed text-[13px] [&_td]:[overflow-wrap:anywhere]">
            <thead class="border-b border-[var(--border-strong)] bg-[var(--bg-page)]">
                <tr>
                    <th scope="col" class="px-4 py-4 text-left text-[10.5px] font-semibold uppercase tracking-[0.06em] text-[var(--text-muted-2)]">Parcel</th>
                    <th scope="col" class="px-4 py-4 text-left text-[10.5px] font-semibold uppercase tracking-[0.06em] text-[var(--text-muted-2)]">Type</th>
                    <th scope="col" class="px-4 py-4 text-left text-[10.5px] font-semibold uppercase tracking-[0.06em] text-[var(--text-muted-2)]">Status</th>
                    <th scope="col" class="px-4 py-4 text-left text-[10.5px] font-semibold uppercase tracking-[0.06em] text-[var(--text-muted-2)]">Custody</th>
                    <th scope="col" class="px-4 py-4 text-left text-[10.5px] font-semibold uppercase tracking-[0.06em] text-[var(--text-muted-2)]">Blocked leg</th>
                    <th scope="col" class="px-4 py-4 text-left text-[10.5px] font-semibold uppercase tracking-[0.06em] text-[var(--text-muted-2)]">Age</th>
                    <th scope="col" class="px-4 py-4 text-right text-[10.5px] font-semibold uppercase tracking-[0.06em] text-[var(--text-muted-2)]">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[var(--border-strong)]">
                @foreach ($exceptions as $incident)
                    <tr class="hover:bg-[var(--bg-page)]">
                        <td class="px-4 py-4 font-semibold text-[var(--text-ink)]">
                            #{{ $incident->incident_seller_order_id }}
                            @if ($incident->sellerOrder?->tracking_number)
                                <span class="mt-1 block font-mono text-[11px] text-[var(--text-muted-2)]">{{ $incident->sellerOrder->tracking_number }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-4">{{ $incident->typeLabel() }}</td>
                        <td class="px-4 py-4">
                            @php($statusValue = $incident->status?->value ?? $incident->status)
                            <span class="inline-flex rounded-md px-2 py-1 text-xs font-semibold {{ $statusValue === 'resolved' ? 'bg-[var(--pill-success-bg)] text-[var(--status-success)]' : 'bg-[var(--pill-warning-bg)] text-[var(--status-warning)]' }}">
                                {{ strtoupper(str_replace('_', ' ', $statusValue)) }}
                                @if ($incident->isHeld()) - ON HOLD @endif
                            </span>
                        </td>
                        <td class="px-4 py-4 text-xs text-[var(--text-muted-2)]">
                            Provider {{ $incident->sellerOrder?->currentCustodyProviderId() ?? '—' }}
                            · Hub {{ $incident->sellerOrder?->custody_hub_id ?? '—' }}
                        </td>
                        <td class="px-4 py-4 text-xs">{{ $incident->blocked_leg_index ?? '—' }}</td>
                        <td class="px-4 py-4 text-xs text-[var(--text-muted-2)]">{{ $incident->created_at?->diffForHumans() ?? '—' }}</td>
                        <td class="px-4 py-4 text-right">
                            <a href="{{ route('logistics.exceptions.show', $incident) }}" class="{{ $quietButton }}">Open</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </div>
    @else
        <div class="flex flex-col items-center px-[22px] py-[50px] text-center">
            <span class="mb-[18px] flex h-[52px] w-[52px] items-center justify-center rounded-xl border border-[var(--pill-success-border)] bg-[var(--pill-success-bg)] text-[var(--status-success)]"><i data-lucide="package-check" class="h-[25px] w-[25px]" aria-hidden="true"></i></span>
            <h3 class="font-sans text-[16px] font-semibold text-[var(--text-ink)]">{{ $exceptions->total() > 0 ? 'No exceptions on this page' : ($hasCustomFilters ? 'No matching exceptions' : 'No active parcel exceptions') }}</h3>
            <p class="mb-[22px] mt-2 max-w-[470px] text-[13px] leading-[1.75] text-[var(--text-muted-2)]">{{ $exceptions->total() > 0 ? 'There are exceptions on an earlier page of these results.' : ($hasCustomFilters ? 'Try another search, change the status or type, or reset your filters.' : 'There are no active cases for parcels held by your company. Exceptions reported from parcel details appear here.') }}</p>
            @if ($exceptions->total() > 0)
                <a href="{{ $exceptions->url(1) }}" class="{{ $quietButton }}">Back to first page</a>
            @elseif ($hasCustomFilters)
                <a href="{{ route('logistics.exceptions.index') }}" data-exception-reset class="{{ $quietButton }}">Reset filters</a>
            @else
                <a href="{{ route('logistics.delivery-monitoring.index') }}" class="{{ $quietButton }}">View delivery tracking</a>
            @endif
        </div>
    @endif
    @if ($exceptions->total() > 0)
        <footer class="flex flex-wrap items-center justify-between gap-4 border-t border-[var(--border-strong)] px-4 py-[18px] sm:px-[22px]">
            <p class="text-[12px] text-[var(--text-muted-2)]">Showing {{ $exceptions->firstItem() ?? 0 }} to {{ $exceptions->lastItem() ?? 0 }} of {{ number_format($exceptions->total()) }} exceptions</p>
            {{ $exceptions->links() }}
        </footer>
    @endif
</section>
</section>
@endsection

@push('scripts')
<script>
(function () {
    const form = document.getElementById('exception-filters');
    if (!form) return;
    const search = form.elements.namedItem('search');
    const feedback = document.getElementById('exception-filter-feedback');
    const focusKey = 'logistics.exceptions.search-focus';
    let timer;
    let composing = false;
    let submitting = false;
    const cancelPending = () => clearTimeout(timer);
    window.addEventListener('pageshow', () => {
        submitting = false;
        composing = false;
        feedback.textContent = 'Filters apply automatically.';
    });
    const apply = () => {
        cancelPending();
        if (!composing && !submitting) form.requestSubmit();
    };
    const schedule = () => {
        cancelPending();
        if (!composing && !submitting) timer = setTimeout(apply, 400);
    };
    form.addEventListener('submit', event => {
        cancelPending();
        if (composing || submitting) { event.preventDefault(); return; }
        submitting = true;
        form.querySelectorAll('[name="page"]').forEach(input => input.remove());
        feedback.textContent = 'Applying filters...';
        try {
            sessionStorage.removeItem(focusKey);
            if (document.activeElement === search) {
                sessionStorage.setItem(focusKey, JSON.stringify({ value: search.value, start: search.selectionStart, end: search.selectionEnd }));
            }
        } catch (_) {}
    });
    form.querySelectorAll('select').forEach(select => select.addEventListener('change', apply));
    search.addEventListener('compositionstart', () => { composing = true; cancelPending(); });
    search.addEventListener('compositionend', () => { composing = false; schedule(); });
    search.addEventListener('input', schedule);
    search.addEventListener('search', schedule);
    search.addEventListener('keydown', event => {
        if (event.key === 'Enter' && (composing || event.isComposing)) event.preventDefault();
    });
    document.querySelectorAll('[data-exception-reset]').forEach(link => link.addEventListener('click', () => {
        cancelPending();
        submitting = true;
        try { sessionStorage.removeItem(focusKey); } catch (_) {}
    }));
    try {
        const saved = JSON.parse(sessionStorage.getItem(focusKey) || 'null');
        sessionStorage.removeItem(focusKey);
        if (saved && saved.value === search.value) {
            search.focus({ preventScroll: true });
            search.setSelectionRange(saved.start, saved.end);
        }
    } catch (_) {}
})();
</script>
@endpush
