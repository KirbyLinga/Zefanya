@php
    $rate = \App\Services\Logistics\ShippingRateService::fixedRate();
    $money = fn (int $minor): string => '₱'.number_format($minor / 100, 2);
    $examples = [1, $rate['included_kg'], $rate['included_kg'] + 1, $rate['included_kg'] + 3];
@endphp

<section class="mb-5 flex max-w-[960px] flex-wrap items-center justify-between gap-4 rounded-xl border border-[var(--border-strong)] bg-[var(--bg-surface)] p-4">
    <div class="max-w-xl">
        <h2 class="text-sm font-semibold text-[var(--text-ink)]">Delivery areas</h2>
        <p class="mt-1 text-xs leading-relaxed text-[var(--text-muted)]">Add the barangays your Station Hubs serve. Rates apply within that coverage; buyers cannot place an order for an unserviceable address.</p>
    </div>
    <a href="{{ route('logistics.areas.index') }}#coverage-form" class="inline-flex min-h-11 items-center justify-center rounded-lg bg-primary-800 px-4 text-sm font-semibold text-white transition-colors hover:bg-primary-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-700">Manage Delivery Areas</a>
</section>

<div class="max-w-[960px]">
    <div class="rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] shadow-sm">
        <div class="grid gap-4 p-4 md:grid-cols-[minmax(0,1fr)_160px_160px] md:items-start">
            <div>
                <p class="font-sans text-[14px] font-semibold text-[var(--text-ink)]">All deliveries</p>
                <p class="font-sans text-[12px] text-[var(--text-muted)]">Same city, same province and other provinces all use this rate.</p>
            </div>
            <div>
                <p class="font-sans text-[12px] font-semibold text-[var(--text-ink)]">First {{ $rate['included_kg'] }} kg</p>
                <p class="mt-1 flex h-10 items-center font-sans text-[15px] text-[var(--text-ink)]">{{ $money($rate['first_kg_minor']) }}</p>
            </div>
            <div>
                <p class="font-sans text-[12px] font-semibold text-[var(--text-ink)]">Each extra kg</p>
                <p class="mt-1 flex h-10 items-center font-sans text-[15px] text-[var(--text-ink)]">+ {{ $money($rate['additional_kg_minor']) }}</p>
            </div>
        </div>
        <dl class="grid grid-cols-2 gap-px border-t border-[var(--border-strong)] bg-[var(--border-strong)] sm:grid-cols-4">
            @foreach ($examples as $kg)
                <div class="bg-[var(--bg-surface)] px-4 py-3">
                    <dt class="font-sans text-[12px] text-[var(--text-muted)]">{{ $kg }} kg parcel</dt>
                    <dd class="mt-0.5 font-sans text-[15px] font-semibold text-[var(--text-ink)]">{{ $money(\App\Services\Buyer\CheckoutCarrierService::feeForRate($rate, $kg * 1000)) }}</dd>
                </div>
            @endforeach
        </dl>
    </div>

    <ul class="mt-3 space-y-1 font-sans text-[12px] text-[var(--text-muted)]">
        <li class="text-[var(--text-muted)]">The marketplace sets this rate for every Logistics company. It is applied at checkout.</li>
        <li class="text-[var(--text-muted)]">Weight rounds up to the next whole kilo, so 2.2 kg is charged as 3 kg.</li>
    </ul>
</div>
