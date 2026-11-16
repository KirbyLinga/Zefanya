@extends('Layouts.logistics')

@section('title', 'Provider Handoffs')

@section('logistics-toolbar')
    <span hidden data-logistics-hosted-bell></span>
@endsection

@section('content')
@php
    $primaryButton = 'inline-flex min-h-11 items-center justify-center gap-2 rounded-lg px-4 font-sans text-[12.5px] font-semibold no-underline transition-colors bg-[rgb(107_39_55)] text-white hover:bg-[rgb(84_29_42)]';
    $quietButton = 'inline-flex min-h-11 items-center justify-center gap-2 rounded-lg px-4 font-sans text-[12.5px] font-semibold no-underline transition-colors border border-[var(--border-strong)] bg-[var(--bg-surface)] text-[var(--text-ink)] hover:bg-[var(--bg-raised)]';
@endphp
<x-logistics.tabs group="transfers" />
<div data-handoffs-page class="min-w-0 [&_:is(a,button,summary,input,select,textarea):focus-visible]:outline-2 [&_:is(a,button,summary,input,select,textarea):focus-visible]:outline-offset-4 [&_:is(a,button,summary,input,select,textarea):focus-visible]:outline-[var(--link)] [overflow-wrap:anywhere]">
    <header class="mb-6 flex flex-wrap items-center justify-between gap-4">
        <div>
            <p class="mb-2 font-sans text-[11px] font-semibold uppercase tracking-[0.12em] text-[var(--text-muted-2)]">Logistics network</p>
            <h1 class="font-serif text-[26px] leading-tight font-semibold text-[var(--text-ink)] sm:text-[30px]">Provider Handoffs</h1>
            <p class="mt-2 max-w-2xl font-sans text-[14px] leading-relaxed text-[var(--text-muted-2)]">Manage parcel transfers between your company and partner providers.</p>
            <p class="mt-2 font-sans text-[12px] text-[var(--text-muted-2)]">{{ $provider->business_name }} <span aria-hidden="true">/</span> {{ $provider->province_name ?: 'Service province' }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <a href="{{ route('logistics.partnerships.index') }}" class="{{ $quietButton }}"><i data-lucide="handshake" class="size-4" aria-hidden="true"></i>Provider Partnerships</a>
            <x-logistics.notification-bell />
        </div>
    </header>

    <x-logistics-flash :messages="['success', 'warning', 'error']" />
    @if($errors->any())
        <div role="alert" class="mb-5 rounded-md border border-[var(--border-error-soft)] bg-[var(--bg-error-soft)] p-4 font-sans text-[13px] leading-relaxed text-[var(--status-danger)]">
            <p class="font-semibold">Please review the following before saving:</p>
            <ul class="mt-1 list-disc pl-4">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    @php
        $statusStyles = [
            'REQUESTED' => ['bg' => 'bg-[var(--pill-warning-bg)]', 'text' => 'text-[var(--status-warning)]', 'border' => 'border-transparent'],
            'ACCEPTED' => ['bg' => 'bg-[var(--pill-primary-bg)]', 'text' => 'text-[var(--link)]', 'border' => 'border-transparent'],
            'IN_TRANSIT' => ['bg' => 'bg-[var(--pill-primary-bg)]', 'text' => 'text-[var(--link)]', 'border' => 'border-transparent'],
            'REJECTED' => ['bg' => 'bg-[var(--pill-danger-bg)]', 'text' => 'text-[var(--status-danger)]', 'border' => 'border-transparent'],
            'RECEIVED' => ['bg' => 'bg-[var(--pill-success-bg)]', 'text' => 'text-[var(--status-success)]', 'border' => 'border-transparent'],
            'CANCELLED' => ['bg' => 'bg-[var(--pill-danger-bg)]', 'text' => 'text-[var(--status-danger)]', 'border' => 'border-transparent'],
        ];
    @endphp

    <div class="mb-6 grid grid-cols-2 overflow-hidden rounded-xl border border-[var(--border-strong)] bg-[var(--bg-surface)] shadow-sm xl:grid-cols-4">
        @foreach ([
            ['label' => 'Incoming requests', 'key' => 'requested', 'scope' => 'Incoming only', 'icon' => 'inbox'],
            ['label' => 'Awaiting dispatch', 'key' => 'accepted', 'scope' => 'Outgoing only', 'icon' => 'package-check'],
            ['label' => 'In transit', 'key' => 'in_transit', 'scope' => 'Incoming and outgoing', 'icon' => 'truck'],
            ['label' => 'Received', 'key' => 'received', 'scope' => 'Incoming and outgoing', 'icon' => 'circle-check'],
        ] as $card)
            @php
                $active = $statusFilter === $card['key'];
                $anchor = ['requested' => '#incomingTitle', 'accepted' => '#outgoingTitle'][$card['key']] ?? '#handoffLists';
            @endphp
            <a href="{{ $active ? route('logistics.handoffs.index') : route('logistics.handoffs.index', ['status' => $card['key']]).$anchor }}"
               @if ($active) aria-current="true" @endif
               aria-label="{{ $active ? 'Show all handoffs' : 'Show '.strtolower($card['label']).' ('.(int) ($counts[$card['key']] ?? 0).')' }}"
               class="group block border-b border-r border-[var(--border-strong)] p-4 no-underline transition-colors hover:bg-[var(--bg-raised)] focus-visible:outline-2 focus-visible:outline-offset-[-4px] focus-visible:outline-primary-700 sm:p-5 {{ $active ? 'bg-[var(--bg-raised)] shadow-[inset_0_-3px_0_var(--link)]' : '' }}">
                <p class="flex items-center gap-2 font-sans text-[12px] font-semibold uppercase tracking-[0.12em] text-[var(--text-muted-2)]"><i data-lucide="{{ $card['icon'] }}" class="size-3.5 text-[var(--link)]"></i>{{ $card['label'] }}</p>
                <p class="mt-2 font-serif text-2xl text-[var(--text-ink)]">{{ (int) ($counts[$card['key']] ?? 0) }}</p>
                <p class="mt-1 flex items-center justify-between gap-2 font-sans text-[12px] text-[var(--text-muted-2)]">{{ $card['scope'] }}<span class="font-semibold text-[var(--link)] {{ $active ? '' : 'opacity-0 transition-opacity group-hover:opacity-100 group-focus-visible:opacity-100' }}">{{ $active ? 'Clear filter' : 'View' }}</span></p>
            </a>
        @endforeach
    </div>

    @if($exceptions->isNotEmpty())
        <section class="mb-6 rounded-lg border border-[var(--border-error-soft)] bg-[var(--bg-error-soft)] p-5" aria-labelledby="handoffExceptionsTitle">
            <h2 id="handoffExceptionsTitle" class="flex items-center gap-2 font-sans text-[14px] font-semibold text-[var(--text-ink)]"><i data-lucide="triangle-alert" class="size-4"></i>Handoffs awaiting Admin resolution</h2>
            <p class="mt-1 font-sans text-[12px] leading-relaxed text-[var(--status-danger)]">No eligible destination partner could be found. These parcels stay safely in your custody and Admin has been notified.</p>
            <ul class="mt-3 space-y-2">
                @foreach($exceptions as $exception)
                    <li class="rounded-md border border-[var(--border-error-soft)] bg-[var(--bg-surface)] px-3 py-2 font-sans text-[12px] text-[var(--status-danger)]">
                        <span class="font-mono font-semibold">{{ $exception->sellerOrder?->tracking_number ?? 'Parcel #'.$exception->seller_order_id }}</span>
                        <span class="text-[var(--status-danger)]">— {{ $exception->reason }}</span>
                        @if($exception->sellerOrder)
                            <a href="{{ route('logistics.handoffs.index') }}" class="ml-1 underline">{{ $exception->sellerOrder->order?->reference }}</a>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <div class="mb-6 grid grid-cols-1 items-start gap-6 xl:grid-cols-[minmax(300px,0.9fr)_minmax(0,2fr)]">
        <aside class="min-w-0 rounded-xl border border-[var(--border-strong)] bg-[var(--bg-surface)] p-5 shadow-sm sm:p-[22px]">
            <h2 class="flex items-center gap-2 font-sans text-[14px] font-semibold text-[var(--text-ink)]"><i data-lucide="send" class="size-4 text-[var(--link)]"></i>Request a handoff</h2>
            <p class="mt-2 font-sans text-[12px] leading-relaxed text-[var(--text-muted-2)]">Choose a parcel in your custody and an eligible destination partner. Acceptance reserves the destination; custody transfers only after the verified receiving scan.</p>

            @if($eligibleParcels->isEmpty())
                <div class="mt-4 rounded-md border border-dashed border-[var(--border-strong)] bg-[var(--bg-page)]/50 px-4 py-4 font-sans text-[12px] leading-relaxed text-[var(--text-muted-2)]">
                    No parcel is ready for a cross-provider handoff right now. Parcels with an active handoff or a closed delivery status are excluded.
                </div>
            @else
                <div class="mt-4 space-y-3">
                    @foreach($eligibleParcels as $parcel)
                        @php($links = $eligibleLinks[$parcel->id] ?? collect())
                        <form method="POST" action="{{ route('logistics.handoffs.store') }}" class="rounded-md border border-[var(--border-strong)] bg-[var(--bg-page)]/40 p-3">
                            @csrf
                            <input type="hidden" name="seller_order_id" value="{{ $parcel->id }}">
                            <p class="font-mono text-[12px] font-semibold text-[var(--text-ink)]">{{ $parcel->tracking_number }}</p>
                            <p class="mt-0.5 font-sans text-[12px] text-[var(--text-muted-2)]">{{ $parcel->order?->reference }} · {{ $parcel->seller?->business_name }} · {{ $parcel->buyerMunicipality() ?? 'Destination' }}</p>

                            <label for="handoff_link_{{ $parcel->id }}" class="mt-3 block font-sans text-[12px] font-semibold uppercase tracking-[0.1em] text-[var(--text-muted-2)]">Destination partner</label>
                            <select id="handoff_link_{{ $parcel->id }}" name="logistics_provider_link_id" required class="mt-1 min-h-11 w-full min-w-0 rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] px-2 font-sans text-xs text-[var(--text-ink)]">
                                @foreach($links as $link)
                                    <option value="{{ $link->id }}">
                                        {{ $link->destinationProvider?->business_name }} — priority {{ $link->priority }} — {{ $link->destinationProvider?->province_name }}
                                    </option>
                                @endforeach
                            </select>

                            <button type="submit" class="{{ $primaryButton }} mt-3 w-full">
                                <i data-lucide="repeat-2" class="size-3.5"></i>Request handoff
                            </button>
                        </form>
                    @endforeach
                </div>
            @endif
        </aside>

        <div id="handoffLists" class="min-w-0 scroll-mt-6 space-y-6">
            @if ($statusFilter)
                <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-[var(--border-strong)] bg-[var(--bg-raised)] px-4 py-3 font-sans text-[13px] text-[var(--text-ink)]">
                    <span>Showing <strong>{{ ['requested' => 'incoming requests', 'accepted' => 'awaiting dispatch', 'in_transit' => 'in transit', 'received' => 'received'][$statusFilter] }}</strong> only.</span>
                    <a href="{{ route('logistics.handoffs.index') }}" class="font-semibold text-[var(--link)] hover:underline">Show all handoffs</a>
                </div>
            @endif
            @if ($showIncoming)
            <section aria-labelledby="incomingTitle" class="min-w-0 overflow-hidden rounded-xl border border-[var(--border-strong)] bg-[var(--bg-surface)] shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-3 border-b border-[var(--border-strong)] px-4 py-[18px] sm:px-[22px]">
                    <div>
                        <h2 id="incomingTitle" class="scroll-mt-24 font-sans text-[14px] font-semibold text-[var(--text-ink)]">Incoming handoffs</h2>
                        <p class="mt-1 font-sans text-[12px] text-[var(--text-muted-2)]">Accept to reserve the parcel for your hub. Only your own receiving scan transfers custody.</p>
                    </div>
                    <p class="font-sans text-[12px] text-[var(--text-muted-2)]">{{ $incoming->total() }} total</p>
                </div>

                <div class="space-y-3 p-4 sm:p-[22px]">
                    @forelse($incoming as $handoff)
                        @php($style = $statusStyles[$handoff->status?->value] ?? $statusStyles['REQUESTED'])
                        <article class="rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-4 shadow-sm">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="inline-flex rounded-md border px-2 py-0.5 font-sans text-[12px] font-semibold uppercase tracking-[0.1em] {{ $style['bg'] }} {{ $style['text'] }} {{ $style['border'] }}">{{ $handoff->statusLabel() }}</span>
                                        <span class="font-mono text-[12px] text-[var(--text-muted-2)]">{{ $handoff->sellerOrder?->tracking_number }}</span>
                                    </div>
                                    <h3 class="mt-2 font-sans text-[14px] font-semibold leading-snug text-[var(--text-ink)]">From {{ $handoff->originProvider?->business_name ?? 'Origin provider' }}</h3>
                                    <p class="mt-1 font-sans text-[12px] text-[var(--text-muted-2)]">{{ $handoff->sendingHub?->name ?? 'Sending hub' }} <span aria-hidden="true">→</span> {{ $handoff->receivingHub?->name ?? 'Receiving hub' }}</p>
                                </div>
                                <a href="{{ route('logistics.handoffs.show', $handoff) }}" class="{{ $quietButton }}">Open</a>
                            </div>

                            <x-handoff-progress :handoff="$handoff" />

                            @if($handoff->isRequested())
                                <div class="mt-3 flex flex-wrap items-center gap-2 border-t border-[var(--border-strong)] pt-3">
                                    <form method="POST" action="{{ route('logistics.handoffs.accept', $handoff) }}">
                                        @csrf
                                        <button type="submit" class="{{ $primaryButton }}">
                                            <i data-lucide="check" class="size-3"></i>Accept
                                        </button>
                                    </form>
                                    <details class="group min-w-0 max-w-full">
                                        <summary class="inline-flex min-h-11 cursor-pointer list-none items-center gap-2 rounded-lg border border-[var(--pill-danger-border)] px-4 font-sans text-[12.5px] font-semibold text-[var(--status-danger)] hover:bg-[var(--pill-danger-bg)] [&::-webkit-details-marker]:hidden">
                                            <i data-lucide="x" class="size-3"></i>Reject
                                        </summary>
                                        <form method="POST" action="{{ route('logistics.handoffs.reject', $handoff) }}" class="mt-2 rounded-md border border-[var(--border-error-soft)] bg-[var(--bg-error-soft)] p-3">
                                            @csrf
                                            <label for="handoff_reason_{{ $handoff->id }}" class="mb-1 block font-sans text-[12px] font-semibold uppercase tracking-[0.1em] text-[var(--status-danger)]">Reason</label>
                                            <textarea id="handoff_reason_{{ $handoff->id }}" name="rejection_reason" required maxlength="500" rows="2" class="min-h-20 w-full rounded-lg border border-[var(--pill-danger-border)] bg-[var(--bg-surface)] p-3 font-sans text-xs text-[var(--text-ink)]"></textarea>
                                            <button type="submit" class="mt-2 inline-flex min-h-11 items-center rounded-lg bg-[var(--status-danger)] px-4 font-sans text-[12.5px] font-semibold text-[var(--bg-surface)]">Confirm rejection</button>
                                        </form>
                                    </details>
                                </div>
                            @endif

                            @if($handoff->rejection_reason)
                                <p class="mt-3 rounded-md border border-[var(--border-error-soft)] bg-[var(--bg-error-soft)] px-3 py-2 font-sans text-[12px] text-[var(--status-danger)]"><span class="font-semibold">Rejected:</span> {{ $handoff->rejection_reason }}</p>
                            @endif
                        </article>
                    @empty
                        <div class="rounded-lg border border-[var(--border-strong)] bg-[var(--bg-raised)] px-[22px] py-[50px] text-center">
                            <span class="mx-auto mb-[18px] flex h-[52px] w-[52px] items-center justify-center rounded-xl border border-[var(--pill-success-border)] bg-[var(--pill-success-bg)] text-[var(--status-success)]"><i data-lucide="inbox" class="size-[25px]" aria-hidden="true"></i></span>
                            <h3 class="font-sans text-[16px] font-semibold text-[var(--text-ink)]">{{ $incoming->total() > 0 ? 'No incoming handoffs on this page.' : ($statusFilter ? 'No incoming handoffs with this status.' : 'No incoming handoffs yet.') }}</h3>
                            <p class="mx-auto mt-2 max-w-[470px] font-sans text-[13px] leading-[1.75] text-[var(--text-muted-2)]">{{ $incoming->total() > 0 ? 'There are handoffs on an earlier page of this queue.' : 'Requests from partner providers will appear here for review.' }}</p>
                            @if ($incoming->total() > 0)
                                <a href="{{ $incoming->url(1) }}" class="{{ $quietButton }} mt-5">Back to first page</a>
                            @endif
                        </div>
                    @endforelse
                </div>

                @if($incoming->hasPages())
                    <div class="border-t border-[var(--border-strong)] p-4">{{ $incoming->links() }}</div>
                @endif
            </section>
            @endif

            @if ($showOutgoing)
            <section aria-labelledby="outgoingTitle" class="min-w-0 overflow-hidden rounded-xl border border-[var(--border-strong)] bg-[var(--bg-surface)] shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-3 border-b border-[var(--border-strong)] px-4 py-[18px] sm:px-[22px]">
                    <div>
                        <h2 id="outgoingTitle" class="scroll-mt-24 font-sans text-[14px] font-semibold text-[var(--text-ink)]">Outgoing handoffs</h2>
                        <p class="mt-1 font-sans text-[12px] text-[var(--text-muted-2)]">Acceptance reserves the destination. Custody stays with you until the destination passes its receiving scan.</p>
                    </div>
                    <p class="font-sans text-[12px] text-[var(--text-muted-2)]">{{ $outgoing->total() }} total</p>
                </div>

                <div class="space-y-3 p-4 sm:p-[22px]">
                    @forelse($outgoing as $handoff)
                        @php($style = $statusStyles[$handoff->status?->value] ?? $statusStyles['REQUESTED'])
                        <article class="rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-4 shadow-sm">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="inline-flex rounded-md border px-2 py-0.5 font-sans text-[12px] font-semibold uppercase tracking-[0.1em] {{ $style['bg'] }} {{ $style['text'] }} {{ $style['border'] }}">{{ $handoff->statusLabel() }}</span>
                                        <span class="font-mono text-[12px] text-[var(--text-muted-2)]">{{ $handoff->sellerOrder?->tracking_number }}</span>
                                    </div>
                                    <h3 class="mt-2 font-sans text-[14px] font-semibold leading-snug text-[var(--text-ink)]">To {{ $handoff->destinationProvider?->business_name ?? 'Destination provider' }}</h3>
                                    <p class="mt-1 font-sans text-[12px] text-[var(--text-muted-2)]">{{ $handoff->sendingHub?->name ?? 'Sending hub' }} <span aria-hidden="true">→</span> {{ $handoff->receivingHub?->name ?? 'Receiving hub' }}</p>
                                </div>
                                <a href="{{ route('logistics.handoffs.show', $handoff) }}" class="{{ $quietButton }}">Open</a>
                            </div>
                            <x-handoff-progress :handoff="$handoff" />

                            @if($handoff->isAccepted())
                                <p class="mt-3 flex items-center gap-2 border-t border-[var(--border-strong)] pt-3 font-sans text-[12px] text-[var(--link)]"><i data-lucide="scan-line" class="size-3.5"></i>Open this handoff and scan the parcel to dispatch.</p>
                            @endif
                        </article>
                    @empty
                        <div class="rounded-lg border border-[var(--border-strong)] bg-[var(--bg-raised)] px-[22px] py-[50px] text-center">
                            <span class="mx-auto mb-[18px] flex h-[52px] w-[52px] items-center justify-center rounded-xl border border-[var(--pill-success-border)] bg-[var(--pill-success-bg)] text-[var(--status-success)]"><i data-lucide="package-open" class="size-[25px]" aria-hidden="true"></i></span>
                            <h3 class="font-sans text-[16px] font-semibold text-[var(--text-ink)]">{{ $outgoing->total() > 0 ? 'No outgoing handoffs on this page.' : ($statusFilter ? 'No outgoing handoffs with this status.' : 'No outgoing handoffs yet.') }}</h3>
                            <p class="mx-auto mt-2 max-w-[470px] font-sans text-[13px] leading-[1.75] text-[var(--text-muted-2)]">{{ $outgoing->total() > 0 ? 'There are handoffs on an earlier page of this queue.' : 'Handoffs you request will appear here as they move to the destination provider.' }}</p>
                            @if ($outgoing->total() > 0)
                                <a href="{{ $outgoing->url(1) }}" class="{{ $quietButton }} mt-5">Back to first page</a>
                            @endif
                        </div>
                    @endforelse
                </div>

                @if($outgoing->hasPages())
                    <div class="border-t border-[var(--border-strong)] p-4">{{ $outgoing->links() }}</div>
                @endif
            </section>
            @endif
        </div>
    </div>
</div>
@endsection
