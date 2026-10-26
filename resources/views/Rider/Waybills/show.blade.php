@extends('Layouts.rider')

@section('title', 'Digital Waybill')

@use('App\Enums\PaymentMethod')

@section('content')
    @php
        $addr = $sellerOrder->order?->shipping_address ?? [];
        $payment = $sellerOrder->payment;
        $buyerName = $addr['name'] ?? $sellerOrder->order?->buyer?->fullName() ?? 'Buyer';
        $buyerPhone = $addr['phone'] ?? $sellerOrder->order?->buyer?->contact_no;
        $seller = $sellerOrder->seller;
        $pickupAddress = collect([
            $seller?->house_number,
            $seller?->street,
            $seller?->barangay_name,
            $seller?->municipality_name,
            $seller?->province_name,
        ])->filter()->implode(', ');
        $tel = $buyerPhone ? preg_replace('/\s+/', '', $buyerPhone) : null;
    @endphp

    <div class="mb-4">
        <a href="{{ $context === 'pickup' ? route('rider.pickups.index') : route('rider.deliveries.index') }}"
           class="font-sans text-[11.5px] font-semibold uppercase tracking-[0.5px] text-[var(--text-muted)] no-underline hover:text-[var(--text-ink)]">
            ← Back to assignments
        </a>
        <h1 class="mt-1 font-serif text-2xl text-[var(--text-ink)]">Digital Waybill</h1>
        <p class="font-mono text-[12.5px] text-[var(--text-muted)]">{{ $sellerOrder->tracking_number ?? 'No tracking number' }}</p>
    </div>

    <section class="space-y-4 rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-4 shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="font-sans text-[13px] font-semibold text-[var(--text-ink)]">Parcel #{{ $sellerOrder->id }}</p>
                <p class="font-sans text-[12px] text-[var(--text-muted)]">{{ $sellerOrder->formattedParcelWeight() }} · {{ $sellerOrder->orderItems->sum('quantity') }} item(s)</p>
            </div>
            <span class="rounded-full border border-[var(--border-strong)] px-2 py-0.5 font-sans text-[10.5px] font-semibold uppercase tracking-[0.5px] text-[var(--text-muted)]">
                {{ $sellerOrder->status->label() }}
            </span>
        </div>

        @if ($showSellerContact)
            <div class="border-t border-[var(--border-strong)] pt-3">
                <p class="mb-1 font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)]">Pickup from (seller)</p>
                <p class="font-sans text-[13px] font-semibold text-[var(--text-ink)]">{{ $seller?->business_name ?? $seller?->fullName() ?? 'Seller' }}</p>
                <p class="font-sans text-[12.5px] text-[var(--text-muted)]">{{ $pickupAddress !== '' ? $pickupAddress : 'Address not recorded' }}</p>
                @if ($seller?->contact_no)
                    <a href="tel:{{ preg_replace('/\s+/', '', $seller->contact_no) }}"
                       class="mt-2 inline-flex h-9 items-center justify-center rounded-sm border border-[var(--border-strong)] px-4 font-sans text-[11.5px] font-semibold uppercase tracking-[0.5px] text-[var(--text-ink)] no-underline">
                        Call seller
                    </a>
                @endif
            </div>
        @endif

        @if ($showBuyerContact)
            <div class="border-t border-[var(--border-strong)] pt-3">
                <p class="mb-1 font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)]">Deliver to (buyer)</p>
                <p class="font-sans text-[13px] font-semibold text-[var(--text-ink)]">{{ $buyerName }}</p>
                <p class="font-sans text-[12.5px] text-[var(--text-muted)]">{{ $addr['full'] ?? 'Address not recorded' }}</p>
                @if ($buyerPhone)
                    <p class="font-sans text-[12.5px] text-[var(--text-ink)]">{{ $buyerPhone }}</p>
                @endif
                @if ($tel)
                    <a href="tel:{{ $tel }}"
                       class="mt-2 inline-flex h-9 items-center justify-center rounded-sm bg-primary-700 px-4 font-sans text-[11.5px] font-semibold uppercase tracking-[0.5px] text-white no-underline hover:bg-primary-800">
                        Call buyer
                    </a>
                @endif
            </div>
        @else
            <div class="border-t border-[var(--border-strong)] pt-3">
                <p class="mb-1 font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)]">Destination (routing)</p>
                <p class="font-sans text-[12.5px] text-[var(--text-ink)]">{{ $addr['municipality'] ?? $sellerOrder->buyerMunicipality() ?? '—' }}</p>
                <p class="font-sans text-[11.5px] text-[var(--text-muted)]">Buyer contact is shown only to the assigned delivery rider.</p>
            </div>
        @endif

        <div class="border-t border-[var(--border-strong)] pt-3">
            <p class="mb-1 font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)]">Payment</p>
            <p class="font-sans text-[12.5px] text-[var(--text-ink)]">{{ $payment?->method?->label() ?? 'Not recorded' }}</p>
            @if ($payment?->method === PaymentMethod::Cod && ($payment->requiresCollection() ?? false))
                <p class="font-sans text-[12.5px] font-semibold text-amber-700">Collect {{ $payment->formattedAmount() }}</p>
            @elseif ($payment?->method === PaymentMethod::Cod)
                <p class="font-sans text-[12px] text-emerald-700">COD already collected</p>
            @elseif ($payment?->method === PaymentMethod::Gcash)
                <p class="font-sans text-[12px] text-[var(--text-muted)]">GCash — no cash collection at the door</p>
            @endif
        </div>

        <div class="flex flex-wrap gap-2 border-t border-[var(--border-strong)] pt-3">
            <a href="{{ route('rider.scan.index') }}"
               class="inline-flex h-9 items-center justify-center rounded-sm border border-[var(--border-strong)] px-4 font-sans text-[11.5px] font-semibold uppercase tracking-[0.5px] text-[var(--text-ink)] no-underline">
                Scan to confirm handoff
            </a>
        </div>
    </section>
@endsection
