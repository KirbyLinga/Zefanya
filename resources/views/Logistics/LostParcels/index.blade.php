@extends('Layouts.logistics')

@section('title', 'Lost parcels')

@section('logistics-toolbar')
    <span hidden data-logistics-hosted-bell></span>
@endsection

@section('content')
<section class="min-w-0 [&_:is(a,button,summary,input,select,textarea):focus-visible]:outline-2 [&_:is(a,button,summary,input,select,textarea):focus-visible]:outline-offset-4 [&_:is(a,button,summary,input,select,textarea):focus-visible]:outline-[var(--link)]" aria-label="Lost parcels">
<x-logistics.tabs group="money" />

@php
    $peso = fn (int $minor): string => '₱'.number_format($minor / 100, 2);
    $th = 'px-4 py-2.5 font-semibold';
    $label = 'block font-sans text-xs font-semibold text-[var(--text-muted-2)]';
    $field = 'mt-1 min-h-10 w-full min-w-0 rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] px-3 font-sans text-[13px] text-[var(--text-ink)]';
    $tz = \App\Models\Seller\StoreSale::TIMEZONE;
@endphp

<div class="mb-6 flex flex-wrap items-center justify-between gap-4">
    <div>
        <p class="mb-2 font-sans text-[11px] font-semibold uppercase tracking-[0.12em] text-[var(--text-muted-2)]">Money</p>
        <h1 class="font-serif text-[26px] leading-tight font-semibold text-[var(--text-ink)] sm:text-[30px]">Lost parcels</h1>
        <p class="mt-2 max-w-2xl font-sans text-[14px] leading-relaxed text-[var(--text-muted-2)]">Parcels Admin confirmed lost while in your custody. You owe the Seller the saved merchandise value, not the shipping or the commission. You pay it yourself; Admin records that it was paid. Custody is judged by the last verified receiving scan.</p>
    </div>
    <x-logistics.notification-bell />
</div>

<x-logistics-flash :messages="['success', 'warning', 'error']" />
@if ($errors->any())
    <div role="alert" class="mb-5 rounded-md border border-red-200 bg-red-50 p-4 font-sans text-xs leading-relaxed text-red-900">
        <ul class="list-disc pl-4">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    </div>
@endif

<div class="mb-6 grid gap-3 sm:grid-cols-2">
    <div class="rounded-xl border border-[var(--border-strong)] bg-[var(--bg-surface)] p-4 shadow-sm"><p class="font-sans text-[12px] text-[var(--text-muted-2)]">You still owe Sellers</p><p data-lost-owed class="mt-1 font-sans text-2xl font-semibold tabular-nums text-[var(--text-ink)]">{{ $peso($owedMinor) }}</p></div>
    <div class="rounded-xl border border-[var(--border-strong)] bg-[var(--bg-surface)] p-4 shadow-sm"><p class="font-sans text-[12px] text-[var(--text-muted-2)]">Recorded as paid</p><p class="mt-1 font-sans text-2xl font-semibold tabular-nums text-[var(--text-ink)]">{{ $peso($paidMinor) }}</p></div>
</div>

<section aria-labelledby="lost-list-heading" class="overflow-hidden rounded-xl border border-[var(--border-strong)] bg-[var(--bg-surface)] shadow-sm">
    <div class="border-b border-[var(--border-strong)] px-4 py-[18px] sm:px-[22px]">
        <h2 id="lost-list-heading" class="font-sans text-[14px] font-semibold text-[var(--text-ink)]">Confirmed lost</h2>
    </div>
    @if ($cases->isEmpty())
        <p class="px-4 py-6 font-sans text-[13px] text-[var(--text-muted-2)] sm:px-[22px]">No parcel has been confirmed lost in your custody.</p>
    @else
        <div class="overflow-x-auto">
            <table class="w-full min-w-[820px] text-left font-sans text-[13px] text-[var(--text-ink)]">
                <thead class="border-b border-[var(--border-strong)] bg-[var(--bg-raised)] text-xs">
                    <tr>
                        <th scope="col" class="{{ $th }} sm:px-[22px]">Parcel</th>
                        <th scope="col" class="{{ $th }}">Seller</th>
                        <th scope="col" class="{{ $th }} text-right">You owe</th>
                        <th scope="col" class="{{ $th }}">Payment</th>
                        <th scope="col" class="{{ $th }}">Found?</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--border-strong)]">
                    @foreach ($cases as $case)
                        <tr data-lost-case="{{ $case->id }}">
                            <td class="px-4 py-3 sm:px-[22px]"><p class="font-semibold">#{{ $case->seller_order_id }}</p><p class="font-mono text-[11px] text-[var(--text-muted-2)]">{{ $case->sellerOrder?->tracking_number }}</p><p class="text-[11.5px] text-[var(--text-muted-2)]">Confirmed {{ $case->confirmed_at->timezone($tz)->format('M j, Y') }}: {{ $case->reason }}</p></td>
                            <td class="px-4 py-3">{{ $case->seller?->business_name ?? '—' }}</td>
                            <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ $peso($case->netCompensationMinor()) }}@if ($case->adjustments->isNotEmpty())<span class="block text-[11px] font-normal text-[var(--text-muted-2)]">after {{ $case->adjustments->count() }} adjustment(s)</span>@endif</td>
                            <td class="px-4 py-3">
                                @if ($case->isPaid())
                                    <span class="inline-flex rounded-full bg-[var(--pill-success-bg)] px-2.5 py-1 text-[11px] font-semibold text-[var(--status-success)]">Paid</span>
                                    <span class="block text-[11.5px] text-[var(--text-muted-2)]">Ref {{ $case->compensation_reference }} · {{ $case->compensation_paid_on?->format('M j, Y') }}</span>
                                @else
                                    <span class="inline-flex rounded-full bg-[var(--pill-warning-bg)] px-2.5 py-1 text-[11px] font-semibold text-[var(--status-warning)]">Owed to the Seller</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                @if ($case->recovered_at)
                                    <span class="font-semibold">Found</span><span class="block text-[11.5px] text-[var(--text-muted-2)]">{{ $case->recovered_at->timezone($tz)->format('M j, Y g:i A') }} · order stays closed</span>
                                    @if ($case->disposition)<span class="block text-[11.5px] text-[var(--text-muted-2)]">Admin: {{ \App\Models\Logistics\ParcelLossCase::DISPOSITIONS[$case->disposition] ?? $case->disposition }}</span>@endif
                                @elseif ($canRecover($case))
                                    <details>
                                        <summary class="cursor-pointer text-[12px] font-semibold text-[var(--link)]">Record as found</summary>
                                        <div class="mt-3 min-w-[260px]" data-loss-recover="{{ $case->id }}">
                                            <label for="hub-{{ $case->id }}" class="{{ $label }}">Hub where it is now
                                                <select id="hub-{{ $case->id }}" class="{{ $field }}" data-loss-hub>@foreach ($hubs as $hub)<option value="{{ $hub->id }}">{{ $hub->name }}</option>@endforeach</select>
                                            </label>
                                            <x-parcel-scanner
                                                :verify-url="route('logistics.lost-parcels.scan.verify', $case)"
                                                heading="Scan the parcel"
                                                subheading="Scan the waybill QR or enter the tracking number. The record action appears after a valid single-use scan."
                                            />
                                        </div>
                                    </details>
                                @else
                                    <span class="text-[var(--text-muted-2)]">Not found</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
</section>
@endsection

@push('scripts')
    @vite('resources/js/parcel-scanner.js')
    <script>
        // The scan needs the hub the parcel is at: send the chosen hub with the verification request.
        document.querySelectorAll('[data-loss-recover]').forEach((box) => {
            const scanner = box.querySelector('[data-parcel-scanner]');
            const select = box.querySelector('[data-loss-hub]');
            if (!scanner || !select) return;
            const base = scanner.dataset.verifyUrl;
            const sync = () => { scanner.dataset.verifyUrl = base + (base.includes('?') ? '&' : '?') + 'hub=' + encodeURIComponent(select.value); };
            select.addEventListener('change', sync);
            sync();
        });
    </script>
@endpush
