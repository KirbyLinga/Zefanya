@extends('Admin.Layouts.app')

@section('title', 'Dashboard')

@section('content')
    @php
        $peso = fn (int $minor): string => ($minor < 0 ? '−' : '').'₱'.number_format(abs($minor) / 100, 2);
        $card = 'relative flex flex-col rounded-2xl border border-neutral-200 bg-white p-5 no-underline';
        $linkedCard = $card.' transition-colors hover:border-primary-800';
        $iconChip = 'absolute right-4 top-4 flex size-9 items-center justify-center rounded-lg bg-primary-100 text-primary-800';
        $cardLabel = 'pr-11 font-sans text-[13px] font-medium text-neutral-600';
        $value = 'mt-4 font-serif text-[28px] font-bold leading-tight text-neutral-950';
        $cardHint = 'mt-1 font-sans text-xs text-neutral-600';
        $panel = 'rounded-2xl border border-neutral-200 bg-white p-5 sm:p-6';
        $panelTitle = 'font-sans text-[15px] font-semibold text-neutral-950';
        $th = 'whitespace-nowrap border-b border-neutral-200 py-2.5 font-sans text-[11px] font-semibold uppercase tracking-[0.6px] text-neutral-600';
        $td = 'whitespace-nowrap border-b border-neutral-200 py-3.5 font-sans text-[13px] text-neutral-950';
        $cards = [
            ['label' => 'Active Users', 'value' => number_format($activeUsers), 'hint' => $activeBreakdown ?: 'No approved accounts yet', 'icon' => 'users', 'url' => $canModerate ? route('admin.users.index') : null],
            ['label' => 'Pending Registrations', 'value' => number_format($pendingRegistrations), 'hint' => $pendingRegistrations > 0 ? 'Waiting for approval' : 'None waiting', 'icon' => 'user-check', 'url' => $canModerate ? route('admin.registrations.index') : null],
            ['label' => 'Open Disputes', 'value' => number_format($openDisputes), 'hint' => 'Escalated return requests', 'icon' => 'scale', 'url' => $canModerate ? route('admin.returns.index') : null],
        ];
        $isCurrent = $period->isSameMonth(now(\App\Models\Seller\StoreSale::TIMEZONE));
        $periodLabel = $period->format('F Y');
        if ($money !== null) {
            $cards[] = ['label' => $isCurrent ? 'Commission (This Month)' : 'Commission ('.$periodLabel.')', 'value' => $peso($money['commission']), 'hint' => 'Revenue: '.(\App\Services\Shared\CommissionFinalizationService::COMMISSION_BPS / 100).'% of item subtotal after seller discounts', 'icon' => 'percent', 'url' => null];
            if ($position !== null) {
                $cards[] = ['label' => 'Zefanya Owes', 'value' => $peso($position['totalLiabilitiesMinor']), 'hint' => 'Seller payouts, logistics shares and refunds (live)', 'icon' => 'arrow-up-right', 'url' => route('admin.seller-payouts.index')];
                $cards[] = ['label' => 'Cash Not Yet Received', 'value' => $peso($position['outstandingCashMinor']), 'hint' => 'With riders, companies or awaiting your confirmation (live)', 'icon' => 'arrow-down-left', 'url' => route('admin.logistics-remittances.index')];
            }
        }
        $waiting = collect($attention)->where('count', '>', 0)->sortByDesc('count')->values();
        $quiet = collect($attention)->where('count', 0)->values();
    @endphp

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="font-serif text-[28px] font-bold text-neutral-950">Welcome back, {{ $admin?->name ?? 'Admin' }}</h1>
            <p class="mt-1 font-sans text-sm text-neutral-600">Here's what needs your attention across Zefanya.</p>
        </div>
        @if ($money !== null)
            <form method="GET" action="{{ route('admin.dashboard') }}" class="flex items-center gap-2">
                <label for="dashboardMonth" class="inline-flex items-center gap-2 font-sans text-[13px] font-semibold text-neutral-950"><i data-lucide="calendar" class="size-4" aria-hidden="true"></i>Month</label>
                <select id="dashboardMonth" name="month" onchange="this.form.submit()" class="min-h-10 rounded-lg border border-neutral-200 bg-white px-3 font-sans text-[13px] font-semibold text-neutral-950">
                    @foreach ($periodOptions as $option)
                        <option value="{{ $option['value'] }}" @selected($option['value'] === $period->format('Y-m'))>{{ $option['label'] }}</option>
                    @endforeach
                </select>
                <noscript><button type="submit" class="min-h-10 rounded-lg border border-neutral-200 bg-white px-3 font-sans text-[13px] font-semibold">Apply</button></noscript>
            </form>
        @endif
    </div>

    @if ($canModerate)
        <section data-attention class="mt-6 {{ $panel }}">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="{{ $panelTitle }}">Needs your action</h2>
                <p class="font-sans text-xs text-neutral-600">{{ $waiting->isEmpty() ? 'Nothing is waiting on you.' : $waiting->sum('count').' item'.($waiting->sum('count') === 1 ? '' : 's').' across '.$waiting->count().' queue'.($waiting->count() === 1 ? '' : 's') }}</p>
            </div>
            @if ($waiting->isEmpty())
                <p class="mt-4 rounded-xl bg-primary-50 px-4 py-6 text-center font-sans text-[13px] text-neutral-600">You're all caught up. New work will appear here.</p>
            @endif
            <ul class="mt-3 grid grid-cols-1 gap-x-8 divide-y divide-neutral-200 xl:grid-cols-2 xl:divide-y-0">
                @foreach ($waiting as $item)
                    @include('Admin.partials.attention-row', ['item' => $item, 'dim' => false])
                @endforeach
            </ul>
            @if ($quiet->isNotEmpty())
                <details class="mt-3 rounded-xl bg-primary-50 px-4 py-3">
                    <summary class="cursor-pointer font-sans text-xs font-semibold text-neutral-600">{{ $quiet->count() }} queue{{ $quiet->count() === 1 ? '' : 's' }} with nothing waiting</summary>
                    <ul class="mt-2 divide-y divide-neutral-200">
                        @foreach ($quiet as $item)
                            @include('Admin.partials.attention-row', ['item' => $item, 'dim' => true])
                        @endforeach
                    </ul>
                </details>
            @endif
        </section>
    @endif

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 {{ $money !== null ? 'xl:grid-cols-3' : 'xl:grid-cols-3' }}">
        @foreach ($cards as $item)
            @if ($item['url'])
                <a href="{{ $item['url'] }}" class="{{ $linkedCard }}">
                    <span class="{{ $iconChip }}"><i data-lucide="{{ $item['icon'] }}" class="size-[18px]" aria-hidden="true"></i></span>
                    <span class="{{ $cardLabel }}">{{ $item['label'] }}</span>
                    <span class="{{ $value }}">{{ $item['value'] }}</span>
                    <span class="{{ $cardHint }}">{{ $item['hint'] }}</span>
                </a>
            @else
                <div class="{{ $card }}">
                    <span class="{{ $iconChip }}"><i data-lucide="{{ $item['icon'] }}" class="size-[18px]" aria-hidden="true"></i></span>
                    <span class="{{ $cardLabel }}">{{ $item['label'] }}</span>
                    <span class="{{ $value }}">{{ $item['value'] }}</span>
                    <span class="{{ $cardHint }}">{{ $item['hint'] }}</span>
                </div>
            @endif
        @endforeach
    </div>

    <div class="mt-6 grid grid-cols-1 items-start gap-6 xl:grid-cols-2">

        @if ($money !== null)
            <section data-platform-money class="{{ $panel }}">
                <h2 class="{{ $panelTitle }}">Platform money {{ $isCurrent ? 'this month' : 'in '.$periodLabel }}</h2>
                <p class="mt-1 font-sans text-xs text-neutral-600">What Zefanya earns and what it funds</p>
                @if ($money['commission'] === 0 && $money['platformVouchers'] === 0 && $money['shippingVouchers'] === 0)
                    <p class="mt-3 rounded-xl bg-primary-50 px-4 py-4 text-center font-sans text-[13px] text-neutral-600">No orders were finalized {{ $isCurrent ? 'this month yet' : 'in '.$periodLabel }}. Commission is counted when an order is completed.</p>
                @endif
                <dl class="mt-3 divide-y divide-neutral-200">
                    <div class="flex items-center justify-between gap-4 py-3">
                        <dt class="font-sans text-[13px] font-semibold text-neutral-950">Commission revenue<span class="block text-xs font-normal text-neutral-600">{{ \App\Services\Shared\CommissionFinalizationService::COMMISSION_BPS / 100 }}% on finalized orders</span></dt>
                        <dd class="font-sans text-sm font-semibold text-neutral-950">+ {{ $peso($money['commission']) }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-4 py-3">
                        <dt class="font-sans text-[13px] font-semibold text-neutral-950">Platform vouchers<span class="block text-xs font-normal text-neutral-600">Discounts Zefanya paid for</span></dt>
                        <dd class="font-sans text-sm font-semibold text-neutral-950">− {{ $peso($money['platformVouchers']) }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-4 py-3">
                        <dt class="font-sans text-[13px] font-semibold text-neutral-950">Shipping vouchers<span class="block text-xs font-normal text-neutral-600">Shipping fees Zefanya paid for</span></dt>
                        <dd class="font-sans text-sm font-semibold text-neutral-950">− {{ $peso($money['shippingVouchers']) }}</dd>
                    </div>
                </dl>
                <div class="mt-2 flex items-center justify-between gap-4 rounded-xl bg-primary-50 px-4 py-4">
                    <span class="font-sans text-[13px] font-semibold text-neutral-950">Platform net earnings<span class="block text-xs font-normal text-neutral-600">Commission revenue minus vouchers Zefanya funded</span></span>
                    <span class="font-serif text-xl font-bold text-neutral-950">{{ $peso($money['net']) }}</span>
                </div>
                <p class="mt-3 font-sans text-xs text-neutral-600">Store vouchers are seller-funded and never appear here.</p>
            </section>
        @else
            <section data-marketplace class="{{ $panel }} {{ $canModerate ? '' : 'xl:col-span-2' }}">
                <h2 class="{{ $panelTitle }}">Marketplace activity</h2>
                <dl class="mt-3 divide-y divide-neutral-200">
                    <div class="flex items-center justify-between gap-4 py-3">
                        <dt class="font-sans text-[13px] text-neutral-600">Orders today</dt>
                        <dd class="font-sans text-sm font-semibold text-neutral-950">{{ number_format($ordersToday) }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-4 py-3">
                        <dt class="font-sans text-[13px] text-neutral-600">Orders in progress</dt>
                        <dd class="font-sans text-sm font-semibold text-neutral-950">{{ number_format($ordersInProgress) }}</dd>
                    </div>
                </dl>
            </section>
        @endif
    </div>

    @if ($money !== null && $position !== null)
        <section data-platform-position-section class="mt-6 {{ $panel }}">
            <h2 class="{{ $panelTitle }}">Liabilities and money in transit <span class="font-normal text-neutral-600">(live, not filtered by month)</span></h2>
            <p class="mt-1 font-sans text-xs text-neutral-600">Not revenue and not part of the net above. COD cash, seller payouts and origin shipping shares only pass through Zefanya.</p>
            <dl data-platform-position class="mt-4 grid grid-cols-1 gap-4 font-sans text-[13px] text-neutral-950 md:grid-cols-3">
                <div class="rounded-xl bg-primary-50 p-4">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.6px] text-neutral-600">Zefanya owes</p>
                    <div class="mt-2 flex justify-between gap-4 py-1.5"><dt>Seller payouts owed</dt><dd class="font-semibold tabular-nums">{{ $peso($position['sellerPayableMinor']) }}</dd></div>
                    <div class="flex justify-between gap-4 py-1.5"><dt>Logistics shares owed (origin 40% / old-parcel 60%)</dt><dd class="font-semibold tabular-nums">{{ $peso($position['originPayableMinor']) }} / {{ $peso($position['destinationPayableMinor']) }}</dd></div>
                    <div class="flex justify-between gap-4 py-1.5"><dt>Refunds still to record ({{ $position['refundsToRecordCount'] }})</dt><dd class="font-semibold tabular-nums">{{ $peso($position['refundsToRecordMinor']) }}</dd></div>
                    <div class="mt-1 flex justify-between gap-4 border-t border-neutral-200 pt-2.5"><dt class="font-semibold">Total owed by Zefanya</dt><dd class="font-serif text-lg font-bold tabular-nums">{{ $peso($position['totalLiabilitiesMinor']) }}</dd></div>
                </div>
                <div class="rounded-xl bg-primary-50 p-4">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.6px] text-neutral-600">Owed to Zefanya</p>
                    <div class="mt-2 flex justify-between gap-4 py-1.5"><dt>Seller debts owed to Zefanya</dt><dd class="font-semibold tabular-nums">{{ $peso($position['sellerDebtMinor']) }}</dd></div>
                </div>
                <div class="rounded-xl bg-primary-50 p-4">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.6px] text-neutral-600">Cash in transit</p>
                    <div class="mt-2 flex justify-between gap-4 py-1.5"><dt>Cash not yet received (riders {{ $peso($position['cashWithRidersMinor']) }}, companies {{ $peso($position['cashAtCompaniesMinor']) }}, awaiting confirmation {{ $peso($position['cashSentAwaitingMinor']) }})</dt><dd class="font-semibold tabular-nums">{{ $peso($position['outstandingCashMinor']) }}</dd></div>
                </div>
            </dl>
            <p class="mt-3 font-sans text-xs text-neutral-600">Refund adjustments: {{ $peso($position['refundsRecordedMonthMinor']) }} refunded this month. Commission already counted on refunded orders: {{ $peso($position['commissionOnRefundedMinor']) }}, not removed from the net.</p>
        </section>
    @endif

    @if ($money !== null)
        <section data-settlements class="mt-6 {{ $panel }}">
            <h2 class="{{ $panelTitle }}">Logistics settlements</h2>
            <p class="mt-1 font-sans text-xs text-neutral-600">COD collected {{ $isCurrent ? 'this month' : 'in '.$periodLabel }}, minus the shipping on those parcels and what Zefanya has confirmed receiving. <a href="{{ route('admin.logistics-remittances.index') }}" class="font-semibold text-primary-800 underline">Review payments</a></p>
            @if ($settlements->isEmpty())
                <p class="mt-4 rounded-xl bg-primary-50 px-4 py-6 text-center font-sans text-[13px] text-neutral-600">No cash on delivery {{ $isCurrent ? 'collected this month' : 'was collected in '.$periodLabel }}.</p>
            @else
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full min-w-[560px] border-collapse [&>tbody>tr:last-child>td]:border-b-0">
                        <thead>
                            <tr>
                                <th class="{{ $th }} text-left">Provider</th>
                                <th class="{{ $th }} text-right">COD collected</th>
                                <th class="{{ $th }} text-right">Shipping earned</th>
                                <th class="{{ $th }} text-right">Received</th>
                                <th class="{{ $th }} text-right">Owed to Zefanya</th>
                                <th class="{{ $th }} pl-6 text-left">Rider cash</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($settlements as $row)
                                <tr>
                                    <td class="{{ $td }} font-semibold">{{ $row['provider'] }}</td>
                                    <td class="{{ $td }} text-right tabular-nums">{{ $peso($row['codMinor']) }}</td>
                                    <td class="{{ $td }} text-right tabular-nums">{{ $peso($row['shippingMinor']) }}</td>
                                    <td class="{{ $td }} text-right tabular-nums">{{ $peso($row['receivedMinor']) }}</td>
                                    <td class="{{ $td }} text-right font-semibold tabular-nums">{{ $peso($row['owedMinor']) }}</td>
                                    <td class="{{ $td }} pl-6">
                                        @if ($row['withRiders'] > 0)
                                            <span class="inline-flex rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-900">{{ $row['withRiders'] }} with riders</span>
                                        @else
                                            <span class="inline-flex rounded-full bg-green-100 px-2.5 py-1 text-xs font-semibold text-green-900">All handed in</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endif
@endsection
