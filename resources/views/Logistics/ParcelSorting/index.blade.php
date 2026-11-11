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

    $buttonBase = 'inline-flex min-h-11 cursor-pointer items-center justify-center gap-2 rounded-lg px-4 font-sans text-[12.5px] font-semibold no-underline transition-colors';
    $primaryButton = $buttonBase.' ip-primary';
    $quietButton = $buttonBase.' border border-[var(--border-strong)] bg-[var(--bg-surface)] text-[var(--text-ink)] hover:bg-[var(--bg-page)]';
    $labelClass = 'font-sans text-[12px] text-[var(--text-muted-2)]';
@endphp

<section class="ip-canvas min-w-0 [&_:is(a,button):focus-visible]:outline-2 [&_:is(a,button):focus-visible]:outline-offset-4 [&_:is(a,button):focus-visible]:outline-[var(--link)]" aria-labelledby="sorting-heading">
<x-logistics.tabs group="parcels" />
<header class="mb-6 flex flex-wrap items-center justify-between gap-x-6 gap-y-4">
    <div class="min-w-0">
        <p class="mb-2 font-sans text-[11px] font-semibold uppercase tracking-[0.12em] text-[var(--text-muted-2)]">Parcel operations</p>
        <h1 id="sorting-heading" class="font-serif text-[26px] leading-tight font-semibold text-[var(--text-ink)] sm:text-[30px]">Parcel Sorting</h1>
        <p class="mt-2 max-w-2xl font-sans text-[14px] leading-relaxed text-[var(--text-muted-2)]">Sort received parcels by destination and prepare them for delivery.</p>
    </div>
    <div class="flex shrink-0 items-center gap-3">
        <a href="{{ route('logistics.scan.index') }}" class="{{ $primaryButton }}">
            <i data-lucide="scan-line" class="h-4 w-4" aria-hidden="true"></i>
            Scan parcel
        </a>
        <x-logistics.notification-bell />
    </div>
</header>

<x-logistics-flash :messages="['success', 'warning', 'error']" />
@if ($errors->any())
    <div role="alert" class="mb-4 rounded-lg border border-[var(--border-error-soft)] bg-[var(--bg-error-soft)] p-4 font-sans text-[13px] text-[var(--status-danger)]">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>
@endif

<section aria-labelledby="sorting-queue-heading" class="overflow-hidden rounded-xl border border-[var(--border-strong)] bg-[var(--bg-surface)] shadow-sm">
<div class="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border-strong)] px-4 py-4 sm:px-[22px] sm:py-[18px]">
    <h2 id="sorting-queue-heading" class="font-sans text-[14px] font-semibold text-[var(--text-ink)] [overflow-wrap:anywhere]">{{ $tab === 'assigned' ? 'Assigned delivery queue' : 'Sorting queue' }}</h2>
    <p class="inline-flex items-center gap-2 font-sans text-[12px] text-[var(--text-muted-2)]">
        <i data-lucide="arrow-down-wide-narrow" class="h-3.5 w-3.5" aria-hidden="true"></i>
        By region, oldest first
    </p>
</div>
<div class="border-b border-[var(--border-strong)] bg-[var(--bg-raised)] px-4 sm:px-[22px]">
<nav aria-label="Parcel sorting queues" class="flex flex-wrap gap-x-6">
    @foreach ($tabs as $key => $item)
        <a href="{{ route('logistics.parcel-sorting.index', ['tab' => $key]) }}" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" @if ($tab === $key) aria-current="page" @endif class="ip-tab -mb-px inline-flex min-h-11 shrink-0 whitespace-nowrap items-center gap-2 font-sans text-[13px] font-medium no-underline">
            {{ $item['title'] }}
            <span class="ip-count rounded-full px-2 py-0.5 text-[11px] font-semibold tabular-nums">{{ $item['count'] }}</span>
        </a>
    @endforeach
</nav>
<p class="pb-3 pt-1 font-sans text-[11px] text-[var(--text-muted-2)]">Counts include all parcels held by your logistics provider.</p>
</div>

@if ($shipments->isEmpty())
    <div class="flex flex-col items-center px-[22px] py-[50px] text-center">
        <span class="mb-[18px] flex h-[52px] w-[52px] items-center justify-center rounded-xl border border-[var(--pill-success-border)] bg-[var(--pill-success-bg)] text-[var(--status-success)]">
            <i data-lucide="{{ $shipments->total() > 0 ? 'files' : ($tab === 'sorting' ? 'package-check' : 'bike') }}" class="h-[25px] w-[25px]" aria-hidden="true"></i>
        </span>
        <h3 class="font-sans text-[16px] font-semibold text-[var(--text-ink)]">
            @if ($shipments->total() > 0) No parcels on this page
            @elseif ($tab === 'sorting') No parcels at the sorting center
            @else No delivery assignments yet
            @endif
        </h3>
        <p class="mb-[22px] mt-2 max-w-[470px] font-sans text-[13px] leading-[1.75] text-[var(--text-muted-2)]">
            @if ($shipments->total() > 0)
                There are parcels in this queue on an earlier page.
            @elseif ($tab === 'sorting')
                Parcels received at your station appear here for sorting and routing. Check incoming parcels to follow pickups on their way.
            @else
                Parcels assigned to delivery riders appear here. Prepare rider assignments from the delivery assignment queue.
            @endif
        </p>
        @if ($shipments->total() > 0)
            <a href="{{ $shipments->url(1) }}" class="{{ $quietButton }}">Back to first page</a>
        @elseif ($tab === 'sorting')
            <a href="{{ route('logistics.incoming-parcels.index') }}" class="{{ $quietButton }}">
                View incoming parcels <i data-lucide="arrow-right" class="h-4 w-4" aria-hidden="true"></i>
            </a>
        @else
            <a href="{{ route('logistics.delivery-assignments.index') }}" class="{{ $quietButton }}">
                Go to Assign deliveries <i data-lucide="arrow-right" class="h-4 w-4" aria-hidden="true"></i>
            </a>
        @endif
    </div>
@else
    <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 px-4 py-4 sm:px-[22px]">
        <p class="font-sans text-[13px] text-[var(--text-muted-2)]">Grouped by destination region, oldest parcel first.</p>
        <p class="font-sans text-[11.5px] text-[var(--text-muted-2)]">Displaying {{ $shipments->count() }} {{ \Illuminate\Support\Str::plural('parcel', $shipments->count()) }}{{ $shipments->hasPages() ? ' on this page' : '' }}</p>
    </div>

    <div class="space-y-6 px-4 pb-5 sm:px-[22px]">
        @foreach ($regions as $region => $parcels)
            <section>
                <h2 class="mb-3 flex flex-wrap items-center gap-2 font-sans text-[12px] font-semibold text-[var(--text-ink)]">
                    <i data-lucide="map" class="h-3.5 w-3.5"></i>
                    {{ $region === 'zz' ? 'Region unavailable' : 'Destination region '.$region }}
                    <span class="font-normal text-[var(--text-muted-2)]">&middot; {{ $parcels->count() }} on this page</span>
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
                            <div class="grid grid-cols-[minmax(0,1fr)_44px] items-center gap-x-4 gap-y-3 p-4 2xl:grid-cols-[minmax(0,1fr)_170px_320px_44px]">
                                <div class="flex min-w-0 items-center gap-3">
                                    <span class="{{ $look['tone'] }} ip-tinted flex h-10 w-10 shrink-0 items-center justify-center rounded-lg">
                                        <i data-lucide="{{ $look['icon'] }}" class="h-5 w-5"></i>
                                    </span>
                                    <div class="min-w-0">
                                        <p class="flex flex-wrap items-center gap-x-2 gap-y-1 font-sans text-[14px] font-semibold text-[var(--text-ink)] [overflow-wrap:anywhere]">
                                            <span class="text-[var(--text-ink)]">#{{ $shipment->id }}</span>
                                            <span class="font-normal text-[var(--text-muted-2)]">·</span>
                                            <span class="text-[var(--text-ink)]">{{ $shipment->seller?->business_name ?? '—' }}</span>
                                        </p>
                                        <p class="mt-0.5 flex min-w-0 items-center gap-1 font-sans text-[12px] text-[var(--text-muted-2)]">
                                            <i data-lucide="map-pin" class="h-3 w-3 shrink-0"></i>
                                            <span class="truncate text-[var(--text-muted-2)]">{{ $buyer?->fullName() ?? '—' }} · {{ $addr['municipality'] ?? '—' }}</span>
                                        </p>
                                    </div>
                                </div>

                                <button type="button" data-ip-toggle aria-expanded="false" aria-controls="parcel-{{ $shipment->id }}-details" aria-label="Details for parcel #{{ $shipment->id }}" title="Details" class="col-start-2 row-start-1 flex h-11 w-11 cursor-pointer items-center justify-center rounded-md text-[var(--text-muted-2)] hover:bg-[var(--bg-page)] 2xl:col-start-4">
                                    <i data-lucide="chevron-down" class="h-4 w-4"></i>
                                </button>

                                <div class="col-span-2 2xl:col-span-1">
                                    <span class="{{ $look['tone'] }} ip-tinted inline-flex rounded-md px-2.5 py-1 font-sans text-[11.5px] font-semibold">{{ $shipment->status->label() }}</span>
                                    <p class="mt-1 break-words font-sans text-[12.5px] {{ $riderCourier ? 'text-[var(--text-ink)]' : 'italic text-[var(--text-muted-2)]' }}">
                                        {{ $riderCourier ? $riderCourier->name : 'No rider yet' }}
                                    </p>
                                </div>

                                <div class="col-span-2 flex flex-wrap items-center gap-2 2xl:col-span-1 2xl:justify-end">
                                    @if ($needsAddress)
                                        <a href="{{ route('logistics.parcel-sorting.address.preview', $shipment) }}" class="{{ $primaryButton }}">Verify delivery barangay</a>
                                    @endif

                                    @if ($tab === 'sorting' && ($sortingBlocked[$shipment->id] ?? null))
                                        <p class="max-w-[260px] font-sans text-[12px] text-[var(--text-muted-2)] 2xl:text-right">{{ $sortingBlocked[$shipment->id] }}</p>
                                    @elseif ($tab === 'sorting' && $shipment->status === OrderStatus::AtSortingCenter)
                                        <a href="{{ route('logistics.scan.index', array_filter(['tracking' => $shipment->tracking_number])) }}" class="{{ $needsAddress ? $quietButton : $primaryButton }}">Scan to confirm</a>
                                    @elseif ($tab === 'sorting' && $isSorted)
                                        <a href="{{ route('logistics.delivery-assignments.index') }}#stage-{{ $riderCourier ? 'dispatch' : 'rider' }}" class="{{ $needsAddress ? $quietButton : $primaryButton }}">
                                            Assign in Assign deliveries
                                            <i data-lucide="arrow-right" class="h-3.5 w-3.5"></i>
                                        </a>
                                    @elseif ($tab === 'assigned')
                                        <p class="font-sans text-[12.5px] text-[var(--text-muted-2)]">Waiting for Rider to start</p>
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
                                    <dl class="grid [overflow-wrap:anywhere] grid-cols-[90px_minmax(0,1fr)] sm:grid-cols-[110px_minmax(0,1fr)] gap-x-3 gap-y-1.5 font-sans text-[13px]">
                                        <dt class="{{ $labelClass }}">Tracking</dt>
                                        <dd class="font-mono text-[12px] text-[var(--text-ink)] [overflow-wrap:anywhere]">{{ $shipment->tracking_number ?? 'Pending' }}</dd>
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
                                                <a href="{{ route('logistics.delivery-monitoring.show', $shipment) }}" class="font-sans text-[13px] text-[var(--text-muted-2)] underline">Report incident</a>
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
@if ($shipments->total() > 0)
    <footer class="flex flex-wrap items-center justify-between gap-4 border-t border-[var(--border-strong)] px-4 py-[18px] sm:px-[22px] [&_nav]:max-w-full">
        <p class="font-sans text-[12px] text-[var(--text-muted-2)]">Showing <strong class="font-semibold text-[var(--text-ink)]">{{ $shipments->firstItem() ?? 0 }} to {{ $shipments->lastItem() ?? 0 }}</strong> of <strong class="font-semibold text-[var(--text-ink)]">{{ number_format($shipments->total()) }}</strong> parcels</p>
        {{ $shipments->links() }}
    </footer>
@endif
</section>
</section>
@endsection
