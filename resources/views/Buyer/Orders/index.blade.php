@extends('Buyer.Layouts.app')

@use('App\Enums\OrderStatus')

@section('title', 'Orders & Tracking')

@section('content')
<div class="bg-[var(--bg-page)] py-8 sm:py-10">
    <div class="mx-auto w-full max-w-[1240px] px-4 sm:px-6 lg:px-8">
        <div class="grid grid-cols-1 gap-8 lg:grid-cols-[280px_1fr]">

            {{-- ── Left Sidebar: Profile & Account Summary ── --}}
            @include('Components.buyer.account-sidebar', ['active' => 'orders'])
            {{-- ── Right Column: Orders & Tracking ── --}}
            <main>
                {{-- Header with Search Bar --}}
                <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 class="font-serif text-[28px] font-normal leading-tight text-[var(--text-ink)] sm:text-[32px]">
                            Orders &amp; Tracking
                        </h1>
                        <p class="mt-1 font-sans text-[13px] text-[var(--text-muted)]">
                            Track deliveries, review orders, and request returns
                        </p>
                    </div>

                    {{-- Search Form --}}
                    <form method="GET" action="{{ route('buyer.orders.index') }}" class="relative w-full sm:w-[260px]">
                        @if ($tab !== 'all')
                            <input type="hidden" name="tab" value="{{ $tab }}">
                        @endif
                        <i data-lucide="search" width="15" height="15" class="absolute left-3.5 top-1/2 -translate-y-1/2 text-[var(--text-placeholder)]"></i>
                        <input type="text"
                               name="search"
                               value="{{ $search }}"
                               placeholder="Search order or item..."
                               class="h-10 w-full rounded-xl border border-[var(--border)] bg-[var(--bg-surface)] pl-9 pr-3 font-sans text-[12.5px] text-[var(--text-ink)] outline-none transition-colors placeholder:text-[var(--text-placeholder)] focus:border-[var(--brand)] focus:ring-2 focus:ring-[var(--hover-rose)]">
                    </form>
                </div>

                {{-- Status Filter Tabs --}}
                @php
                    $tabs = [
                        ['id' => 'all', 'label' => 'All', 'count' => $counts['all']],
                        ['id' => 'to_ship', 'label' => 'To Ship', 'count' => $counts['to_ship']],
                        ['id' => 'to_receive', 'label' => 'To Receive', 'count' => $counts['to_receive']],
                        ['id' => 'completed', 'label' => 'Completed', 'count' => $counts['completed']],
                        ['id' => 'cancelled', 'label' => 'Cancelled', 'count' => $counts['cancelled']],
                    ];
                @endphp

                <div class="mb-6 flex items-center gap-2 overflow-x-auto pb-1">
                    @foreach ($tabs as $t)
                        @php
                            $isActive = $tab === $t['id'];
                        @endphp
                        <a href="{{ route('buyer.orders.index', array_filter(['tab' => $isActive ? null : $t['id'], 'search' => $search !== '' ? $search : null])) }}"
                           class="inline-flex shrink-0 items-center gap-1.5 rounded-full px-4 py-1.5 font-sans text-[12px] font-medium transition-all {{ $isActive ? 'bg-primary-950 font-semibold text-white' : 'bg-secondary-100 text-[var(--text-ink)] hover:bg-secondary-200' }}">
                            <span>{{ $t['label'] }}</span>
                            @if ($t['count'] > 0)
                                <span class="text-[11px] opacity-80">({{ $t['count'] }})</span>
                            @endif
                        </a>
                    @endforeach
                </div>

                {{-- Orders Listing --}}
                @if ($orders->isEmpty())
                    <section class="flex flex-col items-center justify-center rounded-2xl border border-[var(--border)] bg-[var(--bg-surface)] p-12 text-center shadow-sm">
                        <div class="mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-secondary-200">
                            <i data-lucide="package-open" class="h-7 w-7 text-primary-800"></i>
                        </div>
                        <h3 class="font-serif text-[18px] font-medium text-[var(--text-ink)]">No orders found</h3>
                        <p class="mt-1 max-w-[340px] font-sans text-[12.5px] text-[var(--text-muted)]">
                            @if ($search !== '')
                                We couldn't find any orders matching "{{ $search }}". Try a different item or order reference.
                            @elseif ($tab !== 'all')
                                You have no orders in this status right now.
                            @else
                                When you place an order, it will appear here with live tracking updates.
                            @endif
                        </p>
                        <a href="{{ route('buyer.home') }}"
                           class="mt-5 inline-flex items-center justify-center rounded-full bg-primary-800 px-6 py-2.5 font-sans text-[12.5px] font-semibold tracking-wide text-white transition-colors hover:bg-primary-900">
                            Start Shopping
                        </a>
                    </section>
                @else
                    <div class="space-y-5">
                        @foreach ($orders as $order)
                            @php
                                $statuses = $order->sellerOrders->pluck('status')->unique();
                                $overall = match (true) {
                                    $statuses->contains(fn ($s) => $s === OrderStatus::OutForDelivery) => [
                                        'label' => 'To Receive / Out for Delivery',
                                        'color' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
                                    ],
                                    $statuses->contains(fn ($s) => $s === OrderStatus::AtSortingCenter || $s === OrderStatus::Sorted) => [
                                        'label' => 'To Receive / In Transit',
                                        'color' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
                                    ],
                                    $statuses->contains(fn ($s) => $s === OrderStatus::AssignedToRider || $s === OrderStatus::PickedUp) => [
                                        'label' => 'To Receive / Shipped',
                                        'color' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
                                    ],
                                    $statuses->contains(fn ($s) => $s === OrderStatus::ReadyForPickup) => [
                                        'label' => 'Ready for Pickup',
                                        'color' => 'border-amber-200 bg-amber-50 text-amber-700',
                                    ],
                                    $statuses->contains(fn ($s) => $s === OrderStatus::Delivered) => [
                                        'label' => 'Delivered',
                                        'color' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
                                    ],
                                    $order->computeRollupStatus() === OrderStatus::Completed => [
                                        'label' => 'Completed',
                                        'color' => 'border-neutral-200 bg-neutral-100 text-neutral-700',
                                    ],
                                    $statuses->contains(fn ($s) => $s === OrderStatus::DeliveryFailed || $s === OrderStatus::Returned || $s === OrderStatus::Cancelled) => [
                                        'label' => 'Cancelled / Returned',
                                        'color' => 'border-red-200 bg-red-50 text-red-700',
                                    ],
                                    default => [
                                        'label' => 'Processing',
                                        'color' => 'border-secondary-200 bg-secondary-100 text-neutral-800',
                                    ],
                                };

                                $totalItemsCount = $order->sellerOrders->sum(fn ($so) => $so->orderItems->sum('quantity'));
                            @endphp
                            <section class="overflow-hidden rounded-2xl border border-[var(--border)] bg-[var(--bg-surface)] shadow-xs transition-shadow duration-150 hover:shadow-sm">
                                {{-- Card Header --}}
                                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border-faint)] bg-neutral-50/50 px-5 py-3.5 sm:px-6">
                                    <div class="flex flex-wrap items-center gap-2.5">
                                        <a href="{{ route('buyer.orders.show', $order) }}"
                                           class="font-mono text-[13px] font-semibold text-[var(--text-ink)] hover:text-primary-800 hover:underline">
                                            Order #{{ $order->reference }}
                                        </a>
                                        <span class="font-sans text-[12px] text-[var(--text-muted)]">
                                            · {{ $order->created_at->format('M d, Y') }}
                                        </span>
                                    </div>

                                    <span class="inline-flex items-center rounded-md border px-2.5 py-0.5 font-sans text-[11px] font-semibold {{ $overall['color'] }}">
                                        {{ $overall['label'] }}
                                    </span>
                                </div>

                                {{-- Card Items --}}
                                <div class="divide-y divide-[var(--border-faint)] px-5 sm:px-6">
                                    @foreach ($order->sellerOrders as $sellerOrder)
                                        @foreach ($sellerOrder->orderItems as $item)
                                            <div class="flex items-center justify-between gap-4 py-4">
                                                <div class="flex min-w-0 items-center gap-3.5">
                                                    {{-- Thumbnail or fallback box icon --}}
                                                    <div class="flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-secondary-200 bg-secondary-100">
                                                        @if ($item->product && $item->product->catalogImageUrl())
                                                            <img src="{{ $item->product->catalogImageUrl() }}" alt="{{ $item->product_name }}" class="h-full w-full object-cover">
                                                        @else
                                                            <i data-lucide="package" width="22" height="22" class="text-primary-800/70"></i>
                                                        @endif
                                                    </div>

                                                    <div class="min-w-0">
                                                        <p class="truncate font-serif text-[14.5px] font-medium text-[var(--text-ink)]">
                                                            {{ $item->product_name }}
                                                        </p>
                                                        <p class="mt-0.5 truncate font-sans text-[11.5px] text-[var(--text-muted)]">
                                                            @if ($item->variantDescription() !== '')
                                                                {{ $item->variantDescription() }}<br>
                                                            @endif
                                                            Qty: {{ $item->quantity }} · {{ $sellerOrder->seller?->business_name ?? 'Shop item' }}
                                                        </p>
                                                    </div>
                                                </div>

                                                <div class="shrink-0 text-right">
                                                    <span class="font-sans text-[14px] font-semibold text-[var(--text-ink)]">
                                                        {{ $item->formattedLineTotal() }}
                                                    </span>
                                                </div>
                                            </div>
                                        @endforeach
                                    @endforeach
                                </div>

                                {{-- Card Footer --}}
                                <div class="flex flex-col gap-3 border-t border-[var(--border-faint)] bg-neutral-50/30 px-5 py-3.5 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                                    <div class="font-sans text-[13px] text-[var(--text-ink)]">
                                        Total: <span class="font-semibold">{{ $order->formattedTotal() }}</span>
                                        <span class="text-[12px] text-[var(--text-muted)]">({{ $totalItemsCount }} {{ $totalItemsCount === 1 ? 'item' : 'items' }})</span>
                                    </div>

                                    <div class="flex flex-wrap items-center gap-2">
                                        <a href="{{ route('buyer.orders.show', $order) }}"
                                           class="inline-flex items-center justify-center rounded-xl border border-[var(--border)] bg-[var(--bg-surface)] px-4 py-2 font-sans text-[12px] font-medium text-[var(--text-ink)] transition-colors hover:bg-neutral-100">
                                            View Details
                                        </a>
                                    </div>
                                </div>
                            </section>
                        @endforeach

                        {{-- Pagination links --}}
                        <div class="mt-6">
                            {{ $orders->links() }}
                        </div>
                    </div>
                @endif
            </main>
        </div>
    </div>
</div>
@endsection
