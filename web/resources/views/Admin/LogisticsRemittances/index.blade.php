@extends('Admin.Layouts.app')

@section('title', 'Settlements — Zefanya Admin')

@use('App\Models\Logistics\LogisticsRemittance')
@use('App\Models\Seller\StoreSale')

@section('content')
@php
    $peso = fn (int $minor): string => '₱'.number_format($minor / 100, 2);
    $when = fn ($date): string => $date->timezone(StoreSale::TIMEZONE)->format('M j, Y g:i A');
    $panel = 'rounded-lg border border-neutral-200 bg-white';
    $th = 'border-b border-neutral-200 px-4 py-2.5 font-sans text-[11px] font-semibold uppercase tracking-[0.6px] text-neutral-500';
    $td = 'px-4 py-3 align-top';
    $field = 'h-10 w-full rounded-md border border-neutral-300 bg-white px-3 text-sm text-neutral-950 focus:border-primary-400 focus:ring-2 focus:ring-primary-400';
@endphp
<x-admin.tabs :tabs="[
    ['label' => 'Logistics payments', 'route' => 'admin.logistics-remittances.index', 'badge' => $waiting->count()],
    ['label' => 'Seller payouts', 'route' => 'admin.seller-payouts.index', 'badge' => $payoutsWaiting],
]" />
<div class="space-y-6">
    <div>
        <h1 class="font-serif text-3xl text-neutral-950">Settlements</h1>
        @if (\App\Services\Buyer\DeliveryDisputeService::installed())
            <a class="text-sm underline" href="{{ route('admin.delivery-disputes.index') }}">Non-receipt disputes</a>
        @endif
        <p class="mt-2 text-sm text-neutral-600">Logistics companies keep each parcel's shipping fee and send Zefanya the rest of the cash. Confirm each payment only after the money has arrived. Sellers on those parcels can't be paid until you do.</p>
        @if ($originShares['parcels'] > 0)
            <p class="mt-2 text-sm text-neutral-600">On {{ $originShares['parcels'] }} cross-provider {{ \Illuminate\Support\Str::plural('parcel', $originShares['parcels']) }}, the delivering company keeps 60% of the shipping fee and sends the origin carrier's 40% to Zefanya. Of {{ $peso($originShares['shareMinor']) }} in origin shares, Zefanya has received {{ $peso($originShares['receivedMinor']) }} and paid out {{ $peso($originShares['paidMinor']) }}.</p>
        @endif
    </div>

    @if ($codDiscrepancies->isNotEmpty())
        <section class="space-y-3" aria-labelledby="cod-discrepancies">
            <h2 id="cod-discrepancies" class="font-serif text-xl">Rider cash discrepancies</h2>
            @foreach ($codDiscrepancies as $remittance)
                @php
                    $case = $remittance->discrepancy;
                @endphp
                <article class="{{ $panel }} space-y-2 p-4 text-sm">
                    <h3 class="font-semibold">Parcel #{{ $remittance->seller_order_id }} · {{ $remittance->sellerOrder?->tracking_number }}</h3>
                    <p>Due {{ $peso($remittance->amount_minor) }} · Counted {{ $peso($case['counted_minor']) }} · {{ $case['difference_minor'] < 0 ? 'Short' : 'Excess' }} {{ $peso(abs($case['difference_minor'])) }}</p>
                    <p>{{ $case['staff'] }} · {{ $case['recorded_at'] }} · {{ $case['reference'] }}</p>
                    <p>{{ $case['reason'] }}</p>
                    <p>Rider explanation: {{ $case['rider_explanation'] ?? 'Awaiting explanation' }}</p>
                    @if (isset($case['review_note']))<p>Review: {{ $case['review_note'] }}</p>@endif
                    @if ($case['status'] === 'awaiting_review')
                        <form method="POST" action="{{ route('admin.cod-discrepancies.review', $remittance) }}" class="grid gap-2">
                            @csrf
                            <label>Decision <select class="{{ $field }}" name="decision"><option value="keep_open">Keep outstanding</option><option value="authorize_recovery">Authorize verified cash recovery or excess return</option></select></label>
                            <label>Review note <textarea class="block w-full rounded border p-2" name="note" required maxlength="500"></textarea></label>
                            <button class="min-h-10 rounded bg-primary-800 px-4 text-white">Record decision</button>
                        </form>
                    @else
                        <p>Recovery authorized. Waiting for Logistics to confirm exact cash.</p>
                    @endif
                </article>
            @endforeach
            {{ $codDiscrepancies->links() }}
        </section>
    @endif

    <section aria-labelledby="subsidy-heading" class="space-y-3">
        <h2 id="subsidy-heading" class="font-serif text-xl text-neutral-950">Platform voucher cost</h2>
        <p class="text-[13px] text-neutral-600">A platform-funded voucher is paid by Zefanya: the seller still gets the full price while the buyer paid less. Compare it with the 5% commission Zefanya earns. Returned orders are left out.</p>
        <div class="grid gap-4 sm:grid-cols-2">
            @foreach (['This month' => $subsidy['month'], 'All time' => $subsidy['all']] as $label => $figures)
                <div class="{{ $panel }} p-4">
                    <p class="font-sans text-xs font-semibold text-neutral-500">{{ $label }}</p>
                    <p class="mt-1 font-sans text-2xl font-semibold tabular-nums {{ $figures['netMinor'] < 0 ? 'text-rose-900' : 'text-neutral-950' }}">{{ $peso($figures['subsidyMinor']) }}</p>
                    <p class="text-[13px] text-neutral-600">Voucher cost on {{ $figures['orders'] }} {{ \Illuminate\Support\Str::plural('order', $figures['orders']) }}: {{ $peso($figures['paidSubsidyMinor']) }} already paid out to sellers, {{ $peso($figures['pendingSubsidyMinor']) }} still pending.</p>
                    <p class="mt-1 text-[13px] text-neutral-600">Commission earned {{ $peso($figures['commissionMinor']) }}. Left after voucher cost: <span class="font-semibold {{ $figures['netMinor'] < 0 ? 'text-rose-900' : 'text-neutral-950' }}">{{ $peso(abs($figures['netMinor'])) }}{{ $figures['netMinor'] < 0 ? ' short' : '' }}</span></p>
                </div>
            @endforeach
        </div>
    </section>

    @if (session('success'))
        <div role="status" class="rounded-md border border-tertiary-300 bg-tertiary-50 p-4 text-sm text-tertiary-800">{{ session('success') }}</div>
    @endif
    @if ($errors->any())
        <div role="alert" class="rounded-md border border-error bg-white p-4 text-sm text-error">{{ $errors->first() }}</div>
    @endif

    <section aria-labelledby="waiting-heading" class="space-y-3">
        <h2 id="waiting-heading" class="font-serif text-xl text-neutral-950">Waiting for your confirmation ({{ $waiting->count() }})</h2>
        @forelse ($waiting as $payment)
            <article class="{{ $panel }}">
                <div class="grid gap-4 p-4 lg:grid-cols-[minmax(0,1fr)_minmax(280px,340px)]">
                    <div class="min-w-0">
                        <p class="font-sans text-sm font-semibold text-neutral-950">{{ $payment->provider?->business_name ?? 'Unknown provider' }}</p>
                        <p class="mt-1 font-sans text-2xl font-semibold tabular-nums text-neutral-950">{{ $peso($payment->amount_minor) }}</p>
                        <p class="mt-1 text-[13px] text-neutral-600">{{ $peso($payment->cod_total_minor) }} collected, minus {{ $peso($payment->shipping_total_minor) }} shipping kept</p>
                        <p class="text-[13px] text-neutral-600">Reference <span class="font-semibold text-neutral-950">{{ $payment->reference }}</span> · Recorded {{ $when($payment->created_at) }}</p>
                        <details class="mt-2 text-[13px] text-neutral-600">
                            <summary class="cursor-pointer font-semibold text-primary-800">{{ $payment->parcel_count }} {{ \Illuminate\Support\Str::plural('parcel', $payment->parcel_count) }}</summary>
                            <ul class="mt-2 space-y-1">
                                @foreach ($payment->codRemittances as $parcel)
                                    <li class="tabular-nums"><input type="checkbox" name="parcels[]" value="{{ $parcel->id }}" form="confirm-{{ $payment->id }}" data-due="{{ $parcel->dueToZefanyaMinor() }}" data-confirm-parcel="{{ $payment->id }}" checked aria-label="Parcel {{ $parcel->seller_order_id }} arrived" class="mr-1 align-middle"> #{{ $parcel->seller_order_id }} · {{ $parcel->sellerOrder?->tracking_number ?? 'No tracking number' }} · {{ $peso($parcel->dueToZefanyaMinor()) }}@if ($parcel->isCrossProvider()) · Includes {{ $peso($parcel->originShareMinor()) }} for the origin carrier @endif @if ($parcel->sellerOrder?->parcelReturn?->releasesPendingEarnings()) · <span class="font-semibold text-rose-900">Returned, the buyer's refund comes from this</span>@endif</li>
                                @endforeach
                            </ul>
                        </details>
                    </div>
                    <div class="space-y-3">
                        <form id="confirm-{{ $payment->id }}" method="POST" action="{{ route('admin.logistics-remittances.confirm', $payment) }}" class="space-y-1">
                            @csrf
                            <div class="flex items-end gap-2">
                                <label class="block min-w-0 flex-1 text-[13px] font-semibold text-neutral-950">
                                    Amount that arrived
                                    <input name="received_amount" required inputmode="decimal" pattern="[0-9]{1,9}(\.[0-9]{1,2})?" maxlength="12" placeholder="0.00" class="{{ $field }} mt-1 font-normal">
                                </label>
                                <button type="submit" class="h-10 shrink-0 cursor-pointer rounded-md bg-primary-800 px-4 text-sm font-semibold text-white hover:bg-primary-900">Confirm</button>
                            </div>
                            <p class="text-[12px] text-neutral-600">Ticked parcels add up to <span class="font-semibold text-neutral-950" data-confirm-total="{{ $payment->id }}">{{ $peso($payment->amount_minor) }}</span>. If less arrived, untick the parcels it does not cover. They become due again.</p>
                        </form>
                        <details class="text-[13px]">
                            <summary class="cursor-pointer font-semibold text-neutral-600">Reject this payment</summary>
                            <form method="POST" action="{{ route('admin.logistics-remittances.reject', $payment) }}" class="mt-2 space-y-2">
                                @csrf
                                <input name="reason" required maxlength="500" aria-label="Reason for rejecting" placeholder="e.g. Only ₱500.00 arrived" class="{{ $field }}">
                                <button type="submit" class="h-10 cursor-pointer rounded-md border border-neutral-300 bg-white px-4 text-sm font-semibold text-neutral-950 hover:bg-neutral-100">Reject payment</button>
                            </form>
                        </details>
                    </div>
                </div>
            </article>
        @empty
            <p class="{{ $panel }} p-6 text-center text-[13px] text-neutral-600">No payments are waiting for confirmation.</p>
        @endforelse
    </section>

    <section aria-labelledby="unsent-heading" class="space-y-3">
        <h2 id="unsent-heading" class="font-serif text-xl text-neutral-950">Not yet sent by the company</h2>
        @if ($unsent->isEmpty())
            <p class="{{ $panel }} p-6 text-center text-[13px] text-neutral-600">Every company has sent what it collected from its riders.</p>
        @else
            <div class="{{ $panel }} overflow-x-auto">
                <table class="w-full min-w-[560px] text-left text-[13.5px] text-neutral-950">
                    <thead><tr><th scope="col" class="{{ $th }}">Company</th><th scope="col" class="{{ $th }} text-right">Parcels</th><th scope="col" class="{{ $th }} text-right">Owed to Zefanya</th><th scope="col" class="{{ $th }}">Waiting since</th></tr></thead>
                    <tbody class="divide-y divide-neutral-200">
                        @foreach ($unsent as $row)
                            <tr>
                                <td class="{{ $td }} font-semibold">{{ $row['provider'] }}</td>
                                <td class="{{ $td }} text-right tabular-nums">{{ $row['parcels'] }}</td>
                                <td class="{{ $td }} text-right font-semibold tabular-nums">{{ $peso($row['dueMinor']) }}</td>
                                <td class="{{ $td }}">
                                    {{ $row['oldestAt'] ? $row['oldestAt']->timezone(StoreSale::TIMEZONE)->format('M j, Y') : '—' }}
                                    @if ($row['overdueParcels'] > 0)
                                        <span class="ml-1 inline-flex rounded-full bg-rose-100 px-2.5 py-0.5 text-xs font-semibold text-rose-900">{{ $row['overdueParcels'] }} overdue (over {{ $overdueDays }} days)</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section aria-labelledby="origin-heading" class="space-y-3">
        <h2 id="origin-heading" class="font-serif text-xl text-neutral-950">Owed to origin carriers</h2>
        <p class="text-[13px] text-neutral-600">Each origin carrier gets 40% of the shipping fee on parcels another company delivered. Only parcels whose cash you have confirmed receiving can be paid, and each parcel is paid once.</p>
        @forelse ($originOwed as $row)
            <article class="{{ $panel }}">
                <div class="grid gap-4 p-4 lg:grid-cols-[minmax(0,1fr)_minmax(280px,520px)]">
                    <div class="min-w-0">
                        <p class="font-sans text-sm font-semibold text-neutral-950">{{ $row['provider'] }}</p>
                        <p class="mt-1 font-sans text-2xl font-semibold tabular-nums text-neutral-950">{{ $peso($row['amountMinor']) }}</p>
                        <p class="mt-1 text-[13px] text-neutral-600">40% share on {{ $row['parcels'] }} {{ \Illuminate\Support\Str::plural('parcel', $row['parcels']) }}</p>
                    </div>
                    <form method="POST" action="{{ route('admin.logistics-remittances.pay-origin', $row['providerId']) }}" class="grid gap-2 sm:grid-cols-[minmax(0,1fr)_150px_auto] sm:items-end">
                        @csrf
                        <input type="hidden" name="through_id" value="{{ $row['throughId'] }}">
                        <input type="hidden" name="expected_amount_minor" value="{{ $row['amountMinor'] }}">
                        <label class="block min-w-0 text-[13px] font-semibold text-neutral-950">
                            Payment reference
                            <input name="reference" required maxlength="100" placeholder="e.g. 7012 3456 7890" class="{{ $field }} mt-1 font-normal">
                        </label>
                        <label class="block text-[13px] font-semibold text-neutral-950">
                            Date paid
                            <input type="date" name="paid_on" required value="{{ now(StoreSale::TIMEZONE)->toDateString() }}" max="{{ now(StoreSale::TIMEZONE)->toDateString() }}" class="{{ $field }} mt-1 font-normal">
                        </label>
                        <button type="submit" class="h-10 shrink-0 cursor-pointer rounded-md bg-primary-800 px-4 text-sm font-semibold text-white hover:bg-primary-900">Record payment</button>
                    </form>
                </div>
            </article>
        @empty
            <p class="{{ $panel }} p-6 text-center text-[13px] text-neutral-600">No origin carrier is owed anything right now.</p>
        @endforelse

        @if ($originPayouts->isNotEmpty())
            <div class="{{ $panel }} overflow-x-auto">
                <table class="w-full min-w-[640px] text-left text-[13.5px] text-neutral-950">
                    <thead><tr><th scope="col" class="{{ $th }}">Company</th><th scope="col" class="{{ $th }} text-right">Amount</th><th scope="col" class="{{ $th }}">Reference</th><th scope="col" class="{{ $th }}">Date paid</th><th scope="col" class="{{ $th }}">Status</th></tr></thead>
                    <tbody class="divide-y divide-neutral-200">
                        @foreach ($originPayouts as $payout)
                            <tr>
                                <td class="{{ $td }} font-semibold">{{ $payout->provider?->business_name ?? 'Unknown provider' }}</td>
                                <td class="{{ $td }} text-right tabular-nums">{{ $peso($payout->amount_minor) }}</td>
                                <td class="{{ $td }}">{{ $payout->reference }}</td>
                                <td class="{{ $td }} text-neutral-600">{{ $payout->paid_on->format('M j, Y') }}<br>{{ $payout->paidBy?->name }}</td>
                                <td class="{{ $td }}">
                                    @if ($payout->reversal)
                                        <span class="font-semibold text-neutral-950">Reversed</span>
                                        <p class="mt-1 text-xs text-neutral-600">{{ $payout->reversal->actor_name }} &middot; {{ $when($payout->reversal->created_at) }}<br>{{ $payout->reversal->reason }}</p>
                                    @else
                                        <span class="inline-flex rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-semibold text-green-900">Paid</span>
                                        <details class="mt-2" @if(old('_reversal') === 'origin-'.$payout->id) open @endif>
                                            <summary class="cursor-pointer font-semibold text-primary-800">Correct payment record</summary>
                                            <p class="mt-2 text-xs text-neutral-600">Keeps the original history and returns these shares to unpaid. No money is refunded. Reconcile the actual payment before recording its replacement.</p>
                                            <form method="POST" action="{{ route('admin.logistics-remittances.reverse-origin-payment', $payout) }}" class="mt-2 flex flex-col items-start gap-2">
                                                @csrf
                                                <input type="hidden" name="_reversal" value="origin-{{ $payout->id }}">
                                                <label for="origin-reversal-{{ $payout->id }}" class="text-xs font-semibold">Reason for correction</label>
                                                <textarea id="origin-reversal-{{ $payout->id }}" name="reason" required minlength="10" maxlength="500" rows="2" class="w-full rounded-lg border border-neutral-300 bg-white p-2 text-xs text-neutral-950">{{ old('_reversal') === 'origin-'.$payout->id && is_string(old('reason')) ? old('reason') : '' }}</textarea>
                                                <label class="flex items-start gap-2 text-xs"><input type="checkbox" name="confirm_reversal" value="1" required class="mt-0.5">I confirm this record needs reversal and its shares should become unpaid.</label>
                                                <button type="submit" class="rounded-lg border border-neutral-300 px-3 py-2 text-xs font-semibold text-neutral-950">Reverse payment record</button>
                                            </form>
                                        </details>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="p-3">{{ $originPayouts->links() }}</div>
            </div>
        @endif
    </section>

    @if ($historical->isNotEmpty() || $destinationOwed->isNotEmpty())
        <section aria-labelledby="historical-heading" class="space-y-3">
            <h2 id="historical-heading" class="font-serif text-xl text-neutral-950">Old shipping fees to reconcile</h2>
            <p class="text-[13px] text-neutral-600">These cross-company parcels were paid to Zefanya before the 40/60 split existed, so Zefanya holds the whole shipping fee. Split it, and the origin carrier's 40% joins the payments above and the delivering company's 60% is recorded as owed, or keep the fee. Each decision is final and is written to the audit log.</p>
            @if ($historical->isNotEmpty())
                <div class="{{ $panel }} overflow-x-auto">
                    <table class="w-full min-w-[760px] text-left text-[13.5px] text-neutral-950">
                        <thead><tr><th scope="col" class="{{ $th }}">Parcel</th><th scope="col" class="{{ $th }}">Origin carrier</th><th scope="col" class="{{ $th }}">Delivered by</th><th scope="col" class="{{ $th }} text-right">Shipping fee</th><th scope="col" class="{{ $th }}">Decision</th></tr></thead>
                        <tbody class="divide-y divide-neutral-200">
                            @foreach ($historical as $parcel)
                                @php
                                    $fee = (int) $parcel->sellerOrder->shipping_fee_minor;
                                @endphp
                                <tr>
                                    <td class="{{ $td }} tabular-nums">#{{ $parcel->seller_order_id }}<br><span class="text-xs text-neutral-600">{{ $parcel->sellerOrder->tracking_number }}</span></td>
                                    <td class="{{ $td }}">{{ $parcel->sellerOrder->carrierProvider?->business_name ?? 'Unknown' }}</td>
                                    <td class="{{ $td }}">{{ $parcel->provider?->business_name ?? 'Unknown' }}</td>
                                    <td class="{{ $td }} text-right tabular-nums">{{ $peso($fee) }}</td>
                                    <td class="{{ $td }}">
                                        <div class="flex flex-wrap gap-2">
                                            <form method="POST" action="{{ route('admin.logistics-remittances.reconcile-historical', $parcel) }}">@csrf<input type="hidden" name="decision" value="allocated"><button type="submit" class="h-9 cursor-pointer rounded-md bg-primary-800 px-3 text-xs font-semibold text-white hover:bg-primary-900">Split 40/60</button></form>
                                            <form method="POST" action="{{ route('admin.logistics-remittances.reconcile-historical', $parcel) }}">@csrf<input type="hidden" name="decision" value="kept"><button type="submit" class="h-9 cursor-pointer rounded-md border border-neutral-300 bg-white px-3 text-xs font-semibold text-neutral-950 hover:bg-neutral-100">Keep with Zefanya</button></form>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
            @foreach ($destinationOwed as $parcel)
                <article class="{{ $panel }}">
                    <div class="grid gap-3 p-4 lg:grid-cols-[minmax(0,1fr)_minmax(280px,520px)] lg:items-end">
                        <div class="min-w-0">
                            <p class="font-sans text-sm font-semibold text-neutral-950">{{ $parcel->provider?->business_name ?? 'Unknown provider' }} is owed {{ $peso((int) $parcel->destination_share_minor) }}</p>
                            <p class="text-[13px] text-neutral-600">60% of the shipping fee on parcel #{{ $parcel->seller_order_id }} ({{ $parcel->sellerOrder?->tracking_number }}), delivered under the old rule.</p>
                        </div>
                        <form method="POST" action="{{ route('admin.logistics-remittances.pay-destination', $parcel) }}" class="grid gap-2 sm:grid-cols-[minmax(0,1fr)_150px_auto] sm:items-end">
                            @csrf
                            <label class="block min-w-0 text-[13px] font-semibold text-neutral-950">Payment reference<input name="reference" required maxlength="100" class="{{ $field }} mt-1 font-normal"></label>
                            <label class="block text-[13px] font-semibold text-neutral-950">Date paid<input type="date" name="paid_on" required value="{{ now(StoreSale::TIMEZONE)->toDateString() }}" max="{{ now(StoreSale::TIMEZONE)->toDateString() }}" class="{{ $field }} mt-1 font-normal"></label>
                            <button type="submit" class="h-10 shrink-0 cursor-pointer rounded-md bg-primary-800 px-4 text-sm font-semibold text-white hover:bg-primary-900">Record payment</button>
                        </form>
                    </div>
                </article>
            @endforeach
        </section>
    @endif

    <section aria-labelledby="history-heading" class="space-y-3">
        <h2 id="history-heading" class="font-serif text-xl text-neutral-950">History</h2>
        @if ($history->isEmpty())
            <p class="{{ $panel }} p-6 text-center text-[13px] text-neutral-600">Confirmed and rejected payments will appear here.</p>
        @else
            <div class="{{ $panel }} overflow-x-auto">
                <table class="w-full min-w-[760px] text-left text-[13.5px] text-neutral-950">
                    <thead><tr><th scope="col" class="{{ $th }}">Company</th><th scope="col" class="{{ $th }} text-right">Amount</th><th scope="col" class="{{ $th }}">Reference</th><th scope="col" class="{{ $th }}">Result</th><th scope="col" class="{{ $th }}">Decided</th></tr></thead>
                    <tbody class="divide-y divide-neutral-200">
                        @foreach ($history as $payment)
                            @php
                                $isConfirmed = $payment->status === LogisticsRemittance::CONFIRMED;
                            @endphp
                            <tr>
                                <td class="{{ $td }} font-semibold">{{ $payment->provider?->business_name ?? 'Unknown provider' }}</td>
                                <td class="{{ $td }} text-right tabular-nums">{{ $peso($payment->amount_minor) }}</td>
                                <td class="{{ $td }}">{{ $payment->reference }}</td>
                                <td class="{{ $td }}">
                                    <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $isConfirmed ? 'bg-green-100 text-green-900' : 'bg-rose-100 text-rose-900' }}">{{ $isConfirmed ? ($payment->isPartlyConfirmed() ? 'Partly confirmed' : 'Confirmed') : 'Rejected' }}</span>
                                    @if ($payment->isPartlyConfirmed())
                                        <p class="mt-1 text-[12px] text-neutral-600">{{ $peso($payment->received_amount_minor) }} arrived, {{ $peso($payment->short_minor) }} short. {{ $payment->left_out_count }} {{ \Illuminate\Support\Str::plural('parcel', $payment->left_out_count) }} due again.</p>
                                    @endif
                                    @if (! $isConfirmed)
                                        <p class="mt-1 text-[12px] text-neutral-600">{{ $payment->rejection_reason }}</p>
                                    @endif
                                </td>
                                <td class="{{ $td }} text-neutral-600">{{ $when($payment->confirmed_at ?? $payment->rejected_at) }}<br>{{ $payment->decidedBy?->name }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div>{{ $history->links() }}</div>
        @endif
    </section>
</div>
@push('scripts')
<script>
    document.querySelectorAll('[data-confirm-total]').forEach(function (label) {
        var id = label.getAttribute('data-confirm-total');
        var boxes = document.querySelectorAll('[data-confirm-parcel="' + id + '"]');
        var update = function () {
            var cents = 0;
            boxes.forEach(function (box) { if (box.checked) { cents += parseInt(box.getAttribute('data-due'), 10); } });
            label.textContent = '\u20b1' + (cents / 100).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        };
        boxes.forEach(function (box) { box.addEventListener('change', update); });
    });
</script>
@endpush
@endsection
