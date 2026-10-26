@extends('Layouts.rider')

@section('title', 'Dashboard')

@section('content')
    <x-rider.flash />

    <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div>
            <p class="font-sans text-[11px] font-semibold uppercase tracking-[1.2px] text-[var(--text-muted)]">Rider Dashboard</p>
            <h1 class="font-serif text-2xl text-[var(--text-ink)]">{{ $rider->fullName() }}</h1>
            <p class="font-sans text-[12.5px] text-[var(--text-muted)]">
                {{ $rider->provider?->business_name ?? 'Logistics company' }}
                <span class="mx-1 text-[var(--border-strong)]">•</span>
                {{ $rider->station?->name ?? 'No Station Hub assigned' }}
            </p>
        </div>

        @if ($rider->station?->municipality)
            <div class="rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] px-4 py-2">
                <p class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)]">Coverage</p>
                <p class="font-sans text-[12.5px] text-[var(--text-ink)]">{{ $rider->station->municipality }}</p>
            </div>
        @endif
    </div>

    @php
        $cards = [
            ['label' => 'Pending pickups', 'value' => $stats['pending_pickups'], 'icon' => 'package-search', 'route' => 'rider.pickups.index'],
            ['label' => 'Active deliveries', 'value' => $stats['active_deliveries'], 'icon' => 'truck', 'route' => 'rider.deliveries.index'],
            ['label' => 'Completed deliveries', 'value' => $stats['completed_deliveries'], 'icon' => 'package-check', 'route' => 'rider.history.index'],
            ['label' => 'Needs attention', 'value' => $stats['needs_attention'], 'icon' => 'triangle-alert', 'route' => 'rider.deliveries.index'],
        ];
    @endphp

    <div class="mb-8 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($cards as $card)
            <a href="{{ route($card['route']) }}"
               class="group rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-4 no-underline shadow-sm transition hover:border-[var(--color-primary-400)]">
                <div class="mb-3 flex items-center justify-between">
                    <span class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)]">{{ $card['label'] }}</span>
                    <i data-lucide="{{ $card['icon'] }}" class="h-[18px] w-[18px] text-[var(--icon-soft)]"></i>
                </div>
                <p class="font-sans text-[26px] font-semibold leading-none text-[var(--text-ink)]">{{ $card['value'] }}</p>
            </a>
        @endforeach
    </div>

    <section class="rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] shadow-sm">
        <div class="flex items-center justify-between border-b border-[var(--border-strong)] px-4 py-3">
            <h2 class="font-sans text-[13px] font-semibold text-[var(--text-ink)]">Assignments requiring attention</h2>
            <span class="font-sans text-[11px] text-[var(--text-muted)]">{{ $stats['needs_attention'] }} flagged</span>
        </div>

        @if ($stats['attention_parcels']->isEmpty())
            <div class="px-4 py-8 text-center">
                <p class="font-sans text-[12.5px] text-[var(--text-muted)]">Nothing needs your attention right now.</p>
            </div>
        @else
            <ul class="divide-y divide-[var(--border-strong)]">
                @foreach ($stats['attention_parcels'] as $parcel)
                    @php($addr = $parcel->order?->shipping_address ?? [])
                    <li class="flex flex-wrap items-center justify-between gap-2 px-4 py-3">
                        <div class="min-w-0">
                            <p class="font-sans text-[12.5px] font-semibold text-[var(--text-ink)]">
                                #{{ $parcel->id }}
                                <span class="ml-1 font-mono text-[10.5px] font-normal text-[var(--text-muted)]">{{ $parcel->tracking_number ?? 'no tracking' }}</span>
                            </p>
                            <p class="font-sans text-[11.5px] text-[var(--text-muted)]">
                                {{ $parcel->order?->buyer?->fullName() ?? 'Buyer' }} · {{ $addr['municipality'] ?? '—' }}
                            </p>
                            @if ($parcel->reassignment_reason)
                                <p class="font-sans text-[11px] text-amber-700">{{ $parcel->reassignment_reason }}</p>
                            @endif
                        </div>
                        <div class="text-right">
                            <p class="font-sans text-[12px] font-semibold text-[var(--text-ink)]">{{ $parcel->status->label() }}</p>
                            <p class="font-sans text-[11px] text-[var(--text-muted)]">Logistics will follow up</p>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
@endsection
