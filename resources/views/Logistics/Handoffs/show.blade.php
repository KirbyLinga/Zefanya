@extends('Layouts.logistics')

@section('title', 'Handoff #'.$handoff->id)

@section('content')
@if ($handoff->direction === 'return')<p class="mb-4 rounded-md border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900">Reverse physical return transport. Normal outgoing dispatch is blocked.</p>@endif
@use('App\Enums\ParcelHandoffStatus')

@php
    $statusStyles = [
        'REQUESTED' => 'border-amber-200 bg-amber-50 text-amber-800',
        'ACCEPTED' => 'border-sky-200 bg-sky-50 text-sky-800',
        'IN_TRANSIT' => 'border-indigo-200 bg-indigo-50 text-indigo-800',
        'REJECTED' => 'border-red-200 bg-red-50 text-red-800',
        'RECEIVED' => 'border-emerald-200 bg-emerald-50 text-emerald-800',
    ];
    $status = $handoff->status?->value ?? 'REQUESTED';
    $statusStyle = $statusStyles[$status] ?? $statusStyles['REQUESTED'];
    $order = $handoff->sellerOrder;
    $address = $order?->order?->shipping_address ?? [];
    $timeline = [];
    if ($handoff->requested_at) {
        $timeline[] = ['label' => 'Requested', 'at' => $handoff->requested_at, 'by' => $handoff->originProvider?->business_name];
    }
    if ($handoff->accepted_at) {
        $timeline[] = ['label' => 'Accepted by destination', 'at' => $handoff->accepted_at, 'by' => $handoff->destinationProvider?->business_name];
    }
    if ($handoff->rejected_at) {
        $timeline[] = ['label' => 'Rejected by destination', 'at' => $handoff->rejected_at, 'by' => $handoff->destinationProvider?->business_name];
    }
    if ($handoff->dispatched_at) {
        $timeline[] = ['label' => 'Dispatched from sending hub', 'at' => $handoff->dispatched_at, 'by' => $handoff->originProvider?->business_name];
    }
    if ($handoff->received_at) {
        $timeline[] = ['label' => 'Received and custody transferred', 'at' => $handoff->received_at, 'by' => $handoff->destinationProvider?->business_name];
    }
    $canDispatch = $isOrigin && $handoff->status === ParcelHandoffStatus::Accepted;
    $canReceive = $isDestination && $handoff->status === ParcelHandoffStatus::InTransit;
    $canRespond = $isDestination && $handoff->status === ParcelHandoffStatus::Requested;
@endphp

<div class="mb-6 flex flex-wrap items-start justify-between gap-4 border-b border-[var(--border-strong)] pb-6">
    <div class="min-w-0">
        <a href="{{ route('logistics.handoffs.index') }}" class="mb-2 inline-flex items-center gap-1 font-sans text-[10px] font-semibold uppercase tracking-[0.14em] text-primary-700 hover:underline">
            <i data-lucide="arrow-left" class="size-3"></i>All handoffs
        </a>
        <div class="flex flex-wrap items-center gap-3">
            <h1 class="font-serif text-[28px] leading-tight text-[var(--text-ink)] sm:text-[32px]">Handoff #{{ $handoff->id }}</h1>
            <span class="inline-flex rounded-full border px-2.5 py-1 font-sans text-[10px] font-semibold uppercase tracking-[0.1em] {{ $statusStyle }}">{{ $handoff->statusLabel() }}</span>
        </div>
        <p class="mt-2 font-mono text-[11px] text-[var(--text-muted)]">{{ $order?->tracking_number }}</p>
    </div>
    <a href="{{ route('logistics.partnerships.index') }}" class="inline-flex min-h-10 items-center justify-center gap-2 rounded-sm border border-[var(--border-strong)] px-3 font-sans text-[11px] font-semibold text-[var(--text-ink)] transition hover:bg-[var(--bg-surface)]">
        <i data-lucide="handshake" class="size-3.5"></i>Partnerships
    </a>
</div>

<x-logistics-flash :messages="['success', 'warning', 'error']" />
@if($errors->any())
    <div role="alert" class="mb-5 rounded-md border border-red-200 bg-red-50 p-4 font-sans text-xs leading-relaxed text-red-900">
        <ul class="list-disc pl-4">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    </div>
@endif

<div class="grid grid-cols-1 items-start gap-6 xl:grid-cols-[minmax(0,1.5fr)_minmax(300px,1fr)]">
    <div class="min-w-0 space-y-5">
        @if($canDispatch || $canReceive)
            <section class="rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-5 shadow-sm">
                <x-parcel-scanner
                    :verify-url="route('logistics.handoffs.scan.verify', $handoff)"
                    :heading="$canDispatch ? 'Scan the parcel to dispatch' : 'Scan the parcel to receive'"
                    :subheading="$canDispatch
                        ? 'Scan the waybill QR or enter the tracking number. Dispatch records departure only; custody stays with the origin until the destination verifies receipt.'
                        : 'Scan the waybill QR or enter the tracking number. This is the only action that transfers custody, and it never changes the delivery status or tracking number.'"
                />
            </section>
        @endif

        @if($canRespond)
            <section class="rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-5 shadow-sm">
                <h2 class="font-serif text-lg text-[var(--text-ink)]">Respond to this request</h2>
                <p class="mt-1 font-sans text-[11px] leading-relaxed text-[var(--text-muted)]">Accepting reserves the parcel for your hub without moving custody. Rejecting keeps the parcel with the origin and offers it to the next eligible partner by priority.</p>
                <div class="mt-4 flex flex-wrap items-start gap-3">
                    <form method="POST" action="{{ route('logistics.handoffs.accept', $handoff) }}">
                        @csrf
                        <button type="submit" class="inline-flex min-h-9 items-center gap-1.5 rounded-sm bg-emerald-700 px-4 font-sans text-[11px] font-semibold text-white hover:bg-emerald-800">
                            <i data-lucide="check" class="size-3.5"></i>Accept handoff
                        </button>
                    </form>
                    <form method="POST" action="{{ route('logistics.handoffs.reject', $handoff) }}" class="min-w-[260px] flex-1 rounded-md border border-red-200 bg-red-50/60 p-3">
                        @csrf
                        <label for="rejection_reason" class="mb-1 block font-sans text-[10px] font-semibold uppercase tracking-[0.1em] text-red-800">Rejection reason</label>
                        <textarea id="rejection_reason" name="rejection_reason" required maxlength="500" rows="2" class="w-full rounded-sm border border-red-200 bg-white p-2 font-sans text-xs text-[var(--text-ink)]"></textarea>
                        <button type="submit" class="mt-2 inline-flex min-h-8 items-center rounded-sm bg-red-700 px-3 font-sans text-[10px] font-semibold text-white hover:bg-red-800">Reject and offer next partner</button>
                    </form>
                </div>
            </section>
        @endif

        @if($handoff->rejection_reason)
            <section class="rounded-lg border border-red-200 bg-red-50/70 p-4 font-sans text-xs leading-relaxed text-red-900">
                <p class="font-semibold">Rejection reason</p>
                <p class="mt-1">{{ $handoff->rejection_reason }}</p>
            </section>
        @endif

        <section class="overflow-hidden rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] shadow-sm" aria-labelledby="handoffParcelTitle">
            <div class="border-b border-[var(--border-strong)] px-4 py-4">
                <h2 id="handoffParcelTitle" class="font-serif text-xl text-[var(--text-ink)]">Parcel and route</h2>
                <p class="mt-1 font-sans text-[11px] text-[var(--text-muted)]">The tracking number and canonical delivery status are preserved across every handoff.</p>
            </div>
            <x-handoff-progress :handoff="$handoff" />
            <dl class="grid grid-cols-1 gap-x-6 gap-y-4 px-4 py-4 sm:grid-cols-2">
                <div>
                    <dt class="font-sans text-[10px] font-semibold uppercase tracking-[0.12em] text-[var(--text-muted)]">Tracking</dt>
                    <dd class="mt-1 font-mono text-xs font-semibold text-[var(--text-ink)]">{{ $order?->tracking_number ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="font-sans text-[10px] font-semibold uppercase tracking-[0.12em] text-[var(--text-muted)]">Delivery status</dt>
                    <dd class="mt-1 font-sans text-xs text-[var(--text-ink)]">{{ $order?->status?->label() ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="font-sans text-[10px] font-semibold uppercase tracking-[0.12em] text-[var(--text-muted)]">Seller</dt>
                    <dd class="mt-1 font-sans text-xs text-[var(--text-ink)]">{{ $order?->seller?->business_name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="font-sans text-[10px] font-semibold uppercase tracking-[0.12em] text-[var(--text-muted)]">Buyer destination</dt>
                    <dd class="mt-1 font-sans text-xs text-[var(--text-ink)]">{{ $address['barangay'] ?? $order?->buyerMunicipality() ?? '—' }}, {{ $address['municipality'] ?? '' }}</dd>
                </div>
                <div>
                    <dt class="font-sans text-[10px] font-semibold uppercase tracking-[0.12em] text-[var(--text-muted)]">Origin provider</dt>
                    <dd class="mt-1 font-sans text-xs text-[var(--text-ink)]">{{ $handoff->originProvider?->business_name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="font-sans text-[10px] font-semibold uppercase tracking-[0.12em] text-[var(--text-muted)]">Destination provider</dt>
                    <dd class="mt-1 font-sans text-xs text-[var(--text-ink)]">{{ $handoff->destinationProvider?->business_name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="font-sans text-[10px] font-semibold uppercase tracking-[0.12em] text-[var(--text-muted)]">Sending hub</dt>
                    <dd class="mt-1 font-sans text-xs text-[var(--text-ink)]">{{ $handoff->sendingHub?->name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="font-sans text-[10px] font-semibold uppercase tracking-[0.12em] text-[var(--text-muted)]">Receiving hub</dt>
                    <dd class="mt-1 font-sans text-xs text-[var(--text-ink)]">{{ $handoff->receivingHub?->name ?? '—' }}</dd>
                </div>
            </dl>
        </section>
    </div>

    <aside class="space-y-5">
        <section class="rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-5 shadow-sm" aria-labelledby="handoffTimelineTitle">
            <h2 id="handoffTimelineTitle" class="font-serif text-lg text-[var(--text-ink)]">Custody timeline</h2>
            <p class="mt-1 font-sans text-[11px] leading-relaxed text-[var(--text-muted)]">Every actor and timestamp is stored on the handoff row. Dispatch and receipt are separate scans.</p>
            <ol class="mt-4 space-y-4">
                @forelse($timeline as $event)
                    <li class="relative border-l border-[var(--border-strong)] pl-4">
                        <span class="absolute -left-[5px] top-1.5 size-2.5 rounded-full border-2 border-[var(--bg-surface)] bg-primary-600"></span>
                        <p class="font-sans text-[11px] font-semibold text-[var(--text-ink)]">{{ $event['label'] }}</p>
                        <p class="mt-0.5 font-sans text-[10px] text-[var(--text-muted)]">{{ $event['at']->format('M j, Y g:i A') }} · {{ $event['by'] ?? 'Provider' }}</p>
                    </li>
                @empty
                    <li class="font-sans text-[11px] text-[var(--text-muted)]">No custody events recorded yet.</li>
                @endforelse
            </ol>
        </section>

        <section class="rounded-lg border border-[var(--border-strong)] bg-[var(--bg-page)]/50 p-4 font-sans text-[11px] leading-relaxed text-[var(--text-muted)]">
            <p class="flex items-center gap-2 font-semibold text-[var(--text-ink)]"><i data-lucide="shield-check" class="size-4 text-primary-700"></i>Safe handoff rules</p>
            <ul class="mt-2 list-disc space-y-1 pl-4">
                <li>Only the destination can accept or reject its own incoming request.</li>
                <li>Dispatch requires an accepted handoff and a single-use parcel scan.</li>
                <li>Only a separate destination receiving scan transfers custody.</li>
                <li>No scan marks the parcel delivered, returned, or picked up.</li>
            </ul>
        </section>
    </aside>
</div>
@endsection

@if($canDispatch || $canReceive)
    @push('scripts')
        @vite('resources/js/parcel-scanner.js')
    @endpush
@endif
