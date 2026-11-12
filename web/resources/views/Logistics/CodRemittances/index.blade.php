@extends('Layouts.logistics')

@section('title', 'COD Remittances')

@section('content')
<div class="flex flex-col gap-6">
    <header class="border-b border-[var(--border-strong)] pb-6">
        <h1 class="font-serif text-[28px] text-[var(--text-ink)] sm:text-[32px]">COD Remittances</h1>
        <p class="mt-2 font-sans text-sm text-[var(--text-muted)]">Compare COD collected and cash remitted per rider. Confirm each parcel receipt after receiving the full amount.</p>
    </header>

    <x-logistics-flash />

    @if ($errors->any())
        <div role="alert" class="rounded-lg border border-red-200 bg-red-50 p-4 font-sans text-sm text-red-900">
            <ul class="list-disc pl-4">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @php
        $collected = (int) $reconciliationTotals->collected_minor;
        $remitted = (int) $reconciliationTotals->remitted_minor;
        $difference = $collected - $remitted;
        $money = fn (int $minor): string => ($minor < 0 ? '-' : '').'₱'.number_format(intdiv(abs($minor), 100)).'.'.str_pad((string) (abs($minor) % 100), 2, '0', STR_PAD_LEFT);
    @endphp
    <section aria-label="COD totals" class="grid gap-4 sm:grid-cols-3">
        @foreach (['Collected' => $collected, 'Remitted to Logistics' => $remitted, 'Difference / cash still due' => $difference] as $label => $amount)
            <div class="rounded-xl border border-[var(--border-strong)] bg-[var(--bg-surface)] p-5">
                <p class="font-sans text-xs font-semibold text-[var(--text-muted)]">{{ $label }}</p>
                <p class="mt-2 font-sans text-2xl font-semibold tabular-nums {{ $loop->last && $amount > 0 ? 'text-[var(--status-danger)]' : 'text-[var(--text-ink)]' }}">{{ $money($amount) }}</p>
                <p class="mt-1 text-xs text-[var(--text-muted)]">All records for your Logistics company</p>
            </div>
        @endforeach
    </section>

    <section aria-labelledby="riderReconciliationTitle" class="space-y-4">
        <div>
            <h2 id="riderReconciliationTitle" class="font-serif text-xl text-[var(--text-ink)]">Reconciliation by rider</h2>
            <p class="mt-1 text-xs leading-relaxed text-[var(--text-muted)]">Difference = collected minus confirmed remitted cash. A positive difference is a shortfall still due from the rider, including pending remittances. Largest shortfalls appear first.</p>
        </div>
        <div class="overflow-x-auto rounded-xl border border-[var(--border-strong)] bg-[var(--bg-surface)]">
            <table class="w-full min-w-[720px] text-left font-sans text-sm">
                <thead class="border-b border-[var(--border-strong)] bg-[var(--bg-hover)] text-xs text-[var(--text-muted)]">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-semibold">Rider</th>
                        <th scope="col" class="px-4 py-3 text-right font-semibold">Collected</th>
                        <th scope="col" class="px-4 py-3 text-right font-semibold">Remitted</th>
                        <th scope="col" class="px-4 py-3 text-right font-semibold">Difference</th>
                        <th scope="col" class="px-4 py-3 font-semibold">Reconciliation</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--border-strong)]">
                    @forelse ($riderReconciliation as $row)
                        @php
                            $balance = (int) $row->difference_minor;
                        @endphp
                        <tr data-cod-rider="{{ $row->courier_id }}" class="{{ $balance > 0 ? 'bg-[var(--pill-danger-bg)]' : '' }}">
                            <td class="px-4 py-4 font-semibold text-[var(--text-ink)]">{{ $row->courier?->rider?->fullName() ?: ($row->courier?->name ?: 'Rider #'.$row->courier_id) }}</td>
                            <td class="whitespace-nowrap px-4 py-4 text-right tabular-nums text-[var(--text-ink)]">{{ $money((int) $row->collected_minor) }}</td>
                            <td class="whitespace-nowrap px-4 py-4 text-right tabular-nums text-[var(--text-ink)]">{{ $money((int) $row->remitted_minor) }}</td>
                            <td class="whitespace-nowrap px-4 py-4 text-right font-semibold tabular-nums {{ $balance > 0 ? 'text-[var(--status-danger)]' : 'text-[var(--text-ink)]' }}">{{ $money($balance) }}</td>
                            <td class="px-4 py-4">
                                <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold {{ $balance > 0 ? 'bg-red-100 text-red-900' : ($balance < 0 ? 'bg-amber-100 text-amber-900' : 'bg-teal-100 text-teal-900') }}">{{ $balance > 0 ? 'Shortfall' : ($balance < 0 ? 'Over remitted' : 'Balanced') }}</span>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-10 text-center text-[var(--text-muted)]">No COD collections to reconcile yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $riderReconciliation->links() }}
    </section>
    <section aria-labelledby="pendingRemittancesTitle" class="flex flex-col gap-4">
        <h2 id="pendingRemittancesTitle" class="font-serif text-xl text-[var(--text-ink)]">Pending remittances ({{ $remittances->total() }})</h2>
        @if ($overdueCount > 0)
            <p role="status" class="rounded-lg border border-red-300 bg-red-50 p-3 text-sm font-semibold text-red-900">{{ $overdueCount }} {{ \Illuminate\Support\Str::plural('remittance', $overdueCount) }} {{ $overdueCount === 1 ? 'has' : 'have' }} been waiting for more than {{ $overdueDays }} days. Ask the rider to hand over the cash.</p>
        @endif

        <div class="overflow-x-auto rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)]">
            <table class="w-full text-left font-sans text-sm text-[var(--text-ink)]">
                <thead class="border-b border-[var(--border-strong)] text-[var(--text-muted)]">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-semibold">Collecting Rider</th>
                        <th scope="col" class="px-4 py-3 font-semibold">Parcel tracking number</th>
                        <th scope="col" class="px-4 py-3 font-semibold">Amount due</th>
                        <th scope="col" class="px-4 py-3 font-semibold">Confirm cash receipt</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($remittances as $remittance)
                        <tr class="border-b border-[var(--border-strong)] last:border-b-0">
                            <td class="px-4 py-4">{{ $remittance->courier?->rider?->fullName() ?: ($remittance->courier?->name ?: 'Rider #'.$remittance->courier_id) }}</td>
                            <td class="px-4 py-4">{{ $remittance->sellerOrder?->tracking_number ?? 'Unavailable' }}
                                @if ($remittance->created_at->lt($overdueCutoff))
                                    <span class="ml-1 inline-flex rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-semibold text-red-900">Overdue &middot; {{ (int) $remittance->created_at->diffInDays(now()) }} days</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-4 py-4 font-semibold tabular-nums">&#8369;{{ intdiv($remittance->amount_minor, 100) }}.{{ str_pad((string) ($remittance->amount_minor % 100), 2, '0', STR_PAD_LEFT) }}</td>
                            <td class="px-4 py-4">
                                @php
                                    $case = $remittance->discrepancy;
                                @endphp
                                @if ($case)
                                    <p class="mb-2 text-sm">Counted ₱{{ number_format($case['counted_minor'] / 100, 2) }}. {{ $case['difference_minor'] < 0 ? 'Short' : 'Excess' }} ₱{{ number_format(abs($case['difference_minor']) / 100, 2) }}.</p>
                                    <p class="mb-2 text-sm">{{ $case['status'] === 'recovery_authorized' ? 'Recovery authorized. Confirm only after the full amount is physically accounted for.' : 'Awaiting Admin review. The COD obligation remains outstanding.' }}</p>
                                @endif
                                @if (! $case || $case['status'] === 'recovery_authorized')
                                <form method="POST" action="{{ route('logistics.cod-remittances.confirm', $remittance) }}" class="flex flex-wrap items-end gap-3">
                                    @csrf
                                    <div>
                                        <label for="received-amount-{{ $remittance->id }}" class="mb-1 block text-xs text-[var(--text-muted)]">Cash received (PHP)</label>
                                        <input id="received-amount-{{ $remittance->id }}" name="received_amount" type="text" inputmode="decimal" required pattern="[0-9]{1,9}(\.[0-9]{1,2})?" maxlength="12" placeholder="0.00" class="h-10 w-36 rounded-sm border border-[var(--border-strong)] bg-[var(--bg-surface)] px-3 text-[var(--text-ink)] focus-visible:outline-2 focus-visible:outline-primary-700">
                                    </div>
                                    @if ($case)
                                        <label class="text-xs">Recovery or excess-return reference <input class="block rounded border p-2" name="reference" required maxlength="255"></label>
                                    @endif
                                    <button type="submit" class="inline-flex min-h-10 items-center justify-center rounded-sm bg-primary-800 px-4 text-xs font-semibold text-white hover:bg-primary-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-700">Confirm cash received</button>
                                </form>
                                @endif
                                @unless ($case)
                                    <details class="mt-3">
                                        <summary class="cursor-pointer text-sm">Record a cash discrepancy</summary>
                                        <form method="POST" action="{{ route('logistics.cod-remittances.confirm', $remittance) }}" class="mt-2 grid gap-2 text-xs">
                                            @csrf
                                            <input type="hidden" name="record_discrepancy" value="1">
                                            <label>Physical cash counted (PHP) <input class="block rounded border p-2" name="received_amount" inputmode="decimal" required maxlength="12"></label>
                                            <label>Staff member who counted <input class="block rounded border p-2" name="staff" required maxlength="120"></label>
                                            <label>Reason <textarea class="block rounded border p-2" name="reason" required maxlength="500"></textarea></label>
                                            <label>Evidence reference <input class="block rounded border p-2" name="reference" required maxlength="255"></label>
                                            <button class="min-h-10 rounded bg-primary-800 px-4 font-semibold text-white">Record for Admin review</button>
                                        </form>
                                    </details>
                                @endunless
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-10 text-center text-[var(--text-muted)]">No pending COD remittances.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $remittances->links() }}
    </section>
</div>
@endsection
