@extends('Admin.Layouts.app')

@section('title', 'Dashboard')
@section('content-width', 'max-w-none')

@section('content')
    @php
        $peso = fn (int $minor): string => ($minor < 0 ? '−' : '').'₱'.number_format(abs($minor) / 100, 2);
        $card = 'relative flex min-w-0 flex-col overflow-hidden rounded-2xl border border-l-4 border-neutral-200 bg-white p-5 no-underline shadow-sm';
        $linkedCard = $card.' transition-colors hover:border-primary-800';
        $iconChip = 'absolute right-4 top-4 flex size-9 items-center justify-center rounded-lg';
        $cardLabel = 'pr-11 font-sans text-[13px] font-medium text-neutral-600';
        $value = 'mt-4 break-words font-serif text-[28px] font-bold leading-tight text-neutral-950';
        $cardHint = 'mt-1 font-sans text-xs text-neutral-600';
        $panel = 'min-w-0 rounded-2xl border border-neutral-200 bg-white p-5 shadow-sm sm:p-6';
        $panelTitle = 'font-sans text-[15px] font-semibold text-neutral-950';
        $th = 'whitespace-nowrap border-b border-neutral-200 py-2.5 font-sans text-[11px] font-semibold uppercase tracking-[0.6px] text-neutral-600';
        $td = 'whitespace-nowrap border-b border-neutral-200 py-3.5 font-sans text-[13px] text-neutral-950';
        $accents = [
            ['border-l-primary-700', 'bg-primary-100 text-primary-800'],
            ['border-l-amber-500', 'bg-amber-50 text-amber-800'],
            ['border-l-red-400', 'bg-red-50 text-red-800'],
            ['border-l-blue-500', 'bg-blue-50 text-blue-800'],
            ['border-l-violet-400', 'bg-violet-50 text-violet-800'],
            ['border-l-emerald-500', 'bg-emerald-50 text-emerald-800'],
        ];
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
                    @include('Admin.partials.attention-row', ['item' => $item, 'dim' => false, 'wide' => $loop->last && $waiting->count() % 2 !== 0])
                @endforeach
            </ul>
            @if ($quiet->isNotEmpty())
                <details class="mt-3 rounded-xl bg-primary-50 px-4 py-3">
                    <summary class="cursor-pointer font-sans text-xs font-semibold text-neutral-600">{{ $quiet->count() }} queue{{ $quiet->count() === 1 ? '' : 's' }} with nothing waiting</summary>
                    <ul class="mt-2 divide-y divide-neutral-200">
                        @foreach ($quiet as $item)
                            @include('Admin.partials.attention-row', ['item' => $item, 'dim' => true, 'wide' => false])
                        @endforeach
                    </ul>
                </details>
            @endif
        </section>
    @endif

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
        @foreach ($cards as $item)
            @php([$rail, $chip] = $accents[$loop->index % count($accents)])
            @if ($item['url'])
                <a href="{{ $item['url'] }}" class="{{ $linkedCard }} {{ $rail }}">
                    <span class="{{ $iconChip }} {{ $chip }}"><i data-lucide="{{ $item['icon'] }}" class="size-[18px]" aria-hidden="true"></i></span>
                    <span class="{{ $cardLabel }}">{{ $item['label'] }}</span>
                    <span class="{{ $value }}">{{ $item['value'] }}</span>
                    <span class="{{ $cardHint }}">{{ $item['hint'] }}</span>
                </a>
            @else
                <div class="{{ $card }} {{ $rail }}">
                    <span class="{{ $iconChip }} {{ $chip }}"><i data-lucide="{{ $item['icon'] }}" class="size-[18px]" aria-hidden="true"></i></span>
                    <span class="{{ $cardLabel }}">{{ $item['label'] }}</span>
                    <span class="{{ $value }}">{{ $item['value'] }}</span>
                    <span class="{{ $cardHint }}">{{ $item['hint'] }}</span>
                </div>
            @endif
        @endforeach
    </div>

    <div class="mt-6">

        @if ($money !== null)
            <section data-platform-money class="{{ $panel }}">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <h2 class="{{ $panelTitle }}">Platform money {{ $isCurrent ? 'this month' : 'in '.$periodLabel }}</h2>
                    <div class="w-full min-w-0 rounded-xl bg-primary-50 px-5 py-4 sm:w-auto sm:min-w-[220px]">
                        <p class="font-sans text-[13px] font-semibold text-neutral-700">Platform net earnings</p>
                        <p class="mt-1 break-words font-serif text-[32px] font-bold leading-tight text-primary-800 tabular-nums">{{ $peso($money['net']) }}</p>
                    </div>
                </div>
                @if ($money['commission'] === 0 && $money['platformVouchers'] === 0 && $money['shippingVouchers'] === 0)
                    <p class="mt-4 font-sans text-[13px] text-neutral-600">No orders finalized {{ $isCurrent ? 'this month yet' : 'in '.$periodLabel }}.</p>
                @endif
                <dl class="mt-5 grid grid-cols-1 gap-3 md:grid-cols-3">
                    <div class="min-w-0 rounded-xl border border-neutral-200 px-4 py-4">
                        <dt class="font-sans text-sm font-semibold text-neutral-950">Commission revenue</dt>
                        <dd class="mt-2 break-words font-sans text-[25px] font-semibold leading-tight text-neutral-950 tabular-nums">{{ $money['commission'] < 0 ? '' : '+ ' }}{{ $peso($money['commission']) }}</dd>
                        <dd class="mt-2 font-sans text-[13px] text-neutral-600">Finalized orders</dd>
                    </div>
                    <div class="min-w-0 rounded-xl border border-neutral-200 px-4 py-4">
                        <dt class="font-sans text-sm font-semibold text-neutral-950">Platform vouchers</dt>
                        <dd class="mt-2 break-words font-sans text-[25px] font-semibold leading-tight text-neutral-950 tabular-nums">{{ $money['platformVouchers'] < 0 ? '+ ' : '− ' }}{{ $peso(abs($money['platformVouchers'])) }}</dd>
                        <dd class="mt-2 font-sans text-[13px] text-neutral-600">Marketplace discounts</dd>
                    </div>
                    <div class="min-w-0 rounded-xl border border-neutral-200 px-4 py-4">
                        <dt class="font-sans text-sm font-semibold text-neutral-950">Shipping vouchers</dt>
                        <dd class="mt-2 break-words font-sans text-[25px] font-semibold leading-tight text-neutral-950 tabular-nums">{{ $money['shippingVouchers'] < 0 ? '+ ' : '− ' }}{{ $peso(abs($money['shippingVouchers'])) }}</dd>
                        <dd class="mt-2 font-sans text-[13px] text-neutral-600">Shipping discounts</dd>
                    </div>
                </dl>
                <details class="mt-4 border-t border-neutral-200 pt-4 font-sans text-[13px] leading-relaxed text-neutral-600">
                    <summary class="w-fit cursor-pointer rounded font-semibold text-primary-800 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary-700">How earnings are calculated</summary>
                    <div class="mt-3 space-y-2">
                        <p>Commission revenue is {{ \App\Services\Shared\CommissionFinalizationService::COMMISSION_BPS / 100 }}% of item subtotal after seller discounts, counted when an order is completed.</p>
                        <p>Platform net earnings = commission revenue minus marketplace-funded platform and shipping vouchers.</p>
                        <p>Store vouchers are seller-funded and excluded from these totals.</p>
                    </div>
                </details>
            </section>
        @else
            <section data-marketplace class="{{ $panel }}">
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
            <div class="flex flex-wrap items-center gap-3"><h2 class="{{ $panelTitle }}">Money to settle</h2><span class="rounded-full bg-primary-100 px-2.5 py-1 font-sans text-[13px] font-semibold text-primary-800">Live</span></div>
            <p class="mt-2 font-sans text-[13px] text-neutral-600">Current balances, independent of the selected month.</p>
            <div data-platform-position class="mt-5 grid grid-cols-1 items-start gap-4 lg:grid-cols-3">
                <section class="min-w-0 rounded-xl bg-primary-50 p-5" aria-labelledby="liabilitiesTitle">
                    <h3 id="liabilitiesTitle" class="font-sans text-sm font-semibold text-neutral-950">Zefanya owes</h3>
                    <p class="mt-2 break-words font-sans text-[28px] font-semibold leading-tight text-primary-800 tabular-nums">{{ $peso($position['totalLiabilitiesMinor']) }}</p>
                    <details class="mt-4 border-t border-primary-200 pt-3 font-sans text-[13px] text-neutral-700">
                        <summary class="w-fit cursor-pointer rounded font-semibold text-primary-800 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary-700">View breakdown</summary>
                        <dl class="mt-3 space-y-3">
                            <div class="flex items-start justify-between gap-4"><dt class="min-w-0">Seller payouts</dt><dd class="min-w-0 break-words text-right font-semibold tabular-nums">{{ $peso($position['sellerPayableMinor']) }}</dd></div>
                            <div class="flex items-start justify-between gap-4"><dt class="min-w-0">Origin logistics share</dt><dd class="min-w-0 break-words text-right font-semibold tabular-nums">{{ $peso($position['originPayableMinor']) }}</dd></div>
                            <div class="flex items-start justify-between gap-4"><dt class="min-w-0">Legacy destination share</dt><dd class="min-w-0 break-words text-right font-semibold tabular-nums">{{ $peso($position['destinationPayableMinor']) }}</dd></div>
                            <div class="flex items-start justify-between gap-4"><dt class="min-w-0">Refunds to record ({{ $position['refundsToRecordCount'] }})</dt><dd class="min-w-0 break-words text-right font-semibold tabular-nums">{{ $peso($position['refundsToRecordMinor']) }}</dd></div>
                        </dl>
                        <p class="mt-3 leading-relaxed">Origin shares are 40%. Destination shares of 60% apply to legacy parcels.</p>
                    </details>
                </section>
                <section class="min-w-0 rounded-xl bg-primary-50 p-5" aria-labelledby="sellerDebtTitle">
                    <h3 id="sellerDebtTitle" class="font-sans text-sm font-semibold text-neutral-950">Owed to Zefanya</h3>
                    <p class="mt-2 break-words font-sans text-[28px] font-semibold leading-tight text-primary-800 tabular-nums">{{ $peso($position['sellerDebtMinor']) }}</p>
                    <p class="mt-3 font-sans text-[13px] leading-relaxed text-neutral-600">Outstanding seller debts.</p>
                </section>
                <section class="min-w-0 rounded-xl bg-primary-50 p-5" aria-labelledby="cashTransitTitle">
                    <h3 id="cashTransitTitle" class="font-sans text-sm font-semibold text-neutral-950">Cash in transit</h3>
                    <p class="mt-2 break-words font-sans text-[28px] font-semibold leading-tight text-primary-800 tabular-nums">{{ $peso($position['outstandingCashMinor']) }}</p>
                    <dl class="mt-4 space-y-3 border-t border-primary-200 pt-4 font-sans text-[13px] text-neutral-700">
                        <div class="flex items-start justify-between gap-4"><dt class="min-w-0">With riders</dt><dd class="min-w-0 break-words text-right font-semibold tabular-nums">{{ $peso($position['cashWithRidersMinor']) }}</dd></div>
                        <div class="flex items-start justify-between gap-4"><dt class="min-w-0">With companies</dt><dd class="min-w-0 break-words text-right font-semibold tabular-nums">{{ $peso($position['cashAtCompaniesMinor']) }}</dd></div>
                        <div class="flex items-start justify-between gap-4"><dt class="min-w-0">Awaiting confirmation</dt><dd class="min-w-0 break-words text-right font-semibold tabular-nums">{{ $peso($position['cashSentAwaitingMinor']) }}</dd></div>
                    </dl>
                </section>
            </div>
            <details class="mt-5 border-t border-neutral-200 pt-4 font-sans text-[13px] leading-relaxed text-neutral-600">
                <summary class="w-fit cursor-pointer rounded font-semibold text-primary-800 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary-700">Refund details</summary>
                <dl class="mt-3 space-y-3 sm:max-w-xl">
                    <div class="flex items-start justify-between gap-4"><dt class="min-w-0">Refunds recorded this month</dt><dd class="min-w-0 break-words text-right font-semibold tabular-nums">{{ $peso($position['refundsRecordedMonthMinor']) }}</dd></div>
                    <div class="flex items-start justify-between gap-4"><dt class="min-w-0">Commission on refunded orders</dt><dd class="min-w-0 break-words text-right font-semibold tabular-nums">{{ $peso($position['commissionOnRefundedMinor']) }}</dd></div>
                </dl>
                <p class="mt-3">Recorded refunds refer to the current month, regardless of the month selected above. Commission already counted on refunded orders remains included in net earnings.</p>
                <p class="mt-2">Live liabilities and cash in transit are not revenue or part of monthly net earnings. COD cash, seller payouts and logistics shares only pass through Zefanya.</p>
            </details>
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
