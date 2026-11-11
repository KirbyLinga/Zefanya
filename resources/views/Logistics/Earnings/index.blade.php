@extends('Layouts.logistics')

@section('title', 'Earnings')

@use('App\Models\Logistics\CodRemittance')
@use('App\Models\Logistics\LogisticsRemittance')
@use('App\Models\Seller\StoreSale')

@section('logistics-toolbar')
    <span hidden data-logistics-hosted-bell></span>
@endsection

@section('content')
<x-logistics.tabs group="money" />
@include('Logistics._parcel-queue-assets')
@php
    $peso = fn (int $minor): string => '₱'.number_format($minor / 100, 2);
    $riderRates = (new \App\Services\Logistics\PickupPolicy)->rates();
    $ready = $totals['ready'];
    $sent = $totals['sent'];
    $settled = $totals['settled'];
    $rider = $totals['rider'];
    $earnedMinor = $rider['shippingMinor'] + $ready['shippingMinor'] + $sent['shippingMinor'] + $settled['shippingMinor'];
    $tiles = [
        ['label' => 'Shipping earned this month', 'value' => $peso($earnedThisMonthMinor), 'note' => $peso($earnedMinor).' in total', 'tone' => 'ip-emerald', 'icon' => 'wallet'],
        ['label' => 'To send to Zefanya', 'value' => $peso($ready['dueMinor']), 'note' => $ready['parcels'].' '.\Illuminate\Support\Str::plural('parcel', $ready['parcels']), 'tone' => 'ip-amber', 'icon' => 'send'],
        ['label' => 'Waiting for Zefanya to confirm', 'value' => $peso($sent['dueMinor']), 'note' => $sent['parcels'].' '.\Illuminate\Support\Str::plural('parcel', $sent['parcels']), 'tone' => 'ip-sky', 'icon' => 'clock'],
        ['label' => 'Cash still with riders', 'value' => $peso($rider['codMinor']), 'note' => $rider['parcels'].' '.\Illuminate\Support\Str::plural('parcel', $rider['parcels']), 'tone' => 'ip-neutral', 'icon' => 'bike'],
    ];
    $stageLooks = [
        'rider' => ['label' => 'Cash with rider', 'tone' => 'ip-neutral'],
        'ready' => ['label' => 'To send to Zefanya', 'tone' => 'ip-amber'],
        'sent' => ['label' => 'Waiting for Zefanya', 'tone' => 'ip-sky'],
        'settled' => ['label' => 'Settled', 'tone' => 'ip-emerald'],
    ];
    $paymentLooks = [
        LogisticsRemittance::SUBMITTED => ['label' => 'Waiting for Zefanya', 'tone' => 'ip-sky'],
        LogisticsRemittance::CONFIRMED => ['label' => 'Confirmed', 'tone' => 'ip-emerald'],
        LogisticsRemittance::REJECTED => ['label' => 'Rejected', 'tone' => 'ip-rose'],
    ];
    $firstStage = request()->has('payments_page') ? 'payments' : 'fees';
    $th = 'px-4 py-2.5 font-semibold';
    $onRates = ($tab ?? 'overview') === 'shipping-rates';
@endphp

<div class="ip-canvas" data-money-workspace>
<div class="mb-4 border-b border-[var(--border-strong)]">
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0 flex-1">
            <h1 class="font-serif text-2xl font-semibold text-[var(--text-ink)]">Earnings</h1>
            <p class="mt-1 font-sans text-[13px] text-[var(--text-ink)]/80">View shipping revenue, rider payments, remittances, and earnings summaries.</p>
            @if ($onRates)
                <p class="mt-1 font-sans text-[13px] text-[var(--text-ink)]/80">Shipping rates are calculated automatically from the route and parcel weight.</p>
            @endif
        </div>
        <x-logistics.notification-bell />
    </div>
    <div role="tablist" aria-label="Earnings" class="mt-4 flex flex-wrap gap-x-6">
        <a role="tab" href="{{ route('logistics.earnings.index') }}" aria-selected="{{ $onRates ? 'false' : 'true' }}" class="ip-tab -mb-px inline-flex min-h-11 items-center font-sans text-[13px] font-medium no-underline">Overview</a>
        <a role="tab" href="{{ route('logistics.earnings.index', ['tab' => 'shipping-rates']) }}" aria-selected="{{ $onRates ? 'true' : 'false' }}" class="ip-tab -mb-px inline-flex min-h-11 items-center font-sans text-[13px] font-medium no-underline">Shipping Rates</a>
    </div>
</div>

<div class="space-y-0">
<x-logistics-flash :messages="['success', 'warning', 'error']" />
@if ($errors->any())
    <div role="alert" class="mb-4 rounded-md border border-red-200 bg-red-50 p-3 font-sans text-[13px] text-red-900">@foreach ($errors->all() as $error)<p class="text-red-900">{{ $error }}</p>@endforeach</div>
@endif

@if ($onRates)
    @include('Logistics.Earnings._shipping-rates')
@else

<p class="mb-3 font-sans text-xs text-[var(--text-ink)]/80">Shipping earned is for this month; cash and remittance balances cover all records.</p>
<div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
    @foreach ($tiles as $tile)
        <div class="flex items-start gap-3 rounded-xl border border-[var(--border-strong)] bg-[var(--bg-surface)] p-4 shadow-sm">
            <span class="{{ $tile['tone'] }} ip-tinted flex h-9 w-9 shrink-0 items-center justify-center rounded-lg">
                <i data-lucide="{{ $tile['icon'] }}" class="h-[18px] w-[18px]"></i>
            </span>
            <div class="min-w-0">
                <p class="font-sans text-[12px] text-[var(--text-ink)]/80">{{ $tile['label'] }}</p>
                <p class="font-sans text-xl font-semibold tabular-nums text-[var(--text-ink)]">{{ $tile['value'] }}</p>
                <p class="font-sans text-[12px] text-[var(--text-ink)]/80">{{ $tile['note'] }}</p>
            </div>
        </div>
    @endforeach
</div>

<details class="mb-4 rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] px-4 py-3 font-sans">
    <summary class="cursor-pointer text-xs font-semibold text-[var(--text-ink)] focus-visible:outline-2 focus-visible:outline-primary-700">How shipping fees and carrier shares work</summary>
    <div class="mt-2 text-[13px] leading-relaxed">
                <p class="mt-1 font-sans text-[13px] text-[var(--text-ink)]/80">You keep the shipping fee on each delivered parcel and send Zefanya the rest of the cash. If another company was the buyer's chosen carrier, you keep 60% of the fee and that carrier's 40% goes to Zefanya with the cash.</p>
                @if ($originShares['parcels'] > 0)
                    <p class="mt-1 font-sans text-[13px] text-[var(--text-ink)]/80">As the origin carrier on {{ $originShares['parcels'] }} {{ \Illuminate\Support\Str::plural('parcel', $originShares['parcels']) }} another company delivered, your 40% share is <span class="font-semibold text-[var(--text-ink)]">{{ $peso($originShares['shareMinor']) }}</span>. Zefanya has received {{ $peso($originShares['receivedMinor']) }} of it and paid you {{ $peso($originShares['paidMinor']) }}.</p>
                @endif

    </div>
</details>

<section class="mb-5 rounded-xl border border-[var(--border-strong)] bg-[var(--bg-surface)] p-4 shadow-sm" data-company-statement>
    <details>
        <summary class="flex cursor-pointer list-none flex-wrap items-center justify-between gap-3 rounded-md focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-700">
            <span><span class="block font-sans text-[15px] font-semibold text-[var(--text-ink)]">Your profit and loss</span><span class="mt-1 block font-sans text-xs text-[var(--text-ink)]/80">All records - View earnings breakdown <span aria-hidden="true">&#9662;</span></span></span>
            <span class="text-right"><span class="block font-sans text-xs font-semibold text-[var(--text-ink)]/80">Estimated net earnings</span><span class="block font-sans text-2xl font-semibold tabular-nums text-[var(--text-ink)]">{{ $statement['netMinor'] < 0 ? '−' : '+' }}{{ $peso(abs($statement['netMinor'])) }}</span></span>
        </summary>
        <p class="mt-3 font-sans text-[13px] text-[var(--text-ink)]/80">Rider pay is your own expense. It lowers your profit but never the cash you send Zefanya.</p>
    <dl class="mt-3 divide-y divide-[var(--border-strong)] font-sans text-[13px] text-[var(--text-ink)]">
        <div class="flex justify-between gap-4 py-2"><dt>Shipping kept as delivering company (gross)</dt><dd class="shrink-0 tabular-nums">+{{ $peso($statement['deliveredFeeMinor']) }}</dd></div>
        <div class="flex justify-between gap-4 py-2"><dt>Origin 40% share on parcels others delivered (gross)</dt><dd class="shrink-0 tabular-nums">+{{ $peso($statement['originShareMinor']) }}</dd></div>
        <div class="flex justify-between gap-4 py-2 font-semibold"><dt>Gross shipping earnings</dt><dd class="shrink-0 tabular-nums">+{{ $peso($statement['grossMinor']) }}</dd></div>
        <div class="flex justify-between gap-4 py-2"><dt>Rider pickup pay ({{ $peso($riderRates['first_minor']) }} first + {{ $peso($riderRates['additional_minor']) }} additional)</dt><dd class="shrink-0 tabular-nums">−{{ $peso($statement['riderPickupMinor']) }}</dd></div>
        <div class="flex justify-between gap-4 py-2"><dt>Rider delivery pay ({{ $peso($riderRates['delivery_minor']) }} each)</dt><dd class="shrink-0 tabular-nums">−{{ $peso($statement['riderDeliveryMinor']) }}</dd></div>
        <div class="flex justify-between gap-4 py-2 font-semibold"><dt>Estimated net earnings</dt><dd class="shrink-0 tabular-nums {{ $statement['netMinor'] < 0 ? 'text-red-800' : '' }}">{{ $statement['netMinor'] < 0 ? '−' : '+' }}{{ $peso(abs($statement['netMinor'])) }}</dd></div>
    </dl>
    </details>
    <dl class="mt-4 grid gap-3 border-t border-[var(--border-strong)] pt-3 font-sans text-[12.5px] text-[var(--text-ink)]/80 sm:grid-cols-2 xl:grid-cols-4">
        <div><dt>Rider pay paid</dt><dd class="font-semibold tabular-nums text-[var(--text-ink)]">{{ $peso($statement['riderPaidMinor']) }}</dd></div>
        <div><dt>Rider pay still owed</dt><dd class="font-semibold tabular-nums text-[var(--text-ink)]">{{ $peso($statement['riderUnpaidMinor']) }}</dd></div>
        <div><dt>Origin share waiting for Zefanya's cash</dt><dd class="font-semibold tabular-nums text-[var(--text-ink)]">{{ $peso($statement['originAwaitingZefanyaMinor']) }}</dd></div>
        <div><dt>Origin share Zefanya owes you / has paid</dt><dd class="font-semibold tabular-nums text-[var(--text-ink)]">{{ $peso($statement['originReadyMinor']) }} / {{ $peso($statement['originPaidMinor']) }}</dd></div>
    </dl>
    <p class="mt-2 font-sans text-[12px] text-[var(--text-ink)]/80">Estimated: pay is counted when a pickup or delivery succeeds, shipping when the buyer's cash is collected. Rider pay lowers profit, never the cash you send Zefanya.</p>
</section>

@if ($returnCharges->isNotEmpty())
    @php
        $open = $returnCharges->whereNull('settled_at');
        $youOwe = (int) $open->where('direction', \App\Models\Logistics\LogisticsReturnCharge::TO_ZEFANYA)->sum('amount_minor');
        $owedToYou = (int) $open->where('direction', \App\Models\Logistics\LogisticsReturnCharge::TO_PROVIDER)->sum('amount_minor');
    @endphp
    <section class="mb-5 rounded-xl border border-[var(--border-strong)] bg-[var(--bg-surface)] p-4 shadow-sm" data-return-charges>
        <h2 class="font-sans text-[15px] font-semibold text-[var(--text-ink)]">Return charges</h2>
        <p class="mt-1 font-sans text-[12px] text-[var(--text-ink)]/80">Zefanya owes you {{ $peso($owedToYou) }} for return trips and failed deliveries. You owe Zefanya {{ $peso($youOwe) }} for parcels damaged in transit. An Admin records each one when the money is sent.</p>
        <ul class="mt-3 divide-y divide-[var(--border-strong)] font-sans text-[13px]">
            @foreach ($returnCharges as $charge)
                <li class="flex flex-wrap justify-between gap-2 py-2">
                    <span class="text-[var(--text-ink)]"><span class="font-mono">{{ $charge->sellerOrder?->tracking_number ?? '#'.$charge->seller_order_id }}</span> · {{ $charge->label() }}</span>
                    <span class="tabular-nums {{ $charge->owedToZefanya() ? 'text-red-800' : 'text-[var(--text-ink)]' }}">{{ $charge->owedToZefanya() ? 'You owe ' : 'Owed to you ' }}{{ $peso($charge->amount_minor) }} · {{ $charge->settled_at ? 'Settled, ref '.$charge->settlement_reference : 'Not settled' }}</span>
                </li>
            @endforeach
        </ul>
    </section>
@endif

@if ($ready['parcels'] > 0)
    <section class="mb-5 overflow-hidden rounded-xl border border-[var(--border-strong)] bg-[var(--bg-surface)] shadow-sm">
        <div class="p-4">
            <h2 class="font-sans text-[15px] font-semibold text-[var(--text-ink)]">Send <span data-remit-total class="text-[var(--text-ink)]">{{ $peso($ready['dueMinor']) }}</span> to Zefanya</h2>
            @php
                $overdueCutoff = now()->subDays(\App\Services\Logistics\LogisticsRemittanceService::OVERDUE_AFTER_DAYS);
                $overdueParcels = $readyParcels->filter(fn ($parcel) => $parcel->confirmed_at !== null && $parcel->confirmed_at->lt($overdueCutoff))->count();
            @endphp
            @if ($overdueParcels > 0)
                <p role="status" class="mt-2 rounded-lg border border-red-300 bg-red-50 p-3 font-sans text-[13px] font-semibold text-red-900">{{ $overdueParcels }} {{ \Illuminate\Support\Str::plural('parcel', $overdueParcels) }} {{ $overdueParcels === 1 ? 'has' : 'have' }} been waiting more than {{ \App\Services\Logistics\LogisticsRemittanceService::OVERDUE_AFTER_DAYS }} days since you confirmed the rider's cash. Send Zefanya the money soon.</p>
            @endif
            <p class="mt-0.5 font-sans text-[13px] text-[var(--text-ink)]/80">
                You collected {{ $peso($ready['codMinor']) }} and keep {{ $peso($ready['shippingMinor']) }} in shipping. Choose the parcels this payment covers, send the money, then record its reference here.
            </p>
            <div class="ip-field mt-3 rounded-lg border border-[var(--border-strong)] p-3">
                <p class="font-sans text-[12px] font-semibold text-[var(--text-ink)]">Send it to</p>
                @if (filled($paymentDetails))
                    <p class="mt-0.5 whitespace-pre-line font-sans text-[13px] text-[var(--text-ink)]">{{ $paymentDetails }}</p>
                @else
                    <p class="mt-0.5 font-sans text-[13px] text-[var(--text-ink)]/80">Zefanya hasn't added its payment details yet. Contact Zefanya before sending money.</p>
                @endif
            </div>
            <form method="POST" action="{{ route('logistics.earnings.remit') }}" data-remit-form class="mt-3">
                @csrf
                <input type="hidden" name="expected_amount_minor" value="{{ $ready['dueMinor'] }}" data-remit-expected>
                <ul class="max-h-64 divide-y divide-[var(--border-strong)] overflow-y-auto rounded-lg border border-[var(--border-strong)]">
                    @foreach ($readyParcels as $readyParcel)
                        @php
                            $readyDue = $readyParcel->dueToZefanyaMinor();
                        @endphp
                        <li>
                            <label class="flex cursor-pointer items-center gap-3 px-3 py-2 font-sans text-[13px] text-[var(--text-ink)]">
                                <input type="checkbox" name="parcels[]" value="{{ $readyParcel->id }}" data-remit-due="{{ $readyDue }}" checked class="accent-primary-700">
                                <span class="min-w-0 flex-1 truncate text-[var(--text-ink)]">#{{ $readyParcel->seller_order_id }} <span class="font-mono text-[11px] text-[var(--text-ink)]/80">{{ $readyParcel->sellerOrder?->tracking_number }}</span></span>
                                <span class="shrink-0 font-semibold tabular-nums text-[var(--text-ink)]">{{ $peso($readyDue) }}</span>
                            </label>
                        </li>
                    @endforeach
                </ul>
                <div class="mt-3 flex flex-wrap items-end gap-2">
                <label class="block min-w-[240px] flex-1 font-sans text-[12px] font-semibold text-[var(--text-ink)] sm:max-w-[360px]">
                    Bank or GCash reference
                    <input name="reference" required maxlength="100" value="{{ old('reference') }}" placeholder="e.g. 7012 3456 7890" class="ip-field mt-1 h-10 w-full rounded-lg border border-[var(--border-input)] px-2.5 font-sans text-[12.5px] font-normal text-[var(--text-ink)]">
                </label>
                <button type="submit" data-remit-submit class="ip-primary inline-flex h-10 cursor-pointer items-center justify-center rounded-[7px] px-4 font-sans text-[12.5px] font-semibold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-700 disabled:cursor-not-allowed disabled:opacity-50">I sent this payment</button>
                </div>
            </form>
        </div>
    </section>
@endif

<section class="mb-5 overflow-hidden rounded-xl border border-[var(--border-strong)] bg-[var(--bg-surface)] shadow-sm">
    <div class="p-4">
        <h2 class="font-sans text-[15px] font-semibold text-[var(--text-ink)]">Rider pay owed: {{ $peso((int) $riderUnpaid->sum('amountMinor')) }}</h2>
        <p class="mt-1 font-sans text-xs text-[var(--text-ink)]/80">Paid this month: {{ $peso($riderPaidThisMonthMinor) }}. Record payments below after paying your riders.</p>
        <details class="mt-2 font-sans">
            <summary class="cursor-pointer text-xs font-semibold text-[var(--text-ink)] focus-visible:outline-2 focus-visible:outline-primary-700">Rider rates and payment rules</summary>
        <p class="mt-0.5 font-sans text-[13px] text-[var(--text-ink)]/80">You pay your riders {{ $peso($riderRates['first_minor']) }} for the first parcel of each seller pickup, {{ $peso($riderRates['additional_minor']) }} for each additional parcel in that pickup, and {{ $peso($riderRates['delivery_minor']) }} per successful delivery. Failed attempts earn nothing. Each payment covers all of a rider's unpaid jobs, and a job can only be paid once. Paid this month: {{ $peso($riderPaidThisMonthMinor) }}.</p>
        </details>
    </div>
    @if ($riderUnpaid->isNotEmpty())
        <ul class="divide-y divide-[var(--border-strong)] border-t border-[var(--border-strong)]">
            @foreach ($riderUnpaid as $row)
                <li class="grid gap-3 px-4 py-3 font-sans text-[13px] text-[var(--text-ink)] lg:grid-cols-[minmax(0,1fr)_minmax(280px,520px)] lg:items-end">
                    <div class="min-w-0">
                        <p class="font-semibold text-[var(--text-ink)]">{{ $row['courier'] }}</p>
                        <p class="text-[12px] text-[var(--text-ink)]/80">{{ $row['pickups'] }} {{ \Illuminate\Support\Str::plural('pickup', $row['pickups']) }} · {{ $row['deliveries'] }} {{ \Illuminate\Support\Str::plural('delivery', $row['deliveries']) }} · <span class="font-semibold tabular-nums text-[var(--text-ink)]">{{ $peso($row['amountMinor']) }}</span></p>
                    </div>
                    <form method="POST" action="{{ route('logistics.earnings.pay-rider', $row['courierId']) }}" class="grid gap-2 sm:grid-cols-[minmax(0,1fr)_140px_auto] sm:items-end">
                        @csrf
                        <input type="hidden" name="through_id" value="{{ $row['throughId'] }}">
                        <input type="hidden" name="expected_amount_minor" value="{{ $row['amountMinor'] }}">
                        <label class="block min-w-0 text-[12px] font-semibold text-[var(--text-ink)]">
                            Payment reference
                            <input name="reference" required maxlength="100" placeholder="e.g. GCash 7012 3456" class="ip-field mt-1 h-10 w-full rounded-lg border border-[var(--border-input)] px-2.5 text-[12.5px] font-normal text-[var(--text-ink)]">
                        </label>
                        <label class="block text-[12px] font-semibold text-[var(--text-ink)]">
                            Date paid
                            <input type="date" name="paid_on" required value="{{ now(StoreSale::TIMEZONE)->toDateString() }}" max="{{ now(StoreSale::TIMEZONE)->toDateString() }}" class="ip-field mt-1 h-10 w-full rounded-lg border border-[var(--border-input)] px-2.5 text-[12.5px] font-normal text-[var(--text-ink)]">
                        </label>
                        <button type="submit" class="ip-primary inline-flex h-10 cursor-pointer items-center justify-center rounded-[7px] px-4 text-[12.5px] font-semibold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-700">Mark {{ $peso($row['amountMinor']) }} paid</button>
                    </form>
                </li>
            @endforeach
        </ul>
    @else
        <p class="border-t border-[var(--border-strong)] px-4 py-3 font-sans text-[13px] text-[var(--text-ink)]/80">No unpaid rider jobs. New pickup and delivery earnings will appear here.</p>
    @endif
    @if ($riderPayouts->isNotEmpty())
        <details class="border-t border-[var(--border-strong)] px-4 py-3 font-sans text-[13px] text-[var(--text-ink)]" @if(request()->has('rider_payouts_page') || (is_string(old('_reversal')) && str_starts_with(old('_reversal'), 'rider-'))) open @endif>
            <summary class="cursor-pointer font-semibold text-[var(--text-ink)]">Recent rider payments</summary>
            <ul class="mt-2 space-y-1 text-[12.5px] text-[var(--text-ink)]/80">
                @foreach ($riderPayouts as $payout)
                    <li class="border-b border-[var(--border-strong)] py-3 tabular-nums">
                        <p class="text-[var(--text-ink)]/80">{{ $payout->paid_on->format('M j, Y') }} &middot; {{ $payout->courier?->name ?? 'Unknown rider' }} &middot; <span class="font-semibold text-[var(--text-ink)]">{{ $peso($payout->amount_minor) }}</span> for {{ $payout->earning_count }} {{ \Illuminate\Support\Str::plural('job', $payout->earning_count) }} &middot; Ref {{ $payout->reference }} &middot; {{ $payout->reversal ? 'Reversed' : 'Paid' }}</p>
                        @if ($payout->reversal)
                            <p class="mt-1 text-[var(--text-ink)]/80">Reversed by {{ $payout->reversal->actor_name }} on {{ $payout->reversal->created_at->timezone(StoreSale::TIMEZONE)->format('M j, Y g:i A') }}: {{ $payout->reversal->reason }}</p>
                        @else
                            <details class="mt-2" @if(old('_reversal') === 'rider-'.$payout->id) open @endif>
                                <summary class="cursor-pointer font-semibold text-[var(--text-ink)]">Correct this payment record</summary>
                                <p class="mt-2 text-[var(--text-ink)]/80">Reversing keeps this history and returns its jobs to unpaid. This does not refund money. Reconcile the actual payment before recording its replacement.</p>
                                <form method="POST" action="{{ route('logistics.earnings.reverse-rider-payment', $payout) }}" class="mt-2 flex flex-col items-start gap-2">
                                    @csrf
                                    <input type="hidden" name="_reversal" value="rider-{{ $payout->id }}">
                                    <label for="rider-reversal-{{ $payout->id }}" class="font-semibold text-[var(--text-ink)]">Reason for correction</label>
                                    <textarea id="rider-reversal-{{ $payout->id }}" name="reason" required minlength="10" maxlength="500" rows="2" class="w-full rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-2 text-[var(--text-ink)]">{{ old('_reversal') === 'rider-'.$payout->id && is_string(old('reason')) ? old('reason') : '' }}</textarea>
                                    <label class="flex items-start gap-2 text-[var(--text-ink)]"><input type="checkbox" name="confirm_reversal" value="1" required class="mt-0.5">I confirm this record needs reversal and its jobs should become unpaid.</label>
                                    <button type="submit" class="rounded-lg border border-[var(--border-strong)] px-3 py-2 font-semibold text-[var(--text-ink)]">Reverse payment record</button>
                                </form>
                            </details>
                        @endif
                    </li>
                @endforeach
            </ul>
            <div class="mt-3">{{ $riderPayouts->links() }}</div>
        </details>
    @endif
</section>

<div role="tablist" aria-label="Earnings records" class="mb-4 flex flex-wrap gap-x-6 border-b border-[var(--border-strong)]">
    <a role="tab" href="#stage-fees" data-ip-tab="fees" aria-selected="{{ $firstStage === 'fees' ? 'true' : 'false' }}" class="ip-tab -mb-px inline-flex min-h-11 items-center gap-2 font-sans text-[13px] font-medium no-underline">
        Shipping fees
        <span class="ip-count rounded-full px-2 py-0.5 text-[11px] font-semibold tabular-nums">{{ $parcels->total() }}</span>
    </a>
    <a role="tab" href="#stage-payments" data-ip-tab="payments" aria-selected="{{ $firstStage === 'payments' ? 'true' : 'false' }}" class="ip-tab -mb-px inline-flex min-h-11 items-center gap-2 font-sans text-[13px] font-medium no-underline">
        Payments to Zefanya
        <span class="ip-count rounded-full px-2 py-0.5 text-[11px] font-semibold tabular-nums">{{ $payments->total() }}</span>
    </a>
</div>

<section id="stage-fees" role="tabpanel" data-ip-stage="fees" @if ($firstStage !== 'fees') hidden @endif>
    @if ($parcels->isEmpty())
        <p class="rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] px-4 py-5 text-center font-sans text-[13px] text-[var(--text-ink)]/80">Shipping fees appear here once your riders deliver parcels.</p>
    @else
        <p class="mb-3 font-sans text-[12.5px] text-[var(--text-ink)]/80"><strong class="font-semibold text-[var(--text-ink)]">+</strong> cash once your rider hands it over; <strong class="font-semibold text-[var(--text-ink)]">−</strong> Zefanya's part once you send it. Balance is the parcel cash your company holds after each parcel. Greyed amounts have not moved yet.</p>
        <div role="region" aria-label="Shipping fee records" tabindex="0" class="relative overflow-x-auto rounded-xl border border-[var(--border-strong)] bg-[var(--bg-surface)] shadow-sm">
            <table class="w-full min-w-[820px] text-left font-sans text-[13px] text-[var(--text-ink)]">
                <thead class="border-b border-[var(--border-strong)] bg-[var(--bg-raised)] text-xs text-[var(--text-ink)]">
                    <tr>
                        <th scope="col" class="{{ $th }}">Parcel</th>
                        <th scope="col" class="{{ $th }}">Delivered</th>
                        <th scope="col" class="{{ $th }} text-right">Cash collected</th>
                        <th scope="col" class="{{ $th }} text-right">Your shipping fee</th>
                        <th scope="col" class="{{ $th }} text-right">For Zefanya</th>
                        <th scope="col" class="{{ $th }}">Status</th>
                        <th scope="col" class="{{ $th }} text-right">Balance</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--border-strong)]">
                    @foreach ($parcels as $parcel)
                        @php
                            $stage = match (true) {
                                $parcel->status === CodRemittance::PENDING_REMITTANCE => 'rider',
                                $parcel->zefanya_received_at !== null => 'settled',
                                $parcel->logistics_remittance_id !== null => 'sent',
                                default => 'ready',
                            };
                            $look = $stageLooks[$stage];
                        @endphp
                        <tr>
                            <td class="px-4 py-3">
                                <p class="font-semibold text-[var(--text-ink)]">#{{ $parcel->seller_order_id }}</p>
                                <p class="font-mono text-[11px] text-[var(--text-ink)]/80">{{ $parcel->sellerOrder?->tracking_number ?? 'No tracking number' }}</p>
                                @if ($parcel->sellerOrder?->parcelReturn?->releasesPendingEarnings())
                                    <p class="ip-rose ip-tone-text text-[11.5px] font-semibold" title="You keep the shipping fee for the delivery. The rest still goes to Zefanya, which refunds the buyer.">Returned by buyer</p>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-[var(--text-ink)]/80">{{ $parcel->created_at->timezone(StoreSale::TIMEZONE)->format('M j, Y') }}</td>
                            @php
                                $moved = $parcelBalances[$parcel->id] ?? ['cashIn' => false, 'sentOut' => false, 'balanceMinor' => 0];
                                $cashMinor = (int) ($parcel->received_amount_minor ?? $parcel->amount_minor);
                            @endphp
                            <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums @unless ($moved['cashIn']) text-[var(--text-ink)]/60 @endunless" data-cash-in>{{ $moved['cashIn'] ? '+' : '' }}{{ $peso($cashMinor) }}</td>
                            <td class="ip-emerald ip-tone-text px-4 py-3 text-right font-semibold tabular-nums">{{ $peso($parcel->keptShippingMinor()) }}@if ($parcel->isCrossProvider())<span class="block text-[11px] font-normal text-[var(--text-ink)]/80">60% share</span>@endif</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums @unless ($moved['sentOut']) text-[var(--text-ink)]/60 @endunless" data-sent-out>{{ $moved['sentOut'] ? '−' : '' }}{{ $peso($parcel->dueToZefanyaMinor()) }}</td>
                            <td class="px-4 py-3"><span class="{{ $look['tone'] }} ip-tinted inline-flex rounded-full px-2.5 py-0.5 text-[11.5px] font-semibold">{{ $look['label'] }}</span></td>
                            <td class="whitespace-nowrap px-4 py-3 text-right font-semibold tabular-nums" data-balance>{{ $moved['balanceMinor'] < 0 ? '−' : '' }}{{ $peso(abs($moved['balanceMinor'])) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $parcels->links() }}</div>
    @endif
</section>

<section id="stage-payments" role="tabpanel" data-ip-stage="payments" @if ($firstStage !== 'payments') hidden @endif>
    @if ($payments->isEmpty())
        <p class="rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] px-4 py-5 text-center font-sans text-[13px] text-[var(--text-ink)]/80">Payments you record will appear here.</p>
    @else
        <ul class="space-y-3">
            @foreach ($payments as $payment)
                @php
                    $look = $paymentLooks[$payment->status];
                @endphp
                <li class="overflow-hidden rounded-xl border border-[var(--border-strong)] bg-[var(--bg-surface)] shadow-sm">
                    <div class="flex flex-wrap items-center justify-between gap-3 p-4">
                        <div class="min-w-0">
                            <p class="font-sans text-[14px] font-semibold tabular-nums text-[var(--text-ink)]">{{ $peso($payment->amount_minor) }}</p>
                            <p class="font-sans text-[12px] text-[var(--text-ink)]/80">{{ $payment->parcel_count }} {{ \Illuminate\Support\Str::plural('parcel', $payment->parcel_count) }} · kept {{ $peso($payment->shipping_total_minor) }} shipping · Ref {{ $payment->reference }}</p>
                            <p class="font-sans text-[12px] text-[var(--text-ink)]/80">Recorded {{ $payment->created_at->timezone(StoreSale::TIMEZONE)->format('M j, Y g:i A') }}</p>
                        </div>
                        <span class="{{ $look['tone'] }} ip-tinted inline-flex rounded-full px-2.5 py-0.5 font-sans text-[11.5px] font-semibold">{{ $look['label'] }}</span>
                    </div>
                    @if ($payment->status === LogisticsRemittance::REJECTED)
                        <p class="ip-strip px-4 py-3 font-sans text-[12px] text-[var(--text-ink)]"><strong class="font-semibold">Why it was rejected:</strong> {{ $payment->rejection_reason }}. These parcels are back in the amount to send.</p>
                    @endif
                </li>
            @endforeach
        </ul>
        <div class="mt-4">{{ $payments->links() }}</div>
    @endif
</section>

@endif
</div>
</div>
@endsection

@push('scripts')
<script>
    (function () {
        const form = document.querySelector('[data-remit-form]');
        if (!form) return;
        const boxes = [...form.querySelectorAll('[data-remit-due]')];
        const render = () => {
            const total = boxes.filter(box => box.checked).reduce((sum, box) => sum + Number(box.dataset.remitDue), 0);
            form.querySelector('[data-remit-expected]').value = total;
            document.querySelector('[data-remit-total]').textContent = '₱' + (total / 100).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            form.querySelector('[data-remit-submit]').disabled = !boxes.some(box => box.checked);
        };
        boxes.forEach(box => box.addEventListener('change', render));
        render();
    })();
</script>
@endpush
