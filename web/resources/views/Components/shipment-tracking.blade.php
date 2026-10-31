@props(['shipment', 'compact' => false])

@use('App\Enums\OrderStatus')

@php
    $status = $shipment->status;
    $phase = match ($status) {
        OrderStatus::Placed, OrderStatus::Confirmed, OrderStatus::Preparing => 0,
        OrderStatus::ReadyForPickup => 1,
        OrderStatus::PickedUp, OrderStatus::AtSortingCenter, OrderStatus::Sorted,
        OrderStatus::AssignedToRider => 2,
        OrderStatus::OutForDelivery => 3,
        OrderStatus::Delivered => 4,
        OrderStatus::Completed => 5,
        default => null,
    };
    $description = match ($status) {
        OrderStatus::Placed => 'Your order is waiting for the seller to confirm it.',
        OrderStatus::Confirmed => 'The seller has confirmed your order.',
        OrderStatus::Preparing => 'The seller is preparing your items.',
        OrderStatus::ReadyForPickup => 'Your parcel is ready for the pickup courier.',
        OrderStatus::PickedUp => 'The pickup courier has collected your parcel.',
        OrderStatus::AtSortingCenter => 'Your parcel has arrived at a sorting center.',
        OrderStatus::Sorted => 'Your parcel has been sorted for the next delivery stage.',
        OrderStatus::AssignedToRider => 'A delivery courier has been assigned to your parcel.',
        OrderStatus::OutForDelivery => 'Your courier is on the way to deliver your parcel.',
        OrderStatus::Delivered => 'Your parcel was delivered. Order completion is still pending.',
        OrderStatus::Completed => 'This shipment is complete.',
        OrderStatus::DeliveryFailed => 'The delivery attempt was unsuccessful. Check the timeline for the next update.',
        OrderStatus::Returned => 'This shipment was returned. It is not a completed delivery.',
        OrderStatus::Cancelled => 'This shipment was cancelled. It will not continue through delivery.',
    };
    $milestones = ['Order placed', 'Ready for pickup', 'In transit', 'Out for delivery', 'Delivered', 'Completed'];
    $milestoneCount = count($milestones);
    $trackInset = 100 / ($milestoneCount * 2);
    $trackSpan = 100 - ($trackInset * 2);
    $progressWidth = $phase === null ? 0 : $phase * ($trackSpan / ($milestoneCount - 1));
@endphp

<div class="rounded-xl border border-[var(--border-faint)] bg-[var(--bg-page)] p-4" aria-label="Shipment progress">
    @unless ($compact)
        <div class="flex flex-wrap items-center justify-between gap-2">
            <span class="font-sans text-[11px] font-semibold uppercase tracking-wide text-[var(--text-muted)]">Current status</span>
            <x-shipment-status-badge :status="$status" />
        </div>
    @endunless

    <p class="{{ $compact ? '' : 'mt-2' }} font-sans text-xs leading-relaxed text-[var(--text-muted)]">{{ $description }}</p>

    @if ($phase !== null)
        <ol class="relative mt-5 grid grid-cols-6" aria-label="Delivery milestones">
            {{-- Connected track: the muted line runs behind every step. --}}
            <span aria-hidden="true"
                  class="absolute top-2.5 h-0.5 -translate-y-1/2 rounded-full bg-secondary-300"
                  style="left: {{ $trackInset }}%; right: {{ $trackInset }}%;"></span>
            {{-- Completed portion of the track. --}}
            <span aria-hidden="true"
                  class="absolute top-2.5 h-0.5 -translate-y-1/2 rounded-full bg-primary-800 transition-[width] duration-500"
                  style="left: {{ $trackInset }}%; width: {{ $progressWidth }}%;"></span>

            @foreach ($milestones as $index => $milestone)
                @php
                    $isComplete = $index < $phase;
                    $isCurrent = $index === $phase;
                @endphp
                <li class="relative z-10 flex min-w-0 flex-col items-center text-center" @if ($isCurrent) aria-current="step" @endif>
                    <span class="flex size-5 shrink-0 items-center justify-center rounded-full border-2 transition-colors
                        {{ $isComplete
                            ? 'border-primary-800 bg-primary-800 text-white'
                            : ($isCurrent
                                ? 'border-primary-800 bg-[var(--bg-surface)] ring-4 ring-primary-100'
                                : 'border-secondary-300 bg-[var(--bg-surface)]') }}">
                        @if ($isComplete)
                            <svg class="size-3" viewBox="0 0 12 12" fill="none" aria-hidden="true">
                                <path d="M2.5 6.5 5 9l4.5-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        @elseif ($isCurrent)
                            <span class="size-2 rounded-full bg-primary-800" aria-hidden="true"></span>
                        @else
                            <span class="size-1.5 rounded-full bg-secondary-300" aria-hidden="true"></span>
                        @endif
                    </span>
                    <span class="mt-2 block break-words font-sans text-[9px] leading-snug sm:text-[10.5px] {{ $isCurrent ? 'font-semibold text-[var(--text-ink)]' : ($isComplete ? 'font-medium text-[var(--text-ink)]' : 'text-[var(--text-muted)]') }}">{{ $milestone }}</span>
                </li>
            @endforeach
        </ol>
    @endif
</div>

@if (! $compact)
<ol class="mt-5 space-y-3">
    @foreach ($shipment->trackingEvents() as $event)
        <li class="border-l border-[var(--border-strong)] pl-3">
            <p class="font-sans text-xs font-semibold text-[var(--text-ink)]">{{ $event['title'] }}</p>
            <p class="mt-1 font-sans text-[11px] text-[var(--text-muted)]">{{ $event['at']->format('M d, Y h:i A') }}@if ($event['type'] === 'handoff') · Provider handoff @endif</p>
            @if (filled($event['detail'] ?? null))
                <p class="mt-1 font-sans text-xs text-[var(--text-muted)]">{{ $event['detail'] }}</p>
            @endif
        </li>
    @endforeach
</ol>
<p class="mt-3 font-sans text-xs text-[var(--text-muted)]">Current: <span class="font-semibold text-[var(--text-ink)]">{{ $shipment->status->label() }}</span></p>

@endif
