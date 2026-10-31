@extends('Layouts.logistics')

@section('title', 'Dashboard')

@push('scripts')
    @vite('resources/js/logistics/notification-bell.js')
@endpush

@section('content')
    <x-logistics.dashboard-hero
        :name="$provider->business_name ?? $provider->fullName()"
        :date="now()->format('l, F j, Y')"
        :notifications="$notifications"
        :unread-count="$unreadCount"
    />

    <form method="GET" action="{{ route('logistics.dashboard') }}" class="mb-5 flex flex-wrap items-end gap-3">
        <div class="min-w-60">
            <label for="facility" class="mb-1 block font-sans text-[11px] font-semibold uppercase tracking-wide text-[var(--text-muted)]">Facility</label>
            <select id="facility" name="facility" class="h-10 w-full rounded-sm border border-[var(--border-strong)] bg-[var(--bg-surface)] px-3 font-sans text-sm text-[var(--text-ink)]">
                <option value="all" @selected($facilityKey === 'all')>All Facilities</option>
                <optgroup label="Province Hubs">
                    @foreach ($provinceHubs as $provinceHub)
                        <option value="province:{{ $provinceHub->id }}" @selected($facilityKey === 'province:'.$provinceHub->id)>{{ $provinceHub->name }}</option>
                    @endforeach
                </optgroup>
                <optgroup label="Station Hubs">
                    @foreach ($provinceHubs as $provinceHub)
                        @foreach ($provinceHub->stations as $station)
                            <option value="station:{{ $station->id }}" @selected($facilityKey === 'station:'.$station->id)>{{ $station->name }} · {{ $provinceHub->name }}</option>
                        @endforeach
                    @endforeach
                </optgroup>
            </select>
        </div>
        <div>
            <label for="dashboard-from" class="mb-1 block text-xs text-[var(--text-muted)]">From</label>
            <input id="dashboard-from" type="date" name="from" value="{{ $dateFrom }}" class="h-10 rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] px-3 text-sm text-[var(--text-ink)]">
        </div>
        <div>
            <label for="dashboard-to" class="mb-1 block text-xs text-[var(--text-muted)]">To (inclusive)</label>
            <input id="dashboard-to" type="date" name="to" value="{{ $dateTo }}" class="h-10 rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] px-3 text-sm text-[var(--text-ink)]">
        </div>
        @if ($metric)<input type="hidden" name="metric" value="{{ $metric }}">@endif
        <button type="submit" class="h-10 rounded-sm bg-primary-800 px-4 font-sans text-xs font-semibold uppercase text-white hover:bg-primary-900">Apply filters</button>
    </form>

    <p class="mb-4 text-xs text-[var(--text-muted)]">Dates include the full start and end days. Hub, transit and available-fleet results use the last updated date; deliveries use the delivery event date. Without dates, deliveries show today.</p>
    @if ($errors->any())<p role="alert" class="mb-4 text-sm text-red-800">{{ $errors->first() }}</p>@endif
    <section class="mb-6 rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-5 shadow-sm" aria-labelledby="networkSetupTitle">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <h2 id="networkSetupTitle" class="font-sans text-base font-semibold text-[var(--text-ink)]">Network setup</h2>
            @if ($operationsReady)
                <span class="rounded-full bg-green-50 px-3 py-1 font-sans text-[11px] font-semibold text-green-800">Ready for assignments</span>
            @else
                <span class="rounded-full bg-amber-50 px-3 py-1 font-sans text-[11px] font-semibold text-amber-800">Assignments locked until setup is complete</span>
            @endif
        </div>
        <ol class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($checklist['items'] as $item)
                <li class="flex min-h-12 items-center gap-3 rounded-sm border border-[var(--border-strong)] px-3 py-2">
                    <span class="flex size-7 shrink-0 items-center justify-center rounded-full {{ $item['complete'] ? 'bg-green-100 text-green-800' : 'bg-[var(--bg-page)] text-[var(--text-muted)]' }}">
                        <i data-lucide="{{ $item['complete'] ? 'check' : 'circle' }}" width="15" height="15"></i>
                    </span>
                    @if ($item['key'] === 'province_hub' || $item['key'] === 'station_hub')
                        <a href="{{ route('logistics.hubs.index') }}" class="min-w-0 flex-1 font-sans text-xs font-medium text-[var(--text-ink)] hover:text-primary-800">{{ $item['label'] }}</a>
                    @elseif ($item['key'] === 'coverage')
                        <a href="{{ route('logistics.areas.index') }}" class="min-w-0 flex-1 font-sans text-xs font-medium text-[var(--text-ink)] hover:text-primary-800">{{ $item['label'] }}</a>
                    @else
                        <span class="min-w-0 flex-1 font-sans text-xs font-medium text-[var(--text-ink)]">{{ $item['label'] }}</span>
                    @endif
                    <span class="font-sans text-xs text-[var(--text-muted)]">{{ $item['count'] }}</span>
                </li>
            @endforeach
        </ol>
    </section>

    {{-- ========================== STAT CARDS =========================== --}}
    <section class="grid grid-cols-4 gap-5 mb-6 max-lg:grid-cols-2 max-sm:grid-cols-1">
        <a href="{{ route('logistics.dashboard', array_filter(['facility' => $facilityKey, 'from' => $dateFrom, 'to' => $dateTo, 'metric' => 'hub'])) }}#metric-results" class="block rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-5 shadow-sm transition-colors hover:border-primary-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-700">
            <div class="w-[36px] h-[36px] rounded-md flex items-center justify-center mb-4 bg-primary-500 text-[#fff8f7]">
                <i data-lucide="inbox" class="w-[18px] h-[18px]"></i>
            </div>
            <p class="font-sans text-[12.5px] text-[var(--text-muted)] mb-1">Parcels in hub</p>
            <p class="font-sans text-[24px] font-extrabold text-[var(--text-ink)]">{{ number_format($parcelsInHub) }}</p>
            <p class="font-sans text-xs text-[var(--text-muted)] mt-1">At sorting center</p>
        </a>

        <a href="{{ route('logistics.dashboard', array_filter(['facility' => $facilityKey, 'from' => $dateFrom, 'to' => $dateTo, 'metric' => 'transit'])) }}#metric-results" class="block rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-5 shadow-sm transition-colors hover:border-primary-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-700">
            <div class="w-[36px] h-[36px] rounded-md flex items-center justify-center mb-4 bg-secondary-700 text-[#fff8f7]">
                <i data-lucide="truck" class="w-[18px] h-[18px]"></i>
            </div>
            <p class="font-sans text-[12.5px] text-[var(--text-muted)] mb-1">Parcels in transit</p>
            <p class="font-sans text-[24px] font-extrabold text-[var(--text-ink)]">{{ number_format($parcelsInTransit) }}</p>
            <p class="font-sans text-xs text-[var(--text-muted)] mt-1">Picked up, sorted, assigned, or out for delivery</p>
        </a>

        <a href="{{ route('logistics.dashboard', array_filter(['facility' => $facilityKey, 'from' => $dateFrom, 'to' => $dateTo, 'metric' => 'delivered'])) }}#metric-results" class="block rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-5 shadow-sm transition-colors hover:border-primary-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-700">
            <div class="w-[36px] h-[36px] rounded-md flex items-center justify-center mb-4 bg-warning text-[#fff8f7]">
                <i data-lucide="check-circle-2" class="w-[18px] h-[18px]"></i>
            </div>
            <p class="font-sans text-[12.5px] text-[var(--text-muted)] mb-1">{{ $dateFrom || $dateTo ? 'Delivered in period' : 'Delivered today' }}</p>
            <p class="font-sans text-[24px] font-extrabold text-[var(--text-ink)]">{{ number_format($deliveredToday) }}</p>
            <p class="font-sans text-xs text-[var(--text-muted)] mt-1">Unique parcels delivered or completed</p>
        </a>

        <a href="{{ route('logistics.dashboard', array_filter(['facility' => $facilityKey, 'from' => $dateFrom, 'to' => $dateTo, 'metric' => 'riders'])) }}#metric-results" class="block rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-5 shadow-sm transition-colors hover:border-primary-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-700">
            <div class="w-[36px] h-[36px] rounded-md flex items-center justify-center mb-4 bg-tertiary-500 text-[#fff8f7]">
                <i data-lucide="users" class="w-[18px] h-[18px]"></i>
            </div>
            <p class="font-sans text-[12.5px] text-[var(--text-muted)] mb-1">Available fleet riders</p>
            <p class="font-sans text-[24px] font-extrabold text-[var(--text-ink)]">{{ number_format($activeRiders) }}</p>
            <p class="font-sans text-xs text-[var(--text-muted)] mt-1">Available in this facility</p>
        </a>
    </section>

    @if ($metricResults !== null)
        <section id="metric-results" class="mb-6 scroll-mt-5 rounded-xl border border-[var(--border-strong)] bg-[var(--bg-surface)] p-5">
            <h2 class="text-base font-semibold text-[var(--text-ink)]">{{ ['hub' => 'Parcels in hub', 'transit' => 'Parcels in transit', 'delivered' => 'Delivered parcels', 'riders' => 'Available fleet riders'][$metric] }} ({{ $metricResults->total() }})</h2>
            <p class="mt-1 text-xs text-[var(--text-muted)]">Matches the selected dashboard card and filters.</p>
            <ul class="mt-4 divide-y divide-[var(--border-strong)]">
                @forelse ($metricResults as $result)
                    <li class="flex flex-wrap items-center justify-between gap-3 py-3 text-sm">
                        @if ($metric === 'riders')
                            <span class="font-semibold text-[var(--text-ink)]">{{ $result->name }}</span>
                            <span class="rounded-full bg-teal-50 px-3 py-1 text-xs text-teal-900">Available</span>
                        @else
                            <span class="font-mono text-[var(--text-ink)]">{{ $result->tracking_number ?? 'Order #'.$result->id }}</span>
                            <span class="rounded-full bg-primary-50 px-3 py-1 text-xs text-primary-900">{{ $result->status->label() }}</span>
                        @endif
                    </li>
                @empty
                    <li class="py-6 text-sm text-[var(--text-muted)]">No results match this card and date range.</li>
                @endforelse
            </ul>
            <div class="mt-4">{{ $metricResults->fragment('metric-results')->links() }}</div>
        </section>
    @endif
    <div class="grid grid-cols-[2fr_1fr] gap-5 mb-5 max-lg:grid-cols-1">

        {{-- ======================= DELIVERY ACTIVITY ====================== --}}
        {{-- Mirrors the seller dashboard's sales chart: a placeholder chart is
             NOT rendered — it would read as fake data, so an empty state
             stands in until the delivery pipeline exists. --}}
        <section class="bg-[var(--bg-surface)] border border-[var(--border-strong)] rounded-lg p-5 shadow-sm flex flex-col">
            <div class="flex items-center justify-between mb-5">
                <h2 class="font-sans text-base font-semibold text-[var(--text-ink)]">Delivery activity</h2>
                <span class="font-sans text-[11.5px] font-semibold text-[var(--text-muted)] bg-[var(--bg-chip)] rounded-full px-1 px-3">Last 7 days</span>
            </div>

            <div class="flex flex-col items-center justify-center text-center p-10 px-4">
                <i data-lucide="line-chart" class="w-[28px] h-[28px] text-[var(--icon-soft)] mb-3"></i>
                <p class="font-sans text-sm font-semibold text-[var(--text-ink)] mb-1">No delivery data yet</p>
                <p class="font-sans text-[12.5px] text-[var(--text-muted)] max-w-[320px]">
                    Delivery trends will appear here once parcels start moving through your hub.
                </p>
            </div>
        </section>

        {{-- ======================= RIDER APPLICATIONS ===================== --}}
        <section class="bg-[var(--bg-surface)] border border-[var(--border-strong)] rounded-lg p-5 shadow-sm">
            <div class="flex items-center justify-between mb-5">
                <h2 class="font-sans text-base font-semibold text-[var(--text-ink)]">Pending Rider applications</h2>
            </div>
            @if ($pendingApplications->isEmpty())
                <div class="flex flex-col items-center justify-center text-center p-6 px-4">
                    <i data-lucide="clipboard-check" class="w-[28px] h-[28px] text-[var(--icon-soft)] mb-3"></i>
                    <p class="font-sans text-sm font-semibold text-[var(--text-ink)] mb-1">No pending applications</p>
                </div>
            @else
                <ul class="divide-y divide-[var(--border-strong)]">
                    @foreach ($pendingApplications as $application)
                        <li class="flex items-center justify-between gap-3 py-3 first:pt-0">
                            <span class="min-w-0 truncate font-sans text-[12.5px] font-medium text-[var(--text-ink)]">{{ $application->first_name }} {{ $application->last_name }}</span>
                            <span class="shrink-0 font-sans text-[11px] text-[var(--text-muted)]">{{ $application->created_at->diffForHumans() }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>

    {{-- =========================== RECENT PARCELS ======================= --}}
    <section class="bg-[var(--bg-surface)] border border-[var(--border-strong)] rounded-lg p-5 shadow-sm">
        <div class="flex items-center justify-between mb-5">
            <h2 class="font-sans text-base font-semibold text-[var(--text-ink)]">Recent parcels</h2>
        </div>

        @if ($recentParcels->isEmpty())
            <div class="flex flex-col items-center justify-center text-center p-10 px-4">
                <i data-lucide="package" class="w-[28px] h-[28px] text-[var(--icon-soft)] mb-3"></i>
                <p class="font-sans text-sm font-semibold text-[var(--text-ink)] mb-1">No shipments for this facility</p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[520px] text-left font-sans">
                    <thead class="border-b border-[var(--border-strong)]">
                        <tr>
                            <th class="px-3 py-2 font-sans text-[10.5px] font-semibold uppercase text-[var(--text-muted)]">Shipment</th>
                            <th class="px-3 py-2 font-sans text-[10.5px] font-semibold uppercase text-[var(--text-muted)]">Seller</th>
                            <th class="px-3 py-2 font-sans text-[10.5px] font-semibold uppercase text-[var(--text-muted)]">Status</th>
                            <th class="px-3 py-2 font-sans text-[10.5px] font-semibold uppercase text-[var(--text-muted)]">Updated</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--border-strong)]">
                        @foreach ($recentParcels as $parcel)
                            <tr>
                                <td class="px-3 py-3 font-sans text-xs font-semibold text-[var(--text-ink)]">{{ $parcel->tracking_number }}</td>
                                <td class="px-3 py-3 font-sans text-xs text-[var(--text-muted)]">{{ $parcel->seller?->business_name ?? 'Seller' }}</td>
                                <td class="px-3 py-3 font-sans text-xs">
                                    @php
                                        $parcelStatusTone = match ($parcel->status?->name) {
                                            'Cancelled' => 'bg-red-50 text-red-900 ring-red-200',
                                            'DeliveryFailed' => 'bg-orange-50 text-orange-900 ring-orange-200',
                                            'Returned' => 'bg-stone-100 text-stone-900 ring-stone-300',
                                            'Completed' => 'bg-emerald-50 text-emerald-900 ring-emerald-200',
                                            'Delivered' => 'bg-teal-50 text-teal-900 ring-teal-200',
                                            'ReadyForPickup' => 'bg-sky-50 text-sky-900 ring-sky-200',
                                            'PickedUp' => 'bg-indigo-50 text-indigo-900 ring-indigo-200',
                                            'AtSortingCenter', 'Sorted' => 'bg-violet-50 text-violet-900 ring-violet-200',
                                            'AssignedToRider', 'OutForDelivery' => 'bg-blue-50 text-blue-900 ring-blue-200',
                                            'Placed', 'Confirmed', 'Preparing' => 'bg-amber-50 text-amber-900 ring-amber-200',
                                            default => 'bg-neutral-100 text-neutral-900 ring-neutral-200',
                                        };
                                    @endphp
                                    <span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-3 py-1.5 text-[11px] font-semibold ring-1 ring-inset {{ $parcelStatusTone }}">
                                        <span class="size-1.5 shrink-0 rounded-full bg-current text-inherit" aria-hidden="true"></span>
                                        <span class="text-inherit">{{ $parcel->status?->label() ?? 'Unknown' }}</span>
                                    </span>
                                </td>
                                <td class="px-3 py-3 font-sans text-xs text-[var(--text-muted)]">{{ $parcel->updated_at->diffForHumans() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection

