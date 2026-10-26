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
    @if ($record->status->value === 'AWAITING_SELLER_RECEIPT')
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
    @else<p class="mt-3 text-xs text-[var(--text-muted)]">Receipt confirmation is unavailable until verified origin arrival and physical handover.</p>@endif
</section>
@empty<p class="text-sm text-[var(--text-muted)]">No incoming returns.</p>@endforelse
</div><div class="mt-4">{{ $returns->links() }}</div>
@endsection
