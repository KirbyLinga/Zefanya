@extends('Admin.Layouts.app')
@section('title', 'Address check '.$review->reference())
@section('content')
    @php($card = 'mt-4 rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-5 text-sm text-[var(--text-ink)]')
    @php($parcel = $review->sellerOrder)
    @php($address = $parcel?->order?->shipping_address ?? [])
    <a class="text-sm font-semibold text-primary-800 underline" href="{{ route('admin.address-reviews.index') }}">Back to address checks</a>
    <h1 class="mt-4 text-2xl font-semibold text-[var(--text-ink)]">Address check {{ $review->reference() }}</h1>
    <p class="mt-1 text-sm text-[var(--text-muted)]">{{ $review->statusLabel() }} · Order {{ $parcel?->order?->reference }} · shipment #{{ $review->seller_order_id }} · {{ $parcel?->status?->label((int) $parcel?->delivery_attempts) }}</p>
    <x-logistics-flash :messages="['success', 'warning', 'error']" />
    <x-form-errors :show="$errors->any()" :messages="collect($errors->getMessages())->except(['decision', 'call_outcome', 'decision_note'])->flatten()->all()" />

    <section class="{{ $card }}" aria-labelledby="route-heading">
        <h2 id="route-heading" class="font-semibold">Why this shipment is held</h2>
        <p class="mt-2">From {{ $review->seller?->business_name ?? 'Seller' }} in {{ $review->seller_province }} ({{ $review->regionLabel($review->seller_region) }})
            to {{ $review->buyer_province }} ({{ $review->regionLabel($review->buyer_region) }}). These regions are not the same or neighbouring, so the address is confirmed by phone before pickup.</p>
        <p class="mt-2 text-xs text-[var(--text-muted)]">A distant address is not by itself a sign of fraud. The check confirms the Buyer is reachable and the address is complete.</p>
    </section>

    <section class="{{ $card }}" aria-labelledby="buyer-heading">
        <h2 id="buyer-heading" class="font-semibold">Call the Buyer</h2>
        <p class="mt-2">{{ $address['name'] ?? $review->buyer?->fullName() }} · <span class="font-semibold">{{ $review->contact_no ?? 'No phone on the order' }}</span>@if ($review->buyer?->contact_no && $review->buyer->contact_no !== $review->contact_no) · account phone {{ $review->buyer->contact_no }}@endif</p>
        <p class="mt-2 whitespace-pre-wrap break-words">{{ $address['full'] ?? '—' }}</p>
        <p class="mt-2 text-xs text-[var(--text-muted)]">Ask the Buyer to state the street, barangay and city, and a landmark. Do not read the full address out first. Confirm they placed order {{ $parcel?->order?->reference }}.</p>
        <p class="mt-3">Account status: {{ ucfirst((string) ($review->buyer?->status ?? 'unknown')) }} · Delivered orders: {{ $history['delivered'] }}
            @if ($history['codStrikes'] !== null) · COD delivery strikes: {{ $history['codStrikes'] }} of {{ $history['codStrikeLimit'] }} @endif</p>
        @if ($parcel?->orderItems?->isNotEmpty())
            <p class="mt-2 text-xs text-[var(--text-muted)]">Items: {{ $parcel->orderItems->map(fn ($item) => $item->product_name.' ×'.$item->quantity)->implode(', ') }}</p>
        @endif
    </section>

    <section class="{{ $card }}" aria-labelledby="decision-heading">
        <h2 id="decision-heading" class="font-semibold">Result</h2>
        @if ($review->decided_at)
            <p class="mt-2"><span class="font-semibold">{{ $review->statusLabel() }}</span> · {{ \App\Models\Shared\OrderAddressReview::CALL_OUTCOMES[$review->call_outcome] ?? '' }} · {{ $review->decider?->name ?? 'Admin #'.$review->decided_by }} · {{ manila($review->decided_at, 'M j, Y g:i A') }}</p>
            @if ($review->decision_note)<p class="mt-2 whitespace-pre-wrap break-words">{{ $review->decision_note }}</p>@endif
        @endif
        @if ($review->status === 'confirmed')
            <p class="mt-2 text-xs text-[var(--text-muted)]">Confirmed checks are final. The shipment is released for pickup.</p>
        @else
            @if ($review->status === 'not_confirmed')
                <p class="mt-2 text-xs text-[var(--text-muted)]">Still on hold. If the Buyer later confirms the address (for example, by calling back), record it below to release the shipment. The Seller may decline the order instead.</p>
            @endif
            <form method="POST" action="{{ route('admin.address-reviews.decide', $review->id) }}" class="mt-4 space-y-3" data-submit-guard>
                @csrf
                <label class="block" for="call-outcome">Call result <x-required-marker />
                    <select id="call-outcome" name="call_outcome" required @if ($errors->has('call_outcome')) aria-invalid="true" aria-describedby="call-outcome-error" @endif class="mt-1 block min-h-11 w-full rounded-md border bg-[var(--bg-surface)] p-2">
                        <option value="">Choose the call result</option>
                        @foreach (\App\Models\Shared\OrderAddressReview::CALL_OUTCOMES as $value => $label)
                            <option value="{{ $value }}" @selected(old('call_outcome') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <x-field-error id="call-outcome-error" :messages="$errors->get('call_outcome')" />
                <fieldset>
                    <legend class="font-semibold">Decision <x-required-marker /></legend>
                    <label class="mt-2 flex items-start gap-2"><input type="radio" name="decision" value="confirmed" required class="mt-1" @checked(old('decision') === 'confirmed')>
                        <span><span class="font-semibold">Address confirmed</span><span class="block text-xs text-[var(--text-muted)]">Releases the shipment for pickup. Only allowed when the call reached the Buyer and they confirmed the address.</span></span></label>
                    <label class="mt-2 flex items-start gap-2"><input type="radio" name="decision" value="not_confirmed" required class="mt-1" @checked(old('decision') === 'not_confirmed')>
                        <span><span class="font-semibold">Not confirmed</span><span class="block text-xs text-[var(--text-muted)]">Keeps the hold. The Seller is told they may decline; the Buyer is asked to contact support. Note required.</span></span></label>
                </fieldset>
                <x-field-error id="decision-error" :messages="$errors->get('decision')" />
                <label class="block" for="decision-note">Note (required for Not confirmed; 10–1000 characters)
                    <textarea id="decision-note" name="decision_note" minlength="10" maxlength="1000" rows="3" @if ($errors->has('decision_note')) aria-invalid="true" aria-describedby="decision-note-error" @endif class="mt-1 block w-full rounded-md border bg-[var(--bg-surface)] p-2">{{ old('decision_note') }}</textarea>
                </label>
                <p class="text-xs text-[var(--text-muted)]">Internal note for Admin. It is not sent to the Buyer or Seller.</p>
                <x-field-error id="decision-note-error" :messages="$errors->get('decision_note')" />
                <button type="submit" class="min-h-11 rounded-lg bg-primary-800 px-4 py-2 font-semibold text-white hover:bg-primary-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-800 disabled:cursor-wait disabled:opacity-60">Record result</button>
            </form>
        @endif
    </section>

    <section class="{{ $card }}" aria-labelledby="other-heading">
        <h2 id="other-heading" class="font-semibold">Other address checks for this Buyer</h2>
        <ul class="mt-3 space-y-2">
            @forelse ($history['otherReviews'] as $row)
                <li class="rounded-md border border-[var(--border-strong)] p-3"><a class="text-primary-800 underline" href="{{ route('admin.address-reviews.show', $row->id) }}">{{ $row->reference() }}</a> · {{ $row->buyer_province }} · {{ $row->statusLabel() }} · {{ manila($row->created_at, 'M j, Y') }}</li>
            @empty
                <li>None.</li>
            @endforelse
        </ul>
    </section>

    <section class="{{ $card }}" aria-labelledby="audit-heading">
        <h2 id="audit-heading" class="font-semibold">Audit history</h2>
        <ul class="mt-3 space-y-2">
            @forelse ($history['audit'] as $log)
                <li class="rounded-md border border-[var(--border-strong)] p-3">{{ $log->actionLabel() }} · {{ $log->actor_name }} · {{ manila($log->created_at, 'M j, Y g:i A') }}
                    @foreach ($log->changes() as $change)<span class="block text-xs text-[var(--text-muted)]">{{ $change['field'] }}: {{ $change['old'] ?? '—' }} → {{ $change['new'] ?? '—' }}</span>@endforeach
                    @if ($log->reason)<p class="mt-1 whitespace-pre-wrap break-words">{{ $log->reason }}</p>@endif
                </li>
            @empty
                <li>No results recorded yet.</li>
            @endforelse
        </ul>
    </section>
@endsection
