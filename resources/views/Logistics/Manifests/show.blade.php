@extends('Layouts.logistics')

@section('title', 'Manifest #'.$manifest->id)

@section('content')
@if ($manifest->direction === 'return')<p class="mb-4 rounded-md border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900">Reverse physical return transport. Normal outgoing dispatch is blocked.</p>@endif
@use('App\Enums\ManifestStatus')

@php
    $statusStyles = [
        'DRAFT' => 'border-[var(--border-strong)] bg-[var(--bg-page)] text-[var(--text-muted)]',
        'DISPATCHED' => 'border-amber-200 bg-amber-50 text-amber-800',
        'RECEIVED' => 'border-emerald-200 bg-emerald-50 text-emerald-800',
        'CANCELLED' => 'border-rose-200 bg-rose-50 text-rose-800',
    ];
    $receivedPercent = $counts['total'] > 0 ? (int) round(($counts['received'] / $counts['total']) * 100) : 0;
    $actorName = $manifest->provider?->business_name ?? $provider->business_name;
    $isDraft = $manifest->status === ManifestStatus::Draft;
    $isDispatched = $manifest->status === ManifestStatus::Dispatched;
    $isReceived = $manifest->status === ManifestStatus::Received;
@endphp

<div class="mb-6 flex flex-wrap items-start justify-between gap-4 border-b border-[var(--border-strong)] pb-6">
    <div class="min-w-0">
        <a href="{{ route('logistics.manifests.index') }}" class="mb-2 inline-flex items-center gap-1 font-sans text-[10px] font-semibold uppercase tracking-[0.14em] text-primary-700 hover:underline">
            <i data-lucide="arrow-left" class="size-3"></i>Manifests
        </a>
        <div class="flex flex-wrap items-center gap-3">
            <h1 class="font-serif text-[28px] leading-tight text-[var(--text-ink)] sm:text-[32px]">Manifest #{{ $manifest->id }}</h1>
            <span class="inline-flex rounded-full border px-2.5 py-1 font-sans text-[10px] font-semibold uppercase tracking-[0.5px] {{ $statusStyles[$manifest->status->value] ?? $statusStyles['DRAFT'] }}">{{ $manifest->status->label() }}</span>
        </div>
        <p class="mt-2 font-sans text-xs text-[var(--text-muted)]">{{ $manifest->created_at?->format('M j, Y g:i A') }} · {{ $actorName }}</p>
    </div>

    <div class="flex flex-wrap gap-2">
        @if ($isDispatched)
            <a href="{{ route('logistics.manifests.receive.index', $manifest) }}" class="inline-flex min-h-10 items-center justify-center gap-2 rounded-sm bg-primary-700 px-4 font-sans text-[11px] font-semibold uppercase tracking-[0.5px] text-white hover:bg-primary-800">
                <i data-lucide="scan-line" class="size-3.5"></i>Receive parcels
            </a>
        @elseif ($isDraft)
            <span class="inline-flex min-h-10 items-center rounded-sm border border-[var(--border-strong)] px-3 font-sans text-[11px] text-[var(--text-muted)]">Draft — add parcels below</span>
        @endif
    </div>
</div>

<x-logistics-flash :messages="['success', 'warning', 'error']" />

@if ($errors->any())
    <div class="mb-5 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 font-sans text-[12px] text-rose-800">
        <p class="font-semibold">Please review the following:</p>
        <ul class="mt-1 list-disc pl-4">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    </div>
@endif

<section class="mb-6 overflow-hidden rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] shadow-sm">
    <div class="grid grid-cols-1 divide-y divide-[var(--border-strong)] md:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)_minmax(180px,0.7fr)] md:divide-x md:divide-y-0">
        <div class="p-4">
            <p class="font-sans text-[10px] font-semibold uppercase tracking-[0.14em] text-[var(--text-muted)]">Origin</p>
            <p class="mt-1 break-words font-serif text-lg leading-snug text-[var(--text-ink)]">{{ $manifest->originHub?->name ?? '—' }}</p>
            <p class="mt-1 font-sans text-[10px] text-[var(--text-muted)]">{{ $manifest->originHub?->municipality }}</p>
        </div>
        <div class="flex items-center justify-center p-4 text-primary-600">
            <i data-lucide="move-right" class="size-5"></i>
        </div>
        <div class="p-4">
            <p class="font-sans text-[10px] font-semibold uppercase tracking-[0.14em] text-[var(--text-muted)]">Destination</p>
            <p class="mt-1 break-words font-serif text-lg leading-snug text-[var(--text-ink)]">{{ $manifest->destinationHub?->name ?? '—' }}</p>
            <p class="mt-1 font-sans text-[10px] text-[var(--text-muted)]">{{ $manifest->destinationHub?->municipality }}</p>
        </div>
        <div class="p-4">
            <p class="font-sans text-[10px] font-semibold uppercase tracking-[0.14em] text-[var(--text-muted)]">Receipt progress</p>
            <p class="mt-1 font-sans text-lg font-semibold text-[var(--text-ink)]">{{ $counts['received'] }} <span class="font-normal text-[var(--text-muted)]">/ {{ $counts['total'] }}</span></p>
            <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-[var(--bg-page)]" role="progressbar" aria-valuenow="{{ $receivedPercent }}" aria-valuemin="0" aria-valuemax="100">
                <div class="h-full rounded-full bg-emerald-600" style="width: {{ $receivedPercent }}%"></div>
            </div>
            <p class="mt-1 font-sans text-[10px] text-[var(--text-muted)]">{{ $counts['pending'] }} pending</p>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-3 border-t border-[var(--border-strong)] bg-[var(--bg-page)]/40 px-4 py-3 font-sans text-[11px] text-[var(--text-muted)] sm:grid-cols-2">
        <p><span class="font-semibold text-[var(--text-ink)]">Dispatch:</span>
            @if ($manifest->dispatched_at)
                {{ $manifest->dispatched_at->format('M j, Y g:i A') }} by {{ $actorName }}
            @else
                Not dispatched
            @endif
        </p>
        <p><span class="font-semibold text-[var(--text-ink)]">Receiving:</span>
            @if ($manifest->received_at)
                {{ $manifest->received_at->format('M j, Y g:i A') }} by {{ $actorName }}
            @elseif ($isDispatched)
                In transit — origin hub remains accountable
            @else
                Waiting for dispatch
            @endif
        </p>
    </div>
</section>

@if ($isDraft)
    <section class="mb-6 rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] shadow-sm" aria-labelledby="dispatch-title" data-manifest-dispatch data-verify-url="{{ route('logistics.manifests.scan.verify', $manifest) }}" data-item-count="{{ $items->count() }}">
        <div class="border-b border-[var(--border-strong)] px-4 py-4">
            <h2 id="dispatch-title" class="font-serif text-xl text-[var(--text-ink)]">Dispatch this manifest</h2>
            <p class="mt-1 max-w-2xl font-sans text-[11px] leading-relaxed text-[var(--text-muted)]">Every parcel must be scanned at {{ $manifest->originHub?->name ?? 'the origin hub' }}. Verification does not change delivery status; it only proves the parcel is on this manifest.</p>
        </div>

        @if ($items->isEmpty())
            <div class="px-4 py-8 text-center font-sans text-xs text-[var(--text-muted)]">Add parcels before dispatching.</div>
        @else
            <div class="space-y-4 px-4 py-4">
                <div class="rounded-md border border-[var(--border-strong)] bg-[var(--bg-page)] p-3">
                    <div class="mb-3 flex flex-wrap gap-2">
                        <button type="button" data-manifest-scan-start class="inline-flex h-9 items-center justify-center rounded-sm bg-primary-700 px-4 font-sans text-[11.5px] font-semibold uppercase tracking-[0.5px] text-white hover:bg-primary-800">Start camera</button>
                        <button type="button" data-manifest-scan-stop class="hidden items-center justify-center rounded-sm border border-[var(--border-strong)] px-4 font-sans text-[11.5px] font-semibold uppercase tracking-[0.5px] text-[var(--text-ink)]">Stop camera</button>
                    </div>
                    <div data-manifest-scan-panel class="mb-3 hidden overflow-hidden rounded-md border border-[var(--border-strong)] bg-black">
                        <video data-manifest-scan-video playsinline muted class="mx-auto max-h-[360px] w-full object-cover"></video>
                    </div>
                    <label for="manifest-dispatch-tracking" class="mb-1 block font-sans text-[11px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)]">Tracking number</label>
                    <div class="flex flex-col gap-2 sm:flex-row">
                        <input id="manifest-dispatch-tracking" type="text" data-manifest-scan-input maxlength="64" autocomplete="off" spellcheck="false" placeholder="TRK-YYYYMMDD-XXXXXX" class="w-full rounded-sm border border-[var(--border-strong)] bg-[var(--bg-surface)] px-3 py-2 font-mono text-[13px] text-[var(--text-ink)]">
                        <button type="button" data-manifest-scan-verify class="inline-flex h-10 shrink-0 items-center justify-center rounded-sm border border-primary-700 px-4 font-sans text-[11.5px] font-semibold uppercase tracking-[0.5px] text-primary-800 hover:bg-primary-50">Verify parcel</button>
                    </div>
                    <p data-manifest-scan-status class="mt-2 font-sans text-[11px] text-[var(--text-muted)]" role="status"></p>
                    <p data-manifest-scan-error class="mt-1 hidden font-sans text-[11px] text-rose-700" role="alert"></p>
                </div>

                <form method="POST" action="{{ route('logistics.manifests.dispatch', $manifest) }}" data-manifest-dispatch-form>
                    @csrf
                    @foreach ($items as $item)
                        <input type="hidden" name="tokens[{{ $item->id }}]" value="" data-manifest-token="{{ $item->id }}">
                    @endforeach

                    <div class="overflow-hidden rounded-md border border-[var(--border-strong)]">
                        <table class="w-full border-collapse text-left">
                            <thead>
                                <tr class="border-b border-[var(--border-strong)] bg-[var(--bg-page)]/70">
                                    <th scope="col" class="px-3 py-2 font-sans text-[10px] font-semibold uppercase tracking-[0.14em] text-[var(--text-muted)]">Parcel</th>
                                    <th scope="col" class="px-3 py-2 font-sans text-[10px] font-semibold uppercase tracking-[0.14em] text-[var(--text-muted)]">Destination</th>
                                    <th scope="col" class="px-3 py-2 text-right font-sans text-[10px] font-semibold uppercase tracking-[0.14em] text-[var(--text-muted)]">Scan</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[var(--border-strong)]">
                                @foreach ($items as $item)
                                    <tr data-manifest-dispatch-item="{{ $item->id }}">
                                        <td class="px-3 py-3">
                                            <p class="font-mono text-[11px] font-semibold text-[var(--text-ink)]">{{ $item->tracking_number }}</p>
                                            <p class="mt-0.5 font-sans text-[10px] text-[var(--text-muted)]">{{ $item->sellerOrder?->order?->reference ?? 'Order' }} · {{ $item->sellerOrder?->seller?->business_name ?? 'Seller' }}</p>
                                        </td>
                                        <td class="px-3 py-3 font-sans text-[11px] text-[var(--text-muted)]">{{ $item->sellerOrder?->buyerMunicipality() ?? 'Destination' }}</td>
                                        <td class="px-3 py-3 text-right">
                                            <span data-manifest-dispatch-state class="inline-flex rounded-full border border-[var(--border-strong)] bg-[var(--bg-page)] px-2 py-1 font-sans text-[10px] font-semibold text-[var(--text-muted)]">Pending scan</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
                        <p class="font-sans text-[11px] text-[var(--text-muted)]"><span data-manifest-verified-count>0</span> of {{ $items->count() }} parcels verified</p>
                        <button type="submit" data-manifest-dispatch-submit disabled class="inline-flex min-h-10 items-center justify-center gap-2 rounded-sm bg-primary-700 px-4 font-sans text-[11px] font-semibold uppercase tracking-[0.5px] text-white opacity-50 hover:bg-primary-800 disabled:cursor-not-allowed">
                            <i data-lucide="truck" class="size-3.5"></i>Dispatch Manifest
                        </button>
                    </div>
                </form>
            </div>
        @endif
    </section>

    <section class="mb-6 rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] shadow-sm" aria-labelledby="add-parcels-title">
        <div class="border-b border-[var(--border-strong)] px-4 py-4">
            <h2 id="add-parcels-title" class="font-serif text-xl text-[var(--text-ink)]">Add parcels</h2>
            <p class="mt-1 font-sans text-[11px] text-[var(--text-muted)]">Only parcels currently at {{ $manifest->originHub?->name ?? 'the origin hub' }} and not on another active manifest can be added.</p>
        </div>

        @if ($eligibleParcels->isEmpty())
            <div class="px-4 py-8 text-center font-sans text-xs text-[var(--text-muted)]">No eligible parcels are at this origin hub right now.</div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full border-collapse text-left">
                    <thead>
                        <tr class="border-b border-[var(--border-strong)] bg-[var(--bg-page)]/60">
                            <th scope="col" class="px-4 py-2 font-sans text-[10px] font-semibold uppercase tracking-[0.14em] text-[var(--text-muted)]">Parcel</th>
                            <th scope="col" class="px-4 py-2 font-sans text-[10px] font-semibold uppercase tracking-[0.14em] text-[var(--text-muted)]">Order / buyer</th>
                            <th scope="col" class="px-4 py-2 text-right font-sans text-[10px] font-semibold uppercase tracking-[0.14em] text-[var(--text-muted)]">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--border-strong)]">
                        @foreach ($eligibleParcels as $parcel)
                            <tr>
                                <td class="px-4 py-3">
                                    <p class="font-mono text-[11px] font-semibold text-[var(--text-ink)]">{{ $parcel->tracking_number }}</p>
                                    <p class="mt-0.5 font-sans text-[10px] text-[var(--text-muted)]">{{ $parcel->status->label() }} · {{ $parcel->originStation?->name }}</p>
                                </td>
                                <td class="px-4 py-3">
                                    <p class="font-sans text-[11px] text-[var(--text-ink)]">{{ $parcel->order?->reference }}</p>
                                    <p class="mt-0.5 font-sans text-[10px] text-[var(--text-muted)]">{{ $parcel->order?->buyer?->fullName() }} · {{ $parcel->seller?->business_name }}</p>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <form method="POST" action="{{ route('logistics.manifests.items.store', $manifest) }}" class="inline">
                                        @csrf
                                        <input type="hidden" name="seller_order_id" value="{{ $parcel->id }}">
                                        <button type="submit" class="inline-flex min-h-8 items-center rounded-sm border border-primary-700 px-3 font-sans text-[11px] font-semibold text-primary-800 hover:bg-primary-50">Add</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endif

<section class="overflow-hidden rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] shadow-sm" aria-labelledby="movement-history-title">
    <div class="flex flex-wrap items-end justify-between gap-3 border-b border-[var(--border-strong)] px-4 py-4">
        <div>
            <h2 id="movement-history-title" class="font-serif text-xl text-[var(--text-ink)]">Parcel movement history</h2>
            <p class="mt-1 font-sans text-[11px] text-[var(--text-muted)]">Manifest movement is separate from the parcel's canonical delivery status.</p>
        </div>
        <p class="font-sans text-[10px] text-[var(--text-muted)]">{{ $counts['received'] }} received · {{ $counts['pending'] }} pending · {{ $counts['total'] }} total</p>
    </div>

    @if ($items->isEmpty())
        <div class="px-4 py-10 text-center">
            <i data-lucide="package-open" class="mx-auto mb-3 size-7 text-primary-400"></i>
            <p class="font-sans text-xs text-[var(--text-muted)]">No parcels on this manifest.</p>
        </div>
    @else
        <div class="overflow-x-auto">
            <table class="w-full border-collapse text-left">
                <thead>
                    <tr class="border-b border-[var(--border-strong)] bg-[var(--bg-page)]/60">
                        <th scope="col" class="px-4 py-3 font-sans text-[10px] font-semibold uppercase tracking-[0.14em] text-[var(--text-muted)]">Parcel</th>
                        <th scope="col" class="px-4 py-3 font-sans text-[10px] font-semibold uppercase tracking-[0.14em] text-[var(--text-muted)]">Custody</th>
                        <th scope="col" class="px-4 py-3 font-sans text-[10px] font-semibold uppercase tracking-[0.14em] text-[var(--text-muted)]">Dispatch</th>
                        <th scope="col" class="px-4 py-3 font-sans text-[10px] font-semibold uppercase tracking-[0.14em] text-[var(--text-muted)]">Receiving</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--border-strong)]">
                    @foreach ($items as $item)
                        <tr class="align-top">
                            <td class="px-4 py-4">
                                <p class="font-mono text-[11px] font-semibold text-[var(--text-ink)]">{{ $item->tracking_number }}</p>
                                <p class="mt-1 font-sans text-[10px] text-[var(--text-muted)]">{{ $item->sellerOrder?->order?->reference }} · {{ $item->sellerOrder?->seller?->business_name }}</p>
                                <p class="mt-1 font-sans text-[10px] text-[var(--text-muted)]">Delivery status: {{ $item->sellerOrder?->status?->label() }}</p>
                                @if ($isDraft && ! $item->received_at)
                                    <form method="POST" action="{{ route('logistics.manifests.items.destroy', [$manifest, $item]) }}" class="mt-2 inline">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="inline-flex min-h-7 items-center rounded-sm border border-rose-200 px-2.5 font-sans text-[10px] font-semibold text-rose-700 hover:bg-rose-50">Remove</button>
                                    </form>
                                @endif
                            </td>
                            <td class="px-4 py-4">
                                <span class="inline-flex rounded-full border px-2.5 py-1 font-sans text-[10px] font-semibold {{ $item->received_at ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : ($isDispatched ? 'border-amber-200 bg-amber-50 text-amber-800' : 'border-[var(--border-strong)] bg-[var(--bg-page)] text-[var(--text-muted)]') }}">{{ $item->custodyLabel() }}</span>
                                @if ($isDispatched && ! $item->received_at)
                                    <p class="mt-2 max-w-[220px] font-sans text-[10px] leading-relaxed text-[var(--text-muted)]">Custody stays with {{ $manifest->originHub?->name }} until the destination verifies this parcel.</p>
                                @endif
                            </td>
                            <td class="px-4 py-4">
                                @if ($manifest->dispatched_at)
                                    <p class="font-sans text-[11px] text-[var(--text-ink)]">{{ $manifest->dispatched_at->format('M j, Y g:i A') }}</p>
                                    <p class="mt-1 font-sans text-[10px] text-[var(--text-muted)]">By {{ $actorName }} at {{ $manifest->originHub?->name }}</p>
                                @else
                                    <p class="font-sans text-[11px] text-[var(--text-muted)]">Not dispatched</p>
                                @endif
                            </td>
                            <td class="px-4 py-4">
                                @if ($item->received_at)
                                    <p class="font-sans text-[11px] text-[var(--text-ink)]">{{ $item->received_at->format('M j, Y g:i A') }}</p>
                                    <p class="mt-1 font-sans text-[10px] text-[var(--text-muted)]">By {{ $actorName }} at {{ $manifest->destinationHub?->name }}</p>
                                @else
                                    <p class="font-sans text-[11px] text-[var(--text-muted)]">Waiting for a valid receiving scan</p>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
@endsection

@if ($isDraft && $items->isNotEmpty())
    @push('scripts')
        @vite('resources/js/logistics/manifests.js')
    @endpush
@endif
