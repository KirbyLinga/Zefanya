@props(['group'])

{{--
    Tabs for logistics pages that share one sidebar item. Plain links, no
    JavaScript. The groups here must match the `active` patterns in
    x-logistics.sidebar so the tab and the sidebar item light up together.
--}}
@php
    $groups = [
        'parcels' => [
            ['route' => 'logistics.incoming-parcels.index', 'label' => 'Incoming parcels'],
            ['route' => 'logistics.parcel-sorting.index', 'label' => 'Parcel sorting'],
        ],
        'deliveries' => [
            ['route' => 'logistics.delivery-assignments.index', 'label' => 'Assign deliveries'],
            ['route' => 'logistics.delivery-monitoring.index', 'label' => 'Delivery tracking'],
        ],
        'transfers' => [
            ['route' => 'logistics.handoffs.index', 'label' => 'Provider handoffs'],
            ['route' => 'logistics.manifests.index', 'label' => 'Transfer batches'],
            ['route' => 'logistics.transfer-couriers.index', 'label' => 'Couriers'],
        ],
        'money' => [
            ['route' => 'logistics.earnings.index', 'label' => 'Earnings'],
            ['route' => 'logistics.cod-remittances.index', 'label' => 'COD remittances'],
            ['route' => 'logistics.lost-parcels.index', 'label' => 'Lost parcels'],
        ],
        'setup' => [
            ['route' => 'logistics.hubs.index', 'label' => 'Hubs & stations'],
            ['route' => 'logistics.transport-connections.index', 'label' => 'Transport connections'],
            ['route' => 'logistics.partnerships.index', 'label' => 'Provider partnerships'],
            ['route' => 'logistics.areas.index', 'label' => 'Delivery areas'],
            ['route' => 'logistics.audit-logs.index', 'label' => 'Activity log'],
        ],
    ];
    $tabs = array_values(array_filter($groups[$group] ?? [], fn (array $tab): bool => Route::has($tab['route'])
        // Courier Management appears once its tables exist (migration run).
        && ($tab['route'] !== 'logistics.transfer-couriers.index' || \App\Services\Logistics\TransferCourierService::available())
        // Lost parcels appears once its tables exist (migration run).
        && ($tab['route'] !== 'logistics.lost-parcels.index' || \App\Services\Logistics\ParcelLossService::available())));
@endphp

@if (count($tabs) > 1)
    <nav data-logistics-tabs aria-label="Section pages" class="-mx-1 mb-6 flex gap-x-1 overflow-x-auto border-b border-[var(--border-strong)] px-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
        @foreach ($tabs as $tab)
            @php $active = request()->routeIs(substr($tab['route'], 0, -5).'*'); @endphp
            <a href="{{ route($tab['route']) }}"
               @if ($active) aria-current="page" @endif
               class="-mb-px inline-flex min-h-11 shrink-0 items-center whitespace-nowrap border-b-2 px-3 font-sans text-[13px] no-underline transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-700 {{ $active ? 'border-[var(--link)] font-semibold text-[var(--link)]' : 'border-transparent font-medium text-[var(--text-muted)] hover:text-[var(--text-ink)]' }}">
                {{ $tab['label'] }}
            </a>
        @endforeach
    </nav>
    {{-- On narrow screens the row scrolls; bring the current tab into view. --}}
    <script>
        (function () {
            var nav = document.currentScript.previousElementSibling;
            var current = nav && nav.querySelector('[aria-current="page"]');
            if (current && nav.scrollWidth > nav.clientWidth) {
                nav.scrollLeft = current.offsetLeft - (nav.clientWidth - current.offsetWidth) / 2;
            }
        })();
    </script>
@endif
