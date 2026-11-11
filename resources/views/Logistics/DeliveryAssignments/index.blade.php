@extends('Layouts.logistics')

@section('title', 'Assign Deliveries')

@use('App\Enums\OrderStatus')
@use('App\Enums\DeliveryFailureReason')
@use('App\Enums\PaymentMethod')
@use('App\Enums\PaymentStatus')
@use('App\Models\Seller\StoreSale')
@use('App\Services\Seller\SellerOrderStatusService')

@section('logistics-toolbar')
    <span hidden data-logistics-hosted-bell></span>
@endsection

@section('content')
@include('Logistics._parcel-queue-assets')
@php
    $maxAttempts = SellerOrderStatusService::MAX_DELIVERY_ATTEMPTS;
    $stages = [
        'rider' => ['title' => 'Needs a rider', 'help' => 'These parcels have no rider who can deliver them yet.', 'showing' => 'needing a rider', 'empty' => 'Every parcel has a rider.', 'alert' => 'ip-amber'],
        'dispatch' => ['title' => 'Ready to send', 'help' => 'A rider is reserved. Confirm to start the delivery.', 'showing' => 'ready to send', 'empty' => 'Nothing waiting to be sent.', 'alert' => 'ip-maroon'],
        'failed' => ['title' => 'Failed', 'help' => "Each parcel gets {$maxAttempts} delivery attempts: the first delivery and one retry.", 'showing' => 'with a failed delivery', 'empty' => 'No failed deliveries.', 'alert' => 'ip-rose'],
        'active' => ['title' => 'Out with rider', 'help' => 'The rider handles these from their own panel. Nothing to do yet.', 'showing' => 'out with a rider', 'empty' => 'No parcels out with a rider.', 'alert' => null],
        'done' => ['title' => 'Delivered', 'help' => 'Finished deliveries, with their proof and cash collected.', 'showing' => 'delivered', 'empty' => 'No delivered parcels yet.', 'alert' => null],
    ];

    // Out-for-delivery parcels are with the Rider: no direct reassignment (custody-safe).
    $reassignable = [OrderStatus::Sorted, OrderStatus::AssignedToRider];
    $grouped = $shipments->getCollection()->groupBy(fn ($shipment) => match (true) {
        $shipment->status === OrderStatus::DeliveryFailed => 'failed',
        in_array($shipment->status, [OrderStatus::Delivered, OrderStatus::Completed], true) => 'done',
        $shipment->needs_reassignment || $shipment->delivery_courier_id === null => 'rider',
        $shipment->status === OrderStatus::Sorted => 'dispatch',
        default => 'active',
    });
    $firstStage = collect(array_keys($stages))->first(fn ($key) => $grouped->has($key)) ?? 'rider';

    // The card's colour and icon follow the parcel's status, so each one reads at a glance.
    $looks = fn ($shipment, string $stage): array => match (true) {
        $stage === 'rider' => ['tone' => 'ip-amber', 'icon' => 'user-plus'],
        $stage === 'dispatch' => ['tone' => 'ip-maroon', 'icon' => 'package-open'],
        $stage === 'failed' => ['tone' => 'ip-rose', 'icon' => 'package-x'],
        $shipment->status === OrderStatus::AssignedToRider => ['tone' => 'ip-sky', 'icon' => 'clock'],
        $shipment->status === OrderStatus::OutForDelivery => ['tone' => 'ip-violet', 'icon' => 'truck'],
        $shipment->status === OrderStatus::Delivered => ['tone' => 'ip-emerald', 'icon' => 'package-check'],
        default => ['tone' => 'ip-neutral', 'icon' => 'circle-check'],
    };

    $buttonBase = 'inline-flex min-h-11 cursor-pointer items-center justify-center gap-2 rounded-lg px-4 font-sans text-[12.5px] font-semibold no-underline transition-colors';
    $primaryButton = $buttonBase.' ip-primary';
    $quietButton = $buttonBase.' border border-[var(--border-strong)] bg-[var(--bg-surface)] text-[var(--text-ink)] hover:bg-[var(--bg-page)]';
    $dangerButton = $buttonBase.' bg-rose-700 text-white hover:bg-rose-800';
    $fieldClass = 'ip-field h-11 w-full min-w-0 rounded-lg border border-[var(--border-strong)] px-2.5 font-sans text-[12.5px] text-[var(--text-ink)]';
    $labelClass = 'font-sans text-[12px] text-[var(--text-muted-2)]';
@endphp

<section class="ip-canvas min-w-0 [&_:is(a,button,input,select):focus-visible]:outline-2 [&_:is(a,button,input,select):focus-visible]:outline-offset-4 [&_:is(a,button,input,select):focus-visible]:outline-[var(--link)] [&_dialog]:[overflow-wrap:anywhere] [&_dialog_[data-assign-close]]:min-h-11 [&_dialog_[data-assign-close]]:min-w-11" aria-label="Delivery assignments">
<x-logistics.tabs group="deliveries" />
<header class="mb-6 flex flex-wrap items-center justify-between gap-4">
    <div><p class="mb-2 font-sans text-[11px] font-semibold uppercase tracking-[0.12em] text-[var(--text-muted-2)]">Delivery operations</p><h1 class="font-serif text-[26px] leading-tight font-semibold text-[var(--text-ink)] sm:text-[30px]">Assign Deliveries</h1><p class="mt-2 max-w-2xl font-sans text-[14px] leading-relaxed text-[var(--text-muted-2)]">Assign riders, confirm dispatch and manage delivery exceptions.</p></div>
    <x-logistics.notification-bell />
</header>
<div>
<x-logistics-flash :messages="['success', 'warning', 'error']" />
@if ($errors->any())
    <div role="alert" class="mb-4 rounded-lg border border-[var(--border-error-soft)] bg-[var(--bg-error-soft)] p-4 font-sans text-[13px] text-[var(--status-danger)]">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>
@endif

<section aria-labelledby="assignment-queue-heading" class="overflow-hidden rounded-xl border border-[var(--border-strong)] bg-[var(--bg-surface)] shadow-sm">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border-strong)] px-4 py-[18px] sm:px-[22px]">
        <h2 id="assignment-queue-heading" class="font-sans text-[14px] font-semibold text-[var(--text-ink)] [overflow-wrap:anywhere]">Delivery queue</h2>
        <p class="font-sans text-[11.5px] text-[var(--text-muted-2)]">Stage counts show parcels on this page</p>
    </div>
<div class="p-4 sm:p-[22px]">
@if ($shipments->isEmpty())
    <div class="flex flex-col items-center px-2 py-[50px] text-center">
        <span class="mb-[18px] flex h-[52px] w-[52px] items-center justify-center rounded-xl border border-[var(--pill-success-border)] bg-[var(--pill-success-bg)] text-[var(--status-success)]"><i data-lucide="package-check" class="h-[25px] w-[25px]" aria-hidden="true"></i></span>
        <h3 class="font-sans text-[16px] font-semibold text-[var(--text-ink)]">{{ $shipments->total() > 0 ? 'No assignments on this page' : 'No delivery assignments' }}</h3>
        <p class="mb-[22px] mt-2 max-w-[470px] font-sans text-[13px] leading-[1.75] text-[var(--text-muted-2)]">{{ $shipments->total() > 0 ? 'There are delivery assignments on an earlier page.' : 'Sorted parcels appear here for rider assignment and dispatch. Check parcel sorting to prepare the next delivery.' }}</p>
        <a href="{{ $shipments->total() > 0 ? $shipments->url(1) : route('logistics.parcel-sorting.index') }}" class="{{ $quietButton }}">{{ $shipments->total() > 0 ? 'Back to first page' : 'Go to parcel sorting' }}</a>
    </div>
@else
    <div role="tablist" aria-label="Delivery stages" class="mb-5 flex flex-wrap gap-x-6 border-b border-[var(--border-strong)]">
        @foreach ($stages as $key => $stage)
            @php
                $tabCount = $grouped->get($key, collect())->count();
            @endphp
            <a role="tab" href="#stage-{{ $key }}" data-ip-tab="{{ $key }}" aria-selected="{{ $key === $firstStage ? 'true' : 'false' }}" class="ip-tab -mb-px inline-flex min-h-11 shrink-0 items-center gap-2 whitespace-nowrap font-sans text-[13px] font-medium no-underline">
                {{ $stage['title'] }}
                <span class="ip-count {{ $stage['alert'] && $tabCount > 0 ? $stage['alert'].' ip-tinted' : '' }} rounded-full px-2 py-0.5 text-[11px] font-semibold tabular-nums">{{ $tabCount }}</span>
            </a>
        @endforeach
    </div>

    @foreach ($stages as $key => $stage)
        <section id="stage-{{ $key }}" role="tabpanel" data-ip-stage="{{ $key }}" @if ($key !== $firstStage) hidden @endif>
            @if (! $grouped->has($key))
                <p class="rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-8 text-center font-sans text-[13px] text-[var(--text-muted-2)]">{{ $stage['empty'] }}</p>
            @else
                @php
                    $stageCount = $grouped[$key]->count();
                @endphp
                <div class="mb-4 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                    <p class="font-sans text-[13px] text-[var(--text-muted-2)]">{{ $stage['help'] }}</p>
                    <p class="font-sans text-[11.5px] text-[var(--text-muted-2)]">Displaying {{ $stageCount }} {{ \Illuminate\Support\Str::plural('parcel', $stageCount) }} {{ $stage['showing'] }}{{ $shipments->hasPages() ? ' on this page' : '' }}</p>
                </div>
                @if ($key === 'rider' && $grouped[$key]->contains(fn ($s) => $s->status === OrderStatus::Sorted && ! $s->delivery_courier_id))
                    <form id="bulk-assign-form" method="POST" action="{{ route('logistics.parcel-sorting.bulkAssignDelivery') }}" class="mb-4 flex flex-wrap items-center gap-2 rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-3">
                        @csrf
                        <p class="font-sans text-[12.5px] font-semibold text-[var(--text-ink)]">Bulk assign selected parcels</p>
                        <select name="courier_id" required aria-label="Rider for the selected parcels" class="{{ $fieldClass }} max-w-[360px] flex-1 basis-[180px]">
                            <option value="">Choose a Rider</option>
                            @foreach ($availableCouriers as $candidate)
                                <option value="{{ $candidate->id }}">{{ $candidate->rider?->fullName() ?? $candidate->name }} · {{ $candidate->rider?->station?->name ?? 'No station' }} · {{ $candidate->activeWorkload() }}/{{ \App\Models\Rider\Courier::ACTIVE_CAPACITY }} jobs</option>
                            @endforeach
                        </select>
                        <button type="submit" class="{{ $primaryButton }}">Assign selected</button>
                        <p class="w-full font-sans text-[11.5px] text-[var(--text-muted-2)]">Each Rider can hold {{ \App\Models\Rider\Courier::ACTIVE_CAPACITY }} active jobs. Parcels over the limit are skipped and stay Unassigned.</p>
                    </form>
                @endif
                <ul class="space-y-3">
                    @foreach ($grouped[$key] as $shipment)
                        @php
                            $look = $looks($shipment, $key);
                            $addr = $shipment->order?->shipping_address ?? [];
                            $station = $shipment->destinationArea?->station ?? $shipment->deliveryCourier?->rider?->station;
                            $payment = $shipment->payment;
                            $weightGrams = $shipment->parcelWeightGrams();
                            $buyer = $shipment->order?->buyer;
                            $courier = $shipment->deliveryCourier;
                            $assignment = $shipment->deliveryAssignments->where('role', 'delivery')->last();
                            $audit = json_decode($assignment?->notes ?? '', true);
                            $canReassign = $shipment->delivery_courier_id && in_array($shipment->status, $reassignable, true);
                            $canRetry = SellerOrderStatusService::canRetryDelivery($shipment);
                            $isThisForm = (string) old('expected_courier_id') !== '' && (int) old('expected_courier_id') === (int) $shipment->delivery_courier_id;
                        @endphp
                        <li id="parcel-{{ $shipment->id }}" class="overflow-hidden rounded-xl border border-[var(--border-strong)] bg-[var(--bg-surface)] shadow-sm">
                            <div class="grid grid-cols-[minmax(0,1fr)_44px] items-center gap-x-4 gap-y-3 p-4 2xl:grid-cols-[minmax(0,1fr)_140px_140px_minmax(190px,240px)_44px]">
                                <div class="flex min-w-0 items-center gap-3">
                                    @if ($key === 'rider' && $shipment->status === OrderStatus::Sorted && ! $shipment->delivery_courier_id)
                                        <input type="checkbox" form="bulk-assign-form" name="seller_order_ids[]" value="{{ $shipment->id }}" aria-label="Select parcel #{{ $shipment->id }} for bulk assign" class="h-4 w-4 shrink-0 cursor-pointer accent-primary-700">
                                    @endif
                                    <span class="{{ $look['tone'] }} ip-tinted flex h-10 w-10 shrink-0 items-center justify-center rounded-lg">
                                        <i data-lucide="{{ $look['icon'] }}" class="h-5 w-5"></i>
                                    </span>
                                    <div class="min-w-0">
                                        <p class="flex flex-wrap items-center gap-x-2 gap-y-1 font-sans text-[14px] font-semibold text-[var(--text-ink)] [overflow-wrap:anywhere]">
                                            <span class="text-[var(--text-ink)]">#{{ $shipment->id }}</span>
                                            <span class="font-normal text-[var(--text-muted-2)]">·</span>
                                            <span class="text-[var(--text-ink)]">{{ $shipment->seller?->business_name ?? '—' }}</span>
                                            <span class="font-normal text-[var(--text-muted-2)]">·</span>
                                            <span class="ip-chip rounded-md px-2 py-0.5 text-[11.5px] font-medium">{{ $weightGrams > 0 ? $shipment->formattedParcelWeight() : 'No weight' }}</span>
                                        </p>
                                        <p class="mt-0.5 flex min-w-0 items-center gap-1 font-sans text-[12px] text-[var(--text-muted-2)]">
                                            <i data-lucide="map-pin" class="h-3 w-3 shrink-0"></i>
                                            <span class="truncate text-[var(--text-muted-2)]">{{ $buyer?->fullName() ?? '—' }} · {{ $addr['municipality'] ?? '—' }}</span>
                                        </p>
                                    </div>
                                </div>

                                <button type="button" data-ip-toggle aria-expanded="{{ $isThisForm ? 'true' : 'false' }}" aria-controls="parcel-{{ $shipment->id }}-details" aria-label="Details for parcel #{{ $shipment->id }}" title="Details" class="col-start-2 row-start-1 flex h-11 w-11 cursor-pointer items-center justify-center rounded-md text-[var(--text-muted-2)] hover:bg-[var(--bg-page)] 2xl:col-start-5">
                                    <i data-lucide="chevron-down" class="h-4 w-4"></i>
                                </button>

                                <div class="col-span-2 2xl:col-span-1">
                                    <span class="{{ $look['tone'] }} ip-tinted inline-flex rounded-md px-2.5 py-1 font-sans text-[11.5px] font-semibold">{{ $shipment->status->label() }}</span>
                                    <p class="mt-1 break-words font-sans text-[12.5px] {{ $courier ? 'text-[var(--text-ink)]' : 'italic text-[var(--text-muted-2)]' }}">
                                        {{ $courier ? ($courier->rider?->fullName() ?? 'Rider account not linked') : 'Unassigned' }}
                                    </p>
                                </div>

                                <div class="col-span-2 font-sans text-[12.5px] 2xl:col-span-1">
                                    <p class="text-[11px] text-[var(--text-muted-2)]">Cash on delivery</p>
                                    @if ($payment === null)
                                        <p class="text-[var(--text-muted-2)]">Not recorded</p>
                                    @elseif ($payment->method !== PaymentMethod::Cod)
                                        <p class="text-[var(--text-muted-2)]">Not COD</p>
                                    @elseif ($payment->collected_at !== null)
                                        <p class="ip-emerald ip-tone-text font-semibold">Collected {{ $payment->formattedAmount() }}</p>
                                    @elseif ($payment->status === PaymentStatus::Paid)
                                        <p class="ip-emerald ip-tone-text font-semibold">Paid</p>
                                    @else
                                        <p class="ip-amber ip-tone-text font-semibold">Due {{ $payment->formattedAmount() }}</p>
                                    @endif
                                </div>

                                <div class="col-span-2 flex flex-wrap items-center gap-2 2xl:col-span-1 2xl:justify-end">
                                    @if ($canReassign && $shipment->needs_reassignment)
                                        <button type="button" data-ip-open class="{{ $primaryButton }}">Choose new rider</button>
                                    @endif
                                    @if ($shipment->status === OrderStatus::Sorted && ! $shipment->delivery_courier_id)
                                        @include('Logistics.DeliveryAssignments._assign-rider-dialog', ['buttonClass' => $primaryButton])
                                    @endif
                                    @if ($shipment->status === OrderStatus::Sorted && $shipment->delivery_courier_id)
                                        <form method="POST" action="{{ route('logistics.parcel-sorting.assignDelivery', $shipment) }}">
                                            @csrf
                                            <button type="submit" class="{{ $shipment->needs_reassignment ? $quietButton : $primaryButton }}">Confirm delivery dispatch</button>
                                        </form>
                                    @elseif ($shipment->status === OrderStatus::AssignedToRider && ! $shipment->needs_reassignment)
                                        <p class="font-sans text-[12.5px] text-[var(--text-muted-2)]">Waiting for Rider to start</p>
                                    @elseif ($shipment->status === OrderStatus::OutForDelivery && ! $shipment->needs_reassignment)
                                        <p class="font-sans text-[12.5px] text-[var(--text-muted-2)]">Rider is completing the delivery</p>
                                    @elseif ($shipment->status === OrderStatus::OutForDelivery)
                                        <a href="{{ route('logistics.delivery-monitoring.show', $shipment) }}" class="{{ $quietButton }}">Report incident</a>
                                    @elseif ($shipment->status === OrderStatus::DeliveryFailed)
                                        @if ($canRetry)
                                            <button type="button" data-ip-open class="{{ $primaryButton }}">Schedule final attempt</button>
                                        @endif
                                        @if ($shipment->hasActiveReturn())
                                            <a href="{{ route('logistics.returns.show', $shipment->parcelReturn) }}" class="{{ $primaryButton }}">Continue physical return</a>
                                        @else
                                            <form method="POST" action="{{ route('logistics.delivery-assignments.return', $shipment) }}">
                                                @csrf
                                                <button type="submit" class="{{ $canRetry ? $quietButton : $dangerButton }}" onclick="return confirm('Request physical return transport? Completion requires verified Seller receipt.');">Request physical return</button>
                                            </form>
                                        @endif
                                    @elseif ($shipment->deliveryProof)
                                        <a data-proof-open data-proof-label="Parcel #{{ $shipment->id }}{{ $shipment->tracking_number ? ' - '.$shipment->tracking_number : '' }}" href="{{ route('logistics.proofs.show', $shipment->deliveryProof) }}" target="_blank" rel="noopener noreferrer" class="{{ $quietButton }}">
                                            <i data-lucide="image" class="h-4 w-4"></i>
                                            View proof
                                        </a>
                                    @endif
                                </div>
                            </div>

                            @if ($shipment->needs_reassignment || (! $courier && $shipment->status === OrderStatus::Sorted))
                                <div class="{{ $shipment->needs_reassignment ? 'ip-strip' : 'border-t border-[var(--border-strong)]' }} flex flex-wrap items-center justify-between gap-x-4 gap-y-2 px-4 py-3">
                                    @if ($shipment->needs_reassignment)
                                        <p role="status" class="flex min-w-0 flex-1 basis-[280px] items-start gap-2.5 font-sans text-[12px] text-[var(--text-ink)]">
                                            <span class="ip-warn flex h-5 w-5 shrink-0 items-center justify-center rounded-full">
                                                <i data-lucide="triangle-alert" class="h-3 w-3"></i>
                                            </span>
                                            <span class="text-[var(--text-ink)]"><strong class="font-semibold">Needs attention:</strong> {{ $shipment->reassignment_reason }}</span>
                                        </p>
                                    @endif
                                    @if (! $courier && $shipment->status === OrderStatus::Sorted)
                                        <form method="POST" action="{{ route('logistics.dispatch.retry', [$shipment, 'delivery']) }}" class="ml-auto shrink-0">
                                            @csrf
                                            <button type="submit" class="ip-link inline-flex min-h-11 cursor-pointer items-center gap-1.5 font-sans text-[12px] font-semibold">
                                                <i data-lucide="refresh-cw" class="h-3 w-3"></i>
                                                Retry automatic dispatch
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            @endif

                            @if ($shipment->status === OrderStatus::DeliveryFailed)
                                <div class="ip-rose flex items-start gap-2.5 border-t border-[var(--border-strong)] px-4 py-3 font-sans text-[12px] text-[var(--text-ink)]">
                                    <span class="ip-tinted flex h-5 w-5 shrink-0 items-center justify-center rounded-full">
                                        <i data-lucide="triangle-alert" class="h-3 w-3"></i>
                                    </span>
                                    <div class="min-w-0 space-y-0.5">
                                        <p class="text-[var(--text-ink)]">
                                            <strong class="font-semibold">Failed attempts: {{ (int) $shipment->delivery_attempts }} / {{ $maxAttempts }}.</strong>
                                            {{ DeliveryFailureReason::tryFrom((string) $shipment->delivery_failure_reason)?->label() ?? 'No reason recorded' }}
                                        </p>
                                        @if ($shipment->delivery_failure_notes)
                                            <p class="text-[var(--text-muted-2)]">{{ $shipment->delivery_failure_notes }}</p>
                                        @endif
                                        @if ($canRetry && ! $shipment->hasActiveReturn())
                                            @if ($shipment->isBackAtStationAfterFailure())
                                                <p class="text-[var(--text-muted-2)]">Back at the Station Hub since {{ $shipment->returned_to_station_at->timezone(StoreSale::TIMEZONE)->format('M j, g:i A') }}. The final attempt can go to any eligible Rider.</p>
                                            @else
                                                <p class="text-[var(--text-muted-2)]">Still with {{ $courier?->rider?->fullName() ?? 'the Rider' }}. Only they can take the final attempt until the parcel is <a href="{{ route('logistics.scan.index', array_filter(['tracking' => $shipment->tracking_number])) }}" class="font-semibold underline">scanned back in at the Station Hub</a>.</p>
                                            @endif
                                        @endif
                                        @if ((int) $shipment->delivery_attempts >= $maxAttempts)
                                            <p class="ip-tone-text font-semibold">Two attempts exhausted. Physical return required; verified Seller receipt is needed.</p>
                                        @elseif (! $canRetry && ! $shipment->hasActiveReturn())
                                            <p class="ip-tone-text font-semibold">Review the incomplete failure history before retrying.</p>
                                        @endif
                                        @if ($shipment->hasActiveReturn())
                                            <p class="text-[var(--text-muted-2)]">{{ $shipment->parcelReturn->status->label() }}</p>
                                        @endif
                                    </div>
                                </div>
                            @endif

                            <div id="parcel-{{ $shipment->id }}-details" data-ip-details @unless ($isThisForm) hidden @endunless>
                                <div class="grid gap-x-8 gap-y-4 border-t border-[var(--border-strong)] px-4 py-4 lg:grid-cols-2">
                                    <dl class="grid [overflow-wrap:anywhere] grid-cols-[90px_minmax(0,1fr)] sm:grid-cols-[100px_minmax(0,1fr)] gap-x-3 gap-y-1.5 font-sans text-[13px]">
                                        <dt class="{{ $labelClass }}">Tracking</dt>
                                        <dd class="[overflow-wrap:anywhere] font-mono text-[12px] text-[var(--text-ink)]">{{ $shipment->tracking_number ?? 'No tracking number' }}</dd>
                                        <dt class="{{ $labelClass }}">Station Hub</dt>
                                        <dd class="text-[var(--text-ink)]">{{ $station?->name ?? 'Not recorded' }} · {{ $station?->municipality ?? 'Location not recorded' }}</dd>
                                        <dt class="{{ $labelClass }}">Proof</dt>
                                        <dd class="text-[var(--text-ink)]">
                                            @if ($shipment->deliveryProof)
                                                Recorded
                                            @else
                                                Not recorded
                                            @endif
                                        </dd>
                                        @if ($courier)
                                            <dt class="{{ $labelClass }}">Vehicle</dt>
                                            <dd class="text-[var(--text-ink)]">{{ ucfirst($courier->vehicle_type) }}</dd>
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

                                    @if ($canReassign)
                                        <form method="POST" action="{{ route('logistics.delivery-assignments.reassign', $shipment) }}" class="max-w-[420px] space-y-2">
                                            @csrf
                                            <input type="hidden" name="expected_courier_id" value="{{ $shipment->delivery_courier_id }}">
                                            <p class="font-sans text-[13px] font-semibold text-[var(--text-ink)]">Reassign Rider</p>
                                            <select name="courier_id" aria-label="New rider for parcel #{{ $shipment->id }}" class="{{ $fieldClass }}">
                                                <option value="">Auto-assign eligible Rider</option>
                                                @foreach ($availableCouriers as $candidate)
                                                    <option value="{{ $candidate->id }}">{{ $candidate->rider?->fullName() ?? $candidate->name }} · {{ $candidate->rider?->station?->name ?? 'No station' }} · {{ $candidate->activeWorkload() }}/{{ \App\Models\Rider\Courier::ACTIVE_CAPACITY }} jobs · carrying {{ number_format($candidate->activeWeightGrams()) }} of {{ number_format($candidate->max_weight_grams) }} g</option>
                                                @endforeach
                                            </select>
                                            <input name="reason" required maxlength="500" aria-label="Reason for changing the rider" placeholder="Why are you changing the rider?" class="{{ $fieldClass }}" value="{{ $isThisForm ? old('reason') : '' }}">
                                            <button type="submit" class="{{ $shipment->needs_reassignment ? $primaryButton : $quietButton }}">Save new rider</button>
                                        </form>
                                    @elseif ($shipment->status === OrderStatus::OutForDelivery)
                                        <div class="max-w-[420px] space-y-1 font-sans text-[12.5px]">
                                            <p class="text-[13px] font-semibold text-[var(--text-ink)]">Changing the Rider</p>
                                            <p class="text-[var(--text-muted-2)]">{{ \App\Http\Controllers\Logistics\DeliveryAssignmentsController::OUT_FOR_DELIVERY_REASSIGN_MESSAGE }}</p>
                                        </div>
                                    @elseif ($shipment->status === OrderStatus::DeliveryFailed && $canRetry)
                                        <form method="POST" action="{{ route('logistics.delivery-assignments.reschedule', $shipment) }}" class="max-w-[420px] space-y-2">
                                            @csrf
                                            <p class="font-sans text-[13px] font-semibold text-[var(--text-ink)]">Final delivery attempt</p>
                                            @php
                                                // Custody-safe: the parcel stays with the attempting Rider until scanned back in.
                                                $finalCandidates = $shipment->isBackAtStationAfterFailure()
                                                    ? $availableCouriers
                                                    : $availableCouriers->where('id', $shipment->delivery_courier_id);
                                            @endphp
                                            <select name="courier_id" aria-label="Rider for the final attempt on parcel #{{ $shipment->id }}" class="{{ $fieldClass }}">
                                                <option value="">{{ $shipment->isBackAtStationAfterFailure() ? 'Auto-assign eligible Rider' : 'Same Rider (parcel still with them)' }}</option>
                                                @foreach ($finalCandidates as $candidate)
                                                    <option value="{{ $candidate->id }}">{{ $candidate->rider?->fullName() ?? $candidate->name }} · {{ $candidate->rider?->station?->name ?? 'No station' }} · carrying {{ number_format($candidate->activeWeightGrams()) }} of {{ number_format($candidate->max_weight_grams) }} g</option>
                                                @endforeach
                                            </select>
                                            <input name="reason" maxlength="500" aria-label="Reason for manually choosing this Rider" placeholder="Reason for a manual Rider choice" class="{{ $fieldClass }}">
                                            <button type="submit" class="{{ $primaryButton }}">Confirm final attempt</button>
                                        </form>
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
@if ($shipments->hasPages())
    <div class="mt-6 border-t border-[var(--border-strong)] pt-5">{{ $shipments->links() }}</div>
@endif
</div>
</section>
</div>
</section>

<dialog id="delivery-proof-dialog" aria-labelledby="delivery-proof-title" aria-describedby="delivery-proof-context" class="m-auto max-h-[90dvh] w-[calc(100%_-_2rem)] max-w-4xl overflow-hidden rounded-xl border border-[var(--border-strong)] bg-[var(--bg-surface)] p-0 text-[var(--text-ink)] shadow-xl backdrop:bg-black/50">
    <div class="flex max-h-[90dvh] flex-col">
        <header class="flex shrink-0 items-start justify-between gap-3 border-b border-[var(--border-strong)] px-4 py-3 sm:px-5">
            <div class="min-w-0">
                <h2 id="delivery-proof-title" class="font-serif text-xl text-[var(--text-ink)]">Delivery proof</h2>
                <p id="delivery-proof-context" class="mt-1 break-words font-sans text-xs text-[var(--text-ink)]/80"></p>
            </div>
            <button data-proof-close type="button" autofocus aria-label="Close delivery proof" class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-[7px] border border-[var(--border-btn-outline)] text-[var(--text-ink)] hover:bg-[var(--bg-raised)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-700"><i data-lucide="x" class="h-4 w-4" aria-hidden="true"></i></button>
        </header>
        <div class="min-h-0 flex-1 overflow-y-auto bg-[var(--bg-page)] p-3 sm:p-4">
            <p data-proof-loading role="status" class="py-12 text-center font-sans text-sm text-[var(--text-ink)]">Loading delivery proof...</p>
            <div data-proof-error class="hidden py-10 text-center font-sans">
                <p role="alert" class="text-sm text-[var(--text-ink)]">Unable to load this proof. Try again or open the original image.</p>
                <button data-proof-retry type="button" class="mt-4 {{ $quietButton }}"><i data-lucide="rotate-ccw" class="h-4 w-4" aria-hidden="true"></i>Try again</button>
            </div>
            <div data-proof-image class="flex items-center justify-center"></div>
        </div>
        <footer class="flex shrink-0 flex-wrap items-center justify-end gap-2 border-t border-[var(--border-strong)] px-4 py-3 sm:px-5">
            <a data-proof-original target="_blank" rel="noopener noreferrer" class="{{ $quietButton }}">Open original<i data-lucide="arrow-up-right" class="h-4 w-4" aria-hidden="true"></i></a>
            <button data-proof-close type="button" class="{{ $quietButton }}">Close</button>
        </footer>
    </div>
</dialog>
@endsection

@push('scripts')
<script>
(function () {
    document.addEventListener('click', function (event) {
        var opener = event.target.closest('[data-assign-open]');
        if (opener) {
            var dialog = document.getElementById(opener.getAttribute('data-assign-open'));
            if (dialog && typeof dialog.showModal === 'function') dialog.showModal();
            return;
        }

        var closer = event.target.closest('[data-assign-close]');
        if (closer) {
            closer.closest('dialog')?.close();
            return;
        }

        // A click on the dialog element itself (not its form content) is a backdrop click.
        if (event.target instanceof HTMLDialogElement && event.target.id !== 'delivery-proof-dialog' && event.target.open) {
            event.target.close();
        }
    });
})();

(function () {
    const dialog = document.getElementById('delivery-proof-dialog');
    if (!dialog || typeof dialog.showModal !== 'function') return;
    const context = dialog.querySelector('[data-proof-context]') || document.getElementById('delivery-proof-context');
    const loading = dialog.querySelector('[data-proof-loading]');
    const error = dialog.querySelector('[data-proof-error]');
    const imageHost = dialog.querySelector('[data-proof-image]');
    const original = dialog.querySelector('[data-proof-original]');
    let image;
    let requestId = 0;
    let currentUrl;
    let opener;
    let previousOverflow;

    const clearImage = () => {
        if (image) {
            image.onload = null;
            image.onerror = null;
            image.removeAttribute('src');
        }
        imageHost.replaceChildren();
        image = null;
    };
    const load = () => {
        const activeRequest = ++requestId;
        clearImage();
        loading.classList.remove('hidden');
        error.classList.add('hidden');
        imageHost.setAttribute('aria-busy', 'true');
        const nextImage = new Image();
        image = nextImage;
        nextImage.alt = 'Delivery proof for ' + context.textContent;
        nextImage.className = 'block max-h-[65dvh] max-w-full rounded-lg object-contain';
        nextImage.onload = () => {
            if (activeRequest !== requestId || !dialog.open) return;
            loading.classList.add('hidden');
            imageHost.removeAttribute('aria-busy');
            imageHost.replaceChildren(nextImage);
        };
        nextImage.onerror = () => {
            if (activeRequest !== requestId || !dialog.open) return;
            loading.classList.add('hidden');
            imageHost.removeAttribute('aria-busy');
            error.classList.remove('hidden');
        };
        nextImage.src = currentUrl;
    };

    document.querySelectorAll('a[data-proof-open]').forEach(link => {
        link.addEventListener('click', event => {
            if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
            const url = new URL(link.href, location.href);
            if (url.origin !== location.origin) return;
            event.preventDefault();
            opener = link;
            currentUrl = url.href;
            context.textContent = link.dataset.proofLabel;
            original.href = currentUrl;
            if (!dialog.open) {
                previousOverflow = document.documentElement.style.overflow;
                dialog.showModal();
                document.documentElement.style.overflow = 'hidden';
            }
            load();
        });
        link.setAttribute('aria-haspopup', 'dialog');
    });
    dialog.querySelectorAll('[data-proof-close]').forEach(button => button.addEventListener('click', () => dialog.close()));
    dialog.querySelector('[data-proof-retry]').addEventListener('click', load);
    dialog.addEventListener('click', event => {
        if (event.target !== dialog) return;
        const bounds = dialog.getBoundingClientRect();
        if (event.clientX < bounds.left || event.clientX > bounds.right
            || event.clientY < bounds.top || event.clientY > bounds.bottom) dialog.close();
    });
    dialog.addEventListener('close', () => {
        requestId++;
        clearImage();
        imageHost.removeAttribute('aria-busy');
        document.documentElement.style.overflow = previousOverflow;
        if (opener?.isConnected) opener.focus({ preventScroll: true });
    });
})();
</script>
@endpush
