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

        <div class="flex flex-wrap items-stretch gap-3">
            @if ($rider->station?->municipality)
                <div class="rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] px-4 py-2">
                    <p class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)]">Coverage</p>
                    <p class="font-sans text-[12.5px] text-[var(--text-ink)]">{{ $rider->station->municipality }}</p>
                </div>
            @endif

            <form method="POST" action="{{ route('rider.availability.update') }}" class="flex items-center gap-3 rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] px-4 py-2">
                @csrf
                <input type="hidden" name="online" value="{{ $rider->isOnline() ? 0 : 1 }}">
                <div>
                    <p class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)]">Availability</p>
                    <p class="flex items-center gap-1.5 font-sans text-[12.5px] font-semibold {{ $rider->isOnline() ? 'text-emerald-700' : 'text-[var(--text-muted)]' }}">
                        <span class="h-2 w-2 rounded-full {{ $rider->isOnline() ? 'bg-emerald-600' : 'bg-gray-400' }}" aria-hidden="true"></span>
                        {{ $rider->isOnline() ? 'Online' : 'Offline' }}
                    </p>
                </div>
                <button type="submit" class="inline-flex h-9 cursor-pointer items-center rounded-lg border border-[var(--border-strong)] px-3 font-sans text-[12.5px] font-semibold text-[var(--text-ink)] hover:bg-[var(--bg-page)]">
                    {{ $rider->isOnline() ? 'Go offline' : 'Go online' }}
                </button>
            </form>
        </div>
    </div>

    @unless ($rider->isOnline())
        <p role="status" class="mb-6 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 font-sans text-[13px] text-amber-900">
            You are offline. Logistics cannot give you new pickup or delivery jobs. Jobs you already have stay on your lists.
        </p>
    @endunless

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

    @php($pay = $stats['earnings'])
    @php($cash = $stats['cash'])
    @php($rates = (new \App\Services\Logistics\PickupPolicy)->rates())
    @php($rateLabel = fn (int $minor): string => '₱'.rtrim(rtrim(number_format($minor / 100, 2), '0'), '.'))
    @if ($cash['holdingCount'] > 0)
        <a href="{{ route('rider.money.index') }}" class="mb-4 flex flex-wrap items-center justify-between gap-2 rounded-lg border {{ $cash['overdueCount'] > 0 ? 'border-red-300 bg-red-50 text-red-900' : 'border-amber-300 bg-amber-50 text-amber-900' }} px-4 py-3 font-sans text-[13px] font-semibold no-underline">
            <span>You are holding ₱{{ number_format($cash['holdingMinor'] / 100, 2) }} in delivery cash from {{ $cash['holdingCount'] }} {{ \Illuminate\Support\Str::plural('parcel', $cash['holdingCount']) }}.@if ($cash['overdueCount'] > 0) {{ $cash['overdueCount'] }} overdue.@endif</span>
            <span class="underline">Hand it over</span>
        </a>
    @endif
    <section class="mb-8 rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] shadow-sm">
        <div class="grid gap-4 p-4 sm:grid-cols-3">
            <div>
                <h2 class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)]">Pending pay</h2>
                <p class="mt-1 font-sans text-[26px] font-semibold leading-none tabular-nums text-[var(--text-ink)]">₱{{ number_format($pay['pendingMinor'] / 100, 2) }}</p>
            </div>
            <div>
                <h2 class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)]">Paid to you</h2>
                <p class="mt-1 font-sans text-[26px] font-semibold leading-none tabular-nums text-[var(--text-ink)]">₱{{ number_format($pay['paidMinor'] / 100, 2) }}</p>
            </div>
            <div>
                <h2 class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)]">Earned this month</h2>
                <p class="mt-1 font-sans text-[26px] font-semibold leading-none tabular-nums text-[var(--text-ink)]">₱{{ number_format($pay['monthMinor'] / 100, 2) }}</p>
            </div>
        </div>
        <p class="border-t border-[var(--border-strong)] px-4 py-2 font-sans text-[12px] text-[var(--text-muted)]">
            <a href="{{ route('rider.money.index') }}" class="font-semibold text-primary-700 no-underline hover:text-primary-800">See all my money</a> ·
            This month: {{ $pay['monthPickups'] }} {{ \Illuminate\Support\Str::plural('pickup', $pay['monthPickups']) }} ({{ $rateLabel($rates['first_minor']) }} first parcel + {{ $rateLabel($rates['additional_minor']) }} each additional) · {{ $pay['monthDeliveries'] }} {{ \Illuminate\Support\Str::plural('delivery', $pay['monthDeliveries']) }} × {{ $rateLabel($rates['delivery_minor']) }}. Failed attempts earn nothing. {{ $rider->provider?->business_name ?? 'Your Logistics company' }} pays you.
        </p>
        @if ($stats['recent_earnings']->isNotEmpty())
            <ul class="divide-y divide-[var(--border-strong)] border-t border-[var(--border-strong)]">
                @foreach ($stats['recent_earnings'] as $earning)
                    <li class="flex flex-wrap items-center justify-between gap-2 px-4 py-2.5 font-sans text-[12.5px] text-[var(--text-ink)]">
                        <span class="min-w-0">{{ $earning->job === \App\Models\Rider\RiderEarning::PICKUP ? 'Pickup' : 'Delivery' }} · <span class="font-mono text-[11px] text-[var(--text-muted)]">{{ $earning->sellerOrder?->tracking_number ?? '#'.$earning->seller_order_id }}</span></span>
                        <span class="flex items-center gap-2">
                            <span class="font-semibold tabular-nums">₱{{ number_format($earning->amount_minor / 100, 2) }}</span>
                            @if ($earning->isPaid())
                                <span class="inline-flex rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-800" title="Ref {{ $earning->payout?->reference }}">Paid {{ $earning->payout?->paid_on?->format('M j') }}</span>
                            @else
                                <span class="inline-flex rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-800">Pending</span>
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

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
