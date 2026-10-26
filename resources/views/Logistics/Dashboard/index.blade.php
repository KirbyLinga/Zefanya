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
        <button type="submit" class="h-10 rounded-sm bg-primary-800 px-4 font-sans text-xs font-semibold uppercase text-white hover:bg-primary-900">View facility</button>
    </form>

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
        <div class="bg-[var(--bg-surface)] border border-[var(--border-strong)] rounded-lg p-5 shadow-sm">
            <div class="w-[36px] h-[36px] rounded-md flex items-center justify-center mb-4 bg-primary-500 text-[#fff8f7]">
                <i data-lucide="inbox" class="w-[18px] h-[18px]"></i>
            </div>
            <p class="font-sans text-[12.5px] text-[var(--text-muted)] mb-1">Parcels in hub</p>
            <p class="font-sans text-[24px] font-extrabold text-[var(--text-ink)]">{{ number_format($parcelsInHub) }}</p>
            <p class="font-sans text-xs text-[var(--text-muted)] mt-1">At sorting center</p>
        </div>

        <div class="bg-[var(--bg-surface)] border border-[var(--border-strong)] rounded-lg p-5 shadow-sm">
            <div class="w-[36px] h-[36px] rounded-md flex items-center justify-center mb-4 bg-secondary-700 text-[#fff8f7]">
                <i data-lucide="truck" class="w-[18px] h-[18px]"></i>
            </div>
            <p class="font-sans text-[12.5px] text-[var(--text-muted)] mb-1">Parcels in transit</p>
            <p class="font-sans text-[24px] font-extrabold text-[var(--text-ink)]">{{ number_format($parcelsInTransit) }}</p>
            <p class="font-sans text-xs text-[var(--text-muted)] mt-1">Picked up, sorted, assigned, or out for delivery</p>
        </div>

        <div class="bg-[var(--bg-surface)] border border-[var(--border-strong)] rounded-lg p-5 shadow-sm">
            <div class="w-[36px] h-[36px] rounded-md flex items-center justify-center mb-4 bg-warning text-[#fff8f7]">
                <i data-lucide="check-circle-2" class="w-[18px] h-[18px]"></i>
            </div>
            <p class="font-sans text-[12.5px] text-[var(--text-muted)] mb-1">Delivered today</p>
            <p class="font-sans text-[24px] font-extrabold text-[var(--text-ink)]">{{ number_format($deliveredToday) }}</p>
            <p class="font-sans text-xs text-[var(--text-muted)] mt-1">Delivered or completed today</p>
        </div>

        <div class="bg-[var(--bg-surface)] border border-[var(--border-strong)] rounded-lg p-5 shadow-sm">
            <div class="w-[36px] h-[36px] rounded-md flex items-center justify-center mb-4 bg-tertiary-500 text-[#fff8f7]">
                <i data-lucide="users" class="w-[18px] h-[18px]"></i>
            </div>
            <p class="font-sans text-[12.5px] text-[var(--text-muted)] mb-1">Available fleet riders</p>
            <p class="font-sans text-[24px] font-extrabold text-[var(--text-ink)]">{{ number_format($activeRiders) }}</p>
            <p class="font-sans text-xs text-[var(--text-muted)] mt-1">Available in this facility</p>
        </div>
    </section>

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
                                <td class="px-3 py-3 font-sans text-xs text-[var(--text-ink)]">{{ $parcel->status?->label() ?? 'Unknown' }}</td>
                                <td class="px-3 py-3 font-sans text-xs text-[var(--text-muted)]">{{ $parcel->updated_at->diffForHumans() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection

