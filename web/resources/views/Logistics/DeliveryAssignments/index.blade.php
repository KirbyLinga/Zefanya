@extends('Layouts.logistics')

@section('title', 'Delivery Assignments')

@use('App\Enums\OrderStatus')
@use('App\Enums\DeliveryFailureReason')
@use('App\Enums\PaymentMethod')
@use('App\Enums\PaymentStatus')
@use('App\Models\Seller\StoreSale')
@use('App\Services\Seller\SellerOrderStatusService')

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

    $reassignable = [OrderStatus::Sorted, OrderStatus::AssignedToRider, OrderStatus::OutForDelivery];
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

    $buttonBase = 'inline-flex h-9 cursor-pointer items-center justify-center gap-2 rounded-lg px-4 font-sans text-[12.5px] font-semibold no-underline transition-colors';
    $primaryButton = $buttonBase.' ip-primary';
    $quietButton = $buttonBase.' border border-[var(--border-strong)] bg-[var(--bg-surface)] text-[var(--text-ink)] hover:bg-[var(--bg-page)]';
    $dangerButton = $buttonBase.' bg-rose-700 text-white hover:bg-rose-800';
    $fieldClass = 'ip-field h-9 w-full min-w-0 rounded-lg border border-[var(--border-strong)] px-2.5 font-sans text-[12.5px] text-[var(--text-ink)]';
    $labelClass = 'font-sans text-[12px] text-[var(--text-muted)]';
@endphp

<div class="ip-canvas -m-4 min-h-screen sm:-m-6 xl:-m-8">
<div class="border-b border-[var(--border-strong)] bg-[var(--bg-surface)] px-4 py-4 sm:px-6 xl:px-8">
    <h1 class="font-serif text-2xl font-semibold text-[var(--text-ink)]">Delivery Assignments</h1>
</div>

<div class="p-4 sm:p-6 xl:px-8">
<x-logistics-flash :messages="['success', 'warning', 'error']" />
@if ($errors->any())
    <div role="alert" class="mb-4 rounded-md border border-red-200 bg-red-50 p-3 text-xs text-red-900">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>
@endif

@if ($shipments->isEmpty())
    <section class="bg-[var(--bg-surface)] border border-[var(--border-strong)] rounded-lg p-8 shadow-sm flex flex-col items-center text-center">
        <i data-lucide="map-pin" class="w-[32px] h-[32px] text-[var(--icon-soft)] mb-3"></i>
        <p class="font-sans text-[13px] font-semibold text-[var(--text-ink)] mb-1">No delivery assignments</p>
        <p class="font-sans text-[11.5px] text-[var(--text-muted)] max-w-[360px]">Parcels assigned to your delivery Riders will appear here.</p>
    </section>
@else
    <div role="tablist" aria-label="Delivery stages" class="mb-5 flex flex-wrap gap-x-6 border-b border-[var(--border-strong)]">
        @foreach ($stages as $key => $stage)
            @php
                $tabCount = $grouped->get($key, collect())->count();
            @endphp
            <a role="tab" href="#stage-{{ $key }}" data-ip-tab="{{ $key }}" aria-selected="{{ $key === $firstStage ? 'true' : 'false' }}" class="ip-tab -mb-px inline-flex min-h-11 items-center gap-2 font-sans text-[13px] font-medium no-underline">
                {{ $stage['title'] }}
                <span class="ip-count {{ $stage['alert'] && $tabCount > 0 ? $stage['alert'].' ip-tinted' : '' }} rounded-full px-2 py-0.5 text-[11px] font-semibold tabular-nums">{{ $tabCount }}</span>
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
                            <div class="grid grid-cols-[minmax(0,1fr)_28px] items-center gap-x-4 gap-y-3 p-4 xl:grid-cols-[minmax(0,1fr)_140px_140px_minmax(190px,240px)_28px]">
                                <div class="flex min-w-0 items-center gap-3">
                                    <span class="{{ $look['tone'] }} ip-tinted flex h-10 w-10 shrink-0 items-center justify-center rounded-lg">
                                        <i data-lucide="{{ $look['icon'] }}" class="h-5 w-5"></i>
                                    </span>
                                    <div class="min-w-0">
                                        <p class="flex flex-wrap items-center gap-x-2 gap-y-1 font-sans text-[14px] font-semibold text-[var(--text-ink)]">
                                            <span class="text-[var(--text-ink)]">#{{ $shipment->id }}</span>
                                            <span class="font-normal text-[var(--text-muted)]">·</span>
                                            <span class="text-[var(--text-ink)]">{{ $shipment->seller?->business_name ?? '—' }}</span>
                                            <span class="font-normal text-[var(--text-muted)]">·</span>
                                            <span class="ip-chip rounded-md px-2 py-0.5 text-[11.5px] font-medium">{{ $weightGrams > 0 ? $shipment->formattedParcelWeight() : 'No weight' }}</span>
                                        </p>
                                        <p class="mt-0.5 flex min-w-0 items-center gap-1 font-sans text-[12px] text-[var(--text-muted)]">
                                            <i data-lucide="map-pin" class="h-3 w-3 shrink-0"></i>
                                            <span class="truncate text-[var(--text-muted)]">{{ $buyer?->fullName() ?? '—' }} · {{ $addr['municipality'] ?? '—' }}</span>
                                        </p>
                                    </div>
                                </div>

                                <button type="button" data-ip-toggle aria-expanded="{{ $isThisForm ? 'true' : 'false' }}" aria-controls="parcel-{{ $shipment->id }}-details" aria-label="Details for parcel #{{ $shipment->id }}" title="Details" class="col-start-2 row-start-1 flex h-7 w-7 cursor-pointer items-center justify-center rounded-md text-[var(--text-muted)] hover:bg-[var(--bg-page)] xl:col-start-5">
                                    <i data-lucide="chevron-down" class="h-4 w-4"></i>
                                </button>

                                <div class="col-span-2 xl:col-span-1">
                                    <span class="{{ $look['tone'] }} ip-tinted inline-flex rounded-full px-2.5 py-0.5 font-sans text-[11.5px] font-semibold">{{ $shipment->status->label() }}</span>
                                    <p class="mt-1 truncate font-sans text-[12.5px] {{ $courier ? 'text-[var(--text-ink)]' : 'italic text-[var(--text-muted)]' }}">
                                        {{ $courier ? ($courier->rider?->fullName() ?? 'Rider account not linked') : 'Unassigned' }}
                                    </p>
                                </div>

                                <div class="col-span-2 font-sans text-[12.5px] xl:col-span-1">
                                    <p class="text-[11px] text-[var(--text-muted)]">Cash on delivery</p>
                                    @if ($payment === null)
                                        <p class="text-[var(--text-muted)]">Not recorded</p>
                                    @elseif ($payment->method !== PaymentMethod::Cod)
                                        <p class="text-[var(--text-muted)]">Not COD</p>
                                    @elseif ($payment->collected_at !== null)
                                        <p class="ip-emerald ip-tone-text font-semibold">Collected {{ $payment->formattedAmount() }}</p>
                                    @elseif ($payment->status === PaymentStatus::Paid)
                                        <p class="ip-emerald ip-tone-text font-semibold">Paid</p>
                                    @else
                                        <p class="ip-amber ip-tone-text font-semibold">Due {{ $payment->formattedAmount() }}</p>
                                    @endif
                                </div>

                                <div class="col-span-2 flex flex-wrap items-center gap-2 xl:col-span-1 xl:justify-end">
                                    @if ($canReassign && $shipment->needs_reassignment)
                                        <button type="button" data-ip-open class="{{ $primaryButton }}">Choose new rider</button>
                                    @endif
                                    @if ($shipment->status === OrderStatus::Sorted && $shipment->delivery_courier_id)
                                        <form method="POST" action="{{ route('logistics.parcel-sorting.assignDelivery', $shipment) }}">
                                            @csrf
                                            <button type="submit" class="{{ $shipment->needs_reassignment ? $quietButton : $primaryButton }}">Confirm delivery dispatch</button>
                                        </form>
                                    @elseif ($shipment->status === OrderStatus::AssignedToRider && ! $shipment->needs_reassignment)
                                        <p class="font-sans text-[12.5px] text-[var(--text-muted)]">Waiting for Rider to start</p>
                                    @elseif ($shipment->status === OrderStatus::OutForDelivery && ! $shipment->needs_reassignment)
                                        <p class="font-sans text-[12.5px] text-[var(--text-muted)]">Rider is completing the delivery</p>
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
                                        <a href="{{ route('logistics.proofs.show', $shipment->deliveryProof) }}" target="_blank" rel="noopener noreferrer" class="{{ $quietButton }}">
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
                                            <button type="submit" class="ip-link inline-flex cursor-pointer items-center gap-1.5 font-sans text-[12px] font-semibold">
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
                                            <p class="text-[var(--text-muted)]">{{ $shipment->delivery_failure_notes }}</p>
                                        @endif
                                        @if ((int) $shipment->delivery_attempts >= $maxAttempts)
                                            <p class="ip-tone-text font-semibold">Two attempts exhausted. Physical return required; verified Seller receipt is needed.</p>
                                        @elseif (! $canRetry && ! $shipment->hasActiveReturn())
                                            <p class="ip-tone-text font-semibold">Review the incomplete failure history before retrying.</p>
                                        @endif
                                        @if ($shipment->hasActiveReturn())
                                            <p class="text-[var(--text-muted)]">{{ $shipment->parcelReturn->status->label() }}</p>
                                        @endif
                                    </div>
                                </div>
                            @endif

                            <div id="parcel-{{ $shipment->id }}-details" data-ip-details @unless ($isThisForm) hidden @endunless>
                                <div class="grid gap-x-8 gap-y-4 border-t border-[var(--border-strong)] px-4 py-4 lg:grid-cols-2">
                                    <dl class="grid grid-cols-[100px_minmax(0,1fr)] gap-x-3 gap-y-1.5 font-sans text-[13px]">
                                        <dt class="{{ $labelClass }}">Tracking</dt>
                                        <dd class="font-mono text-[12px] text-[var(--text-ink)]">{{ $shipment->tracking_number ?? 'No tracking number' }}</dd>
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
                                                    <option value="{{ $candidate->id }}">{{ $candidate->rider?->fullName() ?? $candidate->name }} · {{ $candidate->rider?->station?->name ?? 'No station' }} · carrying {{ number_format($candidate->activeWeightGrams()) }} of {{ number_format($candidate->max_weight_grams) }} g</option>
                                                @endforeach
                                            </select>
                                            <input name="reason" required maxlength="500" aria-label="Reason for changing the rider" placeholder="Why are you changing the rider?" class="{{ $fieldClass }}" value="{{ $isThisForm ? old('reason') : '' }}">
                                            <button type="submit" class="{{ $shipment->needs_reassignment ? $primaryButton : $quietButton }}">Save new rider</button>
                                        </form>
                                    @elseif ($shipment->status === OrderStatus::DeliveryFailed && $canRetry)
                                        <form method="POST" action="{{ route('logistics.delivery-assignments.reschedule', $shipment) }}" class="max-w-[420px] space-y-2">
                                            @csrf
                                            <p class="font-sans text-[13px] font-semibold text-[var(--text-ink)]">Final delivery attempt</p>
                                            <select name="courier_id" aria-label="Rider for the final attempt on parcel #{{ $shipment->id }}" class="{{ $fieldClass }}">
                                                <option value="">Auto-assign eligible Rider</option>
                                                @foreach ($availableCouriers as $candidate)
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
<div class="mt-4">{{ $shipments->links() }}</div>
</div>
</div>
@endsection
