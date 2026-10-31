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
        <p class="mt-2 text-sm text-neutral-600">Logistics companies keep each parcel's shipping fee and send Zefanya the rest of the cash. Confirm each payment only after the money has arrived. Sellers on those parcels can't be paid until you do.</p>
    </div>

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
                                    <li class="tabular-nums">#{{ $parcel->seller_order_id }} · {{ $parcel->sellerOrder?->tracking_number ?? 'No tracking number' }} · {{ $peso($parcel->amount_minor - (int) $parcel->sellerOrder?->shipping_fee_minor) }}@if ($parcel->sellerOrder?->parcelReturn?->releasesPendingEarnings()) · <span class="font-semibold text-rose-900">Returned, the buyer's refund comes from this</span>@endif</li>
                                @endforeach
                            </ul>
                        </details>
                    </div>
                    <div class="space-y-3">
                        <form method="POST" action="{{ route('admin.logistics-remittances.confirm', $payment) }}" class="flex items-end gap-2">
                            @csrf
                            <label class="block min-w-0 flex-1 text-[13px] font-semibold text-neutral-950">
                                Amount that arrived
                                <input name="received_amount" required inputmode="decimal" pattern="[0-9]{1,9}(\.[0-9]{1,2})?" maxlength="12" placeholder="0.00" class="{{ $field }} mt-1 font-normal">
                            </label>
                            <button type="submit" class="h-10 shrink-0 cursor-pointer rounded-md bg-primary-800 px-4 text-sm font-semibold text-white hover:bg-primary-900">Confirm</button>
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
                <table class="w-full min-w-[480px] text-left text-[13.5px] text-neutral-950">
                    <thead><tr><th scope="col" class="{{ $th }}">Company</th><th scope="col" class="{{ $th }} text-right">Parcels</th><th scope="col" class="{{ $th }} text-right">Owed to Zefanya</th></tr></thead>
                    <tbody class="divide-y divide-neutral-200">
                        @foreach ($unsent as $row)
                            <tr><td class="{{ $td }} font-semibold">{{ $row['provider'] }}</td><td class="{{ $td }} text-right tabular-nums">{{ $row['parcels'] }}</td><td class="{{ $td }} text-right font-semibold tabular-nums">{{ $peso($row['dueMinor']) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

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
                                    <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $isConfirmed ? 'bg-green-100 text-green-900' : 'bg-rose-100 text-rose-900' }}">{{ $isConfirmed ? 'Confirmed' : 'Rejected' }}</span>
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
@endsection
