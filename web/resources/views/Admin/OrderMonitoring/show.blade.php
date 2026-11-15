@extends('Admin.Layouts.app')
@section('title', 'Shipment #'.$shipment->id.' — Order Monitoring')
@section('content-width', 'max-w-none')
@section('content')
@php
    $panel = 'min-w-0 rounded-2xl border border-neutral-200 bg-white p-4 sm:p-6';
    $money = fn ($minor) => $minor === null ? 'Not recorded' : '₱'.number_format($minor / 100, 2);
    $cod = $financials['cod'];
    $payment = $shipment->payment;
    $settlement = $shipment->settlement;
    $return = $shipment->parcelReturn;
    $listQuery = (string) request('list', '');
    $backUrl = route('admin.orders.index').($listQuery !== '' ? '?'.$listQuery : '');
    // Hero total: this shipment's own payable, never the master-order total shared by sibling shipments.
    $heroMinor = $payment?->amount_minor ?? ($shipment->subtotal_minor !== null && $shipment->shipping_fee_minor !== null ? $shipment->subtotal_minor + $shipment->shipping_fee_minor : null);
    $flags = $monitor->attention($shipment);
    $dot = 'absolute -left-[31px] flex h-6 w-6 items-center justify-center rounded-full border-2 bg-white';
    $tabs = ['overview' => ['Overview', 'layout-grid', null], 'journey' => ['Parcel Journey', 'route', null], 'activity' => ['Activity History', 'history', $activity->total()], 'financials' => ['Financials', 'banknote', null]];
@endphp
<div class="space-y-6">
    <a class="inline-flex items-center gap-1 text-sm text-primary-800 underline" href="{{ $backUrl }}"><i data-lucide="arrow-left" width="14" height="14" aria-hidden="true"></i>Back to Order Monitoring</a>

    {{-- Hero summary --}}
    <header class="rounded-2xl bg-[#4a2f36] p-5 text-white shadow-sm sm:p-7">
        <div class="flex flex-wrap items-start justify-between gap-6">
            <div class="min-w-0">
                <p class="text-xs font-semibold uppercase tracking-wide text-white/70">Read-only monitoring</p>
                <h1 class="mt-1 font-serif text-3xl !text-white sm:text-4xl">Shipment #{{ $shipment->id }}</h1>
                <p class="mt-1 text-sm text-white/80">Order #{{ $shipment->order_id }}</p>
                <div class="mt-4 flex flex-wrap items-center gap-2">
                    <x-shipment-status-badge :status="$shipment->status" />
                    @if ($shipment->order?->status && $shipment->order->status !== $shipment->status)<span class="text-xs text-white/70">Order:</span><x-shipment-status-badge :status="$shipment->order->status" />@endif
                    @include('Admin.OrderMonitoring._badge', ['value' => $payment?->method?->value])
                    @include('Admin.OrderMonitoring._badge', ['value' => $payment?->status?->value])
                </div>
                <div class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-white/80">
                    @if ($shipment->order?->reference)<span class="break-all">Order reference: {{ $shipment->order->reference }} <button type="button" data-copy="{{ $shipment->order->reference }}" aria-label="Copy order reference" class="ml-1 font-medium text-white underline">Copy</button></span>@endif
                    @if ($shipment->tracking_number)<span class="break-all">Tracking: {{ $shipment->tracking_number }} <button type="button" data-copy="{{ $shipment->tracking_number }}" aria-label="Copy tracking number" class="ml-1 font-medium text-white underline">Copy</button></span>@endif
                    <span id="copy-feedback" role="status" aria-live="polite"></span>
                </div>
            </div>
            <div class="rounded-xl bg-white/15 px-5 py-4 sm:text-right">
                <p class="text-xs font-semibold uppercase tracking-wide text-white/70">Shipment total</p>
                <p class="mt-1 text-4xl font-bold tabular-nums !text-white sm:text-5xl">{{ $heroMinor === null ? '—' : '₱'.number_format($heroMinor / 100, 2) }}</p>
                <p class="mt-1 text-xs text-white/70">This Seller shipment only</p>
            </div>
        </div>
        @if (count($flags))
            <div class="mt-5 flex flex-wrap gap-2 border-t border-white/15 pt-4">@foreach ($flags as $flag)<span class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-3 py-1 text-xs font-medium text-amber-800"><i data-lucide="triangle-alert" width="12" height="12" aria-hidden="true"></i>{{ $flag }}</span>@endforeach</div>
        @endif
    </header>

    {{-- Sticky section tabs: filter the panels below (all panels stay visible if JavaScript is off). --}}
    <nav aria-label="Shipment sections" id="section-tabs" class="sticky top-0 z-20 -mx-4 border-b border-neutral-200 bg-primary-50/95 px-4 py-2 backdrop-blur sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8">
        <div class="flex gap-2 overflow-x-auto text-sm font-medium">
            @foreach ($tabs as $id => [$label, $icon, $count])
                <a href="#{{ $id }}" data-tab="{{ $id }}" class="inline-flex shrink-0 items-center gap-2 rounded-lg border border-neutral-200 bg-white px-3 py-2 text-primary-800 hover:border-primary-800 focus-visible:outline-2 focus-visible:outline-primary-800 aria-[current=true]:border-primary-800 aria-[current=true]:bg-primary-800 aria-[current=true]:text-white">
                    <i data-lucide="{{ $icon }}" width="15" height="15" aria-hidden="true"></i>{{ $label }}@if ($count !== null)<span class="rounded-full bg-neutral-100 px-1.5 text-[11px] text-neutral-700">{{ $count }}</span>@endif
                </a>
            @endforeach
        </div>
    </nav>

    <section id="overview" data-panel class="{{ $panel }} scroll-mt-20">
        <h2 class="text-lg font-semibold text-neutral-950">Overview</h2>
        @include('Admin.OrderMonitoring._fields', ['badges' => ['Order status (stored rollup)', 'Shipment status'], 'fields' => [
            'Parent order' => '#'.$shipment->order_id,
            'Order status (stored rollup)' => $shipment->order?->status?->label(),
            'Shipment status' => $shipment->status->label(),
            'Buyer' => $shipment->order?->buyer?->fullName(),
            'Seller' => $shipment->seller?->business_name,
            'Responsible Logistics provider (commercial carrier)' => $shipment->carrierProvider?->business_name,
            'Assigned pickup Rider' => $shipment->courier?->name ?? 'Unassigned',
            'Assigned delivery Rider' => $shipment->deliveryCourier?->name ?? 'Unassigned',
            'Checkout at' => $shipment->order?->created_at,
            'Shipment created at' => $shipment->created_at,
            'Current status since' => $monitor->statusSince($shipment),
            'Seller handover at' => $shipment->handover_at,
            'Commission finalized at' => $shipment->commission_finalized_at,
            'Recorded missed pickup windows' => $shipment->pickup_missed_windows ?? 0,
        ]])
        <h3 class="mt-6 flex items-center gap-2 font-semibold"><i data-lucide="layers" width="16" height="16" class="text-primary-700" aria-hidden="true"></i>Seller shipments in this order ({{ $siblings->total() }})</h3>
        <ul class="mt-3 grid gap-2 text-sm sm:grid-cols-2 xl:grid-cols-3">@foreach ($siblings as $sibling)<li><a class="flex flex-wrap items-center justify-between gap-2 rounded-xl border px-3 py-2 hover:border-primary-800 {{ $sibling->id === $shipment->id ? 'border-primary-800 bg-primary-50' : 'border-neutral-200' }}" href="{{ route('admin.orders.show', ['sellerOrder' => $sibling, 'list' => $listQuery]) }}" @if ($sibling->id === $shipment->id) aria-current="page" @endif><span class="min-w-0 break-words">Shipment #{{ $sibling->id }} · {{ $sibling->seller?->business_name }}</span>@include('Admin.OrderMonitoring._badge', ['value' => $sibling->status->label()])</a></li>@endforeach</ul>
        <div class="mt-3">{{ $siblings->links('Admin.OrderMonitoring._pagination') }}</div>
    </section>

    <section id="journey" data-panel class="{{ $panel }} scroll-mt-20">
        <h2 class="text-lg font-semibold text-neutral-950">Parcel Journey</h2>
        <p class="mt-2 text-sm text-neutral-600">Assigned Riders and the responsible carrier describe operational responsibility. Only physical handover, per-parcel scans, verified receipts or delivery proof establish the last verified custodian. This historical evidence may precede later movement.</p>
        <div class="mt-4 rounded-xl border border-primary-200 bg-primary-50 p-4">
            <p class="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-primary-800"><i data-lucide="shield-check" width="14" height="14" aria-hidden="true"></i>Last verified physical custody</p>
            <p class="mt-2 break-words text-base {{ isset($custodian['holder']) ? 'font-semibold text-neutral-950' : 'font-normal italic text-neutral-400' }}">{{ $custodian['holder'] ?? 'Not yet verified' }}</p>
            <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-3">
                @foreach ([['Verified at', isset($custodian['at']) ? manila($custodian['at'], 'Y-m-d H:i:s') : null, 'Not yet verified'], ['Evidence', $custodian['source'] ?? null, 'Not yet verified'], ['Verifying actor', $custodian['actor'] ?? null, 'Not recorded']] as [$k, $v, $none])
                    <div><dt class="text-xs text-neutral-600">{{ $k }}</dt><dd class="mt-1 break-words {{ $v === null ? 'italic text-neutral-400' : 'text-neutral-900' }}">{{ $v ?? $none }}</dd></div>
                @endforeach
            </dl>
        </div>
        @include('Admin.OrderMonitoring._fields', ['fields' => [
            'Return collected at' => $return?->collected_at,
            'Return received by Seller at' => $return?->seller_received_at,
        ]])

        <h3 class="mt-8 font-semibold">Planned route legs</h3>
        <p class="mt-1 text-xs text-neutral-600">A plan does not verify physical arrival.</p>
        <ol class="relative ml-3 mt-4 space-y-3 border-l-2 border-neutral-200 pl-8 text-sm">
            @forelse ($legs as $index => $leg)
                @php $current = $index === $shipment->parcelRoute?->current_leg_index; @endphp
                <li class="relative rounded-xl border p-3 {{ $current ? 'border-primary-800 bg-primary-50' : 'border-neutral-200' }}">
                    <span class="{{ $dot }} top-3 {{ $current ? 'border-primary-800 bg-primary-800 text-white' : 'border-neutral-300 text-neutral-500' }}"><span class="text-[11px] font-bold">{{ $index + 1 }}</span></span>
                    <span class="font-medium">Leg {{ $index + 1 }}: {{ $hubs[$leg['hub_id'] ?? 0] ?? 'Hub not recorded' }}</span> · <span class="text-neutral-600">{{ $leg['transport_mode'] ?? 'Mode not recorded' }}</span>@if ($current) · <span class="font-semibold text-primary-800">Current planned leg</span>@endif
                </li>
            @empty<li class="-ml-8 italic text-neutral-400">No route plan recorded.</li>@endforelse
        </ol>

        <h3 class="mt-8 font-semibold">Station scans and internal transfers</h3>
        <ol class="relative ml-3 mt-4 space-y-4 border-l-2 border-neutral-200 pl-8">
            @forelse ($items as $item)
                @php $scanned = $item->received_at !== null; @endphp
                <li class="relative">
                    <span class="{{ $dot }} top-4 {{ $scanned ? 'border-emerald-600 text-emerald-600' : 'border-neutral-300 text-neutral-400' }}"><i data-lucide="{{ $scanned ? 'check' : 'circle' }}" width="12" height="12" aria-hidden="true"></i></span>
                    <article class="rounded-xl border border-neutral-200 p-4 text-sm">
                        <h4 class="font-semibold">Manifest #{{ $item->manifest_id }} · {{ $item->manifest?->originHub?->name ?? 'Unknown origin' }} → {{ $item->manifest?->destinationHub?->name ?? 'Unknown destination' }}</h4>
                        @include('Admin.OrderMonitoring._fields', ['badges' => ['Transfer status'], 'fields' => [
                            'Transfer status' => $item->manifest?->status?->value,
                            'Dispatch verification' => $item->verified_for_dispatch_at,
                            'Dispatch verifier' => $monitor->actor($item->verified_for_dispatch_by_type, $item->verified_for_dispatch_by_id),
                            'Dispatched at' => $item->manifest?->dispatched_at,
                            'Per-parcel receipt' => $item->received_at ?? 'Not yet verified',
                            'Receipt verifier' => $monitor->actor($item->received_by_type, $item->received_by_id),
                            'Recovery receipt' => $item->recovered_at,
                            'Recovery hub' => $item->recoveredHub?->name,
                            'Issue' => $item->issue_note,
                        ]])
                    </article>
                </li>
            @empty<li class="-ml-8 text-sm italic text-neutral-400">No station scan or internal transfer recorded.</li>@endforelse
        </ol>
        <div class="mt-3">{{ $items->links('Admin.OrderMonitoring._pagination') }}</div>

        <h3 class="mt-8 font-semibold">Provider handoffs</h3>
        <ol class="relative ml-3 mt-4 space-y-4 border-l-2 border-neutral-200 pl-8">
            @forelse ($handoffs as $handoff)
                @php $received = $handoff->received_at !== null; @endphp
                <li class="relative">
                    <span class="{{ $dot }} top-4 {{ $received ? 'border-emerald-600 text-emerald-600' : 'border-neutral-300 text-neutral-400' }}"><i data-lucide="{{ $received ? 'check' : 'circle' }}" width="12" height="12" aria-hidden="true"></i></span>
                    <article class="rounded-xl border border-neutral-200 p-4 text-sm">
                        <h4 class="flex flex-wrap items-center gap-2 font-semibold">Handoff #{{ $handoff->id }} · {{ $handoff->originProvider?->business_name }} → {{ $handoff->destinationProvider?->business_name }} @include('Admin.OrderMonitoring._badge', ['value' => $handoff->statusLabel()])</h4>
                        @include('Admin.OrderMonitoring._fields', ['fields' => [
                            'Sending hub' => $handoff->sendingHub?->name,
                            'Planned receiving hub' => $handoff->receivingHub?->name,
                            'Accepted at (plan only)' => $handoff->accepted_at,
                            'Dispatched at' => $handoff->dispatched_at,
                            'Verified receipt' => $handoff->received_at ?? 'Not yet verified',
                            'Receipt verifier' => $monitor->actor($handoff->received_by_type, $handoff->received_by_id),
                            'Recovery receipt' => $handoff->recovered_at,
                        ]])
                    </article>
                </li>
            @empty<li class="-ml-8 text-sm italic text-neutral-400">No provider handoffs recorded.</li>@endforelse
        </ol>
        <div class="mt-3">{{ $handoffs->links('Admin.OrderMonitoring._pagination') }}</div>
    </section>

    <section id="activity" data-panel class="{{ $panel }} scroll-mt-20">
        <h2 class="text-lg font-semibold text-neutral-950">Activity History</h2>
        <p class="mt-2 text-xs text-neutral-600">Oldest first across status changes, pickup / delivery assignments and shipment audit records. Each assignment remains visible when a Rider is replaced. Times are shown in {{ config('app.timezone') }}.</p>
        @php $events = $activity->values(); $older = max(0, $events->count() - 5); @endphp
        @if ($events->isEmpty())
            <p class="mt-4 text-sm italic text-neutral-400">No activity recorded.</p>
        @else
            @if ($older > 0)
                <details class="mt-4 rounded-xl border border-neutral-200">
                    <summary class="cursor-pointer rounded-xl px-4 py-2 text-sm font-semibold text-primary-800 hover:bg-primary-50">Show {{ $older }} earlier {{ \Illuminate\Support\Str::plural('event', $older) }}</summary>
                    <ol class="relative ml-7 space-y-3 border-l-2 border-neutral-200 py-3 pl-8 pr-4">
                        @foreach ($events->take($older) as $event)@include('Admin.OrderMonitoring._activity_event')@endforeach
                    </ol>
                </details>
            @endif
            <ol class="relative ml-3 mt-4 space-y-3 border-l-2 border-neutral-200 pl-8">
                @foreach ($events->slice($older) as $event)@include('Admin.OrderMonitoring._activity_event')@endforeach
            </ol>
        @endif
        {{ $activity->links('Admin.OrderMonitoring._pagination') }}
    </section>

    <section id="financials" data-panel class="{{ $panel }} scroll-mt-20">
        <h2 class="text-lg font-semibold text-neutral-950">Financials</h2>
        <p class="mt-2 text-sm text-neutral-600">All amounts are pesos converted from recorded integer centavos. Master-order and shared batch amounts are contextual; they are never added to this shipment's obligations. Missing records do not mean paid.</p>
        @include('Admin.OrderMonitoring._financials')
    </section>
</div>
<script>
    document.querySelectorAll('[data-copy]').forEach(button => button.addEventListener('click', async () => {
        const feedback = document.getElementById('copy-feedback');
        try {
            await navigator.clipboard.writeText(button.dataset.copy);
            feedback.textContent = 'Copied to clipboard.';
        } catch (_) {
            feedback.textContent = 'Copy unavailable; select the identifier to copy it.';
        }
    }));

    // Tabs act as view filters. Without JavaScript every panel stays visible and the tabs are plain anchors.
    (() => {
        const bar = document.getElementById('section-tabs');
        const panels = [...document.querySelectorAll('[data-panel]')];
        const tabs = [...document.querySelectorAll('[data-tab]')];
        const ids = panels.map(p => p.id);
        const show = (id, scroll) => {
            if (!ids.includes(id)) id = ids[0];
            panels.forEach(p => { p.hidden = p.id !== id; });
            tabs.forEach(t => t.setAttribute('aria-current', String(t.dataset.tab === id)));
            // Keep pagination inside a panel on that panel after the reload.
            panels.forEach(p => p.querySelectorAll('a[href*="page="]').forEach(a => { a.hash = '#' + p.id; }));
            if (scroll) bar.scrollIntoView({block: 'start'});
        };
        tabs.forEach(t => t.addEventListener('click', e => {
            e.preventDefault();
            history.replaceState(null, '', '#' + t.dataset.tab);
            show(t.dataset.tab, window.scrollY > bar.offsetTop);
        }));
        addEventListener('hashchange', () => show(location.hash.slice(1), false));
        show(location.hash.slice(1), false);
    })();
</script>
@endsection
