@props(['shipment', 'role'])
@php
    $courier = $role === 'pickup' ? $shipment->courier : $shipment->deliveryCourier;
    $assignment = $shipment->deliveryAssignments->where('role', $role)->last();
    $audit = json_decode($assignment?->notes ?? '', true);
@endphp
<div class="mt-2 space-y-1 font-sans text-[11px] text-[var(--text-muted)]">
    @if ($courier)
        <p>{{ $assignment?->assigned_by_type === 'system' ? 'Automatic assignment' : 'Assigned Rider' }} · {{ $courier->rider?->fullName() ?? $courier->name }}</p>
        <p>{{ $courier->rider?->station?->name ?? 'Station requires review' }} · Workload {{ $courier->activeWorkload() }}/{{ \App\Models\Courier::ACTIVE_CAPACITY }} · {{ $courier->is_available ? 'Available' : 'Reserved / busy' }}</p>
    @else
        <p>Pending assignment · No Rider reserved</p>
    @endif
    @if ($assignment)
        <p>{{ $assignment->created_at->format('M j, Y g:i A') }} · {{ $assignment->assigned_by_type === 'system' ? 'Automatic assignment' : 'Logistics #'.$assignment->assigned_by_id }}</p>
        @if (is_array($audit) && filled($audit['reason'] ?? null))
            <p>Override: {{ $audit['reason'] }} · Previous Rider #{{ $audit['previous_rider_id'] ?? '—' }}</p>
        @endif
    @endif
    @if ($shipment->needs_reassignment)
        <p role="status" class="text-amber-800">Needs attention: {{ $shipment->reassignment_reason }}</p>
    @endif
    @if ($role === 'pickup')
        @if ($courier && ! $shipment->needs_reassignment
            && in_array($shipment->status, [\App\Enums\OrderStatus::ReadyForPickup, \App\Enums\OrderStatus::PickedUp], true)
            && $shipment->handover_at === null)
            @if (is_array($audit) && filled($audit['dispatch_confirmed_at'] ?? null))
                <p class="font-sans text-[11px] text-[var(--text-muted)]">Dispatch confirmed · the rider has been notified. Awaiting collection.</p>
            @else
                <p class="font-sans text-[11px] text-[var(--text-muted)]">Rider reserved. Confirm the dispatch to notify the rider.</p>
                <form method="POST" action="{{ route('logistics.incoming-parcels.confirmPickupDispatch', $shipment) }}" class="mt-1">
                    @csrf
                    <button type="submit" class="rounded-sm bg-primary-700 px-3 py-2 text-xs text-white hover:bg-primary-800">Confirm dispatch</button>
                </form>
            @endif
        @elseif ($courier && $shipment->needs_reassignment)
            <p class="font-sans text-[11px] text-amber-800">Reassign the reserved Rider before confirming this pickup dispatch.</p>
        @elseif (! $courier)
            <p class="font-sans text-[11px] text-[var(--text-muted)]">No Rider reserved. Retry automatic dispatch or assign one below.</p>
            <form method="POST" action="{{ route('logistics.dispatch.retry', [$shipment, 'pickup']) }}">
                @csrf
                <button type="submit" class="rounded-sm border border-[var(--border-strong)] px-3 py-2 text-[var(--text-ink)] hover:bg-[var(--bg-page)]">Retry automatic dispatch</button>
            </form>
        @endif
    @elseif (! $courier && $shipment->status === \App\Enums\OrderStatus::Sorted)
        <form method="POST" action="{{ route('logistics.dispatch.retry', [$shipment, 'delivery']) }}">
            @csrf
            <button type="submit" class="rounded-sm border border-[var(--border-strong)] px-3 py-2 text-[var(--text-ink)] hover:bg-[var(--bg-page)]">Retry automatic dispatch</button>
        </form>
    @endif
</div>
