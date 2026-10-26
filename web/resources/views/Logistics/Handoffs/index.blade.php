@extends('Layouts.logistics')

@section('title', 'Cross-Provider Handoffs')

@section('content')
<div data-handoffs-page>
    <header class="mb-7 flex flex-wrap items-end justify-between gap-4 border-b border-[var(--border-strong)] pb-6">
        <div>
            <p class="mb-2 font-sans text-[10px] font-semibold uppercase tracking-[0.18em] text-primary-700">Logistics network</p>
            <h1 class="font-serif text-[28px] leading-tight text-[var(--text-ink)] sm:text-[32px]">Cross-Provider Handoffs</h1>
            <p class="mt-2 font-sans text-xs text-[var(--text-muted)]">{{ $provider->business_name }} <span aria-hidden="true">/</span> {{ $provider->province_name ?: 'Service province' }}</p>
        </div>
        <a href="{{ route('logistics.partnerships.index') }}" class="inline-flex min-h-10 items-center justify-center gap-2 rounded-sm border border-[var(--border-strong)] px-3 font-sans text-[11px] font-semibold text-[var(--text-ink)] transition hover:bg-[var(--bg-surface)]">
            <i data-lucide="handshake" class="size-3.5"></i>Provider Partnerships
        </a>
    </header>

    <x-logistics-flash :messages="['success', 'warning', 'error']" />
    @if($errors->any())
        <div role="alert" class="mb-5 rounded-md border border-red-200 bg-red-50 p-4 font-sans text-xs leading-relaxed text-red-900">
            <p class="font-semibold">Please review the following before saving:</p>
            <ul class="mt-1 list-disc pl-4">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    @php
        $statusStyles = [
            'REQUESTED' => ['bg' => 'bg-amber-50', 'text' => 'text-amber-800', 'border' => 'border-amber-200'],
            'ACCEPTED' => ['bg' => 'bg-sky-50', 'text' => 'text-sky-800', 'border' => 'border-sky-200'],
            'IN_TRANSIT' => ['bg' => 'bg-indigo-50', 'text' => 'text-indigo-800', 'border' => 'border-indigo-200'],
            'REJECTED' => ['bg' => 'bg-red-50', 'text' => 'text-red-800', 'border' => 'border-red-200'],
            'RECEIVED' => ['bg' => 'bg-emerald-50', 'text' => 'text-emerald-800', 'border' => 'border-emerald-200'],
        ];
    @endphp

    <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
        @foreach ([
            ['label' => 'Incoming requests', 'key' => 'requested', 'icon' => 'inbox'],
            ['label' => 'Awaiting dispatch', 'key' => 'accepted', 'icon' => 'package-check'],
            ['label' => 'In transit', 'key' => 'in_transit', 'icon' => 'truck'],
            ['label' => 'Received', 'key' => 'received', 'icon' => 'circle-check'],
        ] as $card)
            <div class="rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-4">
                <p class="flex items-center gap-2 font-sans text-[10px] font-semibold uppercase tracking-[0.12em] text-[var(--text-muted)]"><i data-lucide="{{ $card['icon'] }}" class="size-3.5 text-primary-600"></i>{{ $card['label'] }}</p>
                <p class="mt-2 font-serif text-2xl text-[var(--text-ink)]">{{ (int) ($counts[$card['key']] ?? 0) }}</p>
            </div>
        @endforeach
    </div>

    @if($exceptions->isNotEmpty())
        <section class="mb-6 rounded-lg border border-red-200 bg-red-50/60 p-5" aria-labelledby="handoffExceptionsTitle">
            <h2 id="handoffExceptionsTitle" class="flex items-center gap-2 font-serif text-lg text-red-900"><i data-lucide="triangle-alert" class="size-4"></i>Handoffs awaiting Admin resolution</h2>
            <p class="mt-1 font-sans text-[11px] leading-relaxed text-red-800">No eligible destination partner could be found. These parcels stay safely in your custody and Admin has been notified.</p>
            <ul class="mt-3 space-y-2">
                @foreach($exceptions as $exception)
                    <li class="rounded-md border border-red-200 bg-white px-3 py-2 font-sans text-[11px] text-red-900">
                        <span class="font-mono font-semibold">{{ $exception->sellerOrder?->tracking_number ?? 'Parcel #'.$exception->seller_order_id }}</span>
                        <span class="text-red-700">— {{ $exception->reason }}</span>
                        @if($exception->sellerOrder)
                            <a href="{{ route('logistics.handoffs.index') }}" class="ml-1 underline">{{ $exception->sellerOrder->order?->reference }}</a>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <div class="mb-6 grid grid-cols-1 items-start gap-6 xl:grid-cols-[minmax(300px,0.9fr)_minmax(0,2fr)]">
        <aside class="rounded-lg border border-[var(--border-strong)] border-t-2 border-t-primary-300 bg-[var(--bg-surface)] p-5">
            <h2 class="flex items-center gap-2 font-serif text-lg text-[var(--text-ink)]"><i data-lucide="send" class="size-4 text-primary-700"></i>Request a handoff</h2>
            <p class="mt-2 font-sans text-[11px] leading-relaxed text-[var(--text-muted)]">Only parcels physically held by your provider are listed. Destinations are approved partners ordered by Admin priority; coverage for the buyer's barangay is revalidated server-side.</p>

            @if($eligibleParcels->isEmpty())
                <div class="mt-4 rounded-md border border-dashed border-[var(--border-strong)] bg-[var(--bg-page)]/50 px-4 py-4 font-sans text-[11px] leading-relaxed text-[var(--text-muted)]">
                    No parcel is ready for a cross-provider handoff right now. Parcels with an active handoff or a closed delivery status are excluded.
                </div>
            @else
                <div class="mt-4 space-y-3">
                    @foreach($eligibleParcels as $parcel)
                        @php($links = $eligibleLinks[$parcel->id] ?? collect())
                        <form method="POST" action="{{ route('logistics.handoffs.store') }}" class="rounded-md border border-[var(--border-strong)] bg-[var(--bg-page)]/40 p-3">
                            @csrf
                            <input type="hidden" name="seller_order_id" value="{{ $parcel->id }}">
                            <p class="font-mono text-[11px] font-semibold text-[var(--text-ink)]">{{ $parcel->tracking_number }}</p>
                            <p class="mt-0.5 font-sans text-[10px] text-[var(--text-muted)]">{{ $parcel->order?->reference }} · {{ $parcel->seller?->business_name }} · {{ $parcel->buyerMunicipality() ?? 'Destination' }}</p>

                            <label for="handoff_link_{{ $parcel->id }}" class="mt-3 block font-sans text-[10px] font-semibold uppercase tracking-[0.1em] text-[var(--text-muted)]">Destination partner</label>
                            <select id="handoff_link_{{ $parcel->id }}" name="logistics_provider_link_id" required class="mt-1 h-9 w-full rounded-sm border border-[var(--border-strong)] bg-[var(--bg-surface)] px-2 font-sans text-xs text-[var(--text-ink)]">
                                @foreach($links as $link)
                                    <option value="{{ $link->id }}">
                                        {{ $link->destinationProvider?->business_name }} — priority {{ $link->priority }} — {{ $link->destinationProvider?->province_name }}
                                    </option>
                                @endforeach
                            </select>

                            <button type="submit" class="mt-2 inline-flex min-h-9 w-full items-center justify-center gap-1.5 rounded-sm bg-primary-800 px-3 font-sans text-[11px] font-semibold text-white hover:bg-primary-900">
                                <i data-lucide="repeat-2" class="size-3.5"></i>Request handoff
                            </button>
                        </form>
                    @endforeach
                </div>
            @endif
        </aside>

        <div class="min-w-0 space-y-6">
            <section aria-labelledby="incomingTitle" class="min-w-0">
                <div class="mb-3 flex flex-wrap items-end justify-between gap-2">
                    <div>
                        <h2 id="incomingTitle" class="font-serif text-xl text-[var(--text-ink)]">Incoming handoffs</h2>
                        <p class="mt-1 font-sans text-[10px] text-[var(--text-muted)]">Accept to reserve the parcel for your hub. Only your own receiving scan transfers custody.</p>
                    </div>
                    <p class="font-sans text-[10px] text-[var(--text-muted)]">{{ $incoming->total() }} total</p>
                </div>

                <div class="space-y-3">
                    @forelse($incoming as $handoff)
                        @php($style = $statusStyles[$handoff->status?->value] ?? $statusStyles['REQUESTED'])
                        <article class="rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-4 shadow-sm">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="inline-flex rounded-full border px-2 py-0.5 font-sans text-[9px] font-semibold uppercase tracking-[0.1em] {{ $style['bg'] }} {{ $style['text'] }} {{ $style['border'] }}">{{ $handoff->statusLabel() }}</span>
                                        <span class="font-mono text-[10px] text-[var(--text-muted)]">{{ $handoff->sellerOrder?->tracking_number }}</span>
                                    </div>
                                    <h3 class="mt-2 font-serif text-[18px] leading-snug text-[var(--text-ink)]">From {{ $handoff->originProvider?->business_name ?? 'Origin provider' }}</h3>
                                    <p class="mt-1 font-sans text-[11px] text-[var(--text-muted)]">{{ $handoff->sendingHub?->name ?? 'Sending hub' }} <span aria-hidden="true">→</span> {{ $handoff->receivingHub?->name ?? 'Receiving hub' }}</p>
                                </div>
                                <a href="{{ route('logistics.handoffs.show', $handoff) }}" class="inline-flex min-h-8 items-center rounded-sm border border-[var(--border-strong)] px-3 font-sans text-[10px] font-semibold text-[var(--text-ink)] hover:bg-[var(--bg-page)]">Open</a>
                            </div>

                            <x-handoff-progress :handoff="$handoff" />

                            @if($handoff->isRequested())
                                <div class="mt-3 flex flex-wrap items-center gap-2 border-t border-[var(--border-strong)] pt-3">
                                    <form method="POST" action="{{ route('logistics.handoffs.accept', $handoff) }}">
                                        @csrf
                                        <button type="submit" class="inline-flex min-h-8 items-center gap-1.5 rounded-sm bg-emerald-700 px-3 font-sans text-[10px] font-semibold text-white hover:bg-emerald-800">
                                            <i data-lucide="check" class="size-3"></i>Accept
                                        </button>
                                    </form>
                                    <details class="group">
                                        <summary class="inline-flex min-h-8 cursor-pointer list-none items-center gap-1.5 rounded-sm border border-red-200 px-3 font-sans text-[10px] font-semibold text-red-700 hover:bg-red-50 [&::-webkit-details-marker]:hidden">
                                            <i data-lucide="x" class="size-3"></i>Reject
                                        </summary>
                                        <form method="POST" action="{{ route('logistics.handoffs.reject', $handoff) }}" class="mt-2 rounded-md border border-red-200 bg-red-50/60 p-3">
                                            @csrf
                                            <label for="handoff_reason_{{ $handoff->id }}" class="mb-1 block font-sans text-[10px] font-semibold uppercase tracking-[0.1em] text-red-800">Reason</label>
                                            <textarea id="handoff_reason_{{ $handoff->id }}" name="rejection_reason" required maxlength="500" rows="2" class="w-full rounded-sm border border-red-200 bg-white p-2 font-sans text-xs text-[var(--text-ink)]"></textarea>
                                            <button type="submit" class="mt-2 inline-flex min-h-8 items-center rounded-sm bg-red-700 px-3 font-sans text-[10px] font-semibold text-white hover:bg-red-800">Confirm rejection</button>
                                        </form>
                                    </details>
                                </div>
                            @endif

                            @if($handoff->rejection_reason)
                                <p class="mt-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 font-sans text-[10px] text-red-900"><span class="font-semibold">Rejected:</span> {{ $handoff->rejection_reason }}</p>
                            @endif
                        </article>
                    @empty
                        <div class="rounded-lg border border-dashed border-[var(--border-strong)] bg-[var(--bg-surface)] px-6 py-10 text-center">
                            <i data-lucide="inbox" class="mx-auto mb-3 size-7 text-primary-400"></i>
                            <p class="font-sans text-xs text-[var(--text-muted)]">No incoming handoffs yet.</p>
                        </div>
                    @endforelse
                </div>

                @if($incoming->hasPages())
                    <div class="mt-4">{{ $incoming->links() }}</div>
                @endif
            </section>

            <section aria-labelledby="outgoingTitle" class="min-w-0">
                <div class="mb-3 flex flex-wrap items-end justify-between gap-2">
                    <div>
                        <h2 id="outgoingTitle" class="font-serif text-xl text-[var(--text-ink)]">Outgoing handoffs</h2>
                        <p class="mt-1 font-sans text-[10px] text-[var(--text-muted)]">Acceptance reserves the destination. Custody stays with you until the destination passes its receiving scan.</p>
                    </div>
                    <p class="font-sans text-[10px] text-[var(--text-muted)]">{{ $outgoing->total() }} total</p>
                </div>

                <div class="space-y-3">
                    @forelse($outgoing as $handoff)
                        @php($style = $statusStyles[$handoff->status?->value] ?? $statusStyles['REQUESTED'])
                        <article class="rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-4 shadow-sm">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="inline-flex rounded-full border px-2 py-0.5 font-sans text-[9px] font-semibold uppercase tracking-[0.1em] {{ $style['bg'] }} {{ $style['text'] }} {{ $style['border'] }}">{{ $handoff->statusLabel() }}</span>
                                        <span class="font-mono text-[10px] text-[var(--text-muted)]">{{ $handoff->sellerOrder?->tracking_number }}</span>
                                    </div>
                                    <h3 class="mt-2 font-serif text-[18px] leading-snug text-[var(--text-ink)]">To {{ $handoff->destinationProvider?->business_name ?? 'Destination provider' }}</h3>
                                    <p class="mt-1 font-sans text-[11px] text-[var(--text-muted)]">{{ $handoff->sendingHub?->name ?? 'Sending hub' }} <span aria-hidden="true">→</span> {{ $handoff->receivingHub?->name ?? 'Receiving hub' }}</p>
                                </div>
                                <a href="{{ route('logistics.handoffs.show', $handoff) }}" class="inline-flex min-h-8 items-center rounded-sm border border-[var(--border-strong)] px-3 font-sans text-[10px] font-semibold text-[var(--text-ink)] hover:bg-[var(--bg-page)]">Open</a>
                            </div>
                            <x-handoff-progress :handoff="$handoff" />

                            @if($handoff->isAccepted())
                                <p class="mt-3 flex items-center gap-2 border-t border-[var(--border-strong)] pt-3 font-sans text-[10px] text-sky-800"><i data-lucide="scan-line" class="size-3.5"></i>Open this handoff and scan the parcel to dispatch.</p>
                            @endif
                        </article>
                    @empty
                        <div class="rounded-lg border border-dashed border-[var(--border-strong)] bg-[var(--bg-surface)] px-6 py-10 text-center">
                            <i data-lucide="package-open" class="mx-auto mb-3 size-7 text-primary-400"></i>
                            <p class="font-sans text-xs text-[var(--text-muted)]">No outgoing handoffs yet.</p>
                        </div>
                    @endforelse
                </div>

                @if($outgoing->hasPages())
                    <div class="mt-4">{{ $outgoing->links() }}</div>
                @endif
            </section>
        </div>
    </div>
</div>
@endsection
