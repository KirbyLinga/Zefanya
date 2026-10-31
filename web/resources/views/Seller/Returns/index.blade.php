@extends('Layouts.seller')
@section('title', 'Incoming Returns')
@section('content')
<h1 class="mb-6 font-serif text-2xl text-[var(--text-ink)]">Incoming Returns</h1>
<x-logistics-flash :messages="['success', 'warning', 'error']" />
@if ($errors->any())<div role="alert" class="mb-4 rounded-md border border-red-200 bg-red-50 p-3 text-xs text-red-900">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif

<div class="space-y-5">
@forelse ($returns as $record)
<section class="rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-5">
    <x-return-summary :record="$record" />
    <a href="{{ route('seller.orders.show', $record->sellerOrder) }}" class="mt-3 block text-xs underline">Parcel tracking</a>

    @if ($record->description || $record->photos)
        <div class="mt-4 space-y-2">
            @if ($record->description)
                <p class="text-xs font-semibold text-[var(--text-ink)]">Buyer's description</p>
                <p class="whitespace-pre-line text-xs text-[var(--text-ink)]">{{ $record->description }}</p>
            @endif
            @if ($record->photos)
                <p class="text-xs font-semibold text-[var(--text-ink)]">Buyer's photos</p>
                <div class="flex flex-wrap gap-2">
                    @foreach ($record->photos as $index => $photo)
                        <a href="{{ route('seller.returns.photo', [$record, $index]) }}" target="_blank" rel="noopener">
                            <img src="{{ route('seller.returns.photo', [$record, $index]) }}" alt="Return photo {{ $index + 1 }} from the buyer" class="h-24 w-24 rounded-sm object-cover">
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    @if ($record->inDispute())
        <p class="mt-3 text-xs font-semibold text-[var(--text-ink)]">In Dispute. An admin will resolve this. You cannot change it.</p>
    @elseif ($record->awaitingSellerDecision())
        <div class="mt-4 space-y-3 rounded-md border border-[var(--border-strong)] p-4">
            @if ($record->sellerDecisionOverdue())
                <p class="text-xs font-semibold text-[var(--text-ink)]">The 3-day deadline passed on {{ $record->seller_response_due_at->timezone('Asia/Manila')->format('M j, Y g:i A') }}. This request is being approved automatically.</p>
            @else
                <p class="text-xs font-semibold text-[var(--text-ink)]">Respond by {{ $record->seller_response_due_at->timezone('Asia/Manila')->format('M j, Y g:i A') }} Philippine time ({{ $record->seller_response_due_at->diffForHumans() }}). If you don't, it is approved automatically.</p>
                <form method="POST" action="{{ route('seller.returns.approve', $record) }}" onsubmit="return confirm('Approve this return? The parcel will start its way back to you.')">@csrf
                    <button type="submit" class="rounded-sm bg-primary-700 px-4 py-2 text-xs text-white">Approve return</button>
                </form>
                <form method="POST" action="{{ route('seller.returns.reject', $record) }}" class="space-y-2" onsubmit="return confirm('Reject this return? The buyer will see your reason.')">@csrf
                    <label class="block text-xs" for="return-reject-{{ $record->id }}">Reason for rejecting (10–300 characters)</label>
                    <textarea id="return-reject-{{ $record->id }}" name="reason" required minlength="10" maxlength="300" rows="2" class="block w-full rounded-sm border border-[var(--border-strong)] bg-[var(--bg-surface)] p-2 text-xs text-[var(--text-ink)]"></textarea>
                    <button type="submit" class="rounded-sm border border-primary-700 px-4 py-2 text-xs text-primary-700">Reject return</button>
                </form>
            @endif
        </div>
    @elseif ($record->status->value === 'AWAITING_SELLER_RECEIPT')
        @if ((int) session('return_verification.id') === (int) $record->id)
            <form method="POST" action="{{ route('seller.returns.receive', $record) }}" class="mt-4">@csrf
                <input type="hidden" name="verification_token" value="{{ session('return_verification.token') }}"><input type="hidden" name="condition" value="{{ session('return_verification.condition') }}">
                <button type="submit" class="rounded-sm bg-primary-700 px-4 py-2 text-xs text-white">Confirm my physical receipt</button>
            </form>
        @else
            <form method="POST" action="{{ route('seller.returns.verify', $record) }}" class="mt-4 grid gap-3 sm:grid-cols-2">@csrf
                <label class="text-xs">Scanned tracking number<input name="tracking_number" required maxlength="64" class="mt-1 block w-full rounded-sm border border-[var(--border-strong)] p-2"></label>
                <label class="text-xs">Physical handover code from staff<input name="handover_code" required maxlength="64" autocomplete="off" class="mt-1 block w-full rounded-sm border border-[var(--border-strong)] p-2"></label>
                <label class="text-xs">Inspected condition<select name="condition" required class="mt-1 block w-full rounded-sm border border-[var(--border-strong)] bg-[var(--bg-surface)] p-2"><option value="pending_review">Needs inspection / review</option><option value="resellable">Inspected as resellable</option><option value="damaged">Damaged / not resellable</option></select></label>
                <button type="submit" class="self-end rounded-sm bg-primary-700 px-4 py-2 text-xs text-white">Verify physical handover</button>
            </form>
        @endif
    @elseif ($record->seller_received_at)
        <p class="mt-3 text-xs text-[var(--text-muted)]">Condition: {{ $record->stock_condition }}. {{ $record->stock_restored_at ? 'Approved stock restoration recorded.' : 'Inventory held for policy or condition review; no automatic restock.' }}</p>
    @elseif ($record->dispute_outcome === \App\Models\Logistics\ParcelReturn::DISPUTE_OVERTURNED)
        <p class="mt-3 text-xs text-[var(--text-ink)]">An admin approved this return after the buyer disputed your rejection.</p>
        <p class="mt-3 text-xs text-[var(--text-muted)]">Receipt confirmation is unavailable until verified origin arrival and physical handover.</p>
    @elseif ($record->seller_decision === \App\Models\Logistics\ParcelReturn::REJECTED)
        <p class="mt-3 text-xs text-[var(--text-ink)]">You rejected this request on {{ $record->seller_decided_at->timezone('Asia/Manila')->format('M j, Y g:i A') }}. Reason: {{ $record->seller_decision_reason }}</p>
        @if ($record->dispute_outcome === \App\Models\Logistics\ParcelReturn::DISPUTE_UPHELD)
            <p class="mt-1 text-xs text-[var(--text-ink)]">An admin agreed with your rejection. {{ $record->dispute_note }}</p>
        @endif
    @else
        @if ($record->seller_decision === \App\Models\Logistics\ParcelReturn::AUTO_APPROVED)
            <p class="mt-3 text-xs text-[var(--text-ink)]">Approved automatically because the 3-day deadline passed.</p>
        @elseif ($record->seller_decision === \App\Models\Logistics\ParcelReturn::APPROVED)
            <p class="mt-3 text-xs text-[var(--text-ink)]">You approved this request.</p>
        @endif
        <p class="mt-3 text-xs text-[var(--text-muted)]">Receipt confirmation is unavailable until verified origin arrival and physical handover.</p>
    @endif
</section>
@empty<p class="text-sm text-[var(--text-muted)]">No incoming returns.</p>@endforelse
</div><div class="mt-4">{{ $returns->links() }}</div>
@endsection
