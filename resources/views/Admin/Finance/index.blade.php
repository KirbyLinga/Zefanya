@extends('Admin.Layouts.app')

@section('title', 'Finance — Zefanya Admin')
@section('content-width', 'max-w-none')

@use('App\Services\Admin\FinanceReportService')

@section('content')
@php
    $peso = fn (int $minor): string => ($minor < 0 ? '−' : '').'₱'.number_format(abs($minor) / 100, 2);
    $panel = 'min-w-0 rounded-2xl border border-neutral-200 bg-white p-5 shadow-sm sm:p-6';
    $panelTitle = 'font-sans text-[15px] font-semibold text-neutral-950';
    $th = 'whitespace-nowrap border-b border-neutral-200 px-3 py-2.5 text-left font-sans text-[11px] font-semibold uppercase tracking-[0.6px] text-neutral-600';
    $td = 'whitespace-nowrap border-b border-neutral-200 px-3 py-3 font-sans text-[13px] text-neutral-950';
    $stat = 'min-w-0 rounded-xl bg-primary-50 p-4';
    $statLabel = 'font-sans text-[13px] font-medium text-neutral-600';
    $statValue = 'mt-2 break-words font-sans text-2xl font-semibold leading-tight tabular-nums text-primary-800';
    $monthLabel = $period->format('F Y');
    $directionTone = [
        FinanceReportService::IN => 'bg-emerald-50 text-emerald-800',
        FinanceReportService::OUT => 'bg-red-50 text-red-800',
        FinanceReportService::OWED_BY_ZEFANYA => 'bg-amber-50 text-amber-800',
        FinanceReportService::OWED_TO_ZEFANYA => 'bg-blue-50 text-blue-800',
    ];
    $trendMax = max(1, collect($trend)->max(fn (array $month): int => max(abs($month['netMinor']), $month['commissionMinor'])));
    $methodTotal = max(1, collect($methods)->sum('amountMinor'));
@endphp

<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="font-serif text-3xl text-neutral-950">Finance</h1>
            <p class="mt-2 max-w-3xl text-sm text-neutral-600">A read-only report of Zefanya's earnings and every money movement in the month. To pay sellers or confirm logistics payments, use <a href="{{ route('admin.logistics-remittances.index') }}" class="font-semibold text-primary-800 underline">Settlements</a>.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <form method="GET" action="{{ route('admin.finance.index') }}" class="flex items-center gap-2">
                @if ($type)<input type="hidden" name="type" value="{{ $type }}">@endif
                <label for="financeMonth" class="inline-flex items-center gap-2 font-sans text-[13px] font-semibold text-neutral-950"><i data-lucide="calendar" class="size-4" aria-hidden="true"></i>Month</label>
                <select id="financeMonth" name="month" onchange="this.form.submit()" class="min-h-10 rounded-lg border border-neutral-200 bg-white px-3 font-sans text-[13px] font-semibold text-neutral-950">
                    @foreach ($periodOptions as $option)
                        <option value="{{ $option['value'] }}" @selected($option['value'] === $period->format('Y-m'))>{{ $option['label'] }}</option>
                    @endforeach
                </select>
                <noscript><button type="submit" class="min-h-10 rounded-lg border border-neutral-200 bg-white px-3 font-sans text-[13px] font-semibold">Apply</button></noscript>
            </form>
            <a data-finance-export href="{{ route('admin.finance.export', array_filter(['month' => $period->format('Y-m'), 'type' => $type])) }}"
               class="inline-flex min-h-10 items-center gap-2 rounded-lg bg-primary-800 px-4 font-sans text-[13px] font-semibold text-white no-underline hover:bg-primary-700">
                <i data-lucide="download" class="size-4" aria-hidden="true"></i>Export CSV
            </a>
        </div>
    </div>

    {{-- Earnings: same figures as the dashboard, from orders finalized this month. --}}
    <section data-finance-summary class="{{ $panel }}" aria-labelledby="summaryTitle">
        <h2 id="summaryTitle" class="{{ $panelTitle }}">Platform earnings · {{ $monthLabel }}</h2>
        <p class="mt-1 font-sans text-[13px] text-neutral-600">From {{ number_format($summary['finalizedOrders']) }} {{ str('order')->plural($summary['finalizedOrders']) }} finalized this month.</p>
        <div class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div class="{{ $stat }}"><p class="{{ $statLabel }}">Commission</p><p class="{{ $statValue }}">{{ $peso($summary['commissionMinor']) }}</p></div>
            <div class="{{ $stat }}"><p class="{{ $statLabel }}">Platform vouchers</p><p class="{{ $statValue }}">{{ $peso(-$summary['platformVoucherMinor']) }}</p></div>
            <div class="{{ $stat }}"><p class="{{ $statLabel }}">Shipping vouchers</p><p class="{{ $statValue }}">{{ $peso(-$summary['shippingVoucherMinor']) }}</p></div>
            <div class="{{ $stat }} ring-2 ring-primary-300"><p class="{{ $statLabel }}">Net platform earnings</p><p data-finance-net class="{{ $statValue }}">{{ $peso($summary['netMinor']) }}</p></div>
        </div>
        <dl class="mt-5 grid grid-cols-1 gap-x-8 gap-y-3 border-t border-neutral-200 pt-4 font-sans text-[13px] text-neutral-700 sm:grid-cols-3">
            <div class="flex justify-between gap-4"><dt>Item sales</dt><dd class="font-semibold tabular-nums">{{ $peso($summary['itemSalesMinor']) }}</dd></div>
            <div class="flex justify-between gap-4"><dt>Shipping fees</dt><dd class="font-semibold tabular-nums">{{ $peso($summary['shippingFeesMinor']) }}</dd></div>
            <div class="flex justify-between gap-4"><dt>Seller earnings</dt><dd class="font-semibold tabular-nums">{{ $peso($summary['sellerNetMinor']) }}</dd></div>
        </dl>
        <p class="mt-3 font-sans text-xs text-neutral-600">Only marketplace-funded vouchers are Zefanya's cost; shop vouchers are paid by sellers.</p>
    </section>

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
        {{-- Money that actually moved this month, from the ledger below. --}}
        <section data-finance-cash-flow class="{{ $panel }}" aria-labelledby="flowTitle">
            <h2 id="flowTitle" class="{{ $panelTitle }}">Money movement · {{ $monthLabel }}</h2>
            <p class="mt-1 font-sans text-[13px] text-neutral-600">Totals of the ledger entries below. Pass-through money (COD, seller payouts) is not revenue.</p>
            <dl class="mt-4 space-y-3 font-sans text-[13px]">
                @foreach (FinanceReportService::DIRECTIONS as $key => $label)
                    <div class="flex items-center justify-between gap-4">
                        <dt><span class="rounded-full px-2.5 py-1 font-semibold {{ $directionTone[$key] }}">{{ $label }}</span></dt>
                        <dd data-finance-total="{{ $key }}" class="font-semibold tabular-nums text-neutral-950">{{ $peso($totals[$key]) }}</dd>
                    </div>
                @endforeach
            </dl>
        </section>

        <section data-finance-methods class="{{ $panel }}" aria-labelledby="methodsTitle">
            <h2 id="methodsTitle" class="{{ $panelTitle }}">How buyers paid · {{ $monthLabel }}</h2>
            <p class="mt-1 font-sans text-[13px] text-neutral-600">Finalized orders by payment method.</p>
            <ul class="mt-4 space-y-4 font-sans text-[13px]">
                @foreach ($methods as $method)
                    <li>
                        <div class="flex justify-between gap-4"><span class="font-medium text-neutral-950">{{ $method['label'] }}</span><span class="tabular-nums text-neutral-700">{{ number_format($method['orders']) }} {{ str('order')->plural($method['orders']) }} · <span class="font-semibold text-neutral-950">{{ $peso($method['amountMinor']) }}</span></span></div>
                        <div class="mt-1.5 h-2 overflow-hidden rounded-full bg-neutral-100" aria-hidden="true"><div class="h-full rounded-full bg-primary-700" style="width: {{ round($method['amountMinor'] / $methodTotal * 100, 1) }}%"></div></div>
                    </li>
                @endforeach
            </ul>
        </section>
    </div>

    <section data-finance-trend class="{{ $panel }}" aria-labelledby="trendTitle">
        <h2 id="trendTitle" class="{{ $panelTitle }}">Earnings trend · last 12 months</h2>
        <div class="relative mt-4 overflow-x-auto">
            <table class="w-full min-w-[560px] border-collapse">
                <thead><tr><th class="{{ $th }}">Month</th><th class="{{ $th }} text-right">Commission</th><th class="{{ $th }} text-right">Vouchers</th><th class="{{ $th }} text-right">Net</th><th class="{{ $th }} w-1/3"><span class="sr-only">Net bar</span></th></tr></thead>
                <tbody>
                    @foreach (array_reverse($trend) as $month)
                        <tr @class(['bg-primary-50' => $month['value'] === $period->format('Y-m')])>
                            <td class="{{ $td }}"><a href="{{ route('admin.finance.index', ['month' => $month['value']]) }}" class="text-primary-800 underline-offset-2 hover:underline">{{ $month['label'] }}</a></td>
                            <td class="{{ $td }} text-right tabular-nums">{{ $peso($month['commissionMinor']) }}</td>
                            <td class="{{ $td }} text-right tabular-nums">{{ $peso(-$month['voucherMinor']) }}</td>
                            <td class="{{ $td }} text-right font-semibold tabular-nums">{{ $peso($month['netMinor']) }}</td>
                            <td class="{{ $td }}" aria-hidden="true">
                                <div class="h-2 overflow-hidden rounded-full bg-neutral-100"><div class="h-full rounded-full {{ $month['netMinor'] < 0 ? 'bg-red-400' : 'bg-primary-700' }}" style="width: {{ round(abs($month['netMinor']) / $trendMax * 100, 1) }}%"></div></div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section data-finance-ledger class="{{ $panel }}" aria-labelledby="ledgerTitle">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 id="ledgerTitle" class="{{ $panelTitle }}">Ledger · {{ $monthLabel }}</h2>
                <p class="mt-1 font-sans text-[13px] text-neutral-600">COD cash is listed when a logistics company's payment is confirmed, not per parcel. Rider pay is handled by logistics companies and is not listed.</p>
            </div>
            <form method="GET" action="{{ route('admin.finance.index') }}" class="flex w-full min-w-0 items-center gap-2 sm:w-auto">
                <input type="hidden" name="month" value="{{ $period->format('Y-m') }}">
                <label for="financeType" class="font-sans text-[13px] font-semibold text-neutral-950">Type</label>
                <select id="financeType" name="type" onchange="this.form.submit()" class="min-h-10 min-w-0 flex-1 rounded-lg border border-neutral-200 bg-white px-3 font-sans text-[13px] text-neutral-950">
                    <option value="">All entries</option>
                    @foreach ($types as $value => $label)
                        <option value="{{ $value }}" @selected($type === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <noscript><button type="submit" class="min-h-10 rounded-lg border border-neutral-200 bg-white px-3 font-sans text-[13px] font-semibold">Apply</button></noscript>
            </form>
        </div>

        @if ($entries->isEmpty())
            <p data-finance-empty class="mt-6 rounded-xl bg-neutral-50 p-6 text-center font-sans text-sm text-neutral-600">No money movements {{ $type ? 'of this type ' : '' }}in {{ $monthLabel }}.</p>
        @else
            <div class="relative mt-4 overflow-x-auto">
                <table class="w-full min-w-[860px] border-collapse">
                    <thead><tr><th class="{{ $th }}">Date</th><th class="{{ $th }}">Type</th><th class="{{ $th }}">Reference</th><th class="{{ $th }}">Party</th><th class="{{ $th }}">Method</th><th class="{{ $th }}">Status</th><th class="{{ $th }} text-right">Amount</th></tr></thead>
                    <tbody>
                        @foreach ($entries as $entry)
                            <tr data-finance-entry="{{ $entry['type'] }}">
                                <td class="{{ $td }} text-neutral-700">{{ $entry['at']->format('M j, Y g:i A') }}</td>
                                <td class="{{ $td }}"><span class="mr-2 inline-block rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $directionTone[$entry['direction']] }}">{{ $entry['directionLabel'] }}</span>{{ $entry['typeLabel'] }}</td>
                                <td class="{{ $td }} font-mono text-[12px]">{{ $entry['reference'] }}</td>
                                <td class="{{ $td }}">{{ $entry['party'] }}</td>
                                <td class="{{ $td }} text-neutral-700">{{ $entry['method'] }}</td>
                                <td class="{{ $td }} text-neutral-700">{{ $entry['status'] }}</td>
                                <td class="{{ $td }} text-right font-semibold tabular-nums">{{ $peso($entry['amountMinor']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $entries->links() }}</div>
        @endif
    </section>
</div>
@endsection
