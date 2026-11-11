@extends('Layouts.seller')
@section('title', 'Earnings')
@section('content')
@php
    $peso = fn (int $minor): string => '₱'.number_format($minor / 100, 2);
@endphp
<div class="space-y-6">
    <div>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="font-serif text-3xl text-[var(--text-ink)]">Earnings</h1>
            <a href="{{ route('seller.payout-requests.index') }}" class="inline-flex min-h-10 items-center gap-2 rounded-md border border-[var(--border-strong)] bg-[var(--bg-surface)] px-4 text-xs font-semibold text-[var(--link)] hover:bg-[var(--bg-page)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-700">Withdraw available funds <i data-lucide="arrow-right" aria-hidden="true" class="size-3.5"></i></a>
        </div>
        <p class="mt-2 text-sm leading-relaxed text-[var(--text-muted)]">Review order-level earnings, commission and when funds become available here. Use Payout Requests to withdraw available funds and track approval and payment.</p>
        <p class="mt-2 text-sm text-[var(--text-muted)]">Net is gross merchandise, minus your store sales and shop vouchers, minus Zefanya's {{ \App\Services\Shared\CommissionFinalizationService::percentLabel() }} commission. Each order keeps the rate in effect when it was completed.</p>
    </div>

    <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <article class="rounded-lg border border-[var(--border-input)] bg-[var(--bg-surface)] p-5">
            <h2 class="text-sm text-[var(--text-muted)]">Pending</h2>
            <p class="mt-2 text-3xl font-semibold text-[var(--text-ink)]">{{ $peso($pendingMinor) }}</p>
            <p class="mt-2 text-sm text-[var(--text-muted)]">Finalized earnings waiting until the cash remittance is confirmed.</p>
        </article>
        <article class="rounded-lg border border-[var(--border-input)] bg-[var(--bg-surface)] p-5">
            <h2 class="text-sm text-[var(--text-muted)]">Available</h2>
            <p class="mt-2 text-3xl font-semibold text-[var(--text-ink)]">{{ $peso($availableMinor) }}</p>
            <p class="mt-2 text-sm text-[var(--text-muted)]">Ready to withdraw from Payout Requests.@if ($debtMinor > 0) {{ $peso(min($debtMinor, $availableMinor)) }} of it will be kept against your debt.@endif</p>
            @if ($reservedMinor > 0)
                <p class="mt-2 text-sm text-[var(--text-ink)]">{{ $peso($reservedMinor) }} is reserved by a payout request.</p>
            @endif
        </article>
        <article class="rounded-lg border {{ $debtMinor > 0 ? 'border-red-300 bg-red-50' : 'border-[var(--border-input)] bg-[var(--bg-surface)]' }} p-5">
            <h2 class="text-sm text-[var(--text-muted)]">Debt to Zefanya</h2>
            <p class="mt-2 text-3xl font-semibold text-[var(--text-ink)]">{{ $debtMinor > 0 ? '−' : '' }}{{ $peso($debtMinor) }}</p>
            <p class="mt-2 text-sm text-[var(--text-muted)]">From returns and failed deliveries: money you were already paid, refunded and return shipping your earnings did not cover, and trips for failed deliveries sent back to you. It is taken from your next payouts.</p>
        </article>
        <article class="rounded-lg border border-[var(--border-input)] bg-[var(--bg-surface)] p-5">
            <h2 class="text-sm text-[var(--text-muted)]">Paid withdrawals</h2>
            <p class="mt-2 text-3xl font-semibold text-[var(--text-ink)]">{{ $peso($paidWithdrawalsMinor) }}</p>
            <p class="mt-2 text-sm text-[var(--text-muted)]">Requested amounts Zefanya has paid out.</p>
        </article>
    </section>

    <section class="rounded-lg border border-[var(--border-input)] bg-[var(--bg-surface)] p-5">
        <h2 class="font-serif text-xl text-[var(--text-ink)]">How net is calculated</h2>
        <dl class="mt-4 space-y-2 text-sm text-[var(--text-ink)]">
            <div class="flex justify-between gap-4"><dt>Gross</dt><dd>{{ $peso($grossMinor) }}</dd></div>
            <div class="flex justify-between gap-4"><dt>Seller sales</dt><dd>− {{ $peso($salesMinor) }}</dd></div>
            <div class="flex justify-between gap-4"><dt>Seller vouchers</dt><dd>− {{ $peso($sellerVoucherMinor) }}</dd></div>
            <div class="flex justify-between gap-4"><dt>Commission</dt><dd>− {{ $peso($commissionMinor) }}</dd></div>
            <div class="flex justify-between gap-4 border-t border-[var(--border-input)] pt-2 font-semibold"><dt>Net</dt><dd>{{ $peso($netMinor) }}</dd></div>
        </dl>
        <p class="mt-4 text-sm text-[var(--text-muted)]">Platform vouchers ({{ $peso($platformVoucherMinor) }}) are funded by the marketplace and are not deducted.</p>
        <p class="mt-1 text-sm text-[var(--text-muted)]">Shipping ({{ $peso($shippingMinor) }}) is not deducted.</p>
    </section>

    <section class="rounded-lg border border-[var(--border-input)] bg-[var(--bg-surface)] p-5">
        <h2 class="mb-4 font-serif text-xl text-[var(--text-ink)]">Orders</h2>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-[var(--text-ink)]">
                <thead>
                    <tr class="border-b border-[var(--border-input)]">
                        <th scope="col" class="p-3">Order</th>
                        <th scope="col" class="p-3">Gross</th>
                        <th scope="col" class="p-3">Sales</th>
                        <th scope="col" class="p-3">Seller voucher</th>
                        <th scope="col" class="p-3">Commission</th>
                        <th scope="col" class="p-3">Net</th>
                        <th scope="col" class="p-3">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr class="border-b border-[var(--border-input)]">
                            <td class="p-3">{{ $row['reference'] }}</td>
                            <td class="p-3">{{ $peso($row['grossMinor']) }}</td>
                            <td class="p-3">{{ $peso($row['salesMinor']) }}</td>
                            <td class="p-3">{{ $peso($row['sellerVoucherMinor']) }}</td>
                            <td class="p-3">{{ $peso($row['commissionMinor']) }}</td>
                            <td class="p-3">{{ $peso($row['netMinor']) }}</td>
                            <td class="p-3">{{ $row['bucket'] === 'available' ? 'Available' : 'Pending' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="p-3 text-[var(--text-muted)]">No pending or available earnings yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    @if ($lostCases->isNotEmpty())
        <section id="lost-parcels" class="rounded-lg border border-[var(--border-input)] bg-[var(--bg-surface)] p-5" aria-labelledby="lostParcelsTitle" data-lost-parcels>
            <h2 id="lostParcelsTitle" class="font-serif text-xl text-[var(--text-ink)]">Lost parcels</h2>
            <p class="mt-1 mb-4 text-sm text-[var(--text-muted)]">When Admin confirms a parcel lost, the Logistics company responsible owes you its merchandise value. Shipping and commission are not part of it, and the order earns nothing else. The company pays you directly; Zefanya only records that it was paid. Compensation is not part of your Zefanya balance above.</p>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-[var(--text-ink)]">
                    <thead><tr class="border-b border-[var(--border-input)]"><th scope="col" class="p-3">Parcel</th><th scope="col" class="p-3">Owed by</th><th scope="col" class="p-3 text-right">Merchandise value</th><th scope="col" class="p-3">Status</th></tr></thead>
                    <tbody>
                        @foreach ($lostCases as $case)
                            <tr class="border-b border-[var(--border-input)]" data-lost-case="{{ $case->id }}">
                                <td class="p-3"><span class="font-semibold">#{{ $case->seller_order_id }}</span><span class="block font-mono text-xs text-[var(--text-muted)]">{{ $case->sellerOrder?->tracking_number }}</span></td>
                                <td class="p-3">{{ $case->provider?->business_name ?? 'Being decided by Admin' }}</td>
                                <td class="p-3 text-right tabular-nums">{{ $peso($case->netCompensationMinor()) }}</td>
                                <td class="p-3 font-semibold {{ $case->isPaid() ? 'text-emerald-700' : 'text-amber-700' }}">{{ $case->isPaid() ? 'Paid · ref '.$case->compensation_reference : ($case->provider ? 'Owed to you' : 'Waiting for Admin') }}@if ($case->recovered_at)<span class="block text-xs font-normal text-[var(--text-muted)]">Parcel found; the order stays closed.</span>@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    <section id="transactions" class="rounded-lg border border-[var(--border-input)] bg-[var(--bg-surface)] p-5" aria-labelledby="transactionsTitle">
        <h2 id="transactionsTitle" class="font-serif text-xl text-[var(--text-ink)]">Transactions</h2>
        <p class="mt-1 mb-4 text-sm text-[var(--text-muted)]">Every movement of your money with Zefanya. <strong class="font-semibold text-[var(--text-ink)]">+</strong> an order's net once it is available; <strong class="font-semibold text-[var(--text-ink)]">−</strong> a paid withdrawal. The balance is what Zefanya holds for you, including any amount in a pending request. Pending earnings, open requests and debts are listed but not counted.</p>
        @php
            $txTone = ['emerald' => 'text-emerald-700', 'amber' => 'text-amber-700', 'sky' => 'text-sky-700', 'rose' => 'text-rose-700', 'neutral' => 'text-[var(--text-muted)]'];
        @endphp
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-[var(--text-ink)]">
                <thead>
                    <tr class="border-b border-[var(--border-input)]">
                        <th scope="col" class="p-3">Date</th>
                        <th scope="col" class="p-3">Transaction</th>
                        <th scope="col" class="p-3">Status</th>
                        <th scope="col" class="p-3 text-right">Amount</th>
                        <th scope="col" class="p-3 text-right">Balance</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($transactions as $entry)
                        <tr class="border-b border-[var(--border-input)]" data-transaction>
                            <td class="whitespace-nowrap p-3 text-[var(--text-muted)]">{{ $entry['at']->format('M j, Y') }}</td>
                            <td class="p-3">
                                <span class="font-semibold">{{ $entry['type'] }}</span>
                                <span class="block font-mono text-xs text-[var(--text-muted)]">{{ $entry['reference'] }}</span>
                                @if ($entry['detail'])<span class="block text-xs text-[var(--text-muted)]">{{ $entry['detail'] }}</span>@endif
                            </td>
                            <td class="p-3 font-semibold {{ $txTone[$entry['tone']] ?? '' }}">{{ $entry['status'] }}</td>
                            <td class="whitespace-nowrap p-3 text-right tabular-nums @if (! $entry['counts']) text-[var(--text-muted)] line-through @elseif ($entry['amountMinor'] >= 0) text-emerald-700 @endif">{{ $entry['amountMinor'] >= 0 ? '+' : '−' }}{{ $peso(abs($entry['amountMinor'])) }}</td>
                            <td class="whitespace-nowrap p-3 text-right tabular-nums">{{ $peso($entry['balanceAfterMinor']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="p-3 text-[var(--text-muted)]">No money movements yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $transactions->fragment('transactions')->links() }}</div>
    </section>
</div>
@endsection
