@extends('Layouts.seller')

@section('title', 'Dashboard')

@use('App\Enums\OrderStatus')

@push('scripts')
    @vite('resources/js/seller/notification-bell.js')
@include('Seller.Orders._confirm-dialog')
<script>
    function confirmAccept(btn) {
        sellerConfirm('Accept this order? You can pack it in the next step.', () => btn.closest('form').submit());
    }
    function confirmMarkReady(btn) {
        sellerConfirm('Request pickup for this packed order? This schedules the current eligible or next pickup window. Logistics chooses the rider. No rider is assigned yet.', () => btn.closest('form').submit());
    }
</script>
@endpush

@section('content')
    <x-seller.dashboard-hero
        :name="$seller->business_name ?? $seller->fullName()"
        :date="now(\App\Models\Seller\StoreSale::TIMEZONE)->format('l, F j, Y')"
        :notifications="$notifications"
        :unreadCount="$unreadCount"
    />

    @unless ($shippingReady)
        <section aria-labelledby="shipping-readiness-title" class="mb-6 flex flex-wrap items-start justify-between gap-4 rounded-lg border border-[var(--status-warning)] bg-[var(--bg-surface)] p-5">
            <div class="flex min-w-0 basis-full items-start gap-3 sm:flex-1">
                <i data-lucide="truck" aria-hidden="true" class="mt-0.5 size-5 shrink-0 text-[var(--status-warning)]"></i>
                <div>
                    <h2 id="shipping-readiness-title" class="font-sans text-sm font-semibold text-[var(--text-ink)]">Set up shipping before buyers order</h2>
                    <p class="mt-1 max-w-2xl text-xs leading-relaxed text-[var(--text-muted)]">You can publish products now. Buyers need an enabled, available shipping provider that covers your pickup address to place shippable orders.</p>
                </div>
            </div>
            <a href="{{ route('seller.shipping-providers.index') }}" class="inline-flex min-h-10 items-center gap-2 rounded-md bg-primary-800 px-4 text-xs font-semibold text-white hover:bg-primary-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-700">Enable Shipping Provider <i data-lucide="arrow-right" aria-hidden="true" class="size-3.5"></i></a>
        </section>
    @endunless

    @if ($pendingReturnDecisionCount > 0)
        <section aria-labelledby="return-reminder-title" class="mb-6 overflow-hidden rounded-lg border border-[var(--status-warning)] bg-[var(--bg-surface)]">
            <div class="flex flex-wrap items-start justify-between gap-3 p-5">
                <div class="flex items-start gap-3">
                    <i data-lucide="rotate-ccw" aria-hidden="true" class="mt-0.5 size-5 shrink-0 text-[var(--status-warning)]"></i>
                    <div>
                        <h2 id="return-reminder-title" class="font-sans text-sm font-semibold text-[var(--text-ink)]">{{ $pendingReturnDecisionCount }} return {{ Str::plural('request', $pendingReturnDecisionCount) }} awaiting review</h2>
                        <p class="mt-1 text-xs leading-relaxed text-[var(--text-muted)]">Review within 3 days. Requests without a decision are approved automatically at the deadline.</p>
                    </div>
                </div>
                <a href="{{ route('seller.returns.index') }}" class="inline-flex min-h-9 items-center gap-2 text-xs font-semibold text-[var(--link)] hover:underline">Review returns <i data-lucide="arrow-right" aria-hidden="true" class="size-3.5"></i></a>
            </div>
            <ul class="divide-y divide-[var(--border-strong)] border-t border-[var(--border-strong)]">
                @foreach ($pendingReturnDecisions as $returnDecision)
                    @php
                        $decisionDue = $returnDecision->seller_response_due_at?->copy()->timezone(\App\Models\Seller\StoreSale::TIMEZONE);
                        $decisionOverdue = $decisionDue && $decisionDue->isPast();
                        $decisionUrgent = $decisionDue && $decisionDue->lessThanOrEqualTo(now()->addDay());
                    @endphp
                    <li class="flex flex-wrap items-center justify-between gap-2 px-5 py-3">
                        <a href="{{ route('seller.orders.show', $returnDecision->seller_order_id) }}" class="text-xs font-semibold text-[var(--link)] hover:underline">Order #{{ $returnDecision->seller_order_id }}</a>
                        <div class="flex flex-wrap items-center gap-2">
                            @if ($decisionUrgent)
                                <span class="rounded-md bg-[var(--pill-warning-bg)] px-2 py-1 text-[11px] font-semibold text-[var(--status-warning)]">{{ $decisionOverdue ? 'Overdue - auto-approval pending' : 'Due within 24 hours' }}</span>
                            @endif
                            <p class="text-xs text-[var(--text-muted)]">{{ $decisionDue ? 'Deadline: '.manila($decisionDue, 'M j, Y g:i A').' Philippine time' : 'Deadline not available - review in Returns' }}</p>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- ========================== STAT CARDS =========================== --}}
    @php
        // Mirrors the Admin dashboard card geometry using theme-aware Seller surfaces.
        $summaryCard = 'relative flex min-w-0 flex-col overflow-hidden rounded-2xl border border-l-4 border-[var(--border-strong)] bg-[var(--bg-surface)] p-5 shadow-sm';
        $summaryLink = $summaryCard.' no-underline transition-colors hover:border-[var(--brand)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--brand)]';
        $summaryIcon = 'absolute right-4 top-4 flex size-9 items-center justify-center rounded-lg';
        $summaryLabel = 'pr-11 font-sans text-[13px] font-medium text-[var(--text-muted)]';
        $summaryValue = 'mt-4 break-words font-serif text-[28px] font-bold leading-tight text-[var(--text-ink)]';
        $summaryHint = 'mt-1 font-sans text-xs text-[var(--text-muted)]';
    @endphp
    <section aria-label="Store summary" class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
        <a href="{{ route('seller.reports.index') }}" class="{{ $summaryLink }} border-l-primary-700">
            <div class="{{ $summaryIcon }} bg-primary-100 text-primary-800">
                <i data-lucide="wallet" class="size-[18px]" aria-hidden="true"></i>
            </div>
            <p class="{{ $summaryLabel }}">Total sales</p>
            <p class="{{ $summaryValue }}">
                @if(is_null($totalSales))
                    <span class="text-[var(--text-muted)] font-medium">—</span>
                @else
                    ₱{{ number_format($totalSales, 2) }}
                @endif
            </p>
            <p class="{{ $summaryHint }}">Delivered and completed orders, excluding returns and refunds.</p>
        </a>

        <a href="{{ route('seller.earnings.index') }}" data-net-earnings class="{{ $summaryLink }} border-l-blue-500">
            <div class="{{ $summaryIcon }} bg-blue-50 text-blue-800">
                <i data-lucide="banknote" class="size-[18px]" aria-hidden="true"></i>
            </div>
            <p class="{{ $summaryLabel }}">Net Earnings</p>
            <p class="{{ $summaryValue }}">₱{{ number_format($netEarningsMinor / 100, 2) }}</p>
            <p class="{{ $summaryHint }}">After commission and seller-funded discounts; excludes shipping.</p>
            @if ($earningsPendingCount > 0)
                <p class="{{ $summaryHint }}">{{ number_format($earningsPendingCount) }} completed {{ Str::plural('order', $earningsPendingCount) }} awaiting finalized earnings.</p>
            @endif
        </a>

        <a href="{{ route('seller.orders.index') }}" class="{{ $summaryLink }} border-l-red-400">
            <div class="{{ $summaryIcon }} bg-red-50 text-red-800">
                <i data-lucide="shopping-bag" class="size-[18px]" aria-hidden="true"></i>
            </div>
            <p class="{{ $summaryLabel }}">Total orders</p>
            <p class="{{ $summaryValue }}">
                {{ is_null($totalOrders) ? '—' : number_format($totalOrders) }}
            </p>
            <p class="{{ $summaryHint }}">Every order, in any status.</p>
        </a>

        <a href="{{ route('seller.orders.index', ['bucket' => 'delivered']) }}" data-completed-orders class="{{ $summaryLink }} border-l-emerald-500">
            <div class="{{ $summaryIcon }} bg-emerald-50 text-emerald-800">
                <i data-lucide="circle-check" class="size-[18px]" aria-hidden="true"></i>
            </div>
            <p class="{{ $summaryLabel }}">Completed Orders</p>
            <p class="{{ $summaryValue }}">{{ number_format($completedOrders) }}</p>
            <p class="{{ $summaryHint }}">Received by the buyer.</p>
        </a>

        <a href="{{ route('seller.orders.index', ['bucket' => 'to_ship']) }}" data-pending-orders class="{{ $summaryLink }} border-l-amber-500">
            <div class="{{ $summaryIcon }} bg-amber-50 text-amber-800">
                <i data-lucide="clock" class="size-[18px]" aria-hidden="true"></i>
            </div>
            <p class="{{ $summaryLabel }}">Pending orders</p>
            <p class="{{ $summaryValue }}">
                {{ is_null($pendingOrders) ? '—' : number_format($pendingOrders) }}
            </p>
            <p class="{{ $summaryHint }}">
                {{ $pendingOrders > 0 ? 'Waiting for you to prepare them.' : "You're all caught up." }}
            </p>
        </a>

        {{-- The whole card links through the title; the weight warning stays its own link on top. --}}
        <div class="{{ $summaryCard }} border-l-violet-400 transition-colors hover:border-[var(--brand)]">
            <div class="{{ $summaryIcon }} bg-violet-50 text-violet-800">
                <i data-lucide="package" class="size-[18px]" aria-hidden="true"></i>
            </div>
            <a href="{{ route('seller.products.index') }}" class="block no-underline after:absolute after:inset-0">
                <span class="block {{ $summaryLabel }}">Products</span>
                <span class="block {{ $summaryValue }}">{{ number_format($productCount) }}</span>
                <span class="block {{ $summaryHint }}">{{ $activeProductCount }} active</span>
            </a>
            @if ($productsMissingWeight > 0)
                <a href="{{ route('seller.products.index') }}"
                   class="relative z-10 font-sans text-xs font-semibold text-[var(--status-warning)] mt-1 inline-block no-underline">
                    {{ $productsMissingWeight }} {{ Str::plural('product', $productsMissingWeight) }} need{{ $productsMissingWeight === 1 ? 's' : '' }} weight
                </a>
            @endif
        </div>
    </section>

        <div class="grid grid-cols-[2fr_1fr] gap-5 mb-5 max-lg:grid-cols-1">

        {{-- ========================= SALES CHART ========================= --}}
        <section class="flex min-w-0 flex-col rounded-2xl border border-[var(--border-strong)] bg-[var(--bg-surface)] p-5 shadow-sm sm:p-6">
            <div class="flex items-center justify-between gap-3 mb-5">
                <h2 class="font-sans text-[15px] font-semibold text-[var(--text-ink)]">Sales performance</h2>
                <a href="{{ route('seller.reports.index') }}" class="inline-flex items-center gap-1 font-sans text-[12.5px] font-semibold text-[var(--link)] no-underline">
                    Full report <i data-lucide="arrow-right" class="w-[14px] h-[14px]" aria-hidden="true"></i>
                </a>
            </div>

            @if($ordersModuleReady)
                <x-seller.sales-chart :summary="$salesSummary" />
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
        <section class="min-w-0 self-start rounded-2xl border border-[var(--border-strong)] bg-[var(--bg-surface)] p-5 shadow-sm sm:p-6">
            <div class="flex items-center justify-between mb-5">
                <h2 class="font-sans text-[15px] font-semibold text-[var(--text-ink)]">Low-stock products</h2>
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
                            <span class="font-sans text-[11.5px] font-semibold text-[var(--status-warning)] bg-[var(--pill-warning-bg)] rounded-full px-1 px-3 whitespace-nowrap {{ ($product->variants_stock_total ?? 0) == 0 ? 'text-[var(--status-danger)] bg-[var(--pill-danger-bg)]' : '' }}">
                                {{ $product->variants_stock_total ?? 0 }} left
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
    <section class="min-w-0 rounded-2xl border border-[var(--border-strong)] bg-[var(--bg-surface)] p-5 shadow-sm sm:p-6">
        <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
            <div><h2 class="font-sans text-[15px] font-semibold text-[var(--text-ink)]">Recent orders</h2><p class="mt-1 text-xs text-[var(--text-muted)]">1. Accept &nbsp; 2. Pack &nbsp; 3. Request pickup</p></div>
            <a href="{{ route('seller.orders.index') }}" class="inline-flex items-center gap-1 font-sans text-[12.5px] font-semibold text-[var(--link)] no-underline">
                All orders <i data-lucide="arrow-right" class="w-[14px] h-[14px]" aria-hidden="true"></i>
            </a>
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
            <div class="overflow-x-auto">
            <table class="w-full border-collapse [&>tbody>tr:last-child>td]:border-b-0">
                <thead>
                    <tr>
                        <th class="text-left font-sans text-[11px] font-semibold tracking-[0.6px] uppercase text-[var(--text-muted)] py-2 px-3 border-b border-[var(--border-strong)]">Order</th>
                        <th class="text-left font-sans text-[11px] font-semibold tracking-[0.6px] uppercase text-[var(--text-muted)] py-2 px-3 border-b border-[var(--border-strong)]">Buyer</th>
                        <th class="text-left font-sans text-[11px] font-semibold tracking-[0.6px] uppercase text-[var(--text-muted)] py-2 px-3 border-b border-[var(--border-strong)]">Status</th>
                        <th class="text-left font-sans text-[11px] font-semibold tracking-[0.6px] uppercase text-[var(--text-muted)] py-2 px-3 border-b border-[var(--border-strong)]">Date</th>
                        <th class="relative py-2 px-3 border-b border-[var(--border-strong)]"><span class="sr-only">Action</span></th>
                    </tr>
                </thead>
                 <tbody>
                    @foreach($recentOrders as $sellerOrder)
                         @php
                             $order = $sellerOrder->order ?? null;
                         @endphp
                         <tr>
                             <td class="font-sans text-[13.5px] text-[var(--text-ink)] py-3 px-3 border-b border-[var(--border-strong)]">#{{ $sellerOrder->id }}</td>
                             <td class="font-sans text-[13.5px] text-[var(--text-ink)] py-3 px-3 border-b border-[var(--border-strong)]">{{ $order?->buyer?->fullName() ?? '—' }}</td>
                              <td class="py-3 px-3 border-b border-[var(--border-strong)]"><x-seller.order-status-pill :status="$sellerOrder->status" :delivery-attempts="(int) $sellerOrder->delivery_attempts" /></td>
                              <td class="whitespace-nowrap font-sans text-[13.5px] text-[var(--text-ink)] py-3 px-3 border-b border-[var(--border-strong)]">{{ $sellerOrder->created_at->timezone(\App\Models\Seller\StoreSale::TIMEZONE)->format('M d, Y') }}</td>
                              <td class="py-3 px-3 text-right border-b border-[var(--border-strong)]">
                                  @if(in_array($sellerOrder->status, [OrderStatus::Placed, OrderStatus::Confirmed, OrderStatus::Preparing], true))
                                      @php
                                          $nextAction = match ($sellerOrder->status) {
                                              OrderStatus::Placed => ['route' => 'seller.orders.accept', 'label' => 'Accept order', 'confirm' => 'confirmAccept(this)'],
                                              OrderStatus::Confirmed => ['route' => 'seller.orders.pack', 'label' => 'Pack order', 'confirm' => 'this.form.submit()'],
                                              OrderStatus::Preparing => ['route' => 'seller.orders.markReady', 'label' => 'Request Pickup', 'confirm' => 'confirmMarkReady(this)'],
                                          };
                                      @endphp
                                      <form method="POST" action="{{ route($nextAction['route'], $sellerOrder) }}">
                                          @csrf
                                          <button type="button"
                                              class="inline-flex min-h-9 items-center justify-center rounded-sm bg-primary-700 px-3 font-sans text-[11.5px] font-semibold text-white hover:bg-primary-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-700"
                                              onclick="{{ $nextAction['confirm'] }}">
                                              {{ $nextAction['label'] }}
                                          </button>
                                      </form>
                                  @else
                                      <a href="{{ route('seller.orders.show', $sellerOrder) }}" class="font-sans text-[12px] font-semibold text-[var(--link)]">View</a>
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
