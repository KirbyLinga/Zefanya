@if (($allocationHolds ?? collect())->isNotEmpty())
<section aria-labelledby="allocation-holds-heading" class="mb-6 rounded-lg border border-amber-300 bg-amber-50 p-4 text-neutral-950">
    <h2 id="allocation-holds-heading" class="font-semibold">Shipping allocation requires approval</h2>
    <p class="mt-2 text-sm">These parcels involve three or more Logistics Providers. No new shipping-share payout or affected platform remittance can be finalized until an allocation method is explicitly approved and implemented. Delivery and Rider cash receipt continue. Original charges and existing transaction snapshots are retained. Pending shares are excluded from finalized earnings and payable totals.</p>
    <div class="mt-3 overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead><tr><th class="p-2">Parcel</th><th class="p-2">Financial participants</th><th class="p-2">Original shipping fee</th><th class="p-2">Collected COD</th></tr></thead>
            <tbody>
            @foreach ($allocationHolds as $held)
                @php
                    $participantIds = array_values(array_unique(array_filter([...app(\App\Services\Logistics\ProviderAllocationService::class)->chain($held->sellerOrder), (int) $held->logistics_provider_id])));
                    $participantNames = \App\Models\Logistics\LogisticsProvider::query()->whereIn('id', $participantIds)->pluck('business_name', 'id');
                @endphp
                <tr class="border-t border-amber-200"><td class="p-2">#{{ $held->seller_order_id }} · {{ $held->sellerOrder?->tracking_number }}</td><td class="p-2">{{ collect($participantIds)->map(fn ($id) => $participantNames[$id] ?? 'Provider #'.$id)->implode(' → ') }}</td><td class="p-2">₱{{ number_format($held->sellerOrder?->shipping_fee_minor / 100, 2) }}</td><td class="p-2">₱{{ number_format($held->amount_minor / 100, 2) }}</td></tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <p class="mt-2 text-xs">Shows up to 100 affected records. A general policy note does not enable an unsupported calculation.</p>
</section>
@endif
