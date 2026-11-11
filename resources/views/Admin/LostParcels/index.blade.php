@extends('Admin.Layouts.app')

@section('title', 'Lost parcels — Zefanya Admin')
@section('content-width', 'max-w-none')

@section('content')
@php
    $peso = fn (int $minor): string => '₱'.number_format($minor / 100, 2);
    $panel = 'min-w-0 rounded-2xl border border-neutral-200 bg-white p-5 shadow-sm sm:p-6';
    $th = 'whitespace-nowrap border-b border-neutral-200 px-3 py-2.5 text-left font-sans text-[11px] font-semibold uppercase tracking-[0.6px] text-neutral-600';
    $td = 'border-b border-neutral-200 px-3 py-3 align-top font-sans text-[13px] text-neutral-950';
    $input = 'mt-1 w-full rounded-lg border border-neutral-300 px-3 py-2 font-sans text-[13px] text-neutral-950';
    $btn = 'mt-2 inline-flex min-h-9 cursor-pointer items-center rounded-lg bg-primary-800 px-3 font-sans text-[12px] font-semibold text-white hover:bg-primary-900';
    $tz = \App\Models\Seller\StoreSale::TIMEZONE;
    $filters = ['all' => 'All', 'review' => 'Needs liability decision', 'owed' => 'Owed to Seller', 'paid' => 'Paid', 'found' => 'Found'];
@endphp
<x-admin.tabs :tabs="[
    ['label' => 'Returns & disputes', 'route' => 'admin.returns.index'],
    ['label' => 'Parcel incidents', 'route' => 'admin.incidents.index'],
    ['label' => 'Lost parcels', 'route' => 'admin.lost-parcels.index', 'badge' => $counts['review'] + $counts['found']],
]" />

<div class="space-y-6">
    <div>
        <h1 class="font-serif text-3xl text-neutral-950">Lost parcels</h1>
        <p class="mt-2 max-w-3xl text-sm text-neutral-600">Parcels confirmed lost. The responsible Logistics company owes the Seller the saved merchandise value (no shipping, no commission). It is paid outside Zefanya; you only confirm that it was paid. A lost order is never reopened and stock is never restored here.</p>
    </div>

    @if (session('success'))<div role="status" class="rounded-lg border border-tertiary-300 bg-tertiary-50 p-4 text-sm text-tertiary-800">{{ session('success') }}</div>@endif
    @if ($errors->any())
        <div role="alert" class="rounded-lg border border-error bg-white p-4 text-sm text-error"><ul class="list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif

    <nav aria-label="Filter" class="flex flex-wrap gap-2">
        @foreach ($filters as $key => $label)
            <a href="{{ route('admin.lost-parcels.index', $key === 'all' ? [] : ['status' => $key]) }}" @if ($status === $key) aria-current="page" @endif
               class="inline-flex min-h-9 items-center rounded-full border px-3 font-sans text-[12px] font-semibold no-underline {{ $status === $key ? 'border-primary-800 bg-primary-800 text-white' : 'border-neutral-300 bg-white text-neutral-950 hover:bg-neutral-50' }}">{{ $label }}</a>
        @endforeach
    </nav>

    <section class="{{ $panel }}" aria-labelledby="lostTitle" data-lost-cases>
        <h2 id="lostTitle" class="font-sans text-[15px] font-semibold text-neutral-950">{{ number_format($cases->total()) }} {{ \Illuminate\Support\Str::plural('case', $cases->total()) }}</h2>
        @if ($cases->isEmpty())
            <p class="mt-3 text-[13px] text-neutral-600">No lost parcels match this filter.</p>
        @else
            <div class="mt-4 overflow-x-auto">
                <table class="w-full min-w-[980px] border-collapse">
                    <thead><tr><th class="{{ $th }}">Parcel</th><th class="{{ $th }}">Seller</th><th class="{{ $th }}">Liable company</th><th class="{{ $th }} text-right">Amount</th><th class="{{ $th }}">Status</th><th class="{{ $th }}">Actions</th></tr></thead>
                    <tbody>
                        @foreach ($cases as $case)
                            <tr data-lost-case="{{ $case->id }}">
                                <td class="{{ $td }}"><span class="font-semibold">#{{ $case->seller_order_id }}</span><span class="block font-mono text-[12px] text-neutral-600">{{ $case->sellerOrder?->tracking_number }}</span><span class="block text-[12px] text-neutral-600">{{ $case->confirmed_at->timezone($tz)->format('M j, Y') }} · {{ $case->reason }}</span></td>
                                <td class="{{ $td }}">{{ $case->seller?->business_name ?? '—' }}</td>
                                <td class="{{ $td }}">{{ $case->provider?->business_name ?? 'Not decided' }}<span class="block text-[12px] text-neutral-600">{{ str_replace('_', ' ', $case->liability_basis) }}</span></td>
                                <td class="{{ $td }} text-right font-semibold tabular-nums">{{ $peso($case->netCompensationMinor()) }}@if ($case->adjustments->isNotEmpty())<span class="block text-[12px] font-normal text-neutral-600">of {{ $peso($case->merchandise_minor) }}</span>@endif</td>
                                <td class="{{ $td }}">
                                    @if ($case->isPaid())<span class="rounded-full bg-emerald-50 px-2.5 py-1 text-[12px] font-semibold text-emerald-800">Paid</span><span class="block text-[12px] text-neutral-600">Ref {{ $case->compensation_reference }}</span>
                                    @elseif ($case->compensation_status === \App\Models\Logistics\ParcelLossCase::REVIEW_REQUIRED)<span class="rounded-full bg-red-50 px-2.5 py-1 text-[12px] font-semibold text-red-800">Needs a decision</span>
                                    @else<span class="rounded-full bg-amber-50 px-2.5 py-1 text-[12px] font-semibold text-amber-800">Owed to Seller</span>@endif
                                    @if ($case->recovered_at)<span class="mt-1 block text-[12px] font-semibold text-blue-800">Found {{ $case->recovered_at->timezone($tz)->format('M j') }}</span>@endif
                                </td>
                                <td class="{{ $td }} min-w-[280px]">
                                    @if ($case->compensation_status === \App\Models\Logistics\ParcelLossCase::REVIEW_REQUIRED || ! $case->isPaid())
                                        <details>
                                            <summary class="cursor-pointer text-[12px] font-semibold text-primary-800">{{ $case->logistics_provider_id ? 'Change the liable company' : 'Decide who is liable' }}</summary>
                                            <form method="POST" action="{{ route('admin.lost-parcels.assign', $case) }}">@csrf
                                                <label class="mt-2 block text-[12px] font-semibold text-neutral-600">Company
                                                    <select name="liable_provider_id" required class="{{ $input }}">@foreach ($providers as $p)<option value="{{ $p->id }}" @selected((int) $case->logistics_provider_id === (int) $p->id)>{{ $p->business_name }}</option>@endforeach</select></label>
                                                <label class="mt-2 block text-[12px] font-semibold text-neutral-600">Why<input name="reason" required maxlength="1000" class="{{ $input }}"></label>
                                                <button type="submit" class="{{ $btn }}">Save liability</button>
                                            </form>
                                        </details>
                                    @endif
                                    @if ($case->compensation_status === \App\Models\Logistics\ParcelLossCase::PENDING)
                                        <details class="mt-2">
                                            <summary class="cursor-pointer text-[12px] font-semibold text-primary-800">Record that the Seller was paid</summary>
                                            <form method="POST" action="{{ route('admin.lost-parcels.pay', $case) }}" onsubmit="return confirm('Record {{ $peso($case->merchandise_minor) }} as paid to the Seller? It can be recorded only once.');">@csrf
                                                <label class="mt-2 block text-[12px] font-semibold text-neutral-600">Payment reference<input name="reference" required maxlength="100" class="{{ $input }}"></label>
                                                <label class="mt-2 block text-[12px] font-semibold text-neutral-600">Paid on<input type="date" name="paid_on" required max="{{ now($tz)->toDateString() }}" value="{{ now($tz)->toDateString() }}" class="{{ $input }}"></label>
                                                <button type="submit" class="{{ $btn }}">I confirm it was paid</button>
                                            </form>
                                        </details>
                                    @endif
                                    @if ($case->isPaid())
                                        <details class="mt-2">
                                            <summary class="cursor-pointer text-[12px] font-semibold text-primary-800">Adjust the paid amount ({{ $case->adjustments->count() }})</summary>
                                            @foreach ($case->adjustments as $adj)<p class="mt-1 text-[12px] text-neutral-600">{{ $adj->amount_minor < 0 ? '−' : '+' }}{{ $peso(abs($adj->amount_minor)) }} · {{ $adj->reason }}</p>@endforeach
                                            <form method="POST" action="{{ route('admin.lost-parcels.adjust', $case) }}">@csrf
                                                <label class="mt-2 block text-[12px] font-semibold text-neutral-600">Amount (₱, negative if money was given back)<input name="amount" required inputmode="decimal" placeholder="-100.00" class="{{ $input }}"></label>
                                                <label class="mt-2 block text-[12px] font-semibold text-neutral-600">Why<input name="reason" required maxlength="1000" class="{{ $input }}"></label>
                                                <button type="submit" class="{{ $btn }}">Record adjustment</button>
                                            </form>
                                        </details>
                                    @endif
                                    @if ($case->recovered_at)
                                        @if ($case->disposition)
                                            <p class="mt-2 text-[12px] text-neutral-700"><span class="font-semibold">Decision:</span> {{ \App\Models\Logistics\ParcelLossCase::DISPOSITIONS[$case->disposition] ?? $case->disposition }}</p>
                                        @else
                                            <details class="mt-2" open>
                                                <summary class="cursor-pointer text-[12px] font-semibold text-primary-800">The parcel was found: decide what happens</summary>
                                                <form method="POST" action="{{ route('admin.lost-parcels.disposition', $case) }}">@csrf
                                                    <label class="mt-2 block text-[12px] font-semibold text-neutral-600">Decision
                                                        <select name="disposition" required class="{{ $input }}">@foreach (\App\Models\Logistics\ParcelLossCase::DISPOSITIONS as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></label>
                                                    <label class="mt-2 block text-[12px] font-semibold text-neutral-600">Note<input name="note" required maxlength="1000" class="{{ $input }}"></label>
                                                    <p class="mt-1 text-[11px] text-neutral-600">The order stays closed as lost and no stock is restored.</p>
                                                    <button type="submit" class="{{ $btn }}">Record decision</button>
                                                </form>
                                            </details>
                                        @endif
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $cases->links() }}</div>
        @endif
    </section>
</div>
@endsection
