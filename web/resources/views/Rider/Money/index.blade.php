@extends('Layouts.rider')

@section('title', 'My Money')

@section('content')
    @php
        $peso = fn (int $minor): string => '₱'.number_format($minor / 100, 2);
        $rates = (new \App\Services\Logistics\PickupPolicy)->rates();
        $first = $peso($rates['first_minor']);
        $additional = $peso($rates['additional_minor']);
        $delivery = $peso($rates['delivery_minor']);
        $company = $rider->provider?->business_name ?? 'your Logistics company';
        $label = 'font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)]';
        $panel = 'rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] shadow-sm';
        $th = 'px-4 py-2.5 font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)]';
    @endphp

    <div class="mb-6">
        <p class="font-sans text-[11px] font-semibold uppercase tracking-[1.2px] text-[var(--text-muted)]">Money</p>
        <h1 class="font-serif text-2xl text-[var(--text-ink)]">My Money</h1>
        <p class="font-sans text-[12.5px] text-[var(--text-muted)]">Cash you collected for {{ $company }}, and the pay you earned. You can see everything here; {{ $company }} confirms the cash and records your pay.</p>
    </div>

    <x-rider.flash />

    <section class="mb-4 grid gap-4 sm:grid-cols-2" aria-label="Earnings by job">
        <div class="{{ $panel }} p-4">
            <h2 class="{{ $label }}">Pickup earnings</h2>
            <p class="mt-1 font-sans text-[22px] font-semibold leading-none tabular-nums text-emerald-800">+{{ $peso($pay['pickupMinor']) }}</p>
            <p class="mt-1 font-sans text-[12px] text-[var(--text-muted)]">{{ $first }} for the first parcel of a seller pickup + {{ $additional }} for each additional parcel</p>
        </div>
        <div class="{{ $panel }} p-4">
            <h2 class="{{ $label }}">Delivery earnings</h2>
            <p class="mt-1 font-sans text-[22px] font-semibold leading-none tabular-nums text-emerald-800">+{{ $peso($pay['deliveryMinor']) }}</p>
            <p class="mt-1 font-sans text-[12px] text-[var(--text-muted)]">{{ $delivery }} per successful delivery</p>
        </div>
    </section>

    <section class="mb-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4" aria-label="Money totals">
        <div class="{{ $panel }} p-4 {{ $cash['holdingMinor'] > 0 ? 'border-amber-300 bg-amber-50' : '' }}">
            <h2 class="{{ $label }}">Cash you are holding</h2>
            <p class="mt-1 font-sans text-[26px] font-semibold leading-none tabular-nums text-[var(--text-ink)]">{{ $peso($cash['holdingMinor']) }}</p>
            <p class="mt-1 font-sans text-[12px] text-[var(--text-muted)]">{{ $cash['holdingCount'] }} {{ \Illuminate\Support\Str::plural('parcel', $cash['holdingCount']) }} to hand over</p>
        </div>
        <div class="{{ $panel }} p-4">
            <h2 class="{{ $label }}">Handed over</h2>
            <p class="mt-1 font-sans text-[26px] font-semibold leading-none tabular-nums text-[var(--text-ink)]">{{ $peso($cash['handedOverMinor']) }}</p>
            <p class="mt-1 font-sans text-[12px] text-[var(--text-muted)]">{{ $peso($cash['handedOverMonthMinor']) }} this month</p>
        </div>
        <div class="{{ $panel }} p-4">
            <h2 class="{{ $label }}">Pending pay</h2>
            <p class="mt-1 font-sans text-[26px] font-semibold leading-none tabular-nums text-[var(--text-ink)]">{{ $peso($pay['pendingMinor']) }}</p>
            <p class="mt-1 font-sans text-[12px] text-[var(--text-muted)]">Earned, not yet paid</p>
        </div>
        <div class="{{ $panel }} p-4">
            <h2 class="{{ $label }}">Paid to you</h2>
            <p class="mt-1 font-sans text-[26px] font-semibold leading-none tabular-nums text-[var(--text-ink)]">{{ $peso($pay['paidMinor']) }}</p>
            <p class="mt-1 font-sans text-[12px] text-[var(--text-muted)]">{{ $peso($pay['monthMinor']) }} earned this month</p>
        </div>
    </section>

    <section class="mb-8" aria-labelledby="holding-heading">
        <h2 id="holding-heading" class="mb-2 font-serif text-xl text-[var(--text-ink)]">Cash to hand over ({{ $cash['holdingCount'] }})</h2>
        @if ($cash['overdueCount'] > 0)
            <p role="status" class="mb-3 rounded-lg border border-red-300 bg-red-50 p-3 font-sans text-[13px] font-semibold text-red-900">{{ $cash['overdueCount'] }} {{ \Illuminate\Support\Str::plural('parcel', $cash['overdueCount']) }} {{ $cash['overdueCount'] === 1 ? 'has' : 'have' }} been with you for more than {{ $overdueDays }} days. Hand the cash to {{ $company }} now.</p>
        @endif
        <p class="mb-3 font-sans text-[12.5px] text-[var(--text-muted)]">Bring this cash to {{ $company }}. A parcel leaves this list only when they confirm the exact amount they received. Keep the cash safe and separate.</p>

        @if ($holding->isEmpty())
            <p class="{{ $panel }} p-6 text-center font-sans text-[13px] text-[var(--text-muted)]">You are not holding any delivery cash.</p>
        @else
            <div class="{{ $panel }} overflow-x-auto">
                <table class="w-full min-w-[560px] text-left font-sans">
                    <thead class="border-b border-[var(--border-strong)] bg-[var(--bg-page)]">
                        <tr><th class="{{ $th }}">Parcel</th><th class="{{ $th }}">Collected</th><th class="{{ $th }} text-right">Amount to hand over</th></tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--border-strong)]">
                        @foreach ($holding as $item)
                            <tr>
                                <td class="px-4 py-2.5">
                                    <p class="text-[12.5px] font-semibold text-[var(--text-ink)]">#{{ $item->seller_order_id }}</p>
                                    <p class="font-mono text-[10.5px] text-[var(--text-muted)]">{{ $item->sellerOrder?->tracking_number ?? '—' }}</p>
                                    @if ($case = $item->discrepancy)
                                        <p class="mt-2 text-xs">Cash counted {{ $peso($case['counted_minor']) }}. COD due {{ $peso($item->amount_minor) }}.</p>
                                        @if (isset($case['rider_explanation']))
                                            <p class="mt-2 text-xs">Your explanation was recorded.</p>
                                        @elseif (in_array($case['status'], ['awaiting_review', 'recovery_authorized'], true))
                                            <form method="POST" action="{{ route('rider.money.cod.explain', $item) }}" class="mt-2 space-y-2 text-xs">
                                                @csrf
                                                <label>Explain or dispute this cash count <textarea class="block rounded border p-2" name="explanation" required maxlength="500"></textarea></label>
                                                <button class="min-h-10 rounded bg-primary-800 px-3 text-white">Send explanation</button>
                                            </form>
                                        @endif
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 text-[12px] text-[var(--text-muted)]">
                                    {{ $item->created_at->timezone('Asia/Manila')->format('M j, Y g:i A') }}
                                    @if ($item->created_at->lt($cutoff))
                                        <span class="ml-1 inline-flex rounded-full bg-red-100 px-2 py-0.5 text-[11px] font-semibold text-red-900">Overdue &middot; {{ (int) $item->created_at->diffInDays(now()) }} days</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 text-right text-[13px] font-semibold tabular-nums text-[var(--text-ink)]">{{ $peso($item->amount_minor) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="mb-8" aria-labelledby="handed-heading">
        <h2 id="handed-heading" class="mb-2 font-serif text-xl text-[var(--text-ink)]">Cash you handed over</h2>
        @if ($handedOver->isEmpty())
            <p class="{{ $panel }} p-6 text-center font-sans text-[13px] text-[var(--text-muted)]">Cash {{ $company }} has confirmed will appear here.</p>
        @else
            <div class="{{ $panel }} overflow-x-auto">
                <table class="w-full min-w-[560px] text-left font-sans">
                    <thead class="border-b border-[var(--border-strong)] bg-[var(--bg-page)]">
                        <tr><th class="{{ $th }}">Parcel</th><th class="{{ $th }}">Confirmed by {{ $company }}</th><th class="{{ $th }} text-right">Amount</th></tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--border-strong)]">
                        @foreach ($handedOver as $item)
                            <tr>
                                <td class="px-4 py-2.5">
                                    <p class="text-[12.5px] font-semibold text-[var(--text-ink)]">#{{ $item->seller_order_id }}</p>
                                    <p class="font-mono text-[10.5px] text-[var(--text-muted)]">{{ $item->sellerOrder?->tracking_number ?? '—' }}</p>
                                </td>
                                <td class="px-4 py-2.5 text-[12px] text-[var(--text-muted)]">{{ $item->confirmed_at?->timezone('Asia/Manila')->format('M j, Y g:i A') ?? '—' }}</td>
                                <td class="px-4 py-2.5 text-right text-[13px] font-semibold tabular-nums text-[var(--text-ink)]">{{ $peso((int) $item->received_amount_minor) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-3">{{ $handedOver->links() }}</div>
        @endif
    </section>

    <section class="mb-8" aria-labelledby="pay-heading">
        <h2 id="pay-heading" class="mb-2 font-serif text-xl text-[var(--text-ink)]">Your pay</h2>
        <p class="mb-3 font-sans text-[12.5px] text-[var(--text-muted)]">{{ $first }} for the first parcel of each seller pickup, {{ $additional }} for each additional parcel in the same pickup, and {{ $delivery }} for each successful delivery. Failed attempts earn nothing. {{ ucfirst($company) }} pays you and records the payment with a reference.</p>

        @if ($earnings->isEmpty())
            <p class="{{ $panel }} p-6 text-center font-sans text-[13px] text-[var(--text-muted)]">Complete a pickup or delivery to start earning.</p>
        @else
            <div class="{{ $panel }} overflow-x-auto">
                <table class="w-full min-w-[560px] text-left font-sans">
                    <thead class="border-b border-[var(--border-strong)] bg-[var(--bg-page)]">
                        <tr><th class="{{ $th }}">Job</th><th class="{{ $th }}">Earned</th><th class="{{ $th }}">Status</th><th class="{{ $th }} text-right">Amount</th></tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--border-strong)]">
                        @foreach ($earnings as $earning)
                            <tr>
                                <td class="px-4 py-2.5 text-[12.5px] text-[var(--text-ink)]">{{ $earning->job === \App\Models\Rider\RiderEarning::PICKUP ? 'Pickup' : 'Delivery' }} · <span class="font-mono text-[11px] text-[var(--text-muted)]">{{ $earning->sellerOrder?->tracking_number ?? '#'.$earning->seller_order_id }}</span></td>
                                <td class="px-4 py-2.5 text-[12px] text-[var(--text-muted)]">{{ $earning->earned_at?->timezone('Asia/Manila')->format('M j, Y') }}</td>
                                <td class="px-4 py-2.5">
                                    @if ($earning->isPaid())
                                        <span class="inline-flex rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-800">Paid {{ $earning->payout?->paid_on?->format('M j') }}</span>
                                        <span class="block text-[11px] text-[var(--text-muted)]">Ref {{ $earning->payout?->reference }}</span>
                                    @else
                                        <span class="inline-flex rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-800">Pending</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 text-right text-[13px] font-semibold tabular-nums text-[var(--text-ink)]">{{ $peso($earning->amount_minor) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-3">{{ $earnings->links() }}</div>
        @endif

        @if ($payouts->isNotEmpty())
            <h3 class="mb-2 mt-5 font-sans text-[13px] font-semibold text-[var(--text-ink)]">Payments recorded for you</h3>
            <ul class="{{ $panel }} divide-y divide-[var(--border-strong)]">
                @foreach ($payouts as $payout)
                    <li class="flex flex-wrap items-center justify-between gap-2 px-4 py-2.5 font-sans text-[12.5px] text-[var(--text-ink)]">
                        <span>{{ $payout->paid_on->format('M j, Y') }} · {{ $payout->earning_count }} {{ \Illuminate\Support\Str::plural('job', $payout->earning_count) }} · Ref {{ $payout->reference }}</span>
                        <span class="font-semibold tabular-nums">
                            {{ $peso($payout->amount_minor) }}
                            @if ($payout->reversal)
                                <span class="ml-1 inline-flex rounded-full bg-neutral-200 px-2 py-0.5 text-[11px] font-semibold text-neutral-800">Reversed for correction</span>
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
@endsection
