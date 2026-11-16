@extends('Admin.Layouts.app')
@section('title', 'Address checks')
@section('content')
    <h1 class="text-2xl font-semibold text-[var(--text-ink)]">Delivery address checks</h1>
    <p class="mt-2 max-w-3xl text-sm text-[var(--text-muted)]">
        Shipments going to a region that is not the Seller's own or a neighbouring one (for example Metro Manila to Abra) wait here before pickup.
        Call the Buyer, confirm the address, and record the result. The Seller can accept and pack meanwhile, but cannot request pickup until the address is confirmed.
    </p>
    <x-logistics-flash :messages="['success', 'warning', 'error']" />
    <nav aria-label="Address check status" class="my-4 flex flex-wrap gap-4 text-sm font-semibold text-primary-800">
        @foreach (['open' => 'On hold', 'pending' => 'Awaiting call', 'not_confirmed' => 'Not confirmed', 'confirmed' => 'Confirmed', 'all' => 'All'] as $value => $label)
            <a href="{{ route('admin.address-reviews.index', ['status' => $value]) }}" @if ($status === $value) aria-current="page" class="underline" @endif>{{ $label }}</a>
        @endforeach
    </nav>
    <div class="space-y-3">
        @forelse ($reviews as $review)
            <article class="rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-4">
                <a class="font-semibold text-primary-800 underline" href="{{ route('admin.address-reviews.show', $review->id) }}">{{ $review->reference() }} · Order {{ $review->sellerOrder?->order?->reference }} · shipment #{{ $review->seller_order_id }}</a>
                <p class="mt-2 text-sm text-[var(--text-ink)]">{{ $review->seller?->business_name ?? 'Seller' }} ({{ $review->seller_province }}, {{ $review->regionLabel($review->seller_region) }}) → {{ $review->buyer_province }} ({{ $review->regionLabel($review->buyer_region) }})</p>
                <p class="mt-1 text-xs text-[var(--text-muted)]">{{ $review->statusLabel() }}@if ($review->decided_at) · {{ $review->decider?->name ?? 'Admin #'.$review->decided_by }} · {{ manila($review->decided_at, 'M j, Y g:i A') }}@endif · Placed {{ manila($review->created_at, 'M j, Y g:i A') }}</p>
            </article>
        @empty
            <p class="rounded-lg border border-[var(--border-strong)] p-4 text-sm">No address checks in this view.</p>
        @endforelse
    </div>
    <div class="mt-4">{{ $reviews->links() }}</div>
@endsection
