@extends('Layouts.rider')

@section('title', 'Delivery History')

@use('App\Enums\OrderStatus')

@section('content')
    <div class="mb-6">
        <p class="font-sans text-[11px] font-semibold uppercase tracking-[1.2px] text-[var(--text-muted)]">Assignments</p>
        <h1 class="font-serif text-2xl text-[var(--text-ink)]">Delivery History</h1>
        <p class="font-sans text-[12.5px] text-[var(--text-muted)]">Deliveries you completed, plus failed attempts and returns.</p>
    </div>

    <x-rider.flash />

    @if ($shipments->isEmpty())
        <section class="flex flex-col items-center rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-8 text-center shadow-sm">
            <i data-lucide="history" class="mb-3 h-[32px] w-[32px] text-[var(--icon-soft)]"></i>
            <p class="mb-1 font-sans text-[13px] font-semibold text-[var(--text-ink)]">No delivery history yet</p>
            <p class="max-w-[380px] font-sans text-[11.5px] text-[var(--text-muted)]">
                Delivered, failed and returned parcels assigned to you will be listed here.
            </p>
        </section>
    @else
        <section class="overflow-hidden rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] shadow-sm">
            <table class="w-full text-left font-sans">
                <thead class="border-b border-[var(--border-strong)] bg-[var(--bg-page)]">
                    <tr>
                        <th class="px-4 py-2.5 font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)]">Parcel</th>
                        <th class="px-4 py-2.5 font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)]">Buyer · Destination</th>
                        <th class="px-4 py-2.5 font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)]">Status</th>
                        <th class="px-4 py-2.5 font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)]">Updated</th>
                        <th class="px-4 py-2.5 text-right font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)]">Proof</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--border-strong)]">
                    @foreach ($shipments as $shipment)
                        @php($addr = $shipment->order?->shipping_address ?? [])
                        <tr>
                            <td class="px-4 py-2.5">
                                <p class="font-sans text-[12.5px] font-semibold text-[var(--text-ink)]">#{{ $shipment->id }}</p>
                                <p class="font-mono text-[10.5px] text-[var(--text-muted)]">{{ $shipment->tracking_number ?? '—' }}</p>
                            </td>
                            <td class="px-4 py-2.5">
                                <p class="font-sans text-[12.5px] text-[var(--text-ink)]">{{ $addr['name'] ?? $shipment->order?->buyer?->fullName() ?? 'Buyer' }}</p>
                                <p class="font-sans text-[11px] text-[var(--text-muted)]">{{ $addr['municipality'] ?? '—' }}</p>
                            </td>
                            <td class="px-4 py-2.5">
                                <p class="font-sans text-[12.5px] text-[var(--text-ink)]">{{ $shipment->status->label() }}</p>
                                @if ($shipment->status === OrderStatus::DeliveryFailed)
                                    <p class="font-sans text-[11px] text-rose-700">Attempt {{ (int) $shipment->delivery_attempts }}</p>
                                @endif
                            </td>
                            <td class="px-4 py-2.5">
                                <p class="font-sans text-[12px] text-[var(--text-muted)]">{{ $shipment->updated_at?->format('M j, Y g:i A') ?? '—' }}</p>
                            </td>
                            <td class="px-4 py-2.5 text-right">
                                @if ($shipment->deliveryProof)
                                    <a href="{{ route('rider.proofs.show', $shipment->deliveryProof) }}"
                                       class="font-sans text-[11.5px] font-semibold uppercase tracking-[0.5px] text-primary-700 no-underline hover:text-primary-800">
                                        View
                                    </a>
                                @else
                                    <span class="font-sans text-[11.5px] text-[var(--text-muted)]">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif
@endsection
