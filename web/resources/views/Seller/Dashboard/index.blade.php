@extends('Layouts.seller')

@section('title', 'Dashboard')

@push('scripts')
    @vite('resources/js/seller/notification-bell.js')
@endpush

@section('content')
    <x-seller.dashboard-hero
        :name="$seller->business_name ?? $seller->fullName()"
        :date="now()->format('l, F j, Y')"
        :notifications="$notifications"
        :unreadCount="$unreadCount"
    />

    {{-- ========================== STAT CARDS =========================== --}}
    <section class="grid grid-cols-4 gap-5 mb-6 max-lg:grid-cols-2 max-sm:grid-cols-1">
        <div class="bg-[var(--bg-surface)] border border-[var(--border-strong)] rounded-lg p-5 shadow-sm">
            <div class="w-[36px] h-[36px] rounded-md flex items-center justify-center mb-4 bg-primary-500 text-[#fff8f7]">
                <i data-lucide="wallet" class="w-[18px] h-[18px]"></i>
            </div>
            <p class="font-sans text-[12.5px] text-[var(--text-muted)] mb-1">Total sales</p>
            <p class="font-sans text-[24px] font-extrabold text-[var(--text-ink)]">
                @if(is_null($totalSales))
                    <span class="text-[var(--text-muted)] font-medium">—</span>
                @else
                    ₱{{ number_format($totalSales, 2) }}
                @endif
            </p>
        </div>

        <div class="bg-[var(--bg-surface)] border border-[var(--border-strong)] rounded-lg p-5 shadow-sm">
            <div class="w-[36px] h-[36px] rounded-md flex items-center justify-center mb-4 bg-secondary-700 text-[#fff8f7]">
                <i data-lucide="shopping-bag" class="w-[18px] h-[18px]"></i>
            </div>
            <p class="font-sans text-[12.5px] text-[var(--text-muted)] mb-1">Total orders</p>
            <p class="font-sans text-[24px] font-extrabold text-[var(--text-ink)]">
                {{ is_null($totalOrders) ? '—' : number_format($totalOrders) }}
            </p>
        </div>

        <div class="bg-[var(--bg-surface)] border border-[var(--border-strong)] rounded-lg p-5 shadow-sm">
            <div class="w-[36px] h-[36px] rounded-md flex items-center justify-center mb-4 bg-warning text-[#fff8f7]">
                <i data-lucide="clock" class="w-[18px] h-[18px]"></i>
            </div>
            <p class="font-sans text-[12.5px] text-[var(--text-muted)] mb-1">Pending orders</p>
            <p class="font-sans text-[24px] font-extrabold text-[var(--text-ink)]">
                {{ is_null($pendingOrders) ? '—' : number_format($pendingOrders) }}
            </p>
        </div>

        <div class="bg-[var(--bg-surface)] border border-[var(--border-strong)] rounded-lg p-5 shadow-sm">
            <div class="w-[36px] h-[36px] rounded-md flex items-center justify-center mb-4 bg-tertiary-500 text-[#fff8f7]">
                <i data-lucide="package" class="w-[18px] h-[18px]"></i>
            </div>
            <p class="font-sans text-[12.5px] text-[var(--text-muted)] mb-1">Products</p>
            <p class="font-sans text-[24px] font-extrabold text-[var(--text-ink)]">{{ number_format($productCount) }}</p>
            <p class="font-sans text-xs text-[var(--text-muted)] mt-1">{{ $activeProductCount }} active</p>
        </div>
    </section>

        <div class="grid grid-cols-[2fr_1fr] gap-5 mb-5 max-lg:grid-cols-1">

        {{-- ========================= SALES CHART ========================= --}}
        <section class="bg-[var(--bg-surface)] border border-[var(--border-strong)] rounded-lg p-5 shadow-sm flex flex-col">
            <div class="flex items-center justify-between mb-5">
                <h2 class="font-sans text-base font-semibold text-[var(--text-ink)]">Sales performance</h2>
                <span class="font-sans text-[11.5px] font-semibold text-[var(--text-muted)] bg-[var(--bg-chip)] rounded-full px-1 px-3">Last 7 days</span>
            </div>

            @if($ordersModuleReady)
                @php
                    $max = max(1, $salesTrend->max('value'));
                    $points = $salesTrend->values()->map(function ($point, $i) use ($salesTrend, $max) {
                        $x = $salesTrend->count() > 1 ? ($i / ($salesTrend->count() - 1)) * 100 : 0;
                        $y = 100 - (($point['value'] / $max) * 90);
                        return "$x,$y";
                    })->implode(' ');
                @endphp
                <svg class="w-full h-[160px]" viewBox="0 0 100 100" preserveAspectRatio="none">
                    <polyline
                        fill="none"
                        stroke="var(--brand)"
                        stroke-width="1.5"
                        vector-effect="non-scaling-stroke"
                        points="{{ $points }}"
                    />
                </svg>
                <div class="flex justify-between mt-2 font-sans text-[11.5px] text-[var(--text-muted)]">
                    @foreach($salesTrend as $point)
                        <span>{{ $point['label'] }}</span>
                    @endforeach
                </div>
            @else
                <div class="flex flex-col items-center justify-center text-center p-10 px-4">
                    <i data-lucide="line-chart" class="w-[28px] h-[28px] text-[var(--icon-soft)] mb-3"></i>
                    <p class="font-sans text-sm font-semibold text-[var(--text-ink)] mb-1">No sales data yet</p>
                    <p class="font-sans text-[12.5px] text-[var(--text-muted)] max-w-[320px]">
                        Your sales trend will appear here once the order system goes live and your first orders come in.
                    </p>
                </div>
            @endif
        </section>

        {{-- ========================= LOW STOCK =========================== --}}
        <section class="bg-[var(--bg-surface)] border border-[var(--border-strong)] rounded-lg p-5 shadow-sm">
            <div class="flex items-center justify-between mb-5">
                <h2 class="font-sans text-base font-semibold text-[var(--text-ink)]">Low-stock products</h2>
                @if($lowStockCount > 0)
                    <span class="font-sans text-[11.5px] font-semibold text-[var(--status-warning)] bg-[var(--pill-warning-bg)] rounded-full px-1 px-3">{{ $lowStockCount }}</span>
                @endif
            </div>

            @if($lowStockProducts->isEmpty())
                <div class="flex flex-col items-center justify-center text-center p-6 px-4">
                    <i data-lucide="check-circle-2" class="w-[28px] h-[28px] text-[var(--icon-soft)] mb-3"></i>
                    <p class="font-sans text-sm font-semibold text-[var(--text-ink)] mb-1">All stocked up</p>
                    <p class="font-sans text-[12.5px] text-[var(--text-muted)] max-w-[320px]">No products are at or below their low-stock threshold.</p>
                </div>
            @else
                <ul class="flex flex-col gap-5">
                    @foreach($lowStockProducts as $product)
                        <li class="flex items-center justify-between pb-5 border-b border-[var(--border-strong)] last:border-b-0 last:pb-0">
                            <div>
                                <p class="font-sans text-[13px] font-semibold text-[var(--text-ink)]">{{ $product->name }}</p>
                                <p class="font-sans text-xs text-[var(--text-muted)] mt-2">
                                    Threshold: {{ $product->low_stock_threshold }}
                                </p>
                            </div>
                            <span class="font-sans text-[11.5px] font-semibold text-[var(--status-warning)] bg-[var(--pill-warning-bg)] rounded-full px-1 px-3 whitespace-nowrap {{ $product->stock_quantity == 0 ? 'text-[var(--status-danger)] bg-[var(--pill-danger-bg)]' : '' }}">
                                {{ $product->stock_quantity }} left
                            </span>
                        </li>
                    @endforeach
                </ul>
                <a href="{{ route('seller.products.index', ['stock' => 'low']) }}" class="inline-flex items-center gap-1 mt-5 font-sans text-[12.5px] font-semibold text-[var(--link)] no-underline">
                    View low-stock <i data-lucide="arrow-right" class="w-[14px] h-[14px]"></i>
                </a>
            @endif
        </section>
    </div>

    {{-- =========================== RECENT ORDERS ======================== --}}
    <section class="bg-[var(--bg-surface)] border border-[var(--border-strong)] rounded-lg p-5 shadow-sm">
        <div class="flex items-center justify-between mb-5">
            <h2 class="font-sans text-base font-semibold text-[var(--text-ink)]">Recent orders</h2>
        </div>

        @if(!$ordersModuleReady || $recentOrders->isEmpty())
            <div class="flex flex-col items-center justify-center text-center p-10 px-4">
                <i data-lucide="shopping-bag" class="w-[28px] h-[28px] text-[var(--icon-soft)] mb-3"></i>
                <p class="font-sans text-sm font-semibold text-[var(--text-ink)] mb-1">No orders yet</p>
                <p class="font-sans text-[12.5px] text-[var(--text-muted)] max-w-[320px]">
                    Orders will show up here as soon as buyers start checking out from your store.
                </p>
            </div>
        @else
            <table class="w-full border-collapse [&>tbody>tr:last-child>td]:border-b-0">
                <thead>
                    <tr>
                        <th class="text-left font-sans text-[11px] font-semibold tracking-[0.6px] uppercase text-[var(--text-muted)] px-2 px-3 border-b border-neutral-200">Order</th>
                        <th class="text-left font-sans text-[11px] font-semibold tracking-[0.6px] uppercase text-[var(--text-muted)] px-2 px-3 border-b border-neutral-200">Buyer</th>
                        <th class="text-left font-sans text-[11px] font-semibold tracking-[0.6px] uppercase text-[var(--text-muted)] px-2 px-3 border-b border-neutral-200">Status</th>
                        <th class="text-left font-sans text-[11px] font-semibold tracking-[0.6px] uppercase text-[var(--text-muted)] px-2 px-3 border-b border-neutral-200">Date</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($recentOrders as $order)
                        <tr>
                            <td class="font-sans text-[13.5px] text-[var(--text-ink)] px-3 border-b border-neutral-200">#{{ $order->id }}</td>
                            <td class="font-sans text-[13.5px] text-[var(--text-ink)] px-3 border-b border-neutral-200">{{ $order->buyer_name }}</td>
                            <td class="font-sans text-[13.5px] text-[var(--text-ink)] px-3 border-b border-neutral-200">{{ $order->status }}</td>
                            <td class="font-sans text-[13.5px] text-[var(--text-ink)] px-3 border-b border-neutral-200">{{ $order->created_at->format('M d, Y') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>
@endsection
