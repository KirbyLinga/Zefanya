@extends('Layouts.logistics')
@section('title', 'Physical Return')
@section('content')
<a href="{{ route('logistics.returns.index') }}" class="text-xs underline">All physical returns</a>
<h1 class="my-5 font-serif text-2xl text-[var(--text-ink)]">Physical Return #{{ $return->id }}</h1>
<x-logistics-flash :messages="['success', 'warning', 'error']" />
@if ($errors->any())<div role="alert" class="mb-4 rounded-md border border-red-200 bg-red-50 p-3 text-xs text-red-900">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif

@if (session('handover_code'))<div role="status" class="mb-5 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm">Physical handover code: <strong class="font-mono">{{ session('handover_code') }}</strong><p>Give this code only to the owning Seller at physical handover. It expires in ten minutes.</p></div>@endif
<div class="grid gap-5 lg:grid-cols-2">
<section class="rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-5"><x-return-summary :record="$return" />
    <div class="mt-4 space-y-2 text-xs text-[var(--text-muted)]">
        @foreach ($return->sellerOrder->manifestItems as $item)
            @if ($item->manifest?->direction === 'return')<p><a href="{{ route('logistics.manifests.show', $item->manifest_id) }}" class="underline">Reverse manifest #{{ $item->manifest_id }}</a> | {{ $item->manifest->status->value }} | Dispatch {{ $item->manifest->dispatched_at?->format('M j g:i A') ?? 'pending' }} | Receipt {{ $item->received_at?->format('M j g:i A') ?? 'pending' }}</p>@endif
        @endforeach
        @foreach ($return->sellerOrder->parcelHandoffs as $handoff)
            @if ($handoff->direction === 'return')<p>Reverse handoff #{{ $handoff->id }} | {{ $handoff->status->value }} | Dispatch {{ $handoff->dispatched_at?->format('M j g:i A') ?? 'pending' }} | Receipt {{ $handoff->received_at?->format('M j g:i A') ?? 'pending' }}</p><a href="{{ route('logistics.handoffs.show', $handoff) }}" class="underline">Open verified reverse handoff</a>@endif
        @endforeach
    </div>

    @if ($return->exception_reason)<p class="mt-4 text-xs text-amber-800">Exception: {{ $return->exception_reason }}</p>@endif
    <ol class="mt-5 space-y-2 text-xs text-[var(--text-muted)]">@foreach ($return->events as $event)<li>{{ $event->occurred_at->format('M j, Y g:i A') }} | {{ str_replace('_', ' ', $event->event_key) }} | {{ $event->actor_type }} #{{ $event->actor_id }}</li>@endforeach</ol>
</section>
@if ($canOperate && $return->awaitingBuyerCollection())
<section class="space-y-3 rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-5">
    <h2 class="text-sm font-semibold text-[var(--text-ink)]">Collect from the buyer</h2>
    <p class="text-xs text-[var(--text-muted)]">This parcel is still with the buyer at {{ $return->sellerOrder->order?->shipping_address['full'] ?? 'an address that requires review' }}. Reverse transport opens once the rider confirms collection.</p>
    @if ($return->collectionCourier)
        <p class="text-xs font-semibold text-[var(--text-ink)]">Assigned to {{ $return->collectionCourier->rider?->fullName() ?? 'a rider' }}. You can reassign until it is collected.</p>
    @endif
    @if ((int) $return->collection_failed_attempts > 0)
        <div class="rounded-md border border-amber-300 bg-amber-50 p-3 text-xs text-amber-900">
            <p class="font-semibold">Missed collections: {{ $return->collection_failed_attempts }} of {{ \App\Models\Logistics\ParcelReturn::MAX_COLLECTION_ATTEMPTS }}</p>
            @foreach ($return->events->filter(fn ($event) => str_starts_with($event->event_key, 'collection_failed')) as $event)
                <p>{{ $event->occurred_at->timezone('Asia/Manila')->format('M j, Y g:i A') }} · Rider #{{ $event->actor_id }} · {{ $return->collectionFailureLabel($event->metadata['reason'] ?? null) }}@if (! empty($event->metadata['notes'])) · {{ $event->metadata['notes'] }}@endif</p>
            @endforeach
        </div>
    @endif
    @if ($return->collectionEscalated())
        <p class="text-xs font-semibold text-amber-800">Collection failed {{ \App\Models\Logistics\ParcelReturn::MAX_COLLECTION_ATTEMPTS }} times. Admin decides this return; no rider can be sent until then.</p>
    @else
    <form method="POST" action="{{ route('logistics.returns.assign-collection', $return) }}" class="space-y-3">@csrf
        <label class="block text-xs">Rider
            <select name="courier_id" required class="mt-2 block w-full rounded-sm border border-[var(--border-strong)] bg-[var(--bg-surface)] p-2">
                <option value="">Choose a rider</option>
                @foreach ($collectionCouriers as $courier)
                    <option value="{{ $courier->id }}" @selected((int) $return->collection_courier_id === (int) $courier->id)>{{ $courier->rider?->fullName() }}</option>
                @endforeach
            </select>
        </label>
        <button type="submit" class="rounded-sm bg-primary-700 px-3 py-2 text-xs text-white">Assign rider</button>
    </form>
    @endif
</section>
@elseif ($canOperate && $return->awaitingSellerDecision())
<section class="rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-5">
    <p class="text-xs text-[var(--text-muted)]">{{ $return->movementBlockedReason() }}</p>
</section>
@elseif ($canOperate && $return->receiptDisputed())
<section class="rounded-lg border border-amber-300 bg-amber-50 p-5 text-xs text-amber-900">
    <p class="font-semibold">The Seller reported a problem at handover: {{ \App\Models\Logistics\ParcelReturn::RECEIPT_DISPUTE_ISSUES[$return->receipt_dispute_issue] ?? 'Other' }}.</p>
    <p class="mt-1">{{ $return->receipt_dispute_note }}</p>
    <p class="mt-2">Keep the parcel at {{ $return->originHub?->name ?? 'the origin hub' }}. Admin will send it back to handover or close the return.</p>
</section>
@elseif ($canOperate)
<section class="space-y-5">
    @if (in_array($return->status->value, ['RETURN_REQUESTED','RETURN_IN_TRANSIT']))
        <form method="POST" action="{{ route('logistics.returns.manifest', $return) }}" class="rounded-lg border border-[var(--border-strong)] p-4">@csrf
            <label class="block text-xs">Reverse internal transport destination<select name="destination_hub_id" required class="mt-2 block w-full rounded-sm border border-[var(--border-strong)] bg-[var(--bg-surface)] p-2 text-xs">@foreach ($hubs as $hub)<option value="{{ $hub->id }}">{{ $hub->name }}</option>@endforeach</select></label>
            <button type="submit" class="mt-3 rounded-sm bg-primary-700 px-3 py-2 text-xs text-white">Create reverse manifest</button>
        </form>
        @if ((int) $return->origin_provider_id !== (int) $provider->id)
            <form method="POST" action="{{ route('logistics.returns.handoff', $return) }}">@csrf<button type="submit" class="rounded-sm bg-primary-700 px-3 py-2 text-xs text-white">Request or retry authorized reverse handoff</button></form>
            <a href="{{ route('logistics.partnerships.index') }}" class="block text-xs underline">Review directed reverse partnerships</a>
        @endif
    @endif
    @if ((int) $return->origin_provider_id === (int) $provider->id)
        @php($phase = in_array($return->status->value, ['RETURN_REQUESTED','RETURN_IN_TRANSIT']) ? 'origin' : 'handover')
        <x-parcel-scanner :verify-url="route('logistics.returns.scan', [$return, 'phase' => $phase])" heading="Verify physical return handover" subheading="A Logistics scan records origin custody or prepares handover. Only the owning Seller can confirm Seller receipt." />
    @endif
</section>
@endif
@if ($canOperate && $return->inReturnNetwork() && \App\Models\Logistics\ParcelReturn::recoveryAvailable())
<section class="rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-5 lg:col-span-2">
    <h2 class="text-sm font-semibold text-[var(--text-ink)]">Parcel missing or damaged?</h2>
    <p class="mt-1 text-xs text-[var(--text-muted)]">Report it to Admin. The parcel is put on hold and stays in your custody record until Admin decides. Nothing is restocked or refunded automatically.</p>
    <form method="POST" action="{{ route('logistics.returns.report-problem', $return) }}" enctype="multipart/form-data" class="mt-3 grid gap-2 sm:grid-cols-2">@csrf
        <label class="text-xs">Problem<select name="type" required class="mt-1 block w-full rounded-sm border border-[var(--border-strong)] bg-[var(--bg-surface)] p-2"><option value="missing">Missing</option><option value="damaged">Damaged</option></select></label>
        <label class="text-xs">Short reason<input name="reason" required maxlength="255" class="mt-1 block w-full rounded-sm border border-[var(--border-strong)] p-2"></label>
        <label class="text-xs sm:col-span-2">Details<textarea name="description" maxlength="1000" rows="2" class="mt-1 block w-full rounded-sm border border-[var(--border-strong)] p-2"></textarea></label>
        <label class="text-xs sm:col-span-2">Photo (required for damage)<input type="file" name="photo" accept=".jpg,.jpeg,.png,.webp" class="upload-input"></label>
        <button type="submit" class="rounded-sm border border-red-700 px-3 py-2 text-xs font-semibold text-red-800 sm:col-span-2">Report to Admin</button>
    </form>
</section>
@endif
</div>
@endsection
@push('scripts')@vite('resources/js/parcel-scanner.js')@endpush
