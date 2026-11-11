@extends('Layouts.logistics')

@section('title', 'Dashboard')

@section('content')
    @php
        $quietAction = 'inline-flex min-h-9 shrink-0 items-center justify-center gap-1.5 rounded-[7px] border border-[var(--border-btn-outline)] bg-[var(--bg-surface)] px-3 font-sans text-xs font-semibold text-[var(--text-ink)] no-underline shadow-sm transition-colors hover:bg-[var(--bg-raised)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-700';
        $waitingQueues = collect($actionQueues)->where('count', '>', 0);
        $quietQueues = collect($actionQueues)->where('count', 0);
    @endphp
    <header class="mb-4 flex items-start justify-between gap-3">
        <div class="min-w-0 flex-1">
            <h1 class="font-serif text-2xl text-[var(--text-ink)] sm:text-[28px]">Welcome back, {{ $provider->business_name ?? $provider->fullName() }}</h1>
            <p class="mt-1 font-sans text-[13px] text-[var(--text-ink)]/80">Review your network, move parcels forward, and resolve the work waiting on you.</p>
            <p class="mt-2 inline-flex items-center gap-1.5 font-sans text-xs text-[var(--text-ink)]/80"><i data-lucide="calendar" class="size-3.5" aria-hidden="true"></i>{{ now()->format('l, F j, Y') }}</p>
        </div>
        <div class="flex shrink-0 items-center gap-2">
            <button type="button" data-theme-toggle class="sp-icon-btn zf-theme-toggle" aria-label="Switch to dark mode" aria-pressed="false">
                <i data-lucide="moon" class="zf-icon-moon block h-[18px] w-[18px]" aria-hidden="true"></i>
                <i data-lucide="sun" class="zf-icon-sun block h-[18px] w-[18px]" aria-hidden="true"></i>
            </button>
            <x-logistics.notification-bell />
        </div>
    </header>

    <x-logistics.overdue-transfers :transfers="$overdueTransfers" :total="$overdueTransferCount" />

    <form method="GET" action="{{ route('logistics.dashboard') }}" class="mb-3 grid grid-cols-1 items-end gap-3 rounded-xl sm:grid-cols-2 xl:grid-cols-[minmax(0,1fr)_180px_180px_auto] border border-[var(--border-strong)] bg-[var(--bg-surface)] p-4 shadow-sm">
        <div class="min-w-0">
            <label for="facility" class="mb-1.5 block font-sans text-xs font-semibold text-[var(--text-ink)]">Facility</label>
            <select id="facility" name="facility" class="h-10 w-full rounded-[7px] border border-[var(--border-input)] bg-[var(--bg-surface)] px-3 font-sans text-sm text-[var(--text-ink)]">
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
        <div class="min-w-0">
            <label for="dashboard-from" class="mb-1.5 block font-sans text-xs font-semibold text-[var(--text-ink)]">From</label>
            <input id="dashboard-from" type="date" name="from" value="{{ $dateFrom }}" class="h-10 w-full min-w-0 rounded-[7px] border border-[var(--border-input)] bg-[var(--bg-surface)] px-3 text-sm text-[var(--text-ink)]">
        </div>
        <div class="min-w-0">
            <label for="dashboard-to" class="mb-1.5 block font-sans text-xs font-semibold text-[var(--text-ink)]">To (inclusive)</label>
            <input id="dashboard-to" type="date" name="to" value="{{ $dateTo }}" class="h-10 w-full min-w-0 rounded-[7px] border border-[var(--border-input)] bg-[var(--bg-surface)] px-3 text-sm text-[var(--text-ink)]">
        </div>
        @if ($metric)<input type="hidden" name="metric" value="{{ $metric }}">@endif
        <button type="submit" class="h-10 w-full rounded-[7px] bg-primary-800 sm:w-auto px-4 font-sans text-xs font-semibold text-white hover:bg-primary-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-700">Apply filters</button>
    </form>

    <details class="mb-4 font-sans">
        <summary class="cursor-pointer text-xs font-medium text-[var(--text-ink)]/80 focus-visible:outline-2 focus-visible:outline-primary-700">Metrics and work queues follow the selected facility and dates. View date rules.</summary>
        <p class="mt-2 text-xs leading-relaxed text-[var(--text-ink)]/80">Dates include the full start and end days. Hub, transit and available-rider results use the last updated date; deliveries use the delivery event date. Without dates, deliveries show today.</p>
    </details>
    @if ($errors->any())<p role="alert" class="mb-4 text-sm text-red-800">{{ $errors->first() }}</p>@endif
    <section class="mb-4 rounded-xl border border-[var(--border-strong)] bg-[var(--bg-surface)] p-4 shadow-sm" aria-labelledby="networkSetupTitle">
        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
            <h2 id="networkSetupTitle" class="font-sans text-sm font-semibold text-[var(--text-ink)]">Network setup</h2>
            @if ($operationsReady)
                <span class="rounded-full bg-green-50 px-3 py-1 font-sans text-[11px] font-semibold text-green-800">Ready for assignments</span>
            @else
                <span class="rounded-full bg-amber-50 px-3 py-1 font-sans text-[11px] font-semibold text-amber-800">Assignments locked until setup is complete</span>
            @endif
        </div>
        @php
            // What each step means and where to do it. The Province Hub is provisioned by Admin.
            $setupSteps = [
                'province_hub' => ['help' => 'Admin creates and approves your Province Hub after verifying your province.', 'action' => null, 'waiting' => 'Waiting for Admin'],
                'station_hub' => ['help' => 'Add the local Station Hubs your riders work from. Admin approves each one.', 'action' => ['Add Station Hub', route('logistics.hubs.index')]],
                'coverage' => ['help' => 'Choose the barangays each Station Hub picks up from and delivers to.', 'action' => ['Add delivery areas', route('logistics.areas.index').'#coverage-form']],
                'approved_rider' => ['help' => 'Approve a rider and link them to a Station Hub so parcels can be assigned.', 'action' => ['Review riders', route('logistics.riders.index')]],
            ];
            $nextStep = collect($checklist['items'])->firstWhere('complete', false)['key'] ?? null;
        @endphp
        <p class="mb-2 font-sans text-xs text-[var(--text-ink)]/80">Company-wide readiness, independent of facility and date filters.</p>
        <details @unless ($operationsReady) open @endunless class="group">
            <summary class="cursor-pointer list-none font-sans text-xs font-semibold text-[var(--text-ink)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-700 [&::-webkit-details-marker]:hidden">
                <span class="text-[var(--text-ink)] group-open:hidden">Show setup steps</span>
                <span class="hidden text-[var(--text-ink)] group-open:inline">{{ $operationsReady ? 'Hide setup steps' : 'Finish these steps in order to start receiving parcels.' }}</span>
            </summary>
            <ol class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ($checklist['items'] as $item)
                    @php
                        $step = $setupSteps[$item['key']] ?? ['help' => null, 'action' => null];
                        $isNext = $item['key'] === $nextStep;
                    @endphp
                    <li class="flex flex-col gap-2 rounded-md border p-3 {{ $isNext ? 'border-primary-300 bg-primary-50/40' : 'border-[var(--border-strong)]' }}">
                        <div class="flex items-start gap-3">
                            <span class="flex size-7 shrink-0 items-center justify-center rounded-full font-sans text-xs font-semibold {{ $item['complete'] ? 'bg-green-100 text-green-800' : 'bg-[var(--bg-page)] text-[var(--text-ink)]/80' }}">
                                @if ($item['complete'])<i data-lucide="check" width="15" height="15"></i>@else{{ $loop->iteration }}@endif
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="font-sans text-xs font-semibold text-[var(--text-ink)]">{{ $item['label'] }}</p>
                                @if ($step['help'])<p class="mt-0.5 font-sans text-[11px] leading-relaxed text-[var(--text-ink)]/80">{{ $step['help'] }}</p>@endif
                            </div>
                            <span class="font-sans text-xs text-[var(--text-ink)]/80">{{ $item['count'] }}</span>
                        </div>
                        @if (! $item['complete'])
                            @if ($step['action'])
                                <a href="{{ $step['action'][1] }}" class="mt-auto inline-flex min-h-9 items-center justify-center gap-1.5 rounded-md px-3 font-sans text-xs font-semibold {{ $isNext ? 'bg-primary-800 text-white hover:bg-primary-900' : 'border border-[var(--border-strong)] text-[var(--text-ink)] hover:bg-[var(--bg-page)]' }}">{{ $step['action'][0] }}<i data-lucide="arrow-right" class="size-3.5"></i></a>
                            @else
                                <span class="mt-auto inline-flex min-h-9 items-center justify-center rounded-md bg-amber-50 px-3 font-sans text-xs font-semibold text-amber-800">{{ $step['waiting'] ?? 'Pending' }}</span>
                            @endif
                        @endif
                    </li>
                @endforeach
            </ol>
            <p class="mt-3 font-sans text-[11px] text-[var(--text-ink)]/80">
                Also set before going live:
                <a href="{{ route('logistics.earnings.index', ['tab' => 'shipping-rates']) }}" class="font-semibold text-primary-800 hover:underline">shipping rates</a>,
                <a href="{{ route('logistics.partnerships.index') }}" class="font-semibold text-primary-800 hover:underline">provider partnerships</a> and
                <a href="{{ route('logistics.transport-connections.index') }}" class="font-semibold text-primary-800 hover:underline">transport connections</a>
                for parcels that leave your province.
            </p>
        </details>
    </section>

    @php
        $metricCards = [
            ['key' => 'hub', 'label' => 'Parcels in hub', 'value' => $parcelsInHub, 'hint' => 'At sorting center', 'icon' => 'inbox', 'rail' => 'border-l-primary-700'],
            ['key' => 'transit', 'label' => 'Parcels in transit', 'value' => $parcelsInTransit, 'hint' => 'Picked up, sorted, assigned, or out for delivery', 'icon' => 'truck', 'rail' => 'border-l-sky-500'],
            ['key' => 'delivered', 'label' => $dateFrom || $dateTo ? 'Delivered in period' : 'Delivered today', 'value' => $deliveredToday, 'hint' => 'Unique parcels delivered or completed', 'icon' => 'check-circle-2', 'rail' => 'border-l-emerald-500'],
            ['key' => 'riders', 'label' => 'Available riders', 'value' => $activeRiders, 'hint' => 'Available in this facility', 'icon' => 'users', 'rail' => 'border-l-amber-500'],
        ];
    @endphp
    <section aria-label="Facility metrics" class="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($metricCards as $card)
            <a href="{{ route('logistics.dashboard', array_filter(['facility' => $facilityKey, 'from' => $dateFrom, 'to' => $dateTo, 'metric' => $card['key']])) }}#metric-results" @if ($metric === $card['key']) aria-current="page" @endif class="relative min-w-0 overflow-hidden rounded-xl border border-l-4 border-[var(--border-strong)] {{ $card['rail'] }} bg-[var(--bg-surface)] p-4 no-underline shadow-sm transition-colors hover:border-primary-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-700 aria-[current=page]:ring-2 aria-[current=page]:ring-primary-300">
                <span class="absolute top-4 right-4 flex size-9 items-center justify-center rounded-lg bg-[var(--bg-raised)] text-[var(--link)]"><i data-lucide="{{ $card['icon'] }}" class="size-[18px]" aria-hidden="true"></i></span>
                <p class="pr-11 font-sans text-[13px] font-medium text-[var(--text-ink)]/80">{{ $card['label'] }}</p>
                <p class="mt-3 font-serif text-[28px] font-bold leading-tight text-[var(--text-ink)]">{{ number_format($card['value']) }}</p>
                <p class="mt-1 font-sans text-xs leading-relaxed text-[var(--text-ink)]/80">{{ $card['hint'] }}</p>
            </a>
        @endforeach
    </section>

    @if ($metricResults !== null)
        <section id="metric-results" class="mb-6 scroll-mt-5 rounded-xl border border-[var(--border-strong)] bg-[var(--bg-surface)] p-5">
            <h2 class="text-base font-semibold text-[var(--text-ink)]">{{ ['hub' => 'Parcels in hub', 'transit' => 'Parcels in transit', 'delivered' => 'Delivered parcels', 'riders' => 'Available fleet riders', 'stalled' => 'Shipments with no update for 24 hours'][$metric] }} ({{ $metricResults->total() }})</h2>
            <p class="mt-1 text-xs text-[var(--text-ink)]/80">Matches the selected dashboard card and filters.</p>
            <ul class="mt-4 divide-y divide-[var(--border-strong)]">
                @forelse ($metricResults as $result)
                    <li class="flex flex-wrap items-center justify-between gap-3 py-3 text-sm">
                        @if ($metric === 'riders')
                            <span class="font-semibold text-[var(--text-ink)]">{{ $result->name }}</span>
                            <span class="rounded-full bg-teal-50 px-3 py-1 text-xs text-teal-900">Available</span>
                        @else
                            <a href="{{ route('logistics.delivery-monitoring.show', $result) }}" class="font-mono font-semibold text-[var(--text-ink)] underline">{{ $result->tracking_number ?? 'Order #'.$result->id }}</a>
                            <span class="rounded-full bg-primary-50 px-3 py-1 text-xs text-primary-900">{{ $result->status->label() }}</span>
                        @endif
                    </li>
                @empty
                    <li class="py-6 text-sm text-[var(--text-ink)]/80">No results match this card and date range.</li>
                @endforelse
            </ul>
            <div class="mt-4">{{ $metricResults->fragment('metric-results')->links() }}</div>
        </section>
    @endif
    <div class="mb-4 grid items-start gap-4 xl:grid-cols-[minmax(0,2fr)_minmax(280px,1fr)]">

        <section class="min-w-0 rounded-xl border border-[var(--border-strong)] bg-[var(--bg-surface)] p-4 shadow-sm" aria-label="Operational queues">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="font-sans text-sm font-semibold text-[var(--text-ink)]">Work needing attention</h2>
                <p class="font-sans text-xs text-[var(--text-ink)]/80">{{ $waitingQueues->count() }} {{ Str::plural('queue', $waitingQueues->count()) }} with work</p>
            </div>
            <p class="mt-1 text-xs leading-relaxed text-[var(--text-ink)]/80">Queues use the selected facility and the last update date filters. Incoming transfers remain with their sending provider until verified receipt.</p>
            @foreach (['waiting' => $waitingQueues, 'quiet' => $quietQueues] as $group => $queues)
                @if ($queues->isNotEmpty())
                    @if ($group === 'quiet')
                        <details class="mt-3 rounded-lg border border-[var(--border-strong)] bg-[var(--bg-raised)] px-3 py-2.5">
                            <summary class="cursor-pointer font-sans text-xs font-semibold text-[var(--text-ink)]">Queues with nothing waiting ({{ $queues->count() }})</summary>
                    @endif
                    <ul class="mt-2 divide-y divide-[var(--border-strong)]">
                        @foreach ($queues as $queue)
                            <li data-operation-queue="{{ $queue['key'] }}" data-queue-count="{{ $queue['count'] }}" class="flex min-w-0 items-center gap-3 py-3">
                                <div class="min-w-0 flex-1">
                                    <p class="font-sans text-[13px] font-semibold text-[var(--text-ink)]">{{ $queue['label'] }}</p>
                                    <p class="mt-0.5 font-sans text-xs leading-relaxed text-[var(--text-ink)]/80">{{ $queue['hint'] }}</p>
                                </div>
                                <span class="shrink-0 font-serif text-xl font-bold tabular-nums text-[var(--text-ink)]">{{ number_format($queue['count']) }}</span>
                                <a href="{{ $queue['url'] }}" class="{{ $quietAction }}">Review<span class="sr-only"> {{ $queue['label'] }}</span></a>
                            </li>
                        @endforeach
                    </ul>
                    @if ($group === 'quiet')</details>@endif
                @endif
            @endforeach
            @if (collect($actionQueues)->sum('count') === 0)
                <p class="mt-4 text-sm font-semibold text-[var(--text-ink)]">No pending operational work matches these filters.</p>
                @if ($recentParcels->isEmpty())<p class="mt-1 text-xs text-[var(--text-ink)]/80">No delivery data yet</p>@endif
                <a href="{{ route('logistics.dashboard') }}" class="mt-2 inline-block text-sm font-semibold text-primary-800 underline">View all facilities and dates</a>
            @endif
        </section>

        {{-- ======================= RIDER APPLICATIONS ===================== --}}
        <section class="min-w-0 rounded-xl border border-[var(--border-strong)] bg-[var(--bg-surface)] p-4 shadow-sm">
            <div class="mb-2 flex items-center justify-between gap-3">
                <h2 class="font-sans text-sm font-semibold text-[var(--text-ink)]">Pending Rider applications</h2>
            </div>
            <p class="mb-3 font-sans text-xs text-[var(--text-ink)]/80">Latest {{ $pendingApplications->count() }} verified applications across your company.</p>
            @if ($pendingApplications->isEmpty())
                <div class="rounded-lg bg-[var(--bg-raised)] px-4 py-5 text-center">
                    <p class="font-sans text-sm font-semibold text-[var(--text-ink)] mb-1">No pending applications</p>
                </div>
            @else
                <ul class="divide-y divide-[var(--border-strong)]">
                    @foreach ($pendingApplications as $application)
                        <li class="flex items-center justify-between gap-3 py-3 first:pt-0">
                            <span class="min-w-0 truncate font-sans text-[12.5px] font-medium text-[var(--text-ink)]">{{ $application->first_name }} {{ $application->last_name }}</span>
                            <span class="shrink-0 font-sans text-[11px] text-[var(--text-ink)]/80">{{ $application->created_at->diffForHumans() }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
            <a href="{{ route('logistics.riders.index') }}" class="mt-3 {{ $quietAction }}">Review riders<i data-lucide="arrow-up-right" class="size-3.5" aria-hidden="true"></i></a>
        </section>
    </div>

    {{-- =========================== RECENT PARCELS ======================= --}}
    <section class="min-w-0 overflow-hidden rounded-xl border border-[var(--border-strong)] bg-[var(--bg-surface)] shadow-sm">
        <div class="border-b border-[var(--border-strong)] px-4 py-3">
            <h2 class="font-sans text-sm font-semibold text-[var(--text-ink)]">Recent parcels</h2>
            <p class="mt-1 font-sans text-xs text-[var(--text-ink)]/80">Latest {{ $recentParcels->count() }} parcels for this facility. Date filters do not apply to this list.</p>
        </div>

        @if ($recentParcels->isEmpty())
            <div class="px-4 py-6 text-center">
                <p class="font-sans text-sm font-semibold text-[var(--text-ink)] mb-1">No shipments for this facility</p>
            </div>
        @else
            <div role="region" aria-label="Recent parcel records" tabindex="0" class="relative overflow-x-auto focus-visible:outline-2 focus-visible:outline-primary-700">
                <table class="w-full min-w-[720px] text-left font-sans">
                    <caption class="sr-only">Recent parcels, statuses, and last updates for the selected facility</caption>
                    <thead class="border-b border-[var(--border-strong)] bg-[var(--bg-raised)]">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-sans text-xs font-semibold text-[var(--text-ink)]">Shipment</th>
                            <th scope="col" class="px-4 py-3 font-sans text-xs font-semibold text-[var(--text-ink)]">Seller</th>
                            <th scope="col" class="px-4 py-3 font-sans text-xs font-semibold text-[var(--text-ink)]">Status</th>
                            <th scope="col" class="px-4 py-3 font-sans text-xs font-semibold text-[var(--text-ink)]">Updated</th>
                            <th scope="col" class="px-4 py-3 text-right font-sans text-xs font-semibold text-[var(--text-ink)]">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--border-strong)]">
                        @foreach ($recentParcels as $parcel)
                            <tr>
                                <td class="px-4 py-3 font-sans text-[13px] font-semibold text-[var(--text-ink)]">{{ $parcel->tracking_number }}</td>
                                <td class="px-4 py-3 font-sans text-[13px] text-[var(--text-ink)]/80">{{ $parcel->seller?->business_name ?? 'Seller' }}</td>
                                <td class="px-4 py-3 font-sans text-[13px]">
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
                                <td class="whitespace-nowrap px-4 py-3 font-sans text-[13px] text-[var(--text-ink)]/80">{{ $parcel->updated_at->diffForHumans() }}</td>
                                <td class="px-4 py-3 text-right">
                                    @if (\Illuminate\Support\Facades\Gate::forUser($provider)->allows('advance-shipment', [$parcel]))
                                        <a href="{{ route('logistics.delivery-monitoring.show', $parcel) }}" class="{{ $quietAction }}"><i data-lucide="eye" class="size-3.5" aria-hidden="true"></i>View<span class="sr-only"> parcel {{ $parcel->tracking_number ?? '#'.$parcel->id }}</span></a>
                                    @else
                                        <span class="font-sans text-xs text-[var(--text-ink)]/80">No actions available</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection

