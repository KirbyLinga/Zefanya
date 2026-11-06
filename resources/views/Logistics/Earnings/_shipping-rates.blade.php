@php
    $exampleKg = 3;
    $money = fn (int $minor): string => '₱'.number_format($minor / 100, 2);
@endphp

<section class="mb-5 flex max-w-[960px] flex-wrap items-center justify-between gap-4 rounded-xl border border-[var(--border-strong)] bg-[var(--bg-surface)] p-4">
    <div class="max-w-xl">
        <h2 class="text-sm font-semibold text-[var(--text-ink)]">Delivery areas</h2>
        <p class="mt-1 text-xs leading-relaxed text-[var(--text-muted)]">Add the barangays your Station Hubs serve. Rates apply within that coverage; buyers cannot place an order for an unserviceable address.</p>
    </div>
    <a href="{{ route('logistics.areas.index') }}#coverage-form" class="inline-flex min-h-11 items-center justify-center rounded-lg bg-primary-800 px-4 text-sm font-semibold text-white transition-colors hover:bg-primary-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-700">Manage Delivery Areas</a>
</section>

<div class="max-w-[960px]">
    <div class="divide-y divide-[var(--border-strong)] rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] shadow-sm">
        @foreach ($zones as $key => $zone)
            @php
                $rate = $zone['own'] !== null && $zone['own']->first_kg_minor !== null
                    ? ['first_kg_minor' => $zone['own']->first_kg_minor, 'additional_kg_minor' => (int) $zone['own']->additional_kg_minor]
                    : $zone['marketplace'];
            @endphp
            <div class="grid gap-4 p-4 md:grid-cols-[minmax(0,1fr)_130px_130px_130px] md:items-start">
                <div>
                    <p class="font-sans text-[14px] font-semibold text-[var(--text-ink)]">{{ $zone['label'] }}</p>
                    <p class="font-sans text-[12px] text-[var(--text-muted)]">{{ $zone['help'] }}</p>
                </div>
                @if ($rate)
                    <div>
                        <p class="font-sans text-[12px] font-semibold text-[var(--text-ink)]">First kilo</p>
                        <p class="mt-1 flex h-10 items-center font-sans text-[15px] text-[var(--text-ink)]">{{ $money($rate['first_kg_minor']) }}</p>
                    </div>
                    <div>
                        <p class="font-sans text-[12px] font-semibold text-[var(--text-ink)]">Each extra kilo</p>
                        <p class="mt-1 flex h-10 items-center font-sans text-[15px] text-[var(--text-ink)]">{{ $money($rate['additional_kg_minor']) }}</p>
                    </div>
                    <div>
                        <p class="font-sans text-[12px] font-semibold text-[var(--text-ink)]">{{ $exampleKg }} kg parcel</p>
                        <p class="mt-1 flex h-10 items-center font-sans text-[15px] font-semibold text-[var(--text-ink)]">{{ $money($rate['first_kg_minor'] + ($exampleKg - 1) * $rate['additional_kg_minor']) }}</p>
                    </div>
                @else
                    <p class="font-sans text-[12px] text-[var(--text-muted)] md:col-span-3">Not offered: no marketplace rate is set for this route.</p>
                @endif
            </div>
        @endforeach
    </div>

    <ul class="mt-3 space-y-1 font-sans text-[12px] text-[var(--text-muted)]">
        <li class="text-[var(--text-muted)]">Rates are set automatically by the marketplace and applied at checkout.</li>
        <li class="text-[var(--text-muted)]">Weight rounds up to the next whole kilo, so 1.2 kg is charged as 2 kg.</li>
    </ul>
</div>
