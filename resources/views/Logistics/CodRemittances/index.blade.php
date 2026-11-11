@extends('Layouts.logistics')

@section('title', 'COD Remittances')

@section('logistics-toolbar')<span hidden data-logistics-hosted-bell></span>@endsection

@section('content')
<x-logistics.tabs group="money" />
<div data-money-workspace class="flex flex-col gap-4 font-sans">
    <header class="flex items-start justify-between gap-4">
        <div class="min-w-0">
            <h1 class="font-serif text-2xl text-[var(--text-ink)]">COD Remittances</h1>
            <p class="mt-1 text-[13px] text-[var(--text-ink)]/80">Confirm cash received from riders and track the balance still due.</p>
        </div>
        <x-logistics.notification-bell />
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
    <section aria-label="COD totals" class="grid gap-3 sm:grid-cols-3">
        @foreach (['Collected' => $collected, 'Remitted to Logistics' => $remitted, 'Difference / cash still due' => $difference] as $label => $amount)
            <div class="rounded-xl border border-[var(--border-strong)] bg-[var(--bg-surface)] p-4 shadow-sm">
                <p class="font-sans text-xs font-semibold text-[var(--text-ink)]/80">{{ $label }}</p>
                <p class="mt-2 font-sans text-2xl font-semibold tabular-nums {{ $loop->last && $amount > 0 ? 'text-[var(--status-danger)]' : 'text-[var(--text-ink)]' }}">{{ $money($amount) }}</p>

            </div>
        @endforeach
    </section>
    <p class="-mt-1 text-xs text-[var(--text-ink)]/80">Totals include all COD records for your Logistics company. Difference is collected cash minus confirmed receipts.</p>

    <section aria-labelledby="pendingRemittancesTitle" class="flex flex-col gap-4">
        <div>
            <h2 id="pendingRemittancesTitle" class="text-sm font-semibold text-[var(--text-ink)]">Pending remittances ({{ $remittances->total() }})</h2>
            <p class="mt-1 text-[13px] text-[var(--text-ink)]/80">Enter the cash you physically received. If it is short, name the responsible Rider and the reason: the missing amount stays outstanding until recovered.</p>
        </div>
        @if ($overdueCount > 0)
            <p role="status" class="rounded-lg border border-red-300 bg-red-50 p-3 text-sm font-semibold text-red-900">{{ $overdueCount }} {{ \Illuminate\Support\Str::plural('remittance', $overdueCount) }} {{ $overdueCount === 1 ? 'has' : 'have' }} been waiting for more than {{ $overdueDays }} days. Ask the rider to hand over the cash.</p>
        @endif

        @if ($remittances->isEmpty())
            <p class="rounded-xl border border-[var(--border-strong)] bg-[var(--bg-surface)] px-4 py-5 text-[13px] text-[var(--text-ink)]/80">No pending COD remittances.</p>
        @else
        <div role="region" aria-label="Pending COD receipts" tabindex="0" class="relative overflow-x-auto rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)]">
            <table class="w-full min-w-[900px] text-left font-sans text-[13px] text-[var(--text-ink)]">
                <thead class="border-b border-[var(--border-strong)] bg-[var(--bg-raised)] text-xs text-[var(--text-ink)]">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-semibold">Collecting Rider</th>
                        <th scope="col" class="px-4 py-3 font-semibold">Parcel tracking number</th>
                        <th scope="col" class="px-4 py-3 text-right font-semibold">Amount due</th>
                        <th scope="col" class="px-4 py-3 text-right font-semibold">Confirm cash receipt</th>
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
                            <td class="whitespace-nowrap px-4 py-4 text-right font-semibold tabular-nums">&#8369;{{ intdiv($remittance->amount_minor, 100) }}.{{ str_pad((string) ($remittance->amount_minor % 100), 2, '0', STR_PAD_LEFT) }}</td>
                            <td class="px-4 py-4">
                                <form method="POST" action="{{ route('logistics.cod-remittances.confirm', $remittance) }}" class="flex flex-wrap items-end justify-end gap-2">
                                    @csrf
                                    <div>
                                        <label for="received-amount-{{ $remittance->id }}" class="mb-1 block text-xs font-semibold text-[var(--text-ink)]">Cash received (PHP)</label>
                                        <input id="received-amount-{{ $remittance->id }}" name="received_amount" type="text" inputmode="decimal" required pattern="[0-9]{1,9}(\.[0-9]{1,2})?" maxlength="12" placeholder="0.00" class="h-10 w-36 rounded-[7px] border border-[var(--border-input)] bg-[var(--bg-surface)] px-3 text-[var(--text-ink)] focus-visible:outline-2 focus-visible:outline-primary-700">
                                    </div>
                                    <div>
                                        <label for="shortage-rider-{{ $remittance->id }}" class="mb-1 block text-xs font-semibold text-[var(--text-ink)]">If short: responsible Rider</label>
                                        <select id="shortage-rider-{{ $remittance->id }}" name="shortage_rider_id" class="h-10 w-44 rounded-[7px] border border-[var(--border-input)] bg-[var(--bg-surface)] px-3 text-[var(--text-ink)]">
                                            <option value="">Not short</option>
                                            @foreach ($riders as $rider)
                                                <option value="{{ $rider->id }}">{{ $rider->fullName() }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <label for="shortage-reason-{{ $remittance->id }}" class="mb-1 block text-xs font-semibold text-[var(--text-ink)]">If short: reason</label>
                                        <input id="shortage-reason-{{ $remittance->id }}" name="shortage_reason" type="text" maxlength="1000" placeholder="e.g. cash not handed over" class="h-10 w-56 rounded-[7px] border border-[var(--border-input)] bg-[var(--bg-surface)] px-3 text-[var(--text-ink)]">
                                    </div>
                                    <button type="submit" class="inline-flex min-h-10 items-center justify-center rounded-[7px] bg-primary-800 px-4 text-xs font-semibold text-white hover:bg-primary-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-700">Confirm cash received</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-10 text-center text-[var(--text-ink)]/80">No pending COD remittances.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @endif
        {{ $remittances->links() }}
    </section>
    <section aria-labelledby="shortageRecoveryTitle" class="space-y-4">
        <div>
            <h2 id="shortageRecoveryTitle" class="text-sm font-semibold text-[var(--text-ink)]">Outstanding shortages ({{ $shortages->count() }})</h2>
            <p class="mt-1 text-[13px] text-[var(--text-ink)]/80">Cash the Rider collected but the company has not received. Record each manual recovery with its reference; the missing money is never counted as received until it arrives.</p>
        </div>
        @if ($shortages->isEmpty())
            <p class="rounded-xl border border-[var(--border-strong)] bg-[var(--bg-surface)] px-4 py-5 text-[13px] text-[var(--text-ink)]/80">No outstanding shortages.</p>
        @else
            <div class="space-y-3">
                @foreach ($shortages as $shortage)
                    @php($outstanding = $shortage->outstandingShortageMinor())
                    <article class="rounded-xl border border-red-300 bg-[var(--bg-surface)] p-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p class="font-sans text-sm font-semibold text-[var(--text-ink)]">{{ $shortage->sellerOrder?->tracking_number ?? 'Parcel #'.$shortage->seller_order_id }}</p>
                                <p class="mt-1 text-xs text-[var(--text-ink)]/80">Collected by {{ $shortage->courier?->rider?->fullName() ?: ($shortage->courier?->name ?: 'Rider #'.$shortage->courier_id) }} · Responsible: {{ $shortage->shortageRider?->fullName() ?? 'not recorded' }}</p>
                                <p class="mt-1 text-xs text-[var(--text-ink)]/80">{{ $shortage->shortage_reason }}</p>
                            </div>
                            <div class="text-right">
                                <p class="text-xs font-semibold text-[var(--text-ink)]/80">Still missing</p>
                                <p class="text-lg font-semibold tabular-nums text-[var(--status-danger)]">{{ $money($outstanding) }}</p>
                                <p class="text-xs text-[var(--text-ink)]/80">Received {{ $money((int) $shortage->received_amount_minor) }} of {{ $money((int) $shortage->amount_minor) }}</p>
                            </div>
                        </div>
                        <form method="POST" action="{{ route('logistics.cod-remittances.shortage-recovery', $shortage) }}" class="mt-3 flex flex-wrap items-end gap-2">
                            @csrf
                            <div>
                                <label for="recovered-{{ $shortage->id }}" class="mb-1 block text-xs font-semibold text-[var(--text-ink)]">Cash recovered (PHP)</label>
                                <input id="recovered-{{ $shortage->id }}" name="recovered_amount" type="text" inputmode="decimal" required pattern="[0-9]{1,9}(\.[0-9]{1,2})?" maxlength="12" placeholder="0.00" class="h-10 w-36 rounded-[7px] border border-[var(--border-input)] bg-[var(--bg-surface)] px-3 text-[var(--text-ink)]">
                            </div>
                            <div>
                                <label for="recovery-ref-{{ $shortage->id }}" class="mb-1 block text-xs font-semibold text-[var(--text-ink)]">Reference</label>
                                <input id="recovery-ref-{{ $shortage->id }}" name="reference" type="text" required maxlength="255" placeholder="Cash receipt / payroll deduction" class="h-10 w-56 rounded-[7px] border border-[var(--border-input)] bg-[var(--bg-surface)] px-3 text-[var(--text-ink)]">
                            </div>
                            <div>
                                <label for="recovery-note-{{ $shortage->id }}" class="mb-1 block text-xs font-semibold text-[var(--text-ink)]">Note</label>
                                <input id="recovery-note-{{ $shortage->id }}" name="note" type="text" maxlength="1000" class="h-10 w-56 rounded-[7px] border border-[var(--border-input)] bg-[var(--bg-surface)] px-3 text-[var(--text-ink)]">
                            </div>
                            <button type="submit" class="inline-flex min-h-10 items-center justify-center rounded-[7px] bg-primary-800 px-4 text-xs font-semibold text-white hover:bg-primary-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-700">Record recovery</button>
                        </form>
                    </article>
                @endforeach
            </div>
        @endif
    </section>
    <section aria-labelledby="riderReconciliationTitle" class="space-y-4">
        <div>
            <h2 id="riderReconciliationTitle" class="text-sm font-semibold text-[var(--text-ink)]">Reconciliation by rider</h2>
            <p class="mt-1 text-xs leading-relaxed text-[var(--text-ink)]/80">Difference = collected minus confirmed remitted cash. A positive difference is a shortfall still due from the rider, including pending remittances. Largest shortfalls appear first.</p>
        </div>
        @if ($riderReconciliation->isEmpty())
            <p class="rounded-xl border border-[var(--border-strong)] bg-[var(--bg-surface)] px-4 py-5 text-[13px] text-[var(--text-ink)]/80">No COD collections to reconcile yet.</p>
        @else
        <div role="region" aria-label="COD reconciliation by rider" tabindex="0" class="relative overflow-x-auto rounded-xl border border-[var(--border-strong)] bg-[var(--bg-surface)]">
            <table class="w-full min-w-[720px] text-left font-sans text-[13px]">
                <thead class="border-b border-[var(--border-strong)] bg-[var(--bg-raised)] text-xs text-[var(--text-ink)]">
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
                        @php($balance = (int) $row->difference_minor)
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
                        <tr><td colspan="5" class="px-4 py-10 text-center text-[var(--text-ink)]/80">No COD collections to reconcile yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @endif
        {{ $riderReconciliation->links() }}
    </section>

</div>
@endsection
