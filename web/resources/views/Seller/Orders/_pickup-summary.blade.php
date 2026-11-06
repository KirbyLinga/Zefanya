@php
    $compactPickup = $compactPickup ?? false;
    $pickupStarts = $pickupShipment->pickup_window_starts_at?->copy()->timezone(\App\Models\Seller\StoreSale::TIMEZONE);
    $pickupEnds = $pickupShipment->pickup_window_ends_at?->copy()->timezone(\App\Models\Seller\StoreSale::TIMEZONE);
    $pickupMoves = (int) ($pickupShipment->pickup_missed_windows ?? 0);
    $awaitingPickup = $pickupShipment->status === \App\Enums\OrderStatus::ReadyForPickup;
    $beforeReady = in_array($pickupShipment->status, [\App\Enums\OrderStatus::Placed, \App\Enums\OrderStatus::Confirmed, \App\Enums\OrderStatus::Preparing], true);
@endphp
<div data-seller-pickup-summary class="{{ $compactPickup ? 'space-y-1.5 text-left' : 'space-y-4' }} font-sans">
    <div>
        @unless ($compactPickup)<p class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-[var(--text-muted)]">Pickup window</p>@endunless
        <p class="text-xs font-semibold leading-relaxed text-[var(--text-ink)]">
            @if ($pickupStarts && $pickupEnds)
                {{ $pickupStarts->format('M j, Y g:i A') }} &ndash; {{ $pickupEnds->format($pickupStarts->isSameDay($pickupEnds) ? 'g:i A' : 'M j, Y g:i A') }}
                <span class="{{ $compactPickup ? 'block' : '' }} text-[11px] font-normal text-[var(--text-muted)]">Philippine time</span>
            @elseif ($beforeReady)
                Window scheduled when marked ready
            @elseif ($awaitingPickup)
                Pickup window not available yet
            @else
                No pickup window recorded
            @endif
        </p>
    </div>
    <p class="text-xs leading-relaxed text-[var(--text-muted)]"><span class="font-semibold text-[var(--text-ink)]">Pickup rider:</span> {{ $pickupShipment->courier?->name ?? ($awaitingPickup ? 'Awaiting Logistics assignment' : 'Not assigned') }}</p>
    @if ($pickupMoves > 0)
        <p class="text-xs leading-relaxed text-[var(--status-warning)]">Rescheduled {{ $pickupMoves }} {{ Str::plural('time', $pickupMoves) }}{{ $awaitingPickup ? ' - next eligible window' : '' }}</p>
    @endif
    @unless ($compactPickup)
        @if ($awaitingPickup)
            <p class="rounded-lg bg-[var(--bg-page)] p-3 text-xs leading-relaxed text-[var(--text-muted)]">This is a scheduled window, not confirmation of collection. Logistics arranges the pickup rider. If a window is missed, the parcel moves automatically to the next eligible window and keeps its existing rider. This is not a failed pickup. Sellers cannot manually reschedule.</p>
        @elseif ($beforeReady)
            <p class="text-xs leading-relaxed text-[var(--text-muted)]">Marking ready schedules the current eligible or next pickup window. Logistics arranges the pickup rider.</p>
        @endif
    @endunless
</div>
