@extends('Layouts.seller')

@section('title', 'Dashboard')

@section('content')
    <header class="seller-page-header">
        <div>
            <h1 class="seller-page-header__title">
                Welcome back, {{ $seller->business_name ?? $seller->fullName() }}
            </h1>
            <p class="seller-page-header__sub">
                Here's what's happening with your store today.
            </p>
        </div>
        <div class="seller-page-header__meta">
            {{ now()->format('l, F j, Y') }}
        </div>
    </header>

    {{-- ========================== STAT CARDS =========================== --}}
    <section class="stat-grid">
        <div class="stat-card">
            <div class="stat-card__icon stat-card__icon--rose">
                <i data-lucide="wallet"></i>
            </div>
            <p class="stat-card__label">Total sales</p>
            <p class="stat-card__value">
                @if(is_null($totalSales))
                    <span class="stat-card__placeholder">—</span>
                @else
                    ₱{{ number_format($totalSales, 2) }}
                @endif
            </p>
        </div>

        <div class="stat-card">
            <div class="stat-card__icon stat-card__icon--coral">
                <i data-lucide="shopping-bag"></i>
            </div>
            <p class="stat-card__label">Total orders</p>
            <p class="stat-card__value">
                {{ is_null($totalOrders) ? '—' : number_format($totalOrders) }}
            </p>
        </div>

        <div class="stat-card">
            <div class="stat-card__icon stat-card__icon--warning">
                <i data-lucide="clock"></i>
            </div>
            <p class="stat-card__label">Pending orders</p>
            <p class="stat-card__value">
                {{ is_null($pendingOrders) ? '—' : number_format($pendingOrders) }}
            </p>
        </div>

        <div class="stat-card">
            <div class="stat-card__icon stat-card__icon--sage">
                <i data-lucide="package"></i>
            </div>
            <p class="stat-card__label">Products</p>
            <p class="stat-card__value">{{ number_format($productCount) }}</p>
            <p class="stat-card__footnote">{{ $activeProductCount }} active</p>
        </div>
    </section>

        <div class="seller-dashboard-grid">

        {{-- ========================= SALES CHART ========================= --}}
        <section class="panel panel--chart">
            <div class="panel__header">
                <h2 class="panel__title">Sales performance</h2>
                <span class="panel__badge">Last 7 days</span>
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
                <svg class="sales-chart" viewBox="0 0 100 100" preserveAspectRatio="none">
                    <polyline
                        fill="none"
                        stroke="var(--color-primary-500)"
                        stroke-width="1.5"
                        vector-effect="non-scaling-stroke"
                        points="{{ $points }}"
                    />
                </svg>
                <div class="sales-chart__labels">
                    @foreach($salesTrend as $point)
                        <span>{{ $point['label'] }}</span>
                    @endforeach
                </div>
            @else
                <div class="empty-state">
                    <i data-lucide="line-chart" class="empty-state__icon"></i>
                    <p class="empty-state__title">No sales data yet</p>
                    <p class="empty-state__body">
                        Your sales trend will appear here once the order system goes live and your first orders come in.
                    </p>
                </div>
            @endif
        </section>

        {{-- ========================= LOW STOCK =========================== --}}
        <section class="panel panel--low-stock">
            <div class="panel__header">
                <h2 class="panel__title">Low-stock products</h2>
                @if($lowStockCount > 0)
                    <span class="panel__badge panel__badge--warning">{{ $lowStockCount }}</span>
                @endif
            </div>

            @if($lowStockProducts->isEmpty())
                <div class="empty-state empty-state--compact">
                    <i data-lucide="check-circle-2" class="empty-state__icon"></i>
                    <p class="empty-state__title">All stocked up</p>
                    <p class="empty-state__body">No products are at or below their low-stock threshold.</p>
                </div>
            @else
                <ul class="low-stock-list">
                    @foreach($lowStockProducts as $product)
                        <li class="low-stock-item">
                            <div>
                                <p class="low-stock-item__name">{{ $product->name }}</p>
                                <p class="low-stock-item__meta">
                                    Threshold: {{ $product->low_stock_threshold }}
                                </p>
                            </div>
                            <span class="low-stock-item__qty {{ $product->stock_quantity == 0 ? 'is-out' : '' }}">
                                {{ $product->stock_quantity }} left
                            </span>
                        </li>
                    @endforeach
                </ul>
                <a href="{{ route('seller.inventory.index') }}" class="panel__link">
                    View inventory <i data-lucide="arrow-right"></i>
                </a>
            @endif
        </section>
    </div>

    {{-- =========================== RECENT ORDERS ======================== --}}
    <section class="panel panel--wide">
        <div class="panel__header">
            <h2 class="panel__title">Recent orders</h2>
        </div>

        @if(!$ordersModuleReady || $recentOrders->isEmpty())
            <div class="empty-state">
                <i data-lucide="shopping-bag" class="empty-state__icon"></i>
                <p class="empty-state__title">No orders yet</p>
                <p class="empty-state__body">
                    Orders will show up here as soon as buyers start checking out from your store.
                </p>
            </div>
        @else
            <table class="seller-table">
                <thead>
                    <tr>
                        <th>Order</th>
                        <th>Buyer</th>
                        <th>Status</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($recentOrders as $order)
                        <tr>
                            <td>#{{ $order->id }}</td>
                            <td>{{ $order->buyer_name }}</td>
                            <td>{{ $order->status }}</td>
                            <td>{{ $order->created_at->format('M d, Y') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>
@endsection
