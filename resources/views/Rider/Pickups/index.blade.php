@extends('Layouts.rider')

@section('title', 'Pickup Assignments')

@use('App\Enums\OrderStatus')
@use('App\Models\Logistics\PickupSession')
@use('App\Services\Logistics\PickupWindowService')

@section('content')
    @php
        $peso = fn (int $minor): string => '₱'.number_format($minor / 100, 2);
        // One card per seller stop, earliest window first.
        $stops = $shipments
            ->groupBy(fn ($shipment) => $shipment->pickup_session_id ?? 'parcel-'.$shipment->id)
            ->sortBy(fn ($parcels) => $parcels->first()->pickup_window_starts_at?->timestamp ?? PHP_INT_MAX);
    @endphp

    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="font-sans text-[11px] font-semibold uppercase tracking-[1.2px] text-[var(--text-muted)]">Assignments</p>
            <h1 class="font-serif text-2xl text-[var(--text-ink)]">Pickup Assignments</h1>
            <p class="font-sans text-[12.5px] text-[var(--text-muted)]">
                Each card is one visit to a seller. Scan every parcel at the seller, then hand them to your Station Hub. You earn the first rate for the first parcel of a visit and the additional rate for each other one.
            </p>
        </div>
        <a href="{{ route('rider.scan.index') }}"
            class="inline-flex h-9 shrink-0 items-center justify-center rounded-sm border border-[var(--border-strong)] px-4 font-sans text-[11.5px] font-semibold uppercase tracking-[0.5px] text-[var(--text-ink)] hover:bg-[var(--bg-surface)]">
            Scan parcel
        </a>
    </div>

    <x-rider.flash />

    @if ($shipments->isEmpty())
        <section class="flex flex-col items-center rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-8 text-center shadow-sm">
            <i data-lucide="package-search" class="mb-3 h-[32px] w-[32px] text-[var(--icon-soft)]"></i>
            <p class="mb-1 font-sans text-[13px] font-semibold text-[var(--text-ink)]">No pickup assignments</p>
            <p class="max-w-[380px] font-sans text-[11.5px] text-[var(--text-muted)]">
                When Logistics assigns you a parcel that is ready at a seller, it will appear here.
            </p>
        </section>
    @else
        <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
            @foreach ($stops as $parcels)
                @php
                    $first = $parcels->first();
                    $session = $first->pickup_session_id ? ($sessions[$first->pickup_session_id] ?? null) : null;
                    $summary = $session ? ($summaries[$session->id] ?? null) : null;
                    $seller = $first->seller;
                    $pickupAddress = collect([
                        $seller?->house_number, $seller?->street, $seller?->barangay_name, $seller?->municipality_name, $seller?->province_name,
                    ])->filter()->implode(', ');
                    $window = PickupWindowService::label($first);
                    $canFinish = $session?->status === PickupSession::IN_PROGRESS;
                @endphp

                <section data-pickup-stop class="rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] shadow-sm">
                    <header class="border-b border-[var(--border-strong)] px-4 py-3">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)]">Pickup from</p>
                                <p class="font-sans text-[14px] font-semibold text-[var(--text-ink)]">{{ $seller?->business_name ?? $seller?->name ?? 'Seller' }}</p>
                                <p class="font-sans text-[11.5px] text-[var(--text-muted)]">{{ $pickupAddress !== '' ? $pickupAddress : 'Address not recorded' }}</p>
                                @if ($seller?->contact_no)
                                    <p class="font-sans text-[11.5px] text-[var(--text-muted)]">{{ $seller->contact_no }}</p>
                                @endif
                            </div>
                            @if ($session)
                                <span class="shrink-0 rounded-full border border-[var(--border-strong)] px-2 py-0.5 font-sans text-[10.5px] font-semibold uppercase tracking-[0.5px] text-[var(--text-muted)]">
                                    {{ $session->status === PickupSession::IN_PROGRESS ? 'In progress' : 'Scheduled' }}
                                </span>
                            @endif
                        </div>

                        <dl class="mt-3 grid grid-cols-2 gap-3">
                            <div>
                                <dt class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)]">Pickup window</dt>
                                <dd data-stop-window class="font-sans text-[12.5px] text-[var(--text-ink)]">{{ $window ?? 'Not scheduled' }}</dd>
                            </div>
                            <div>
                                <dt class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)]">Progress</dt>
                                <dd data-stop-progress class="font-sans text-[12.5px] text-[var(--text-ink)]">
                                    {{ $summary['collected'] ?? $parcels->where('status', '!=', OrderStatus::ReadyForPickup)->count() }} of {{ $summary['total'] ?? $parcels->count() }} collected
                                </dd>
                            </div>
                            @if ($summary)
                                <div class="col-span-2">
                                    <dt class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)]">This stop pays</dt>
                                    <dd data-stop-earnings class="font-sans text-[12.5px] text-[var(--text-ink)]">
                                        <span class="font-semibold text-emerald-700">+{{ $peso($summary['earnedMinor']) }}</span> earned
                                        @if ($summary['potentialMinor'] > $summary['earnedMinor'])
                                            <span class="text-[var(--text-muted)]">· up to +{{ $peso($summary['potentialMinor']) }} when every parcel is collected</span>
                                        @endif
                                    </dd>
                                </div>
                            @endif
                        </dl>
                    </header>

                    <ul class="divide-y divide-[var(--border-strong)]">
                        @foreach ($parcels as $shipment)
                            <li class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                                <div class="min-w-0">
                                    <p class="font-sans text-[13px] font-semibold text-[var(--text-ink)]">Parcel #{{ $shipment->id }}</p>
                                    <p class="font-mono text-[10.5px] text-[var(--text-muted)]">{{ $shipment->tracking_number ?? 'No tracking number' }}</p>
                                    <p class="font-sans text-[11.5px] text-[var(--text-muted)]">
                                        {{ $shipment->formattedParcelWeight() }} · {{ $shipment->orderItems->sum('quantity') }} item(s) · to {{ ($shipment->order?->shipping_address ?? [])['municipality'] ?? '—' }}
                                    </p>
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <a href="{{ route('rider.waybills.show', $shipment) }}"
                                       class="inline-flex h-9 items-center justify-center rounded-sm border border-[var(--border-strong)] px-3 font-sans text-[11.5px] font-semibold uppercase tracking-[0.5px] text-[var(--text-ink)] no-underline hover:bg-[var(--bg-page)]">
                                        Waybill
                                    </a>
                                    @if ($shipment->status === OrderStatus::ReadyForPickup)
                                        <a href="{{ route('rider.scan.index') }}"
                                           class="inline-flex h-9 items-center justify-center rounded-sm bg-primary-700 px-3 font-sans text-[11.5px] font-semibold uppercase tracking-[0.5px] text-white no-underline hover:bg-primary-800">
                                            Scan to confirm
                                        </a>
                                    @elseif ($shipment->status === OrderStatus::PickedUp)
                                        <p class="font-sans text-[11.5px] font-semibold text-emerald-700">
                                            Collected{{ $shipment->handover_at ? ' '.$shipment->handover_at->format('M j, g:i A') : '' }}. Hand over at your Station Hub.
                                        </p>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>

                    @if ($canFinish)
                        <footer class="border-t border-[var(--border-strong)] px-4 py-3">
                            <form method="POST" action="{{ route('rider.pickups.stops.finish', $session) }}">
                                @csrf
                                <button type="submit"
                                    class="inline-flex h-9 cursor-pointer items-center justify-center rounded-sm border border-[var(--border-strong)] px-4 font-sans text-[11.5px] font-semibold uppercase tracking-[0.5px] text-[var(--text-ink)] hover:bg-[var(--bg-page)]">
                                    Finish this stop
                                </button>
                                <span class="ml-2 font-sans text-[11px] text-[var(--text-muted)]">Use this when you are leaving the seller. Parcels left behind stay assigned to you.</span>
                            </form>
                        </footer>
                    @endif
                </section>
            @endforeach
        </div>
    @endif
@endsection
