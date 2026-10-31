@extends('Layouts.logistics')

@section('title', 'Earnings')

@use('App\Models\Logistics\CodRemittance')
@use('App\Models\Logistics\LogisticsRemittance')
@use('App\Models\Seller\StoreSale')

@section('content')
@include('Logistics._parcel-queue-assets')
@php
    $peso = fn (int $minor): string => '₱'.number_format($minor / 100, 2);
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
@endphp

<div class="ip-canvas -m-4 min-h-screen sm:-m-6 xl:-m-8">
<div class="border-b border-[var(--border-strong)] bg-[var(--bg-surface)] px-4 py-4 sm:px-6 xl:px-8">
    <h1 class="font-serif text-2xl font-semibold text-[var(--text-ink)]">Earnings</h1>
    <p class="mt-1 font-sans text-[13px] text-[var(--text-muted)]">You keep the shipping fee on each delivered parcel and send Zefanya the rest of the cash.</p>
</div>

<div class="p-4 sm:p-6 xl:px-8">
<x-logistics-flash :messages="['success', 'warning', 'error']" />
@if ($errors->any())
    <div role="alert" class="mb-4 rounded-md border border-red-200 bg-red-50 p-3 font-sans text-[13px] text-red-900">@foreach ($errors->all() as $error)<p class="text-red-900">{{ $error }}</p>@endforeach</div>
@endif

<div class="mb-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
    @foreach ($tiles as $tile)
        <div class="flex items-start gap-3 rounded-xl border border-[var(--border-strong)] bg-[var(--bg-surface)] p-4 shadow-sm">
            <span class="{{ $tile['tone'] }} ip-tinted flex h-9 w-9 shrink-0 items-center justify-center rounded-lg">
                <i data-lucide="{{ $tile['icon'] }}" class="h-[18px] w-[18px]"></i>
            </span>
            <div class="min-w-0">
                <p class="font-sans text-[12px] text-[var(--text-muted)]">{{ $tile['label'] }}</p>
                <p class="font-sans text-xl font-semibold tabular-nums text-[var(--text-ink)]">{{ $tile['value'] }}</p>
                <p class="font-sans text-[12px] text-[var(--text-muted)]">{{ $tile['note'] }}</p>
            </div>
        </div>
    @endforeach
</div>

@if ($ready['parcels'] > 0)
    <section class="mb-5 overflow-hidden rounded-xl border border-[var(--border-strong)] bg-[var(--bg-surface)] shadow-sm">
        <div class="p-4">
            <h2 class="font-sans text-[15px] font-semibold text-[var(--text-ink)]">Send <span data-remit-total class="text-[var(--text-ink)]">{{ $peso($ready['dueMinor']) }}</span> to Zefanya</h2>
            <p class="mt-0.5 font-sans text-[13px] text-[var(--text-muted)]">
                You collected {{ $peso($ready['codMinor']) }} and keep {{ $peso($ready['shippingMinor']) }} in shipping. Choose the parcels this payment covers, send the money, then record its reference here.
            </p>
            <div class="ip-field mt-3 rounded-lg border border-[var(--border-strong)] p-3">
                <p class="font-sans text-[12px] font-semibold text-[var(--text-ink)]">Send it to</p>
                @if (filled($paymentDetails))
                    <p class="mt-0.5 whitespace-pre-line font-sans text-[13px] text-[var(--text-ink)]">{{ $paymentDetails }}</p>
                @else
                    <p class="mt-0.5 font-sans text-[13px] text-[var(--text-muted)]">Zefanya hasn't added its payment details yet. Contact Zefanya before sending money.</p>
                @endif
            </div>
            <form method="POST" action="{{ route('logistics.earnings.remit') }}" data-remit-form class="mt-3">
                @csrf
                <input type="hidden" name="expected_amount_minor" value="{{ $ready['dueMinor'] }}" data-remit-expected>
                <ul class="max-h-64 divide-y divide-[var(--border-strong)] overflow-y-auto rounded-lg border border-[var(--border-strong)]">
                    @foreach ($readyParcels as $readyParcel)
                        @php
                            $readyDue = $readyParcel->amount_minor - (int) $readyParcel->sellerOrder?->shipping_fee_minor;
                        @endphp
                        <li>
                            <label class="flex cursor-pointer items-center gap-3 px-3 py-2 font-sans text-[13px] text-[var(--text-ink)]">
                                <input type="checkbox" name="parcels[]" value="{{ $readyParcel->id }}" data-remit-due="{{ $readyDue }}" checked class="accent-primary-700">
                                <span class="min-w-0 flex-1 truncate text-[var(--text-ink)]">#{{ $readyParcel->seller_order_id }} <span class="font-mono text-[11px] text-[var(--text-muted)]">{{ $readyParcel->sellerOrder?->tracking_number }}</span></span>
                                <span class="shrink-0 font-semibold tabular-nums text-[var(--text-ink)]">{{ $peso($readyDue) }}</span>
                            </label>
                        </li>
                    @endforeach
                </ul>
                <div class="mt-3 flex flex-wrap items-end gap-2">
                <label class="block min-w-[240px] flex-1 font-sans text-[12px] font-semibold text-[var(--text-ink)] sm:max-w-[360px]">
                    Bank or GCash reference
                    <input name="reference" required maxlength="100" value="{{ old('reference') }}" placeholder="e.g. 7012 3456 7890" class="ip-field mt-1 h-9 w-full rounded-lg border border-[var(--border-strong)] px-2.5 font-sans text-[12.5px] font-normal text-[var(--text-ink)]">
                </label>
                <button type="submit" data-remit-submit class="ip-primary inline-flex h-9 cursor-pointer items-center justify-center rounded-lg px-4 font-sans text-[12.5px] font-semibold disabled:cursor-not-allowed disabled:opacity-50">I sent this payment</button>
                </div>
            </form>
        </div>
    </section>
@endif

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
        <p class="rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-8 text-center font-sans text-[13px] text-[var(--text-muted)]">Shipping fees appear here once your riders deliver parcels.</p>
    @else
        <div class="overflow-x-auto rounded-xl border border-[var(--border-strong)] bg-[var(--bg-surface)] shadow-sm">
            <table class="w-full min-w-[720px] text-left font-sans text-[13px] text-[var(--text-ink)]">
                <thead class="border-b border-[var(--border-strong)] text-[12px] text-[var(--text-muted)]">
                    <tr>
                        <th scope="col" class="{{ $th }}">Parcel</th>
                        <th scope="col" class="{{ $th }}">Delivered</th>
                        <th scope="col" class="{{ $th }} text-right">Cash collected</th>
                        <th scope="col" class="{{ $th }} text-right">Your shipping fee</th>
                        <th scope="col" class="{{ $th }} text-right">For Zefanya</th>
                        <th scope="col" class="{{ $th }}">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--border-strong)]">
                    @foreach ($parcels as $parcel)
                        @php
                            $fee = (int) $parcel->sellerOrder?->shipping_fee_minor;
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
                                <p class="font-mono text-[11px] text-[var(--text-muted)]">{{ $parcel->sellerOrder?->tracking_number ?? 'No tracking number' }}</p>
                                @if ($parcel->sellerOrder?->parcelReturn?->releasesPendingEarnings())
                                    <p class="ip-rose ip-tone-text text-[11.5px] font-semibold" title="You keep the shipping fee for the delivery. The rest still goes to Zefanya, which refunds the buyer.">Returned by buyer</p>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-[var(--text-muted)]">{{ $parcel->created_at->timezone(StoreSale::TIMEZONE)->format('M j, Y') }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ $peso($parcel->amount_minor) }}</td>
                            <td class="ip-emerald ip-tone-text px-4 py-3 text-right font-semibold tabular-nums">{{ $peso($fee) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ $peso($parcel->amount_minor - $fee) }}</td>
                            <td class="px-4 py-3"><span class="{{ $look['tone'] }} ip-tinted inline-flex rounded-full px-2.5 py-0.5 text-[11.5px] font-semibold">{{ $look['label'] }}</span></td>
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
        <p class="rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-8 text-center font-sans text-[13px] text-[var(--text-muted)]">Payments you record will appear here.</p>
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
                            <p class="font-sans text-[12px] text-[var(--text-muted)]">{{ $payment->parcel_count }} {{ \Illuminate\Support\Str::plural('parcel', $payment->parcel_count) }} · kept {{ $peso($payment->shipping_total_minor) }} shipping · Ref {{ $payment->reference }}</p>
                            <p class="font-sans text-[12px] text-[var(--text-muted)]">Recorded {{ $payment->created_at->timezone(StoreSale::TIMEZONE)->format('M j, Y g:i A') }}</p>
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
