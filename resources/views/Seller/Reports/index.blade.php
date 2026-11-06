@extends('Layouts.seller')
@section('title', 'Reports')
@section('content')
@php
    $peso = fn (int $minor): string => '₱'.number_format($minor / 100, 2);
@endphp
<div class="space-y-6">
    <div>
        <h1 class="font-serif text-3xl text-[var(--text-ink)]">Reports</h1>
        <p class="mt-2 text-sm text-[var(--text-muted)]">Sales, commission, and earnings for completed orders. The start and end dates are both included, in Philippine time.</p>
    </div>

    <form method="GET" action="{{ route('seller.reports.index') }}" class="flex flex-col gap-4 rounded-lg border border-[var(--border-input)] bg-[var(--bg-surface)] p-5 sm:flex-row sm:items-end">
        <label class="flex flex-col gap-2 text-sm text-[var(--text-ink)]" for="report-from">From
            <input id="report-from" name="from" type="date" required value="{{ $from }}" class="rounded-md border border-[var(--border-input)] bg-[var(--bg-surface)] px-3 py-2 text-[var(--text-ink)]">
        </label>
        <label class="flex flex-col gap-2 text-sm text-[var(--text-ink)]" for="report-to">To
            <input id="report-to" name="to" type="date" required value="{{ $to }}" class="rounded-md border border-[var(--border-input)] bg-[var(--bg-surface)] px-3 py-2 text-[var(--text-ink)]">
        </label>
        <button class="rounded-md bg-primary-800 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-900">Apply</button>
    </form>
    @if ($errors->any())
        <div role="alert" class="rounded-md border border-error p-4 text-sm text-error">
            <ul class="list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <section class="grid gap-4 sm:grid-cols-3">
        <article class="rounded-lg border border-[var(--border-input)] bg-[var(--bg-surface)] p-5">
            <h2 class="text-sm text-[var(--text-muted)]">Sales</h2>
            <p class="mt-2 text-3xl font-semibold text-[var(--text-ink)]">{{ $peso($salesMinor) }}</p>
        </article>
        <article class="rounded-lg border border-[var(--border-input)] bg-[var(--bg-surface)] p-5">
            <h2 class="text-sm text-[var(--text-muted)]">Commission</h2>
            <p class="mt-2 text-3xl font-semibold text-[var(--text-ink)]">{{ $peso($commissionMinor) }}</p>
        </article>
        <article class="rounded-lg border border-[var(--border-input)] bg-[var(--bg-surface)] p-5">
            <h2 class="text-sm text-[var(--text-muted)]">Earnings</h2>
            <p class="mt-2 text-3xl font-semibold text-[var(--text-ink)]">{{ $peso($netMinor) }}</p>
        </article>
    </section>

    <p class="text-sm text-[var(--text-muted)]">Net is gross minus store sales, seller vouchers, and 5% commission. Platform vouchers ({{ $peso($platformVoucherMinor) }}) and shipping ({{ $peso($shippingMinor) }}) are not deducted.</p>

    <section class="rounded-lg border border-[var(--border-input)] bg-[var(--bg-surface)] p-5">
        <h2 class="mb-4 font-serif text-xl text-[var(--text-ink)]">Orders in this range</h2>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-[var(--text-ink)]">
                <thead>
                    <tr class="border-b border-[var(--border-input)]">
                        <th scope="col" class="p-3">Finalized</th>
                        <th scope="col" class="p-3">Order</th>
                        <th scope="col" class="p-3">Sales</th>
                        <th scope="col" class="p-3">Commission</th>
                        <th scope="col" class="p-3">Earnings</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr class="border-b border-[var(--border-input)]">
                            <td class="p-3">{{ $row['finalizedAt']->timezone('Asia/Manila')->format('M j, Y g:i A') }}</td>
                            <td class="p-3">{{ $row['reference'] }}</td>
                            <td class="p-3">{{ $peso($row['salesMinor']) }}</td>
                            <td class="p-3">{{ $peso($row['commissionMinor']) }}</td>
                            <td class="p-3">{{ $peso($row['netMinor']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="p-3 text-[var(--text-muted)]">No completed earnings in this range.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
@endsection
