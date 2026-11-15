<section class="min-w-0 space-y-5" aria-label="Station parcel inventory">
    <x-logistics.setup-header :title="$station->name.' — Parcels'" description="Physically held parcels and expected arrivals. Inbound parcels are not yet inside this station.">
        <a href="{{ route('logistics.hubs.index') }}" class="inline-flex min-h-11 items-center rounded-md px-3 text-sm font-semibold text-primary-800 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-700">Back to hubs</a>
    </x-logistics.setup-header>
    <dl class="grid grid-cols-2 gap-3 sm:grid-cols-5">
        @foreach (['total' => 'Total parcels', 'inside' => 'Inside', 'to_sort' => 'To sort', 'exceptions' => 'Exceptions', 'inbound' => 'Inbound'] as $key => $label)
            <div class="rounded-md border border-[var(--border-strong)] bg-[var(--bg-surface)] p-3">
                <dt class="text-xs text-[var(--text-muted)]">{{ $label }}</dt>
                <dd class="mt-1 text-lg font-semibold text-[var(--text-ink)]">{{ $inventoryCounts[$key] }}</dd>
            </div>
        @endforeach
    </dl>
    <div role="region" aria-label="Station parcels" tabindex="0" class="overflow-x-auto rounded-md border border-[var(--border-strong)] bg-[var(--bg-surface)] focus-visible:outline-2 focus-visible:outline-primary-700">
        <table class="w-full min-w-[800px] text-left text-sm text-[var(--text-ink)]">
            <thead class="bg-[var(--bg-page)]"><tr>
                @foreach (['Waybill', 'Parcel type', 'Status', 'Station / custody', 'Origin → destination', 'Next step'] as $heading)
                    <th scope="col" class="px-4 py-3 text-xs font-semibold">{{ $heading }}</th>
                @endforeach
            </tr></thead>
            <tbody class="divide-y divide-[var(--border-strong)]">
                @forelse ($inventoryRows as $row)
                    @php($parcel = $row['parcel'])
                    @php($return = $parcel->parcelReturn)
                    <tr>
                        <td class="px-4 py-3 font-mono text-xs">{{ $parcel->tracking_number ?: 'Pending waybill' }}</td>
                        <td class="px-4 py-3">{{ $return?->isActive() ? ($return->isBuyerRequest() ? 'Buyer Return' : 'Return') : 'Outbound delivery' }}</td>
                        <td class="px-4 py-3">{{ $return?->isActive() ? $return->collectionStatusLabel() : $parcel->status->label() }}</td>
                        <td class="px-4 py-3">{{ $row['inside'] ? $station->name.' · Logistics station custody' : 'In transport · Expected at '.$station->name }}</td>
                        <td class="px-4 py-3">{{ $row['origin'] ?? $station->name }} → {{ $row['destination'] ?? 'See shipment route' }}</td>
                        <td class="px-4 py-3">
                            <p>{{ $row['note'] }}</p>
                            @if ($return?->isActive() && ($row['inside'] || (int) $return->origin_provider_id === (int) request()->user('logistics')->id))
                                <a href="{{ route('logistics.returns.show', $return) }}" class="inline-flex min-h-11 items-center text-xs font-semibold text-primary-800 hover:underline focus-visible:outline-2 focus-visible:outline-primary-700">View return movement</a>
                            @elseif (! $return?->isActive())
                                <a href="{{ route('logistics.delivery-monitoring.show', $parcel) }}" class="inline-flex min-h-11 items-center text-xs font-semibold text-primary-800 hover:underline focus-visible:outline-2 focus-visible:outline-primary-700">View parcel</a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-[var(--text-muted)]">No parcels inside or inbound to this station.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $inventoryRows->links() }}
</section>
