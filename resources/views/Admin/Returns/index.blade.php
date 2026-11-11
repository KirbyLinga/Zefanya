@extends('Admin.Layouts.app')
@section('title', 'Returns & disputes')
@section('content-width', 'max-w-none')
@section('content')
<section class="case-workspace" aria-labelledby="case-heading">
<x-admin.tabs :tabs="[
    ['label' => 'Returns & disputes', 'route' => 'admin.returns.index'],
    ['label' => 'Parcel incidents', 'route' => 'admin.incidents.index'],
    ...(\App\Services\Logistics\ParcelLossService::available() ? [['label' => 'Lost parcels', 'route' => 'admin.lost-parcels.index']] : []),
]" />
<header class="case-heading"><div><p class="case-eyebrow">Case management</p><h1 id="case-heading">Returns &amp; disputes</h1><p class="case-description">Review return requests, resolve disputes and track return custody.</p></div><span class="case-total"><i data-lucide="undo-2" aria-hidden="true"></i><strong>{{ number_format($returns->total()) }}</strong> total returns</span></header>
<div class="case-panel-heading case-directory-heading"><h2>Return directory</h2><span>All return statuses</span></div>
<x-logistics-flash :messages="['success', 'warning', 'error']" />
@if ($errors->any())<div role="alert" class="mb-4 rounded-md border border-red-200 bg-red-50 p-3 text-xs text-red-900">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif

<div class="space-y-5">@forelse ($returns as $record)
<article class="case-return-record rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-5">
    <x-return-summary :record="$record" />
    <div class="mt-4 space-y-2 text-xs text-[var(--text-muted)]">
        @foreach ($record->sellerOrder->manifestItems as $item)
            @if ($item->manifest?->direction === 'return')<p>Reverse manifest #{{ $item->manifest_id }} | {{ $item->manifest->status->value }} | Dispatch {{ $item->manifest->dispatched_at?->format('M j g:i A') ?? 'pending' }} | Receipt {{ $item->received_at?->format('M j g:i A') ?? 'pending' }}</p>@endif
        @endforeach
        @foreach ($record->sellerOrder->parcelHandoffs as $handoff)
            @if ($handoff->direction === 'return')<p>Reverse handoff #{{ $handoff->id }} | {{ $handoff->status->value }} | Dispatch {{ $handoff->dispatched_at?->format('M j g:i A') ?? 'pending' }} | Receipt {{ $handoff->received_at?->format('M j g:i A') ?? 'pending' }}</p>@endif
        @endforeach
    </div>

    @if ($record->inDispute())
        <p class="mt-3 text-xs font-semibold text-[var(--text-ink)]">In Dispute. The seller is locked out until you resolve this.</p>
        <p class="mt-1 text-xs text-[var(--text-muted)]">Seller's rejection: {{ $record->seller_decision_reason }}</p>
        <form method="POST" action="{{ route('admin.returns.dispute', $record) }}" class="mt-3 space-y-2">@csrf
            <fieldset class="flex flex-wrap gap-4 text-xs text-[var(--text-ink)]">
                <label class="flex items-center gap-2"><input type="radio" name="outcome" value="upheld" required> Keep the rejection</label>
                <label class="flex items-center gap-2"><input type="radio" name="outcome" value="overturned"> Approve the return</label>
            </fieldset>
            <label class="block text-xs" for="dispute-note-{{ $record->id }}">Resolution note</label>
            <textarea id="dispute-note-{{ $record->id }}" name="note" required minlength="10" maxlength="1000" class="block w-full rounded-sm border border-[var(--border-strong)] p-2 text-xs"></textarea>
            <button type="submit" class="rounded-sm bg-primary-700 px-3 py-2 text-xs text-white">Resolve dispute</button>
        </form>
    @endif
    @if (\App\Models\Logistics\ParcelReturn::recoveryAvailable())
        @if ((int) $record->collection_failed_attempts > 0)
            <div class="mt-3 rounded-md border border-amber-300 bg-amber-50 p-3 text-xs text-amber-900">
                <p class="font-semibold">{{ $record->collectionEscalated() ? 'Needs your decision: collection failed '.$record->collection_failed_attempts.' times.' : 'Missed collections: '.$record->collection_failed_attempts.' of '.\App\Models\Logistics\ParcelReturn::MAX_COLLECTION_ATTEMPTS }}</p>
                @foreach ($record->events->filter(fn ($event) => str_starts_with($event->event_key, 'collection_failed')) as $event)
                    <p>{{ $event->occurred_at->timezone('Asia/Manila')->format('M j, Y g:i A') }} · Rider #{{ $event->actor_id }} · {{ $record->collectionFailureLabel($event->metadata['reason'] ?? null) }}@if (! empty($event->metadata['notes'])) · {{ $event->metadata['notes'] }}@endif</p>
                @endforeach
                @if ($record->collectionRetryAuthorized())
                    <p class="mt-2 font-semibold">One extra collection was authorized {{ $record->collection_retry_authorized_at->timezone('Asia/Manila')->format('M j, Y g:i A') }}. No further attempt is allowed after it.</p>
                @elseif ($record->collectionEscalated() && (int) $record->collection_failed_attempts === \App\Models\Logistics\ParcelReturn::MAX_COLLECTION_ATTEMPTS)
                    <form method="POST" action="{{ route('admin.returns.collection-retry', $record) }}" class="mt-2 space-y-2">
                        @csrf
                        <label class="block" for="retry-note-{{ $record->id }}">Authorize one more buyer collection (why the buyer can now hand it over)</label>
                        <textarea id="retry-note-{{ $record->id }}" name="retry_note" required minlength="10" maxlength="1000" rows="2" class="block w-full rounded-sm border border-[var(--border-strong)] p-2 text-xs"></textarea>
                        <button type="submit" class="rounded-sm border border-primary-700 px-3 py-2 text-xs font-semibold text-primary-800">Authorize one more attempt</button>
                    </form>
                @endif
            </div>
        @endif
        @if ($record->receiptDisputed())
            <div class="mt-3 rounded-md border border-amber-300 bg-amber-50 p-3 text-xs text-amber-900">
                <p class="font-semibold">Seller receipt disputed: {{ \App\Models\Logistics\ParcelReturn::RECEIPT_DISPUTE_ISSUES[$record->receipt_dispute_issue] ?? 'Other' }}</p>
                <p class="mt-1">{{ $record->receipt_dispute_note }}</p>
                @foreach ($record->receipt_dispute_photos ?? [] as $index => $photo)
                    <a class="mr-2 font-semibold underline" href="{{ route('admin.returns.receipt-dispute.photo', [$record, $index]) }}" target="_blank" rel="noopener">Photo {{ $index + 1 }}</a>
                @endforeach
                <p class="mt-1">The parcel stays with {{ $record->originProvider?->business_name ?? 'Logistics' }} at {{ $record->originHub?->name ?? 'the origin hub' }}. To charge Logistics for transit damage, use "Decide who pays" below.</p>
                <form method="POST" action="{{ route('admin.returns.receipt-dispute.resume', $record) }}" class="mt-2 space-y-2">@csrf
                    <label class="block" for="resume-note-{{ $record->id }}">Send back to handover (the Seller must receive it with a verified code)</label>
                    <textarea id="resume-note-{{ $record->id }}" name="resume_note" required minlength="10" maxlength="1000" rows="2" class="block w-full rounded-sm border border-[var(--border-strong)] p-2 text-xs"></textarea>
                    <button type="submit" class="rounded-sm bg-primary-700 px-3 py-2 text-xs text-white">Send back to handover</button>
                </form>
            </div>
        @endif
        @if ($record->receiptDisputed() || ($record->isBuyerRequest() && $record->status === \App\Enums\ParcelReturnStatus::Requested && $record->collected_at === null))
            <details class="mt-3 text-xs" @if ($record->collectionEscalated()) open @endif>
                <summary class="cursor-pointer font-semibold text-red-800">Close this return</summary>
                <p class="mt-1 text-[var(--text-muted)]">{{ $record->receiptDisputed() ? 'Closes the disputed return where it stands. Custody, refund and who pays do not change.' : 'Not collected yet: the buyer keeps the item, no refund is due and the Seller\'s held earnings come back.' }}</p>
                <form method="POST" action="{{ route('admin.returns.close', $record) }}" class="mt-2 space-y-2" onsubmit="return confirm('Close this return? This cannot be undone.')">@csrf
                    <label class="block" for="close-reason-{{ $record->id }}">Reason (shown to the buyer and Seller)</label>
                    <textarea id="close-reason-{{ $record->id }}" name="close_reason" required minlength="10" maxlength="1000" rows="2" class="block w-full rounded-sm border border-[var(--border-strong)] p-2 text-xs"></textarea>
                    <button type="submit" class="rounded-sm border border-red-700 px-3 py-2 text-xs font-semibold text-red-800">Close return</button>
                </form>
            </details>
        @endif
        @if ($record->closed_at)
            <p class="mt-3 text-xs text-[var(--text-ink)]">{{ $record->status->label() }} {{ $record->closed_at->timezone('Asia/Manila')->format('M j, Y g:i A') }} by {{ $record->closed_by_type }} #{{ $record->closed_by_id }}. {{ $record->closed_reason }}</p>
        @endif
        @if ($record->dispositionCanBeRecorded())
            <div class="mt-3 rounded-md border border-[var(--border-strong)] bg-[var(--bg-raised)] p-3 text-xs">
                <p class="font-semibold text-[var(--text-ink)]">Physical disposition</p>
                @if ($record->disposition)
                    <p class="mt-1 text-[var(--text-ink)]">{{ $record->dispositionLabel() }} · {{ $record->disposition_set_at?->timezone('Asia/Manila')->format('M j, Y g:i A') }}. {{ $record->disposition_note }}</p>
                @else
                    <p class="mt-1 text-[var(--text-muted)]">Not recorded yet. This tracks where the parcel physically went; it does not move custody, refund or stock.</p>
                @endif
                <form method="POST" action="{{ route('admin.returns.disposition', $record) }}" class="mt-2 grid gap-2 sm:grid-cols-2">
                    @csrf
                    <label class="text-xs">What happened
                        <select name="disposition" required class="mt-1 block w-full rounded-sm border border-[var(--border-strong)] p-2 text-xs">
                            @foreach (\App\Models\Logistics\ParcelReturn::DISPOSITIONS as $value => $label)
                                <option value="{{ $value }}" @selected($record->disposition === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="text-xs sm:col-span-2" for="disposition-note-{{ $record->id }}">Evidence and detail
                        <textarea id="disposition-note-{{ $record->id }}" name="disposition_note" required minlength="10" maxlength="1000" rows="2" class="mt-1 block w-full rounded-sm border border-[var(--border-strong)] p-2 text-xs"></textarea>
                    </label>
                    <button type="submit" class="rounded-sm border border-primary-700 px-3 py-2 text-xs font-semibold text-primary-800 sm:col-span-2">Record disposition</button>
                </form>
            </div>
        @endif
    @endif
    @if ($record->exception_reason)
        <p class="mt-3 text-xs text-red-800">Unresolved: {{ $record->exception_reason }}</p>
        <form method="POST" action="{{ route('admin.returns.resolve', $record) }}" class="mt-3 flex flex-wrap gap-2">@csrf<input name="resolution_note" required maxlength="1000" placeholder="Resolution evidence" aria-label="Resolution evidence" class="min-w-0 flex-1 rounded-sm border border-[var(--border-strong)] p-2 text-xs"><button type="submit" class="rounded-sm bg-primary-700 px-3 py-2 text-xs text-white">Record exception resolution</button></form>
        <form method="POST" action="{{ route('admin.returns.reverse-route', $record) }}" class="mt-3 space-y-2">@csrf
            <label class="block text-xs text-[var(--text-ink)]" for="reverse-route-{{ $record->id }}">No reverse partnership? Authorize this parcel to go back to the provider that handed it over.</label>
            <input id="reverse-route-{{ $record->id }}" name="note" required minlength="10" maxlength="1000" placeholder="Why this return is authorized" class="block w-full rounded-sm border border-[var(--border-strong)] p-2 text-xs">
            <button type="submit" class="rounded-sm border border-primary-700 px-3 py-2 text-xs font-semibold text-primary-800">Authorize reverse route</button>
        </form>
    @else<p class="mt-3 text-xs text-[var(--text-muted)]">No unresolved routing exception. Resolution does not confirm Seller receipt.</p>@endif
    @if (blank($record->seller_address['full'] ?? null) && $record->status->value === 'RETURN_REQUESTED')
        <form method="POST" action="{{ route('admin.returns.origin', $record) }}" class="mt-4 grid gap-2 sm:grid-cols-2">@csrf
            <label class="text-xs">Verified original hub<select name="origin_hub_id" required class="mt-1 block w-full border p-2">@foreach ($hubs as $hub)<option value="{{ $hub->id }}">{{ $hub->provider?->business_name }} | {{ $hub->name }}</option>@endforeach</select></label>
            <label class="text-xs">Original barangay code<input name="barangay_code" required maxlength="20" class="mt-1 block w-full border p-2"></label>
            <label class="text-xs">Original pickup address<input name="address" required maxlength="1000" class="mt-1 block w-full border p-2"></label>
            <label class="text-xs">Historical verification evidence<input name="evidence" required maxlength="1000" class="mt-1 block w-full border p-2"></label>
            <button type="submit" class="rounded-sm bg-primary-700 px-3 py-2 text-xs text-white">Verify original location</button>
        </form>
    @endif
    @if ($record->financial_review_required)
        <x-return-money :record="$record" audience="admin" />
        @if ($record->faultCanChange())
            <form method="POST" action="{{ route('admin.returns.fault', $record) }}" class="mt-3 space-y-2">@csrf
                <fieldset class="flex flex-wrap gap-4 text-xs text-[var(--text-ink)]">
                    <legend class="mb-1 text-xs font-semibold">Decide who pays</legend>
                    <label class="flex items-center gap-2"><input type="radio" name="fault_party" value="{{ \App\Models\Logistics\ParcelReturn::FAULT_SELLER }}" required @checked($record->fault_party === \App\Models\Logistics\ParcelReturn::FAULT_SELLER)> Seller (wrong, defective or not as described)</label>
                    <label class="flex items-center gap-2"><input type="radio" name="fault_party" value="{{ \App\Models\Logistics\ParcelReturn::FAULT_LOGISTICS }}" @checked($record->isLogisticsFault())> Logistics (damaged in transit)</label>
                </fieldset>
                <label class="block text-xs" for="fault-note-{{ $record->id }}">Why (shown to Admins only)</label>
                <textarea id="fault-note-{{ $record->id }}" name="fault_note" required minlength="10" maxlength="1000" rows="2" class="block w-full rounded-sm border border-[var(--border-strong)] p-2 text-xs"></textarea>
                <button type="submit" class="rounded-sm border border-primary-700 px-3 py-2 text-xs font-semibold text-primary-800">Save who pays</button>
            </form>
        @endif
        @php $paidMinor = (int) ($record->sellerOrder->payment?->amount_minor ?? 0); @endphp
        @if ($record->refunded_at)
            <p class="mt-3 text-xs text-[var(--text-ink)]">Refund recorded {{ $record->refunded_at->timezone('Asia/Manila')->format('M j, Y g:i A') }}. Reference: {{ $record->refund_reference }}.
                @if ($record->refund_amount_minor !== null)₱{{ number_format($record->refund_amount_minor / 100, 2) }} to {{ $record->refund_recipient }}, paid {{ $record->refund_paid_on?->format('M j, Y') }}.@endif
                @if ($record->refund_proof_path)<a class="font-semibold text-primary-800 underline" href="{{ route('admin.returns.refund-proof', $record) }}" target="_blank" rel="noopener">View proof</a>.@endif</p>
            @if ($record->refund_reason)<p class="mt-1 text-xs text-[var(--text-muted)]">Why less than the buyer paid: {{ $record->refund_reason }}</p>@endif
            <details class="mt-2 text-xs">
                <summary class="cursor-pointer font-semibold text-red-800">The transfer failed? Void this refund</summary>
                <form method="POST" action="{{ route('admin.returns.refund.void', $record) }}" class="mt-2 space-y-2" onsubmit="return confirm('Void this refund? It stays in the history and you can record a new one.')">@csrf
                    <label class="block" for="void-reason-{{ $record->id }}">What went wrong (bounced transfer, wrong account…)</label>
                    <textarea id="void-reason-{{ $record->id }}" name="void_reason" required minlength="10" maxlength="1000" rows="2" class="block w-full rounded-sm border border-[var(--border-strong)] p-2 text-xs"></textarea>
                    <button type="submit" class="rounded-sm border border-red-700 px-3 py-2 text-xs font-semibold text-red-800">Void refund</button>
                </form>
            </details>
        @elseif ($record->isFailedDeliveryReturn() && ! \App\Models\Logistics\CodRemittance::query()->where('seller_order_id', $record->seller_order_id)->exists())
            <p class="mt-3 text-xs text-[var(--text-muted)]">No money was collected from the buyer for this failed delivery, so there is nothing to refund.</p>
        @elseif ($record->isCancelled())
            <p class="mt-3 text-xs text-[var(--text-muted)]">Cancelled before collection: the buyer kept the item, so there is nothing to refund.</p>
        @elseif ($record->needsAdminFaultDecision())
            <p class="mt-3 text-xs text-amber-800">Recording the refund is available after you decide who pays.</p>
        @else
            <form method="POST" action="{{ route('admin.returns.refund', $record) }}" enctype="multipart/form-data" class="mt-3 grid gap-2 sm:grid-cols-2">@csrf
                <input name="reference" required maxlength="255" placeholder="COD refund reference" aria-label="COD refund reference" class="min-w-0 rounded-sm border border-[var(--border-strong)] p-2 text-xs">
                <input name="amount" required inputmode="decimal" placeholder="Amount refunded (PHP)" aria-label="Amount refunded in pesos" class="min-w-0 rounded-sm border border-[var(--border-strong)] p-2 text-xs">
                <input name="recipient" required maxlength="255" placeholder="Who received it (name and account)" aria-label="Refund recipient" class="min-w-0 rounded-sm border border-[var(--border-strong)] p-2 text-xs">
                <input type="date" name="paid_on" required max="{{ now('Asia/Manila')->toDateString() }}" value="{{ now('Asia/Manila')->toDateString() }}" aria-label="Date the refund was paid" class="min-w-0 rounded-sm border border-[var(--border-strong)] p-2 text-xs">
                <label class="text-xs text-[var(--text-ink)] sm:col-span-2">Reason, required if you refund less than ₱{{ number_format($paidMinor / 100, 2) }} (the buyer and Seller see it)
                    <textarea name="refund_reason" maxlength="1000" rows="2" class="mt-1 block w-full rounded-sm border border-[var(--border-strong)] p-2 text-xs"></textarea>
                </label>
                <label class="text-xs text-[var(--text-ink)] sm:col-span-2">Proof of payment (optional, image or PDF up to 5 MB)
                    <input type="file" name="proof" accept=".jpg,.jpeg,.png,.pdf" class="upload-input">
                </label>
                <button type="submit" class="rounded-sm bg-primary-700 px-3 py-2 text-xs text-white sm:col-span-2">Record refund</button>
            </form>
            <p class="mt-2 text-xs text-amber-800">A full refund is everything the buyer paid, shipping included. If you refund less, whoever pays is charged only the amount refunded.</p>
        @endif
        @php $voided = $record->events->filter(fn ($event) => str_starts_with($event->event_key, 'refund_voided')); @endphp
        @if ($voided->isNotEmpty())
            <div class="mt-3 text-xs text-[var(--text-muted)]">
                <p class="font-semibold text-[var(--text-ink)]">Voided refunds</p>
                @foreach ($voided as $event)
                    <p>{{ $event->occurred_at->timezone('Asia/Manila')->format('M j, Y g:i A') }} · ₱{{ number_format(((int) ($event->metadata['amount_minor'] ?? 0)) / 100, 2) }} to {{ $event->metadata['recipient'] ?? '?' }} (ref {{ $event->metadata['reference'] ?? '?' }}) · {{ $event->metadata['void_reason'] ?? '' }}</p>
                @endforeach
            </div>
        @endif
    @endif
    @if ($record->returnCharges->isNotEmpty())
        <div data-return-charges class="mt-4 space-y-2 rounded-md border border-[var(--border-strong)] p-3 text-xs">
            <p class="font-semibold text-[var(--text-ink)]">Return charges with Logistics</p>
            @foreach ($record->returnCharges as $charge)
                <div class="flex flex-wrap items-center gap-2">
                    <p class="min-w-0 flex-1 text-[var(--text-ink)]">{{ $charge->label() }}: {{ $charge->owedToZefanya() ? ($charge->provider?->business_name ?? 'Logistics').' repays Zefanya' : 'Zefanya pays '.($charge->provider?->business_name ?? 'Logistics') }} ₱{{ number_format($charge->amount_minor / 100, 2) }}.
                        @if ($charge->settled_at)<span class="text-[var(--text-muted)]">Settled {{ $charge->settled_at->timezone('Asia/Manila')->format('M j, Y') }}, ref {{ $charge->settlement_reference }}.</span>@else<span class="font-semibold text-amber-800">Not settled.</span>@endif</p>
                    @if (! $charge->settled_at)
                        <form method="POST" action="{{ route('admin.return-charges.settle', $charge) }}" class="flex gap-2">@csrf
                            <input name="reference" required maxlength="100" placeholder="Payment reference" aria-label="Payment reference for return charge {{ $charge->id }}" class="w-40 rounded-sm border border-[var(--border-strong)] p-2 text-xs">
                            <button type="submit" class="rounded-sm border border-primary-700 px-3 py-2 text-xs font-semibold text-primary-800">{{ $charge->owedToZefanya() ? 'Mark repaid' : 'Mark paid' }}</button>
                        </form>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
    @if ($record->seller_received_at && ! $record->stock_restored_at)
        <p class="mt-3 text-xs text-amber-800">Inventory held | {{ $record->stock_condition }}</p>
        @if ($record->stock_condition === 'resellable' && config('returns.restore_verified_resellable') === true)
            <form method="POST" action="{{ route('admin.returns.stock', $record) }}" class="mt-2">@csrf<button class="rounded-sm bg-primary-700 px-3 py-2 text-xs text-white" type="submit">Release approved resellable stock</button></form>
        @endif
    @endif
    <ol class="mt-4 space-y-1 text-xs text-[var(--text-muted)]">@foreach ($record->events as $event)<li>{{ $event->occurred_at->format('M j, Y g:i A') }} | {{ $event->event_key }} | {{ $event->actor_type }} #{{ $event->actor_id }}</li>@endforeach</ol>
    <ul class="mt-3 space-y-1 text-xs">@foreach ($record->sellerOrder->parcelHandoffExceptions as $exception)<li>{{ $exception->status }} | {{ $exception->resolution_note ?? $exception->reason }}</li>@endforeach</ul>
</article>
@empty
<div class="case-panel case-empty"><span class="case-empty-icon"><i data-lucide="clipboard-check" aria-hidden="true"></i></span>
@if ($returns->total() > 0)
<h3>No returns on this page</h3><p>Browse the first page to see existing returns and disputes.</p><a class="case-action" href="{{ $returns->url(1) }}">Back to first page</a>
@else
<h3>No returns or disputes to review</h3><p>Return requests, disputes and custody details will appear here.</p><a class="case-action" href="{{ route('admin.incidents.index') }}"><i data-lucide="arrow-right" aria-hidden="true"></i>View parcel incidents</a>
@endif
</div>@endforelse</div>
@if ($returns->total() > 0)<footer class="case-pagination"><p>Showing <strong>{{ $returns->firstItem() ?? 0 }} to {{ $returns->lastItem() ?? 0 }}</strong> of <strong>{{ number_format($returns->total()) }}</strong> returns</p>{{ $returns->links() }}</footer>@endif
</section>
@endsection
