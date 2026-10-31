@extends('Layouts.logistics')

@section('title', 'Shipping Rates')

@section('content')
@php
    $exampleKg = 3;
    $pesos = fn (?int $minor): string => $minor === null ? '' : rtrim(rtrim(number_format($minor / 100, 2, '.', ''), '0'), '.');
    $money = fn (int $minor): string => '₱'.number_format($minor / 100, 2);
    $fieldClass = 'h-10 w-full rounded-md border border-[var(--border-strong)] bg-[var(--bg-surface)] pl-7 pr-2 font-sans text-[13px] text-[var(--text-ink)]';
@endphp

<div class="mb-5">
    <h1 class="font-serif text-2xl text-[var(--text-ink)]">Shipping Rates</h1>
    <p class="mt-1 font-sans text-[13px] text-[var(--text-muted)]">Set shipping rates from ₱0.01. Changes apply only to checkouts started afterward; open checkouts keep their rates.</p>
</div>

<section class="mb-5 flex max-w-[960px] flex-wrap items-center justify-between gap-4 rounded-xl border border-[var(--border-strong)] bg-[var(--bg-surface)] p-4">
    <div class="max-w-xl">
        <h2 class="text-sm font-semibold text-[var(--text-ink)]">Delivery zones</h2>
        <p class="mt-1 text-xs leading-relaxed text-[var(--text-muted)]">Add the barangays your Station Hubs serve. Rates apply within that coverage; buyers cannot place an order for an unserviceable address.</p>
    </div>
    <a href="{{ route('logistics.areas.index') }}#coverage-form" class="inline-flex min-h-11 items-center justify-center rounded-lg bg-primary-800 px-4 text-sm font-semibold text-white transition-colors hover:bg-primary-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-700">Add delivery zone</a>
</section>
<x-logistics-flash :messages="['success', 'warning', 'error']" />
@if ($errors->any())
    <div role="alert" class="mb-4 rounded-md border border-red-200 bg-red-50 p-3 font-sans text-[13px] text-red-900">
        @foreach ($errors->all() as $error)<p class="text-red-900">{{ $error }}</p>@endforeach
    </div>
@endif

<form method="POST" action="{{ route('logistics.shipping-rates.update') }}" class="max-w-[960px]">
    @csrf
    @method('PUT')

    <div class="divide-y divide-[var(--border-strong)] rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] shadow-sm">
        @foreach ($zones as $key => $zone)
            @php
                $marketplace = $zone['marketplace'];
                $first = old("rates.{$key}.first_kg", $pesos($zone['own']?->first_kg_minor));
                $additional = old("rates.{$key}.additional_kg", $pesos($zone['own']?->additional_kg_minor));
            @endphp
            <div data-rate-zone data-default-first="{{ $marketplace['first_kg_minor'] ?? '' }}" data-default-additional="{{ $marketplace['additional_kg_minor'] ?? '' }}" class="grid gap-4 p-4 md:grid-cols-[minmax(0,1fr)_130px_130px_130px] md:items-start">
                <div>
                    <p class="font-sans text-[14px] font-semibold text-[var(--text-ink)]">{{ $zone['label'] }}</p>
                    <p class="font-sans text-[12px] text-[var(--text-muted)]">{{ $zone['help'] }}</p>
                    <p class="mt-1 font-sans text-[12px] text-[var(--text-muted)]">
                        @if ($marketplace)
                            If blank: marketplace rate of {{ $money($marketplace['first_kg_minor']) }} + {{ $money($marketplace['additional_kg_minor']) }} per extra kilo.
                        @else
                            If blank: you don't offer this route.
                        @endif
                    </p>
                </div>
                <label class="block font-sans text-[12px] font-semibold text-[var(--text-ink)]">
                    First kilo
                    <span class="relative mt-1 block">
                        <span class="absolute left-2.5 top-1/2 -translate-y-1/2 font-normal text-[var(--text-muted)]">₱</span>
                        <input type="number" min="0.01" max="999999.99" step="0.01" inputmode="decimal" name="rates[{{ $key }}][first_kg]" value="{{ $first }}" data-rate-first pattern="[0-9]+(\.[0-9]{1,2})?" title="Minimum ₱0.01; up to two decimal places" class="{{ $fieldClass }} font-normal">
                    </span>
                </label>
                <label class="block font-sans text-[12px] font-semibold text-[var(--text-ink)]">
                    Each extra kilo
                    <span class="relative mt-1 block">
                        <span class="absolute left-2.5 top-1/2 -translate-y-1/2 font-normal text-[var(--text-muted)]">₱</span>
                        <input type="number" min="0.01" max="999999.99" step="0.01" inputmode="decimal" name="rates[{{ $key }}][additional_kg]" value="{{ $additional }}" data-rate-additional pattern="[0-9]+(\.[0-9]{1,2})?" title="Minimum ₱0.01; up to two decimal places" class="{{ $fieldClass }} font-normal">
                    </span>
                </label>
                <div>
                    <p class="font-sans text-[12px] font-semibold text-[var(--text-ink)]">{{ $exampleKg }} kg parcel</p>
                    <p data-rate-example class="mt-1 flex h-10 items-center font-sans text-[15px] font-semibold text-[var(--text-ink)]">—</p>
                </div>
            </div>
        @endforeach
    </div>

    <ul class="mt-3 space-y-1 font-sans text-[12px] text-[var(--text-muted)]">
        <li class="text-[var(--text-muted)]">Weight rounds up to the next whole kilo, so 1.2 kg is charged as 2 kg.</li>
        <li class="text-[var(--text-muted)]">Same-province orders of {{ $money($freeShippingMinor) }} or more ship free for the buyer. This is a marketplace rule, and it applies before your rate.</li>
    </ul>

    <label class="mt-4 block max-w-[480px] font-sans text-[12px] font-semibold text-[var(--text-ink)]">
        Reason for this change (optional)
        <input name="reason" maxlength="500" value="{{ old('reason') }}" placeholder="e.g. Fuel prices went up" class="mt-1 h-10 w-full rounded-md border border-[var(--border-strong)] bg-[var(--bg-surface)] px-3 font-sans text-[13px] font-normal text-[var(--text-ink)]">
    </label>

    <button type="submit" class="mt-4 inline-flex h-10 items-center justify-center rounded-md bg-primary-700 px-5 font-sans text-[13px] font-semibold text-white hover:bg-primary-800">Save rates</button>
</form>
@endsection

@push('scripts')
<script>
    (function () {
        const exampleKg = {{ $exampleKg }};
        const money = minor => '₱' + (minor / 100).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        const toMinor = value => /^\d+(\.\d{1,2})?$/.test(value.trim()) ? Math.round(parseFloat(value) * 100) : null;

        document.querySelectorAll('[data-rate-zone]').forEach(zone => {
            const firstInput = zone.querySelector('[data-rate-first]');
            const additionalInput = zone.querySelector('[data-rate-additional]');
            const example = zone.querySelector('[data-rate-example]');
            const update = () => {
                const blank = firstInput.value.trim() === '' && additionalInput.value.trim() === '';
                const first = blank ? (zone.dataset.defaultFirst === '' ? null : Number(zone.dataset.defaultFirst)) : toMinor(firstInput.value);
                const additional = blank ? Number(zone.dataset.defaultAdditional || 0) : toMinor(additionalInput.value);
                if (first === null) {
                    example.textContent = blank ? 'Not offered' : '—';
                } else {
                    example.textContent = additional === null ? '—' : money(first + (exampleKg - 1) * additional);
                }
            };
            firstInput.addEventListener('input', update);
            additionalInput.addEventListener('input', update);
            update();
        });
    })();
</script>
@endpush
