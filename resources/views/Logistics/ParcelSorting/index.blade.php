@extends('Layouts.logistics')

@section('title', 'Parcel Sorting')

@use('App\Enums\OrderStatus')
@use('App\Models\Seller\StoreSale')

@section('logistics-toolbar')
    <span hidden data-logistics-hosted-bell></span>
@endsection

@section('content')
@include('Logistics._parcel-queue-assets')
@php
    $tabs = [
        'sorting' => ['title' => 'At sorting center', 'count' => $sortingCount],
        'assigned' => ['title' => 'Assigned to rider', 'count' => $assignedCount],
    ];
    $regions = $shipments->getCollection()->groupBy('destination_region_sort');

    $buttonBase = 'inline-flex h-9 cursor-pointer items-center justify-center gap-2 rounded-lg px-4 font-sans text-[12.5px] font-semibold no-underline transition-colors';
    $primaryButton = $buttonBase.' ip-primary';
    $quietButton = $buttonBase.' border border-[var(--border-strong)] bg-[var(--bg-surface)] text-[var(--text-ink)] hover:bg-[var(--bg-page)]';
    $labelClass = 'font-sans text-[12px] text-[var(--text-muted)]';
@endphp

<div class="ip-canvas -m-4 min-h-screen sm:-m-6 xl:-m-8">
<x-logistics.page-header title="Parcel Sorting">
    <a href="{{ route('logistics.scan.index') }}" class="inline-flex h-11 items-center justify-center gap-2 rounded-xl border border-[var(--border-strong)] bg-[var(--bg-surface)] px-3.5 font-sans text-[12.5px] font-semibold text-[var(--text-ink)] no-underline hover:bg-[var(--bg-page)]">
        <i data-lucide="barcode" class="h-4 w-4 text-[var(--text-muted)]"></i>
        Scan parcel
    </a>
</x-logistics.page-header>

<div class="p-4 sm:p-6 xl:px-8">
<x-logistics-flash :messages="['success', 'warning', 'error']" />
@if ($errors->any())
    <div role="alert" class="mb-4 rounded-md border border-red-200 bg-red-50 p-3 text-xs text-red-900">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>
@endif

<div class="mb-5 flex flex-wrap gap-x-6 border-b border-[var(--border-strong)]">
    @foreach ($tabs as $key => $item)
        <a href="{{ route('logistics.parcel-sorting.index', ['tab' => $key]) }}" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" class="ip-tab -mb-px inline-flex min-h-11 items-center gap-2 font-sans text-[13px] font-medium no-underline">
            {{ $item['title'] }}
            <span class="ip-count rounded-full px-2 py-0.5 text-[11px] font-semibold tabular-nums">{{ $item['count'] }}</span>
        </a>
    @endforeach
</div>

@if ($shipments->isEmpty())
    <section class="bg-[var(--bg-surface)] border border-[var(--border-strong)] rounded-lg p-8 shadow-sm flex flex-col items-center text-center">
        <i data-lucide="archive" class="w-[32px] h-[32px] text-[var(--icon-soft)] mb-3"></i>
        <p class="font-sans text-[13px] font-semibold text-[var(--text-ink)] mb-1">
            @if ($tab === 'sorting') No parcels at the sorting center
            @else No delivery assignments yet
            @endif
        </p>
        <p class="font-sans text-[11.5px] text-[var(--text-muted)] max-w-[360px]">
            @if ($tab === 'sorting')
                Parcels delivered to the sorting center by pickup riders will appear here for sorting and routing.
            @else
                Parcels assigned to delivery riders will appear here once sorted and routed.
            @endif
        </p>
    </section>
@else
    <div class="mb-4 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
        <p class="font-sans text-[13px] text-[var(--text-muted)]">Grouped by destination region, oldest parcel first.</p>
        <p class="font-sans text-[11.5px] text-[var(--text-muted)]">Displaying {{ $shipments->count() }} {{ \Illuminate\Support\Str::plural('parcel', $shipments->count()) }}{{ $shipments->hasPages() ? ' on this page' : '' }}</p>
    </div>

    <div class="space-y-5">
        @foreach ($regions as $region => $parcels)
            <section>
                <h2 class="mb-2 flex items-center gap-2 font-sans text-[12px] font-semibold text-[var(--text-muted)]">
                    <i data-lucide="map" class="h-3.5 w-3.5"></i>
                    {{ $region === 'zz' ? 'Region unavailable' : 'Destination region '.$region }}
                </h2>
                <ul class="space-y-3">
                    @foreach ($parcels as $shipment)
                        @php
                            $addr = $shipment->order?->shipping_address ?? [];
                            $buyer = $shipment->order?->buyer;
                            $riderCourier = $shipment->deliveryCourier;
                            $assignment = $shipment->deliveryAssignments->where('role', 'delivery')->last();
                            $isSorted = $shipment->status === OrderStatus::Sorted;
                            $needsAddress = blank($shipment->buyerBarangayCode()) && ! $riderCourier
                                && in_array($shipment->status, [OrderStatus::AtSortingCenter, OrderStatus::Sorted], true);
                            $canReportIncident = $shipment->handover_at !== null;
                            $look = match (true) {
                                $shipment->status === OrderStatus::AtSortingCenter => ['tone' => 'ip-maroon', 'icon' => 'scan-line'],
                                $needsAddress => ['tone' => 'ip-amber', 'icon' => 'map-pin'],
                                $isSorted && ! $riderCourier => ['tone' => 'ip-amber', 'icon' => 'user-plus'],
                                $isSorted => ['tone' => 'ip-maroon', 'icon' => 'package-open'],
                                default => ['tone' => 'ip-sky', 'icon' => 'clock'],
                            };
                        @endphp
                        <li id="parcel-{{ $shipment->id }}" class="overflow-hidden rounded-xl border border-[var(--border-strong)] bg-[var(--bg-surface)] shadow-sm">
                            <div class="grid grid-cols-[minmax(0,1fr)_28px] items-center gap-x-4 gap-y-3 p-4 xl:grid-cols-[minmax(0,1fr)_170px_320px_28px]">
                                <div class="flex min-w-0 items-center gap-3">
                                    <span class="{{ $look['tone'] }} ip-tinted flex h-10 w-10 shrink-0 items-center justify-center rounded-lg">
                                        <i data-lucide="{{ $look['icon'] }}" class="h-5 w-5"></i>
                                    </span>
                                    <div class="min-w-0">
                                        <p class="flex flex-wrap items-center gap-x-2 gap-y-1 font-sans text-[14px] font-semibold text-[var(--text-ink)]">
                                            <span class="text-[var(--text-ink)]">#{{ $shipment->id }}</span>
                                            <span class="font-normal text-[var(--text-muted)]">·</span>
                                            <span class="text-[var(--text-ink)]">{{ $shipment->seller?->business_name ?? '—' }}</span>
                                        </p>
                                        <p class="mt-0.5 flex min-w-0 items-center gap-1 font-sans text-[12px] text-[var(--text-muted)]">
                                            <i data-lucide="map-pin" class="h-3 w-3 shrink-0"></i>
                                            <span class="truncate text-[var(--text-muted)]">{{ $buyer?->fullName() ?? '—' }} · {{ $addr['municipality'] ?? '—' }}</span>
                                        </p>
                                    </div>
                                </div>

                                <button type="button" data-ip-toggle aria-expanded="false" aria-controls="parcel-{{ $shipment->id }}-details" aria-label="Details for parcel #{{ $shipment->id }}" title="Details" class="col-start-2 row-start-1 flex h-7 w-7 cursor-pointer items-center justify-center rounded-md text-[var(--text-muted)] hover:bg-[var(--bg-page)] xl:col-start-4">
                                    <i data-lucide="chevron-down" class="h-4 w-4"></i>
                                </button>

                                <div class="col-span-2 xl:col-span-1">
                                    <span class="{{ $look['tone'] }} ip-tinted inline-flex rounded-full px-2.5 py-0.5 font-sans text-[11.5px] font-semibold">{{ $shipment->status->label() }}</span>
                                    <p class="mt-1 truncate font-sans text-[12.5px] {{ $riderCourier ? 'text-[var(--text-ink)]' : 'italic text-[var(--text-muted)]' }}">
                                        {{ $riderCourier ? $riderCourier->name : 'No rider yet' }}
                                    </p>
                                </div>

                                <div class="col-span-2 flex flex-wrap items-center gap-2 xl:col-span-1 xl:justify-end">
                                    @if ($needsAddress)
                                        <a href="{{ route('logistics.parcel-sorting.address.preview', $shipment) }}" class="{{ $primaryButton }}">Verify delivery barangay</a>
                                    @endif

                                    @if ($tab === 'sorting' && ($sortingBlocked[$shipment->id] ?? null))
                                        <p class="max-w-[260px] font-sans text-[12px] text-[var(--text-muted)] xl:text-right">{{ $sortingBlocked[$shipment->id] }}</p>
                                    @elseif ($tab === 'sorting' && $shipment->status === OrderStatus::AtSortingCenter)
                                        <a href="{{ route('logistics.scan.index', array_filter(['tracking' => $shipment->tracking_number])) }}" class="{{ $needsAddress ? $quietButton : $primaryButton }}">Scan to confirm</a>
                                    @elseif ($tab === 'sorting' && $isSorted)
                                        <a href="{{ route('logistics.delivery-assignments.index') }}#stage-{{ $riderCourier ? 'dispatch' : 'rider' }}" class="{{ $needsAddress ? $quietButton : $primaryButton }}">
                                            Assign in Assign deliveries
                                            <i data-lucide="arrow-right" class="h-3.5 w-3.5"></i>
                                        </a>
                                    @elseif ($tab === 'assigned')
                                        <p class="font-sans text-[12.5px] text-[var(--text-muted)]">Waiting for Rider to start</p>
                                    @endif
                                </div>
                            </div>

                            @if ($shipment->needs_reassignment)
                                <div class="ip-strip flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3">
                                    <p role="status" class="flex min-w-0 flex-1 basis-[280px] items-start gap-2.5 font-sans text-[12px] text-[var(--text-ink)]">
                                        <span class="ip-warn flex h-5 w-5 shrink-0 items-center justify-center rounded-full">
                                            <i data-lucide="triangle-alert" class="h-3 w-3"></i>
                                        </span>
                                        <span class="text-[var(--text-ink)]"><strong class="font-semibold">Needs attention:</strong> {{ $shipment->reassignment_reason }}</span>
                                    </p>
                                </div>
                            @endif

                            <div id="parcel-{{ $shipment->id }}-details" data-ip-details hidden>
                                <div class="grid gap-x-8 gap-y-4 border-t border-[var(--border-strong)] px-4 py-4 lg:grid-cols-2">
                                    <dl class="grid grid-cols-[110px_minmax(0,1fr)] gap-x-3 gap-y-1.5 font-sans text-[13px]">
                                        <dt class="{{ $labelClass }}">Tracking</dt>
                                        <dd class="font-mono text-[12px] text-[var(--text-ink)]">{{ $shipment->tracking_number ?? 'Pending' }}</dd>
                                        <dt class="{{ $labelClass }}">Pickup rider</dt>
                                        <dd class="text-[var(--text-ink)]">{{ $shipment->courier ? $shipment->courier->name.' · '.ucfirst($shipment->courier->vehicle_type) : '—' }}</dd>
                                        @if ($shipment->destinationArea)
                                            <dt class="{{ $labelClass }}">Delivery area</dt>
                                            <dd class="text-[var(--text-ink)]">{{ $shipment->destinationArea->name }}</dd>
                                        @endif
                                        @if ($riderCourier)
                                            <dt class="{{ $labelClass }}">Delivery rider</dt>
                                            <dd class="text-[var(--text-ink)]">{{ $riderCourier->name }} · {{ ucfirst($riderCourier->vehicle_type) }}</dd>
                                            <dt class="{{ $labelClass }}">Rider's station</dt>
                                            <dd class="text-[var(--text-ink)]">{{ $riderCourier->rider?->station?->name ?? 'Station requires review' }}</dd>
                                            <dt class="{{ $labelClass }}">Workload</dt>
                                            <dd class="text-[var(--text-ink)]">{{ $riderCourier->activeWorkload() }}/{{ \App\Models\Rider\Courier::ACTIVE_CAPACITY }} · {{ $riderCourier->is_available ? 'Available' : 'Reserved / busy' }}</dd>
                                        @endif
                                        @if ($assignment)
                                            <dt class="{{ $labelClass }}">Assigned</dt>
                                            <dd class="text-[var(--text-ink)]">{{ $assignment->created_at->timezone(StoreSale::TIMEZONE)->format('M j, Y g:i A') }} · {{ $assignment->assigned_by_type === 'system' ? 'Automatic assignment' : 'Logistics #'.$assignment->assigned_by_id }}</dd>
                                        @endif
                                    </dl>

                                    @if ($canReportIncident)
                                        @can('advance-shipment', $shipment)
                                            <div>
                                                <a href="{{ route('logistics.delivery-monitoring.show', $shipment) }}" class="font-sans text-[13px] text-[var(--text-muted)] underline">Report incident</a>
                                            </div>
                                        @endcan
                                    @endif
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
    </div>
@endif
<div class="mt-4">{{ $shipments->links() }}</div>
</div>
</div>
@endsection
