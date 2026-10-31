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
        if ($money !== null) {
            $cards[] = ['label' => 'Commission (This Month)', 'value' => $peso($money['commission']), 'hint' => (\App\Services\Shared\CommissionFinalizationService::COMMISSION_BPS / 100).'% of item subtotal after seller discounts', 'icon' => 'percent', 'url' => null];
        }
    @endphp

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="font-serif text-[28px] font-bold text-neutral-950">Welcome back, {{ $admin?->name ?? 'Admin' }}</h1>
            <p class="mt-1 font-sans text-sm text-neutral-600">Here's what needs your attention across Zefanya.</p>
        </div>
        @if ($money !== null)
            <span class="inline-flex min-h-10 items-center gap-2 rounded-lg border border-neutral-200 bg-white px-4 font-sans text-[13px] font-semibold text-neutral-950">
                <i data-lucide="calendar" class="size-4" aria-hidden="true"></i>{{ now(\App\Models\Seller\StoreSale::TIMEZONE)->format('F Y') }}
            </span>
        @endif
    </div>

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 {{ $money !== null ? 'xl:grid-cols-4' : 'xl:grid-cols-3' }}">
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
        @if ($canModerate)
            <section data-attention class="{{ $panel }}">
                <h2 class="{{ $panelTitle }}">Needs your action</h2>
                <ul class="mt-3 divide-y divide-neutral-200">
                    @foreach ($attention as $item)
                        <li class="flex items-center gap-3 py-3">
                            <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary-100 text-primary-800">
                                <i data-lucide="{{ $item['icon'] }}" class="size-[18px]" aria-hidden="true"></i>
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block font-sans text-[13px] font-semibold text-neutral-950">{{ $item['label'] }}</span>
                                <span class="block font-sans text-xs text-neutral-600">{{ $item['detail'] }}</span>
                            </span>
                            <span class="font-serif text-lg font-bold {{ $item['count'] > 0 ? 'text-neutral-950' : 'text-neutral-400' }}">{{ number_format($item['count']) }}</span>
                            @if ($item['url'])
                                <a href="{{ $item['url'] }}" class="inline-flex min-h-9 w-[76px] shrink-0 items-center justify-center rounded-lg border border-neutral-200 font-sans text-xs font-semibold text-primary-800 no-underline transition-colors hover:border-primary-800">{{ $item['action'] }}</a>
                            @else
                                <span title="Coming soon" class="inline-flex min-h-9 w-[76px] shrink-0 cursor-default items-center justify-center font-sans text-[10px] font-semibold uppercase tracking-wide text-neutral-400">Soon</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($money !== null)
            <section data-platform-money class="{{ $panel }}">
                <h2 class="{{ $panelTitle }}">Platform money this month</h2>
                <p class="mt-1 font-sans text-xs text-neutral-600">What Zefanya earns and what it funds</p>
                <dl class="mt-3 divide-y divide-neutral-200">
                    <div class="flex items-center justify-between gap-4 py-3">
                        <dt class="font-sans text-[13px] font-semibold text-neutral-950">Commission earned<span class="block text-xs font-normal text-neutral-600">{{ \App\Services\Shared\CommissionFinalizationService::COMMISSION_BPS / 100 }}% on finalized orders</span></dt>
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
                    <span class="font-sans text-[13px] font-semibold text-neutral-950">Platform net</span>
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

    @if ($money !== null)
        <section data-settlements class="mt-6 {{ $panel }}">
            <h2 class="{{ $panelTitle }}">Logistics settlements</h2>
            <p class="mt-1 font-sans text-xs text-neutral-600">COD collected this month, minus the shipping on those parcels and what Zefanya has confirmed receiving. <a href="{{ route('admin.logistics-remittances.index') }}" class="font-semibold text-primary-800 underline">Review payments</a></p>
            @if ($settlements->isEmpty())
                <p class="mt-4 rounded-xl bg-primary-50 px-4 py-6 text-center font-sans text-[13px] text-neutral-600">No cash on delivery collected this month.</p>
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
