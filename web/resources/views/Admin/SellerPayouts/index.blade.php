@extends('Admin.Layouts.app')

@section('title', 'Seller Payouts — Zefanya Admin')

@use('App\Models\Seller\SellerPayoutRequest')
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
    ['label' => 'Logistics payments', 'route' => 'admin.logistics-remittances.index'],
    ['label' => 'Seller payouts', 'route' => 'admin.seller-payouts.index', 'badge' => $waiting->count()],
]" />
<div class="space-y-6">
    <div>
        <h1 class="font-serif text-3xl text-neutral-950">Seller Payouts</h1>
        <p class="mt-2 text-sm text-neutral-600">Send each seller their money by bank or GCash first, then record the reference here. Zefanya has already confirmed receiving the cash behind every request.</p>
    </div>

    @if (session('success'))
        <div role="status" class="rounded-md border border-tertiary-300 bg-tertiary-50 p-4 text-sm text-tertiary-800">{{ session('success') }}</div>
    @endif
    @if ($errors->any())
        <div role="alert" class="rounded-md border border-error bg-white p-4 text-sm text-error">{{ $errors->first() }}</div>
    @endif

    <section aria-labelledby="payouts-waiting-heading" class="space-y-3">
        <h2 id="payouts-waiting-heading" class="font-serif text-xl text-neutral-950">Waiting to be paid ({{ $waiting->count() }})</h2>
        @forelse ($waiting as $payout)
            <article class="{{ $panel }}">
                <div class="grid gap-4 p-4 lg:grid-cols-[minmax(0,1fr)_minmax(280px,340px)]">
                    <div class="min-w-0">
                        <p class="font-sans text-sm font-semibold text-neutral-950">{{ $payout->seller?->business_name ?? 'Unknown seller' }}</p>
                        <p class="mt-1 font-sans text-2xl font-semibold tabular-nums text-neutral-950">{{ $peso($payout->amount_minor) }}</p>
                        <p class="mt-1 text-[13px] text-neutral-600">Send to: <span class="font-semibold text-neutral-950">{{ $payout->payout_destination ?? 'No details given. Reject and ask the seller to request again.' }}</span></p>
                        <p class="text-[13px] text-neutral-600">Requested {{ $when($payout->created_at) }}</p>
                        <details class="mt-2 text-[13px] text-neutral-600">
                            <summary class="cursor-pointer font-semibold text-primary-800">{{ $payout->allocations->count() }} {{ \Illuminate\Support\Str::plural('order', $payout->allocations->count()) }}</summary>
                            <ul class="mt-2 space-y-1">
                                @foreach ($payout->allocations as $allocation)
                                    <li class="tabular-nums">#{{ $allocation->settlement?->seller_order_id }} · {{ $allocation->settlement?->sellerOrder?->tracking_number ?? 'No tracking number' }} · {{ $peso($allocation->amount_minor) }}</li>
                                @endforeach
                            </ul>
                        </details>
                    </div>
                    <div class="space-y-3">
                        <form method="POST" action="{{ route('admin.seller-payouts.pay', $payout) }}" class="flex items-end gap-2">
                            @csrf
                            <label class="block min-w-0 flex-1 text-[13px] font-semibold text-neutral-950">
                                Bank or GCash reference
                                <input name="reference" required maxlength="255" class="{{ $field }} mt-1 font-normal">
                            </label>
                            <button type="submit" class="h-10 shrink-0 cursor-pointer rounded-md bg-primary-800 px-4 text-sm font-semibold text-white hover:bg-primary-900">I paid this</button>
                        </form>
                        <details class="text-[13px]">
                            <summary class="cursor-pointer font-semibold text-neutral-600">Reject this request</summary>
                            <form method="POST" action="{{ route('admin.seller-payouts.reject', $payout) }}" class="mt-2 space-y-2">
                                @csrf
                                <input name="reason" required maxlength="500" aria-label="Reason for rejecting" placeholder="e.g. The GCash number is not registered" class="{{ $field }}">
                                <button type="submit" class="h-10 cursor-pointer rounded-md border border-neutral-300 bg-white px-4 text-sm font-semibold text-neutral-950 hover:bg-neutral-100">Reject request</button>
                            </form>
                        </details>
                    </div>
                </div>
            </article>
        @empty
            <p class="{{ $panel }} p-6 text-center text-[13px] text-neutral-600">No sellers are waiting for a payout.</p>
        @endforelse
    </section>

    <section aria-labelledby="payouts-history-heading" class="space-y-3">
        <h2 id="payouts-history-heading" class="font-serif text-xl text-neutral-950">History</h2>
        @if ($history->isEmpty())
            <p class="{{ $panel }} p-6 text-center text-[13px] text-neutral-600">Paid and rejected requests will appear here.</p>
        @else
            <div class="{{ $panel }} overflow-x-auto">
                <table class="w-full min-w-[760px] text-left text-[13.5px] text-neutral-950">
                    <thead><tr><th scope="col" class="{{ $th }}">Seller</th><th scope="col" class="{{ $th }} text-right">Amount</th><th scope="col" class="{{ $th }}">Result</th><th scope="col" class="{{ $th }}">Decided</th></tr></thead>
                    <tbody class="divide-y divide-neutral-200">
                        @foreach ($history as $payout)
                            @php
                                $isPaid = $payout->status === SellerPayoutRequest::PAID;
                            @endphp
                            <tr>
                                <td class="{{ $td }} font-semibold">{{ $payout->seller?->business_name ?? 'Unknown seller' }}</td>
                                <td class="{{ $td }} text-right tabular-nums">{{ $peso($payout->amount_minor) }}</td>
                                <td class="{{ $td }}">
                                    <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $isPaid ? 'bg-green-100 text-green-900' : 'bg-rose-100 text-rose-900' }}">{{ $isPaid ? 'Paid' : 'Rejected' }}</span>
                                    <p class="mt-1 text-[12px] text-neutral-600">{{ $isPaid ? 'Ref '.$payout->payout_reference : $payout->rejection_reason }}</p>
                                </td>
                                <td class="{{ $td }} text-neutral-600">{{ $when($payout->paid_at ?? $payout->rejected_at) }}<br>{{ $payout->decidedBy?->name }}</td>
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
