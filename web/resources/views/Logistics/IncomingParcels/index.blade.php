@extends('Layouts.logistics')

@section('title', 'Incoming Parcels')

@use('App\Enums\OrderStatus')
@use('App\Models\Seller\StoreSale')

@section('content')
@include('Logistics._parcel-queue-assets')
@php
    $stages = [
        'rider' => ['title' => 'Needs a rider', 'help' => 'Choose a rider for these parcels.', 'showing' => 'requiring manual dispatch', 'empty' => 'Every parcel has a rider.'],
        'dispatch' => ['title' => 'Ready to send', 'help' => 'Confirm to tell the rider to collect the parcel.', 'showing' => 'ready to send', 'empty' => 'Nothing waiting to be sent.'],
        'waiting' => ['title' => 'Waiting for rider', 'help' => 'The rider has been told. Nothing to do yet.', 'showing' => 'awaiting collection', 'empty' => 'No riders on their way to a seller.'],
        'arriving' => ['title' => 'On the way', 'help' => 'Scan each parcel when it arrives at your station.', 'showing' => 'on the way to your station', 'empty' => 'No parcels on the way to your station.'],
    ];

    $assignmentOf = fn ($shipment) => $shipment->deliveryAssignments->where('role', 'pickup')->last();
    $isDispatchConfirmed = function ($shipment) use ($assignmentOf): bool {
        $audit = json_decode($assignmentOf($shipment)?->notes ?? '', true);

        return is_array($audit) && filled($audit['dispatch_confirmed_at'] ?? null);
    };
    $grouped = $shipments->getCollection()->groupBy(fn ($shipment) => match (true) {
        $shipment->courier === null, $shipment->needs_reassignment && $shipment->handover_at === null => 'rider',
        $shipment->handover_at === null && ! $isDispatchConfirmed($shipment) => 'dispatch',
        $shipment->status === OrderStatus::PickedUp => 'arriving',
        default => 'waiting',
    });
    $firstStage = collect(array_keys($stages))->first(fn ($key) => $grouped->has($key)) ?? 'rider';

    $buttonBase = 'inline-flex h-9 items-center justify-center gap-2 rounded-lg px-4 font-sans text-[12.5px] font-semibold no-underline transition-colors';
    $primaryButton = $buttonBase.' ip-primary';
    $quietButton = $buttonBase.' border border-[var(--border-strong)] bg-[var(--bg-surface)] text-[var(--text-ink)] hover:bg-[var(--bg-page)]';
    $fieldClass = 'ip-field h-9 w-full min-w-0 rounded-lg border border-[var(--border-strong)] px-2.5 font-sans text-[12.5px] text-[var(--text-ink)]';
    $labelClass = 'font-sans text-[12px] text-[var(--text-muted)]';
@endphp

<div class="ip-canvas -m-4 min-h-screen sm:-m-6 xl:-m-8">
<div class="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border-strong)] bg-[var(--bg-surface)] px-4 py-4 sm:px-6 xl:px-8">
    <h1 class="font-serif text-2xl font-semibold text-[var(--text-ink)]">Incoming Parcels</h1>
    <a href="{{ route('logistics.scan.index') }}" class="inline-flex h-10 items-center justify-center gap-2 rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] px-4 font-sans text-[11px] font-semibold uppercase tracking-[0.5px] text-[var(--text-ink)] no-underline shadow-sm hover:bg-[var(--bg-page)]">
        <i data-lucide="barcode" class="h-4 w-4 text-[var(--text-muted)]"></i>
        Scan parcel
    </a>
</div>

<div class="p-4 sm:p-6 xl:px-8">
<x-logistics-flash :messages="['success', 'warning', 'error']" />
@if ($errors->any())
    <div role="alert" class="mb-4 rounded-md border border-red-200 bg-red-50 p-3 text-xs text-red-900">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>
@endif

@if ($highlightedId)
    <p class="mb-4 font-sans text-[13px] text-[var(--text-muted)]">
        Showing one parcel.
        <a href="{{ route('logistics.incoming-parcels.index') }}" class="ip-link font-semibold underline">Show all parcels</a>
    </p>
@endif

@if ($shipments->isEmpty())
    <section class="bg-[var(--bg-surface)] border border-[var(--border-strong)] rounded-lg p-8 shadow-sm flex flex-col items-center text-center">
        <i data-lucide="inbox" class="w-[32px] h-[32px] text-[var(--icon-soft)] mb-3"></i>
        <p class="font-sans text-[13px] font-semibold text-[var(--text-ink)] mb-1">No parcels awaiting pickup</p>
        <p class="font-sans text-[11.5px] text-[var(--text-muted)] max-w-[360px]">
            Parcels marked ready for pickup by sellers will appear here for you to collect and deliver to the sorting center.
        </p>
    </section>
@else
    <div role="tablist" aria-label="Parcel stages" class="mb-5 flex flex-wrap gap-x-6 border-b border-[var(--border-strong)]">
        @foreach ($stages as $key => $stage)
            <a role="tab" href="#stage-{{ $key }}" data-ip-tab="{{ $key }}" aria-selected="{{ $key === $firstStage ? 'true' : 'false' }}" class="ip-tab -mb-px inline-flex min-h-11 items-center gap-2 font-sans text-[13px] font-medium no-underline">
                {{ $stage['title'] }}
                <span class="ip-count rounded-full px-2 py-0.5 text-[11px] font-semibold tabular-nums">{{ $grouped->get($key, collect())->count() }}</span>
            </a>
        @endforeach
    </div>
    @foreach ($stages as $key => $stage)
        <section id="stage-{{ $key }}" role="tabpanel" data-ip-stage="{{ $key }}" @if ($key !== $firstStage) hidden @endif>
            @if (! $grouped->has($key))
                <p class="rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-8 text-center font-sans text-[13px] text-[var(--text-muted)]">{{ $stage['empty'] }}</p>
            @else
                @php
                    $stageCount = $grouped[$key]->count();
                @endphp
                <div class="mb-4 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                    <p class="font-sans text-[13px] text-[var(--text-muted)]">{{ $stage['help'] }}</p>
                    <p class="font-sans text-[11.5px] text-[var(--text-muted)]">Displaying {{ $stageCount }} {{ \Illuminate\Support\Str::plural('parcel', $stageCount) }} {{ $stage['showing'] }}{{ $shipments->hasPages() ? ' on this page' : '' }}</p>
                </div>
                <ul class="space-y-3">
                    @foreach ($grouped[$key] as $shipment)
                        @php
                            $courier = $shipment->courier;
                            $assignment = $assignmentOf($shipment);
                            $audit = json_decode($assignment?->notes ?? '', true);
                            $destinationMunicipality = $shipment->order?->shipping_address['municipality'] ?? $shipment->order?->buyer?->municipality_name;
                            $buyerName = $shipment->order?->buyer?->fullName();
                            $isClaimed = $shipment->isClaimedBy($provider);
                            $isUnassigned = $shipment->status === OrderStatus::ReadyForPickup && $shipment->courier_id === null;
                            $isPickedUp = $shipment->status === OrderStatus::PickedUp;
                            $canReassign = $courier && ($shipment->status === OrderStatus::ReadyForPickup || ($isPickedUp && ! $shipment->handover_at));
                            $canReportIncident = $shipment->handover_at !== null && ! in_array($shipment->status, [OrderStatus::Delivered, OrderStatus::Completed, OrderStatus::Returned, OrderStatus::Cancelled], true);
                            $isThisReassignment = (string) old('expected_courier_id') !== '' && (int) old('expected_courier_id') === (int) $shipment->courier_id;
                            $isHighlighted = (int) ($highlightedId ?? 0) === (int) $shipment->id;
                        @endphp
                        <li id="parcel-{{ $shipment->id }}" class="overflow-hidden rounded-xl border border-[var(--border-strong)] bg-[var(--bg-surface)] shadow-sm {{ $isHighlighted ? 'ip-highlight' : '' }}">
                            <div class="grid grid-cols-[minmax(0,1fr)_28px] items-center gap-x-4 gap-y-3 p-4 lg:grid-cols-[minmax(0,1fr)_120px_minmax(260px,300px)_28px]">
                                <div class="flex min-w-0 items-center gap-3">
                                    <span class="ip-tile flex h-10 w-10 shrink-0 items-center justify-center rounded-lg">
                                        <i data-lucide="package-open" class="h-5 w-5"></i>
                                    </span>
                                    <div class="min-w-0">
                                        <p class="flex flex-wrap items-center gap-x-2 gap-y-1 font-sans text-[14px] font-semibold text-[var(--text-ink)]">
                                            <span class="text-[var(--text-ink)]">#{{ $shipment->id }}</span>
                                            <span class="font-normal text-[var(--text-muted)]">·</span>
                                            <span class="text-[var(--text-ink)]">{{ $shipment->seller?->business_name ?? $shipment->seller?->name ?? '—' }}</span>
                                            <span class="font-normal text-[var(--text-muted)]">·</span>
                                            <span class="ip-chip rounded-md px-2 py-0.5 text-[11.5px] font-medium">{{ $shipment->formattedParcelWeight() }}</span>
                                        </p>
                                        <p class="mt-0.5 flex min-w-0 items-center gap-1 font-sans text-[12px] text-[var(--text-muted)]" title="{{ $shipment->pickupAddress() }}">
                                            <i data-lucide="map-pin" class="h-3 w-3 shrink-0"></i>
                                            <span class="truncate text-[var(--text-muted)]">{{ $shipment->pickupAddress() ?? '—' }}</span>
                                        </p>
                                    </div>
                                </div>

                                <button type="button" data-ip-toggle aria-expanded="{{ $isThisReassignment ? 'true' : 'false' }}" aria-controls="parcel-{{ $shipment->id }}-details" aria-label="Details for parcel #{{ $shipment->id }}" title="Details" class="col-start-2 row-start-1 flex h-7 w-7 cursor-pointer items-center justify-center rounded-md text-[var(--text-muted)] hover:bg-[var(--bg-page)] lg:col-start-4">
                                    <i data-lucide="chevron-down" class="h-4 w-4"></i>
                                </button>

                                <p class="col-span-2 flex items-center gap-2 font-sans text-[12.5px] lg:col-span-1">
                                    <span class="h-1.5 w-1.5 shrink-0 rounded-full {{ ! $courier || $shipment->needs_reassignment ? 'bg-amber-500' : 'bg-emerald-500' }}"></span>
                                    <span class="{{ $courier ? 'text-[var(--text-ink)]' : 'italic text-[var(--text-ink)]' }}">{{ $courier ? ($courier->rider?->fullName() ?? $courier->name) : 'No rider yet' }}</span>
                                </p>

                                <div class="col-span-2 flex flex-wrap items-center gap-2 lg:col-span-1 lg:justify-end">
                                    @if ($isUnassigned)
                                        @php
                                            $hubId = $originStationHubs[$shipment->id] ?? null;
                                            $hubCouriers = $availableCouriers->filter(fn ($candidate): bool => $hubId !== null
                                                && (int) $candidate->rider?->station_hub_id === (int) $hubId);
                                        @endphp
                                        @if ($hubCouriers->isEmpty())
                                            <p class="font-sans text-[13px] text-[var(--text-muted)]">No eligible Rider at this Station Hub.</p>
                                        @else
                                            <form method="POST" action="{{ route('logistics.incoming-parcels.assignPickupRider', $shipment) }}" class="flex w-full gap-2">
                                                @csrf
                                                <select name="courier_id" required aria-label="Rider for parcel #{{ $shipment->id }}" class="{{ $fieldClass }} flex-1">
                                                    <option value="">Select an eligible Rider</option>
                                                    @foreach ($hubCouriers as $candidate)
                                                        <option value="{{ $candidate->id }}" @selected((int) old('courier_id') === (int) $candidate->id)>{{ $candidate->rider?->fullName() ?? $candidate->name }} · carrying {{ number_format($candidate->activeWeightGrams()) }} of {{ number_format($candidate->max_weight_grams) }} g</option>
                                                    @endforeach
                                                </select>
                                                <button type="submit" class="{{ $primaryButton }} shrink-0">Assign</button>
                                            </form>
                                        @endif
                                    @endif

                                    @if ($courier && $key === 'rider' && $canReassign)
                                        <button type="button" data-ip-open class="{{ $primaryButton }}">Choose new rider</button>
                                    @endif

                                    @if ($key === 'dispatch')
                                        <form method="POST" action="{{ route('logistics.incoming-parcels.confirmPickupDispatch', $shipment) }}">
                                            @csrf
                                            <button type="submit" class="{{ $primaryButton }}">Confirm dispatch</button>
                                        </form>
                                    @endif

                                    @if ($key === 'waiting')
                                        <p class="font-sans text-[13px] text-[var(--text-muted)]">Rider notified</p>
                                    @endif

                                    @if ($isPickedUp)
                                        <a href="{{ route('logistics.scan.index') }}" class="{{ $key === 'arriving' ? $primaryButton : $quietButton }}">Scan at station</a>
                                    @endif
                                </div>
                            </div>

                            @if ($shipment->needs_reassignment || ! $courier)
                                <div class="{{ $shipment->needs_reassignment ? 'ip-strip' : 'border-t border-[var(--border-strong)]' }} flex flex-wrap items-center justify-between gap-x-4 gap-y-2 px-4 py-3">
                                    @if ($shipment->needs_reassignment)
                                        <p role="status" class="flex min-w-0 flex-1 basis-[280px] items-start gap-2.5 font-sans text-[12px] text-[var(--text-ink)]">
                                            <span class="ip-warn flex h-5 w-5 shrink-0 items-center justify-center rounded-full">
                                                <i data-lucide="triangle-alert" class="h-3 w-3"></i>
                                            </span>
                                            <span class="text-[var(--text-ink)]"><strong class="font-semibold">Needs attention:</strong> {{ $shipment->reassignment_reason }}</span>
                                        </p>
                                    @endif
                                    @if (! $courier)
                                        <form method="POST" action="{{ route('logistics.dispatch.retry', [$shipment, 'pickup']) }}" class="ml-auto shrink-0">
                                            @csrf
                                            <button type="submit" class="ip-link inline-flex cursor-pointer items-center gap-1.5 font-sans text-[12px] font-semibold">
                                                <i data-lucide="refresh-cw" class="h-3 w-3"></i>
                                                Retry automatic dispatch
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            @endif

                            <div id="parcel-{{ $shipment->id }}-details" data-ip-details @unless ($isThisReassignment) hidden @endunless>
                                <div class="grid gap-x-8 gap-y-4 border-t border-[var(--border-strong)] px-4 py-4 lg:grid-cols-2">
                                    <dl class="grid grid-cols-[90px_minmax(0,1fr)] gap-x-3 gap-y-1.5 font-sans text-[13px]">
                                        <dt class="{{ $labelClass }}">Status</dt>
                                        <dd class="text-[var(--text-ink)]">{{ $shipment->pickupStateLabel() }}</dd>
                                        <dt class="{{ $labelClass }}">Pick up from</dt>
                                        <dd class="text-[var(--text-ink)]">{{ $shipment->pickupAddress() ?? '—' }}</dd>
                                        <dt class="{{ $labelClass }}">Bring to</dt>
                                        <dd class="text-[var(--text-ink)]">{{ $shipment->originStationName() ?? 'Set when a rider is assigned' }}</dd>
                                        <dt class="{{ $labelClass }}">Deliver to</dt>
                                        <dd class="text-[var(--text-ink)]">
                                            @if ($isClaimed && $buyerName){{ $buyerName }} · @endif{{ $destinationMunicipality ?? '—' }}
                                            @if (! $isClaimed)
                                                <span class="block text-[12px] text-[var(--text-muted)]">Buyer's name shows once your rider is assigned</span>
                                            @endif
                                        </dd>
                                        @if ($courier)
                                            <dt class="{{ $labelClass }}">Rider's station</dt>
                                            <dd class="text-[var(--text-ink)]">{{ $courier->rider?->station?->name ?? 'Station requires review' }}</dd>
                                            <dt class="{{ $labelClass }}">Workload</dt>
                                            <dd class="text-[var(--text-ink)]">{{ $courier->activeWorkload() }}/{{ \App\Models\Rider\Courier::ACTIVE_CAPACITY }} · {{ $courier->is_available ? 'Available' : 'Reserved / busy' }}</dd>
                                        @endif
                                        @if ($assignment)
                                            <dt class="{{ $labelClass }}">Assigned</dt>
                                            <dd class="text-[var(--text-ink)]">{{ $assignment->created_at->timezone(StoreSale::TIMEZONE)->format('M j, Y g:i A') }} · {{ $assignment->assigned_by_type === 'system' ? 'Automatic assignment' : 'Logistics #'.$assignment->assigned_by_id }}</dd>
                                            @if (is_array($audit) && filled($audit['reason'] ?? null))
                                                <dt class="{{ $labelClass }}">Override</dt>
                                                <dd class="text-[var(--text-ink)]">{{ $audit['reason'] }} · Previous Rider #{{ $audit['previous_rider_id'] ?? '—' }}</dd>
                                            @endif
                                        @endif
                                    </dl>

                                    @if ($canReassign || $canReportIncident)
                                        <div>
                                            @if ($canReassign)
                                                <form method="POST" action="{{ route('logistics.incoming-parcels.assignPickupRider', $shipment) }}" class="max-w-[420px] space-y-2">
                                                    @csrf
                                                    <input type="hidden" name="reassign" value="1">
                                                    <input type="hidden" name="expected_courier_id" value="{{ $shipment->courier_id }}">
                                                    <p class="font-sans text-[13px] font-semibold text-[var(--text-ink)]">Reassign Rider</p>
                                                    <select name="courier_id" aria-label="New rider for parcel #{{ $shipment->id }}" class="{{ $fieldClass }}">
                                                        <option value="">Choose for me (next eligible rider)</option>
                                                        @foreach ($availableCouriers as $candidate)
                                                            @if ((int) $candidate->rider?->station_hub_id === (int) $shipment->origin_station_hub_id && (int) $candidate->id !== (int) $shipment->courier_id)
                                                                <option value="{{ $candidate->id }}">{{ $candidate->rider?->fullName() ?? $candidate->name }} · carrying {{ number_format($candidate->activeWeightGrams()) }} of {{ number_format($candidate->max_weight_grams) }} g</option>
                                                            @endif
                                                        @endforeach
                                                    </select>
                                                    <input name="reason" required maxlength="500" aria-label="Reason for changing the rider" placeholder="Why are you changing the rider?" class="{{ $fieldClass }}" value="{{ $isThisReassignment ? old('reason') : '' }}">
                                                    <button type="submit" class="{{ $quietButton }}">Save new rider</button>
                                                </form>
                                            @endif
                                            @if ($canReportIncident)
                                                @can('advance-shipment', $shipment)
                                                    <a href="{{ route('logistics.delivery-monitoring.show', $shipment) }}" class="font-sans text-[13px] text-[var(--text-muted)] underline">Report incident</a>
                                                @endcan
                                            @endif
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endforeach
@endif
<div class="mt-4">{{ $shipments->links() }}</div>
</div>
</div>
@endsection
