@extends('Layouts.seller')
@section('title', 'Payout Requests')
@section('content')
<div class="space-y-6">
    <div>
        <h1 class="font-serif text-3xl text-[var(--text-ink)]">Payout Requests</h1>
        <p class="mt-2 text-sm text-[var(--text-muted)]">Request a withdrawal from your completed, reconciled earnings.</p>
    </div>
    @if(session('success'))
        <div role="status" class="rounded-md border border-tertiary-300 bg-tertiary-50 p-4 text-sm text-tertiary-800">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div role="alert" class="rounded-md border border-error p-4 text-sm text-error">
            <ul class="list-disc pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif
    <section class="rounded-lg border border-[var(--border-input)] bg-[var(--bg-surface)] p-5">
        <h2 class="text-sm text-[var(--text-muted)]">Available earnings</h2>
        <p class="mt-2 text-3xl font-semibold text-[var(--text-ink)]">₱{{ number_format($availableMinor / 100, 2) }}</p>
        <p class="mt-2 text-sm text-[var(--text-muted)]">Pending requests reserve earnings until cancelled. Minimum withdrawal: ₱100.00.</p>
        @if($pending)
            <p role="status" class="mt-5 text-sm font-semibold text-[var(--text-ink)]">A payout request is already pending: ₱{{ number_format($pending->amount_minor / 100, 2) }}.</p>
        @else
            <form method="POST" action="{{ route('seller.payout-requests.store') }}" class="mt-5 flex flex-col gap-4 sm:flex-row sm:items-end">
                @csrf
                <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">
                <label class="flex flex-col gap-2 text-sm text-[var(--text-ink)]" for="payout-amount">Amount (PHP)
                    <input id="payout-amount" name="amount" type="number" min="100" step="0.01" required value="{{ is_scalar(old('amount')) ? old('amount') : '' }}" class="rounded-md border border-[var(--border-input)] bg-[var(--bg-surface)] px-3 py-2 text-[var(--text-ink)]">
                </label>
                <label class="flex flex-1 flex-col gap-2 text-sm text-[var(--text-ink)]" for="payout-destination">Send to (bank or GCash account name and number)
                    <input id="payout-destination" name="destination" required maxlength="255" value="{{ is_scalar(old('destination')) ? old('destination') : '' }}" placeholder="e.g. GCash, Juana Cruz, 0917 123 4567" class="rounded-md border border-[var(--border-input)] bg-[var(--bg-surface)] px-3 py-2 text-[var(--text-ink)]">
                </label>
                <button class="rounded-md bg-primary-800 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-900" @disabled($availableMinor < 10000)>Request payout</button>
            </form>
        @endif
    </section>
    <section class="rounded-lg border border-[var(--border-input)] bg-[var(--bg-surface)] p-5">
        <h2 class="mb-4 font-serif text-xl text-[var(--text-ink)]">Request history</h2>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-[var(--text-ink)]">
                <thead><tr class="border-b border-[var(--border-input)]"><th scope="col" class="p-3">Request</th><th scope="col" class="p-3">Amount</th><th scope="col" class="p-3">Status</th><th scope="col" class="p-3">Requested</th><th scope="col" class="p-3">Action</th></tr></thead>
                <tbody>
                    @forelse($payouts as $payout)
                        <tr class="border-b border-[var(--border-input)]">
                            <td class="p-3">#{{ $payout->id }}</td>
                            <td class="p-3">₱{{ number_format($payout->amount_minor / 100, 2) }}</td>
                            <td class="p-3">
                                {{ ucfirst(strtolower($payout->status)) }}
                                @if($payout->status === \App\Models\Seller\SellerPayoutRequest::PAID)
                                    <span class="block text-xs text-[var(--text-muted)]">Reference: {{ $payout->payout_reference }}</span>
                                @elseif($payout->status === \App\Models\Seller\SellerPayoutRequest::REJECTED)
                                    <span class="block text-xs text-[var(--text-muted)]">{{ $payout->rejection_reason }}</span>
                                @endif
                            </td>
                            <td class="p-3">{{ $payout->created_at->timezone('Asia/Manila')->format('M d, Y h:i A') }}</td>
                            <td class="p-3">@if($payout->status === \App\Models\Seller\SellerPayoutRequest::PENDING)
                                <form method="POST" action="{{ route('seller.payout-requests.destroy', $payout) }}">@csrf @method('DELETE')<button class="font-semibold text-primary-800 underline">Cancel request</button></form>
                            @else<span class="text-[var(--text-muted)]">—</span>@endif</td>
                        </tr>
                    @empty<tr><td colspan="5" class="p-3 text-[var(--text-muted)]">No payout requests yet.</td></tr>@endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $payouts->links() }}</div>
    </section>
</div>
@endsection
