@extends('Admin.Layouts.app')
@section('title', 'Order Monitoring — Zefanya Admin')
@section('content-width', 'max-w-none')
@section('content')
<div class="space-y-6">
    <header>
        <h1 class="font-serif text-3xl text-neutral-950">Order Monitoring</h1>
        <p class="mt-2 text-sm text-neutral-600">Monitor shipments, payouts, and activity in one place. Logistics manages Rider assignments.</p>
    </header>
    @php
        $summaryCards = [
            'active' => ['Active Shipments', 'package', 'bg-emerald-50 text-emerald-800'],
            'pickup' => ['Awaiting Pickup', 'clock', 'bg-amber-50 text-amber-800'],
            'transit' => ['In Transit', 'truck', 'bg-violet-50 text-violet-800'],
            'attention' => ['Needs Attention', 'triangle-alert', 'bg-red-50 text-red-800'],
        ];
        $listQuery = http_build_query(request()->query());
    @endphp
    <div class="grid grid-cols-2 gap-3 xl:grid-cols-4" aria-label="Shipment summary">
        @foreach ($summaryCards as $key => [$label, $icon, $tone])
            <a href="{{ route('admin.orders.index', ['summary' => $key]) }}" @if(request('summary') === $key) aria-current="true" @endif class="group relative min-w-0 rounded-2xl border border-l-4 {{ ['border-l-emerald-500', 'border-l-amber-500', 'border-l-blue-500', 'border-l-red-400'][$loop->index] }} {{ request('summary') === $key ? 'border-primary-800 bg-primary-50 ring-1 ring-primary-800' : 'border-neutral-200 bg-white hover:border-primary-800' }} p-5 shadow-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-800">
                <span class="absolute right-4 top-4 inline-flex size-9 items-center justify-center rounded-lg {{ $tone }}"><i data-lucide="{{ $icon }}" class="size-[18px]" aria-hidden="true"></i></span>
                <span class="block pr-11 text-[13px] font-medium text-neutral-700">{{ $label }}</span>
                <span class="mt-4 block break-words font-serif text-[28px] font-bold leading-tight tabular-nums text-neutral-950">{{ number_format($summary[$key] ?? 0) }}</span>
                <span class="mt-1 block whitespace-nowrap text-xs font-medium text-primary-800">{{ request('summary') === $key ? 'Selected' : 'View' }} <span aria-hidden="true">&rarr;</span></span>
            </a>
        @endforeach
    </div>
    <section class="admin-monitor-filter-panel" aria-label="Shipment filters">
    <form data-admin-auto-filter method="GET" action="{{ route('admin.orders.index') }}" class="admin-monitor-filters">
        @if(request('summary'))<input type="hidden" name="summary" value="{{ request('summary') }}">@endif
        <label class="admin-monitor-search">Search shipments
            <span class="admin-monitor-search-field"><i data-lucide="search" aria-hidden="true"></i><input name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Order / shipment ID, tracking, Buyer, Seller"></span>
        </label>
        <label class="admin-monitor-field">Shipment status
            <select name="status" >
                <option value="">All statuses</option>
                @foreach (\App\Enums\OrderStatus::cases() as $status)
                    <option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ $status->label() }}</option>
                @endforeach
            </select>
        </label>
        <label class="admin-monitor-field">Responsible Logistics provider
            <select name="provider" >
                <option value="">All providers</option>
                @foreach ($providers as $provider)
                    <option value="{{ $provider->id }}" @selected((string) ($filters['provider'] ?? '') === (string) $provider->id)>{{ $provider->business_name }}</option>
                @endforeach
            </select>
        </label>
        <label class="admin-monitor-field">Existing attention indicator
            <select name="attention" >
                <option value="">All shipments</option>
                @foreach ($monitor::ATTENTION as $key => $label)
                    <option value="{{ $key }}" @selected(($filters['attention'] ?? '') === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label class="admin-monitor-field">Checkout date from<input type="date" name="from" value="{{ $filters['from'] ?? '' }}" ></label>
        <label class="admin-monitor-field">Checkout date to<input type="date" name="to" value="{{ $filters['to'] ?? '' }}" ></label>
        <div class="admin-monitor-filter-actions">
            <a href="{{ route('admin.orders.index') }}" class="admin-monitor-reset"><i data-lucide="rotate-ccw" aria-hidden="true"></i>Reset</a>
        </div>
        @if ($errors->any())<p class="text-sm text-red-700" role="alert">{{ $errors->first() }}</p>@endif
    </form>
    <div class="admin-monitor-filter-results"><p>Showing <strong>{{ $shipments->firstItem() ?? 0 }} to {{ $shipments->lastItem() ?? 0 }}</strong> of <strong>{{ number_format($shipments->total()) }}</strong> shipments</p><p><i data-lucide="arrow-down-wide-narrow" aria-hidden="true"></i>Newest first</p></div>
    </section>
    <section class="min-w-0 rounded-2xl border border-neutral-200 bg-white p-4 sm:p-5">
        <h2 class="font-semibold text-neutral-950">Seller shipments <span class="font-normal text-neutral-600">({{ number_format($shipments->total()) }})</span></h2>
        <p class="mt-1 text-xs text-neutral-600">One row per Seller shipment. Duration is time in the current status; no SLA threshold is applied. Latest verified location is historical evidence, with its timestamp.</p>
        <div class="mt-4 min-w-0">
            <table class="block w-full text-left text-sm lg:table">
                <thead class="sticky top-0 z-10 hidden border-b border-neutral-200 bg-neutral-50 text-xs text-neutral-600 lg:table-header-group"><tr>
                    @foreach (['Order / shipment', 'Buyer / Seller', 'Status / duration', 'Responsible Logistics', 'Assigned Riders', 'Latest verified location', 'Attention', ''] as $heading)<th scope="col" class="px-3 py-3 font-semibold">{{ $heading }}</th>@endforeach
                </tr></thead>
                <tbody class="block space-y-3 lg:table-row-group lg:space-y-0">
                @forelse ($shipments as $shipment)
                    @php
                        $evidence = $custody[$shipment->id] ?? null;
                        $flags = $monitor->attention($shipment);
                        $priority = ['incident', 'handoff_exception', 'failed_delivery', 'reassignment', 'missed_pickup', 'unassigned', 'transfer'];
                        $flags = array_replace(array_intersect_key(array_flip($priority), $flags), $flags);
                        $detailUrl = route('admin.orders.show', ['sellerOrder' => $shipment, 'list' => $listQuery]);
                    @endphp
                    <tr class="block rounded-xl border border-neutral-200 p-4 transition-colors hover:bg-neutral-50 lg:table-row lg:rounded-none lg:border-x-0 lg:border-t-0 lg:p-0 lg:align-top">
                        <td class="block lg:table-cell lg:px-3 lg:py-4"><span class="block text-xs text-neutral-600">Order #{{ $shipment->order_id }}</span><a class="mt-1 inline-block font-semibold text-primary-800 underline decoration-primary-800/40 underline-offset-2 focus-visible:outline-2 focus-visible:outline-primary-800" href="{{ $detailUrl }}">Shipment #{{ $shipment->id }}</a></td>
                        <td class="mt-3 block [overflow-wrap:anywhere] lg:mt-0 lg:table-cell lg:px-3 lg:py-4"><span class="block font-medium text-neutral-950">{{ $shipment->order?->buyer?->fullName() ?? 'Unavailable' }}</span><span class="mt-1 block text-xs text-neutral-600">Seller: {{ $shipment->seller?->business_name ?? 'Unavailable' }}</span></td>
                        <td class="mt-3 block lg:mt-0 lg:table-cell lg:px-3 lg:py-4"><x-shipment-status-badge :status="$shipment->status" :delivery-attempts="(int) $shipment->delivery_attempts" /><span class="mt-1 block text-xs text-neutral-600">{{ ($since = $monitor->statusSince($shipment)) ? 'Status age: '.$since->diffForHumans(null, true) : 'Status time not recorded' }}</span></td>
                        <td class="mt-3 block [overflow-wrap:anywhere] lg:mt-0 lg:table-cell lg:px-3 lg:py-4"><span class="block text-xs text-neutral-600 lg:hidden">Responsible Logistics</span><span class="font-medium text-neutral-900">{{ $shipment->carrierProvider?->business_name ?? 'Not recorded' }}</span></td>
                        <td class="mt-3 block [overflow-wrap:anywhere] lg:mt-0 lg:table-cell lg:px-3 lg:py-4"><span class="block text-xs text-neutral-600 lg:hidden">Assigned Riders</span><span class="block"><span aria-hidden="true" class="text-neutral-500">&uarr;</span> <span class="text-xs text-neutral-600">Pickup</span> {{ $shipment->courier?->name ?? "\u{2014}" }}</span><span class="mt-1 block"><span aria-hidden="true" class="text-neutral-500">&darr;</span> <span class="text-xs text-neutral-600">Delivery</span> {{ $shipment->deliveryCourier?->name ?? "\u{2014}" }}</span></td>
                        <td class="mt-3 block [overflow-wrap:anywhere] lg:mt-0 lg:table-cell lg:px-3 lg:py-4"><span class="block text-xs text-neutral-600 lg:hidden">Latest verified location</span><span class="font-medium text-neutral-900">{{ $evidence['holder'] ?? 'Not yet verified' }}</span>@if ($evidence)<span class="mt-1 block text-xs text-neutral-600">{{ manila($evidence['at'], 'Y-m-d H:i:s') }}<br>{{ $evidence['source'] }}</span>@endif</td>
                        <td class="mt-3 block lg:mt-0 lg:table-cell lg:px-3 lg:py-4">@foreach (array_slice($flags, 0, 2, true) as $key => $flag)<span class="mb-1 mr-1 inline-block rounded-md px-2 py-1 text-xs font-medium {{ in_array($key, ['incident', 'handoff_exception', 'failed_delivery']) ? 'bg-red-50 text-red-800' : 'bg-amber-50 text-amber-900' }}">{{ $flag }}</span>@endforeach @if(count($flags) > 2)<span class="text-xs text-neutral-600">+{{ count($flags) - 2 }} more</span>@endif</td>
                        <td class="mt-3 block lg:mt-0 lg:table-cell lg:px-3 lg:py-4"><a href="{{ $detailUrl }}" data-monitor-detail class="inline-flex min-h-10 items-center gap-2 rounded-lg border border-neutral-300 bg-white px-3 text-sm font-semibold text-primary-800 hover:bg-primary-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-800" aria-label="View details for shipment #{{ $shipment->id }}">View Details <span aria-hidden="true">&rarr;</span></a></td>
                    </tr>
                @empty
                    <tr class="block lg:table-row"><td colspan="8" class="block px-3 py-10 text-center text-neutral-600 lg:table-cell">No shipments match these filters. <a href="{{ route('admin.orders.index') }}" class="text-primary-800 underline">Clear filters</a></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-5">{{ $shipments->links('Admin.OrderMonitoring._pagination') }}</div>
    </section>
</div>
<dialog id="monitor-detail-dialog" aria-labelledby="monitor-detail-title" class="fixed inset-y-0 right-0 left-auto m-0 h-dvh max-h-none w-full max-w-[42rem] overflow-hidden border-0 bg-white p-0 shadow-xl backdrop:bg-black/50">
    <div class="flex h-full min-h-0 flex-col">
        <header class="flex shrink-0 items-center justify-between gap-3 border-b border-neutral-200 px-4 py-3 sm:px-5">
            <div><h2 id="monitor-detail-title" class="font-serif text-xl font-semibold text-neutral-950">Shipment details</h2><p class="text-xs text-neutral-600">Shipment details &amp; audit trail</p></div>
            <button type="button" id="monitor-detail-close" aria-label="Close shipment details" class="inline-flex size-11 items-center justify-center rounded-lg border border-neutral-200 text-neutral-900 hover:bg-primary-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-800"><span aria-hidden="true" class="text-xl">&times;</span></button>
        </header>
        <iframe id="monitor-detail-frame" title="Selected shipment details and audit trail" class="min-h-0 w-full flex-1 border-0" src="about:blank"></iframe>
    </div>
</dialog>
<script>
    (() => {
        const dialog = document.getElementById('monitor-detail-dialog');
        const frame = document.getElementById('monitor-detail-frame');
        const close = document.getElementById('monitor-detail-close');
        let opener = null;
        if (dialog && frame && close && typeof dialog.showModal === 'function') {
            document.querySelectorAll('[data-monitor-detail]').forEach(link => link.addEventListener('click', event => {
                if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
                event.preventDefault();
                opener = link;
                const url = new URL(link.href, location.href);
                url.searchParams.set('embed', '1');
                frame.src = url.href;
                dialog.showModal();
                close.focus();
            }));
            close.addEventListener('click', () => dialog.close());
            dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });
            dialog.addEventListener('close', () => { frame.src = 'about:blank'; if (opener && opener.isConnected) opener.focus(); });
        }
    })();
    (() => {
        const key = 'order-monitoring-scroll:' + location.pathname + '?' + new URLSearchParams(location.search).toString();
        const restore = () => {
            try {
                const saved = sessionStorage.getItem(key);
                if (saved !== null) { requestAnimationFrame(() => window.scrollTo(0, Number(saved))); sessionStorage.removeItem(key); }
            } catch (_) { /* Storage may be unavailable in private browsing. */ }
        };
        window.addEventListener('pageshow', restore);
        document.querySelectorAll('a[href*="/admin/orders/"]').forEach(link => link.addEventListener('click', () => { try { sessionStorage.setItem(key, String(window.scrollY)); } catch (_) { /* Navigation still works without storage. */ } }));
    })();
</script>
@endsection
