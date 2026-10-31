@extends('Admin.Layouts.app')
@section('title', 'Return Exceptions')
@section('content')
<x-admin.tabs :tabs="[
    ['label' => 'Incidents', 'route' => 'admin.incidents.index'],
    ['label' => 'Return exceptions', 'route' => 'admin.returns.index'],
]" />
<h1 class="mb-6 font-serif text-2xl text-[var(--text-ink)]">Return Exceptions and Custody</h1>
<x-logistics-flash :messages="['success', 'warning', 'error']" />
@if ($errors->any())<div role="alert" class="mb-4 rounded-md border border-red-200 bg-red-50 p-3 text-xs text-red-900">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif

<div class="space-y-5">@forelse ($returns as $record)
<article class="rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-5">
    <x-return-summary :record="$record" />
    <div class="mt-4 space-y-2 text-xs text-[var(--text-muted)]">
        @foreach ($record->sellerOrder->manifestItems as $item)
            @if ($item->manifest?->direction === 'return')<p>Reverse manifest #{{ $item->manifest_id }} | {{ $item->manifest->status->value }} | Dispatch {{ $item->manifest->dispatched_at?->format('M j g:i A') ?? 'pending' }} | Receipt {{ $item->received_at?->format('M j g:i A') ?? 'pending' }}</p>@endif
        @endforeach
        @foreach ($record->sellerOrder->parcelHandoffs as $handoff)
            @if ($handoff->direction === 'return')<p>Reverse handoff #{{ $handoff->id }} | {{ $handoff->status->value }} | Dispatch {{ $handoff->dispatched_at?->format('M j g:i A') ?? 'pending' }} | Receipt {{ $handoff->received_at?->format('M j g:i A') ?? 'pending' }}</p>@endif
        @endforeach
    </div>

    @if ($record->inDispute())
        <p class="mt-3 text-xs font-semibold text-[var(--text-ink)]">In Dispute. The seller is locked out until you resolve this.</p>
        <p class="mt-1 text-xs text-[var(--text-muted)]">Seller's rejection: {{ $record->seller_decision_reason }}</p>
        <form method="POST" action="{{ route('admin.returns.dispute', $record) }}" class="mt-3 space-y-2">@csrf
            <fieldset class="flex flex-wrap gap-4 text-xs text-[var(--text-ink)]">
                <label class="flex items-center gap-2"><input type="radio" name="outcome" value="upheld" required> Keep the rejection</label>
                <label class="flex items-center gap-2"><input type="radio" name="outcome" value="overturned"> Approve the return</label>
            </fieldset>
            <label class="block text-xs" for="dispute-note-{{ $record->id }}">Resolution note</label>
            <textarea id="dispute-note-{{ $record->id }}" name="note" required minlength="10" maxlength="1000" class="block w-full rounded-sm border border-[var(--border-strong)] p-2 text-xs"></textarea>
            <button type="submit" class="rounded-sm bg-primary-700 px-3 py-2 text-xs text-white">Resolve dispute</button>
        </form>
    @endif
    @if ($record->exception_reason)
        <p class="mt-3 text-xs text-red-800">Unresolved: {{ $record->exception_reason }}</p>
        <form method="POST" action="{{ route('admin.returns.resolve', $record) }}" class="mt-3 flex flex-wrap gap-2">@csrf<input name="resolution_note" required maxlength="1000" placeholder="Resolution evidence" aria-label="Resolution evidence" class="min-w-0 flex-1 rounded-sm border border-[var(--border-strong)] p-2 text-xs"><button type="submit" class="rounded-sm bg-primary-700 px-3 py-2 text-xs text-white">Record exception resolution</button></form>
    @else<p class="mt-3 text-xs text-[var(--text-muted)]">No unresolved routing exception. Resolution does not confirm Seller receipt.</p>@endif
    @if (blank($record->seller_address['full'] ?? null) && $record->status->value === 'RETURN_REQUESTED')
        <form method="POST" action="{{ route('admin.returns.origin', $record) }}" class="mt-4 grid gap-2 sm:grid-cols-2">@csrf
            <label class="text-xs">Verified original hub<select name="origin_hub_id" required class="mt-1 block w-full border p-2">@foreach ($hubs as $hub)<option value="{{ $hub->id }}">{{ $hub->provider?->business_name }} | {{ $hub->name }}</option>@endforeach</select></label>
            <label class="text-xs">Original barangay code<input name="barangay_code" required maxlength="20" class="mt-1 block w-full border p-2"></label>
            <label class="text-xs">Original pickup address<input name="address" required maxlength="1000" class="mt-1 block w-full border p-2"></label>
            <label class="text-xs">Historical verification evidence<input name="evidence" required maxlength="1000" class="mt-1 block w-full border p-2"></label>
            <button type="submit" class="rounded-sm bg-primary-700 px-3 py-2 text-xs text-white">Verify original location</button>
        </form>
    @endif
    @if ($record->financial_review_required)
        @if ($record->refunded_at)
            <p class="mt-3 text-xs text-[var(--text-ink)]">Refund recorded {{ $record->refunded_at->timezone('Asia/Manila')->format('M j, Y g:i A') }}. Reference: {{ $record->refund_reference }}. Pending earnings and commission have left this shipment.</p>
        @else
            <form method="POST" action="{{ route('admin.returns.refund', $record) }}" class="mt-3 flex flex-wrap gap-2">@csrf
                <input name="reference" required maxlength="255" placeholder="COD refund reference" aria-label="COD refund reference" class="min-w-0 flex-1 rounded-sm border border-[var(--border-strong)] p-2 text-xs">
                <button type="submit" class="rounded-sm bg-primary-700 px-3 py-2 text-xs text-white">Record refund</button>
            </form>
            <p class="mt-2 text-xs text-amber-800">Approving this return, or recording the refund, releases pending earnings and commission. Shipping is not reimbursed here.</p>
        @endif
    @endif
    @if ($record->seller_received_at && ! $record->stock_restored_at)
        <p class="mt-3 text-xs text-amber-800">Inventory held | {{ $record->stock_condition }}</p>
        @if ($record->stock_condition === 'resellable' && config('returns.restore_verified_resellable') === true)
            <form method="POST" action="{{ route('admin.returns.stock', $record) }}" class="mt-2">@csrf<button class="rounded-sm bg-primary-700 px-3 py-2 text-xs text-white" type="submit">Release approved resellable stock</button></form>
        @endif
    @endif
    <ol class="mt-4 space-y-1 text-xs text-[var(--text-muted)]">@foreach ($record->events as $event)<li>{{ $event->occurred_at->format('M j, Y g:i A') }} | {{ $event->event_key }} | {{ $event->actor_type }} #{{ $event->actor_id }}</li>@endforeach</ol>
    <ul class="mt-3 space-y-1 text-xs">@foreach ($record->sellerOrder->parcelHandoffExceptions as $exception)<li>{{ $exception->status }} | {{ $exception->resolution_note ?? $exception->reason }}</li>@endforeach</ul>
</article>
@empty<p class="text-sm text-[var(--text-muted)]">No return exceptions or physical returns.</p>@endforelse</div>
<div class="mt-4">{{ $returns->links() }}</div>
@endsection
