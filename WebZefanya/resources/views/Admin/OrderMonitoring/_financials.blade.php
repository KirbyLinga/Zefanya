@php
    // The ledger below the payment summary is mostly empty for new shipments; collapse it unless something is recorded.
    $hasLedger = $cod || $settlement || $return || $financials['loss']
        || $financials['allocations']->total() > 0 || $financials['earnings']->total() > 0 || $financials['debts']->total() > 0
        || ($financials['unpaidRider'] ?? 0) > 0;
    $sub = 'mt-8 flex items-center gap-2 font-semibold';
    $list = 'mt-3 space-y-2 text-sm';
    $row = 'rounded-xl border border-neutral-200 bg-neutral-50 px-3 py-2';
    $none = 'rounded-xl border border-dashed border-neutral-200 px-3 py-2 italic text-neutral-400';
@endphp
<h3 class="mt-6 flex items-center gap-2 font-semibold"><i data-lucide="receipt" width="16" height="16" class="text-primary-700" aria-hidden="true"></i>Buyer payment and commission</h3>
@include('Admin.OrderMonitoring._fields', ['badges' => ['This shipment payment method', 'Payment status'], 'fields' => [
    'Master order total — all Seller shipments (context only)' => $money($shipment->order?->total_minor),
    'This shipment payment method' => $payment?->method?->value,
    'This shipment payable' => $money($payment?->amount_minor),
    'Payment status' => $payment?->status?->value,
    'COD collection required' => $payment ? ($payment->requiresCollection() ? 'Yes' : 'No') : 'Not recorded',
    'COD collected amount' => $money($payment?->collected_amount_minor),
    'Collected by' => $payment?->collected_at ? $monitor->actor($payment->collected_by_type, $payment->collected_by_id) : 'Not recorded',
    'Collected at' => $payment?->collected_at,
    'Shipment merchandise subtotal' => $money($shipment->subtotal_minor),
    'Shipment shipping fee' => $money($shipment->shipping_fee_minor),
    'Platform commission' => $shipment->commission_finalized_at ? $money($shipment->commission_minor).' · finalized' : $money($shipment->commission_minor).' · not finalized',
]])

<details class="group mt-8 rounded-xl border border-neutral-200" @if ($hasLedger) open @endif>
    <summary class="flex cursor-pointer list-none items-center justify-between gap-3 rounded-xl px-4 py-3 text-sm font-semibold text-primary-800 hover:bg-primary-50 focus-visible:outline-2 focus-visible:outline-primary-800">
        <span class="flex items-center gap-2"><i data-lucide="book-open" width="16" height="16" aria-hidden="true"></i>View full ledger <span class="font-normal text-neutral-600">— remittance, settlement, payouts, earnings, refunds</span></span>
        <i data-lucide="chevron-down" width="16" height="16" class="transition-transform group-open:rotate-180" aria-hidden="true"></i>
    </summary>
    <div class="border-t border-neutral-200 p-4 pt-0">
<h3 class="{{ $sub }}"><i data-lucide="truck" width="16" height="16" class="text-primary-700" aria-hidden="true"></i>Rider → Logistics → platform</h3>
@if ($cod)
    @if($cod->allocationRequiresApproval())
        <p class="mt-3 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-neutral-950">Shipping allocation requires an approved three-or-more-provider method. Original charges and historical snapshots are retained; no new shipping-share payout is permitted. <a class="font-semibold underline" href="{{ route('admin.logistics-remittances.index') }}">Review allocation context</a></p>
    @endif
    @include('Admin.OrderMonitoring._fields', ['fields' => [
        'COD remittance' => '#'.$cod->id.' · '.$cod->status,
        'Collecting provider' => $cod->provider?->business_name,
        'Collecting courier' => $cod->courier?->name,
        'Rider cash due to Logistics' => $money($cod->amount_minor),
        'Cash received by Logistics' => $money($cod->received_amount_minor),
        'Logistics receipt confirmed at' => $cod->confirmed_at,
        'Outstanding rider shortage' => $money($cod->outstandingShortageMinor()),
        'Shortage reason' => $cod->shortage_reason,
        'This parcel due to platform' => $money($cod->dueToZefanyaMinor()),
        'This parcel platform receipt' => $cod->zefanya_received_at ?? 'Not received',
        'This parcel origin shipping share' => $money($cod->originShareMinor()),
        'This parcel destination kept shipping' => $money($cod->keptShippingMinor()),
        'Origin share payout' => $cod->originSharePayout ? '#'.$cod->originSharePayout->id.' · '.$cod->originSharePayout->reference.' · '.$cod->originSharePayout->paid_on : 'No payout recorded',
        'Historical destination share' => $money($cod->destination_share_minor),
        'Historical destination payout' => $cod->destination_paid_on ? $cod->destination_paid_on.' · '.$cod->destination_paid_reference : 'No payout recorded',
        'Outstanding rider → Logistics cash' => $money(max(0, $cod->amount_minor - (int) $cod->received_amount_minor)),
        'Outstanding Logistics → platform (this parcel)' => $cod->zefanya_received_at ? $money(0) : $money($cod->dueToZefanyaMinor()),
    ]])
    <p class="mt-3 flex flex-wrap items-center gap-2 text-sm text-neutral-600">Remittance status: @include('Admin.OrderMonitoring._badge', ['value' => $cod->status])</p>
    @if ($batch = $financials['batch'])
        <p class="mt-4 rounded-lg bg-neutral-50 p-3 text-sm">Shared Logistics remittance #{{ $batch->id }} · {{ $batch->status }} · {{ $batch->parcel_count }} parcels. Batch submitted: {{ $money($batch->amount_minor) }}; batch received: {{ $money($batch->received_amount_minor) }}. These batch totals are context only. Parcel receipt is determined by its own platform receipt timestamp above.</p>
    @endif
@else
    <p class="mt-3 text-sm italic text-neutral-400">No COD remittance recorded.</p>
@endif

<h3 class="{{ $sub }}"><i data-lucide="landmark" width="16" height="16" class="text-primary-700" aria-hidden="true"></i>Seller settlement and payout allocations</h3>
@if ($settlement)
    @include('Admin.OrderMonitoring._fields', ['fields' => [
        'Settlement' => '#'.$settlement->id.' · '.$settlement->status,
        'Settlement collected' => $money($settlement->collected_amount_minor),
        'Shipping allocation' => $money($settlement->shipping_allocation_minor),
        'Settlement commission' => $money($settlement->commission_minor),
        'Seller payout entitlement' => $money($settlement->seller_payout_minor),
        'Seller paid amount' => $money($settlement->paid_amount_minor),
        'Remaining Seller payout (recorded balance)' => $settlement->seller_payout_minor === null ? 'Not finalized' : $money($settlement->remainingPayoutMinor()),
        'Financial hold' => $settlement->pending_reason ?? 'None recorded',
    ]])
    <p class="mt-3 flex flex-wrap items-center gap-2 text-sm text-neutral-600">Settlement status: @include('Admin.OrderMonitoring._badge', ['value' => $settlement->status])</p>
@else
    <p class="mt-3 text-sm italic text-neutral-400">No Seller settlement recorded.</p>
@endif
<ul class="{{ $list }}">
    @forelse ($financials['allocations'] as $allocation)
        <li class="{{ $row }}">Request #{{ $allocation->seller_payout_request_id }} · {{ $allocation->payoutRequest?->status ?? 'Unavailable' }} · This settlement allocation: {{ $money($allocation->amount_minor) }} · {{ manila($allocation->payoutRequest?->paid_at, 'Y-m-d H:i:s', 'No payment date') }}. Request reference: {{ $allocation->payoutRequest?->payout_reference ?? 'Not recorded' }}.</li>
    @empty<li class="{{ $none }}">No Seller payout allocations recorded.</li>@endforelse
</ul>
<div class="mt-3">{{ $financials['allocations']->links('Admin.OrderMonitoring._pagination') }}</div>

<h3 class="{{ $sub }}"><i data-lucide="bike" width="16" height="16" class="text-primary-700" aria-hidden="true"></i>Rider earnings and payouts</h3>
<p class="mt-2 text-sm">Outstanding Rider earnings for this shipment: <span class="font-semibold">{{ $money($financials['unpaidRider']) }}</span></p>
<ul class="{{ $list }}">
    @forelse ($financials['earnings'] as $earning)
        <li class="{{ $row }} flex flex-wrap items-center gap-x-2 gap-y-1">{{ $earning->courier?->name ?? 'Courier #'.$earning->courier_id }} · {{ $earning->job }} · {{ $money($earning->amount_minor) }} · {{ $earning->isPaid() ? 'Paid' : 'Unpaid' }}@if ($earning->payout) · Shared payout #{{ $earning->payout->id }} · {{ $earning->payout->reference }} · {{ $earning->payout->paid_on }}@endif</li>
    @empty<li class="{{ $none }}">No Rider earnings recorded.</li>@endforelse
</ul>
<div class="mt-3">{{ $financials['earnings']->links('Admin.OrderMonitoring._pagination') }}</div>

<h3 class="{{ $sub }}"><i data-lucide="undo-2" width="16" height="16" class="text-primary-700" aria-hidden="true"></i>Refunds, debts and return obligations</h3>
@include('Admin.OrderMonitoring._fields', ['badges' => ['Return status'], 'fields' => [
    'Return status' => $return?->status?->value,
    'Return fault' => $return?->fault,
    'Recorded refund' => $money($return?->refund_amount_minor),
    'Refund paid at' => $return?->refunded_at,
    'Refund reference' => $return?->refund_reference,
    'Refund recipient' => $return?->refund_recipient,
    'Financial review required' => $return ? ($return->financial_review_required ? 'Yes' : 'No') : 'Not recorded',
]])
<ul class="{{ $list }} mt-4">
    @forelse ($financials['debts'] as $debt)<li class="{{ $row }}">Seller debt #{{ $debt->id }} · {{ $debt->reason }} · Recorded: {{ $money($debt->amount_minor) }} · Settled: {{ $money($debt->settled_minor) }} · Outstanding: {{ $money($debt->outstandingMinor()) }}</li>@empty<li class="{{ $none }}">No Seller debts recorded.</li>@endforelse
    @foreach ($return?->returnCharges ?? [] as $charge)<li class="{{ $row }}">{{ $charge->provider?->business_name }} · {{ $charge->label() }} · {{ $charge->owedToZefanya() ? 'Provider owes platform' : 'Platform owes provider' }} · {{ $money($charge->amount_minor) }} · {{ $charge->settled_at ? 'Settled '.$charge->settled_at : 'Outstanding' }}</li>@endforeach
</ul>
<div class="mt-3">{{ $financials['debts']->links('Admin.OrderMonitoring._pagination') }}</div>
@if ($loss = $financials['loss'])
    @include('Admin.OrderMonitoring._fields', ['badges' => ['Loss compensation status'], 'fields' => [
        'Loss compensation status' => $loss->compensation_status,
        'Net loss compensation' => $money($loss->netCompensationMinor()),
        'Loss compensation outstanding' => $money($loss->isPaid() ? 0 : $loss->netCompensationMinor()),
        'Compensation payment reference' => $loss->compensation_reference,
        'Compensation paid at' => $loss->compensation_paid_at,
    ]])
@endif
    </div>
</details>
