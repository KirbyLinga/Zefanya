{{-- First delivery-rider assignment for a sorted parcel. Assign Deliveries is the
     one place riders are assigned; Parcel Sorting links here once a parcel is sorted. --}}
@php
    $dialogId = 'assign-rider-'.$shipment->id;
    $assignable = $availableCouriers->filter(fn ($candidate): bool => $shipment->destination_area_id === null
        || (int) $candidate->rider?->station_hub_id === (int) $shipment->destinationArea?->station_hub_id)->values();
    $weight = $shipment->parcelWeightGrams();
    $capacityReason = function ($candidate) use ($shipment, $weight): ?string {
        if ($candidate->isAtJobLimit($shipment)) return 'At the active job limit.';
        $carried = $candidate->activeWeightGrams() - ($candidate->holdsActiveJob($shipment) ? (int) $shipment->parcel_weight_grams : 0);
        if ((int) $candidate->max_weight_grams < $weight || $carried + $weight > (int) $candidate->max_weight_grams) return 'Not enough remaining weight capacity.';
        return null;
    };
    $hasCapacity = $availableCouriers->contains(fn ($candidate) => $capacityReason($candidate) === null);
    $blockReason = $assignmentNetworkBlock
        ?: (blank($shipment->buyerBarangayCode()) ? 'The delivery barangay is not verified for this parcel.'
        : ($availableCouriers->isEmpty() ? 'No approved, online Rider is available in your fleet.'
        : (! $hasCapacity ? 'No online Rider has enough remaining job and weight capacity.' : null)));
@endphp
<button type="button" data-assign-open="{{ $dialogId }}" class="{{ $buttonClass }}">Assign Rider</button>
<dialog id="{{ $dialogId }}" aria-labelledby="{{ $dialogId }}-title" aria-describedby="{{ $dialogId }}-help"
    class="m-auto w-[min(26rem,calc(100vw-2rem))] rounded-xl border border-[var(--border-strong)] bg-[var(--bg-surface)] p-0 text-left text-[var(--text-ink)] shadow-xl backdrop:bg-black/40">
    <form method="POST" action="{{ route('logistics.parcel-sorting.assignDelivery', $shipment) }}" class="p-5">
        @csrf
        <div class="flex items-start justify-between gap-3">
            <div>
                <h2 id="{{ $dialogId }}-title" class="font-serif text-lg text-[var(--text-ink)]">Assign Rider</h2>
                <p class="mt-1 font-sans text-[12px] text-[var(--text-muted)]">
                    Parcel #{{ $shipment->id }}@if ($shipment->destinationArea) · {{ $shipment->destinationArea->name }}@endif
                </p>
            </div>
            <button type="button" data-assign-close aria-label="Close" class="shrink-0 cursor-pointer rounded-md p-1 text-[var(--text-muted)] hover:bg-[var(--bg-page)] hover:text-[var(--text-ink)]">
                <i data-lucide="x" class="h-4 w-4"></i>
            </button>
        </div>

        <div id="{{ $dialogId }}-help" class="mt-4 space-y-2 font-sans text-[12px] text-[var(--text-muted)]">
            @if ($blockReason)
                <p role="status" class="rounded-lg border border-[var(--border-strong)] p-3 text-[var(--text-ink)]">Assignment unavailable: {{ $blockReason }}</p>
            @else
                <p>Available Riders are checked again for coverage, workload and weight when assigned.</p>
            @endif
            <p><a class="font-semibold text-[var(--link)] underline" href="{{ route('logistics.riders.index') }}">Review Rider availability</a> &middot; <a class="font-semibold text-[var(--link)] underline" href="{{ route('logistics.areas.index') }}">Review barangay coverage</a>@if (blank($shipment->buyerBarangayCode())) &middot; <a class="font-semibold text-[var(--link)] underline" href="{{ route('logistics.parcel-sorting.address.preview', $shipment) }}">Verify delivery address</a>@endif</p>
        </div>
        <div class="mt-4 max-h-72 space-y-2 overflow-y-auto pr-1">
            <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-[var(--border-strong)] p-3 hover:bg-[var(--bg-page)]">
                <input type="radio" name="courier_id" value="" checked @disabled($blockReason) class="mt-0.5 accent-primary-700">
                <span class="font-sans text-[13px] text-[var(--text-ink)]">
                    Auto-assign by area
                    <span class="block text-[12px] text-[var(--text-muted)]">Nearest eligible Rider: linked to this barangay first, then its municipality, then station-wide.</span>
                </span>
            </label>
            @forelse ($assignable as $candidate)
                @php($candidateBlock = $capacityReason($candidate))
                <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-[var(--border-strong)] p-3 hover:bg-[var(--bg-page)]">
                    <input type="radio" name="courier_id" value="{{ $candidate->id }}" @disabled($blockReason || $candidateBlock) @if ($candidateBlock) aria-describedby="{{ $dialogId }}-rider-{{ $candidate->id }}-reason" @endif class="mt-0.5 accent-primary-700">
                    <span class="font-sans text-[13px] text-[var(--text-ink)]">
                        {{ $candidate->rider?->fullName() ?? $candidate->name }}
                        <span class="block text-[12px] text-[var(--text-muted)]">
                            {{ $candidate->rider?->station?->name ?? 'No station' }} · {{ $candidate->activeWorkload() }}/{{ $candidate::ACTIVE_CAPACITY }} jobs · carrying {{ number_format($candidate->activeWeightGrams()) }} of {{ number_format($candidate->max_weight_grams) }} g
                        </span>
                        @if ($candidateBlock)<span id="{{ $dialogId }}-rider-{{ $candidate->id }}-reason" class="block text-[12px] text-[var(--status-danger)]">{{ $candidateBlock }}</span>@endif
                    </span>
                </label>
            @empty
                <p class="rounded-lg border border-[var(--border-strong)] p-3 font-sans text-[12px] text-[var(--text-muted)]">
                    No online Rider is listed for this parcel's recorded Station Hub. Automatic assignment is checked against the delivery barangay when submitted.
                </p>
            @endforelse
        </div>

        <div class="mt-5 flex justify-end gap-2">
            <button type="button" data-assign-close class="{{ $quietButton }}">Cancel</button>
            <button type="submit" @disabled($blockReason) class="{{ $buttonClass }} disabled:cursor-not-allowed disabled:opacity-50">Assign Rider</button>
        </div>
    </form>
</dialog>
