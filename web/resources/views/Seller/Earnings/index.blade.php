@extends('Layouts.seller')
@section('title', 'Earnings')
@section('content')
@php
    $peso = fn (int $minor): string => '₱'.number_format($minor / 100, 2);
@endphp
<div class="space-y-6">
    <div>
        <h1 class="font-serif text-3xl text-[var(--text-ink)]">Earnings</h1>
        <p class="mt-2 text-sm text-[var(--text-muted)]">Net is gross merchandise, minus your store sales and shop vouchers, minus 5% commission.</p>
    </div>

    <section class="grid gap-4 sm:grid-cols-2">
        <article class="rounded-lg border border-[var(--border-input)] bg-[var(--bg-surface)] p-5">
            <h2 class="text-sm text-[var(--text-muted)]">Pending</h2>
            <p class="mt-2 text-3xl font-semibold text-[var(--text-ink)]">{{ $peso($pendingMinor) }}</p>
            <p class="mt-2 text-sm text-[var(--text-muted)]">Finalized earnings waiting until the cash remittance is confirmed.</p>
        </article>
        <article class="rounded-lg border border-[var(--border-input)] bg-[var(--bg-surface)] p-5">
            <h2 class="text-sm text-[var(--text-muted)]">Available</h2>
            <p class="mt-2 text-3xl font-semibold text-[var(--text-ink)]">{{ $peso($availableMinor) }}</p>
            <p class="mt-2 text-sm text-[var(--text-muted)]">Ready to withdraw from Payout Requests.</p>
            @if ($reservedMinor > 0)
                <p class="mt-2 text-sm text-[var(--text-ink)]">{{ $peso($reservedMinor) }} is reserved by a payout request.</p>
            @endif
        </article>
    </section>

    <section class="rounded-lg border border-[var(--border-input)] bg-[var(--bg-surface)] p-5">
        <h2 class="font-serif text-xl text-[var(--text-ink)]">How net is calculated</h2>
        <dl class="mt-4 space-y-2 text-sm text-[var(--text-ink)]">
            <div class="flex justify-between gap-4"><dt>Gross</dt><dd>{{ $peso($grossMinor) }}</dd></div>
            <div class="flex justify-between gap-4"><dt>Seller sales</dt><dd>− {{ $peso($salesMinor) }}</dd></div>
            <div class="flex justify-between gap-4"><dt>Seller vouchers</dt><dd>− {{ $peso($sellerVoucherMinor) }}</dd></div>
            <div class="flex justify-between gap-4"><dt>Commission (5%)</dt><dd>− {{ $peso($commissionMinor) }}</dd></div>
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
</div>
@endsection
