@props(['group'])

@php
    $tabs = match ($group) {
        'parcels' => ['incoming-parcels' => 'Incoming', 'parcel-sorting' => 'Sorting'],
        'deliveries' => ['delivery-assignments' => 'Assignments', 'delivery-monitoring' => 'Monitoring'],
        'transfers' => ['handoffs' => 'Handoffs', 'manifests' => 'Transfer batches']
            + (\App\Services\Logistics\TransferCourierService::available() ? ['transfer-couriers' => 'Courier management'] : []),
        'money' => ['earnings' => 'Earnings', 'cod-remittances' => 'COD remittances'],
        'setup' => ['hubs' => 'Hubs', 'transport-connections' => 'Transport connections', 'partnerships' => 'Partnerships',
            'areas' => 'Areas', 'shipping-rates' => 'Shipping rates', 'audit-logs' => 'Audit logs'],
    };
@endphp

<nav aria-label="Section pages" class="mb-6 flex flex-wrap gap-x-3 border-b border-[var(--border-strong)]">
    @foreach ($tabs as $page => $label)
        @php($active = request()->routeIs('logistics.'.$page.'.*'))
        <a href="{{ route('logistics.'.$page.'.index') }}"
           @if ($active) aria-current="page" @endif
           class="inline-flex min-h-11 items-center border-b-2 px-3 font-sans text-sm {{ $active ? 'border-current font-semibold text-[var(--text-ink)]' : 'border-transparent text-[var(--text-muted)]' }}">
            {{ $label }}
        </a>
    @endforeach
</nav>
