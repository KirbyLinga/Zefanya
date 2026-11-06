{{-- First delivery-rider assignment for a sorted parcel. Assign Deliveries is the
     one place riders are assigned; Parcel Sorting links here once a parcel is sorted. --}}
@php
    $dialogId = 'assign-rider-'.$shipment->id;
    $assignable = $availableCouriers->filter(fn ($candidate): bool => $shipment->destination_area_id === null
        || (int) $candidate->rider?->station_hub_id === (int) $shipment->destinationArea?->station_hub_id)->values();
@endphp
<button type="button" data-assign-open="{{ $dialogId }}" class="{{ $buttonClass }}">Assign Rider</button>
<dialog id="{{ $dialogId }}" aria-labelledby="{{ $dialogId }}-title"
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

        <div class="mt-4 max-h-72 space-y-2 overflow-y-auto pr-1">
            <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-[var(--border-strong)] p-3 hover:bg-[var(--bg-page)]">
                <input type="radio" name="courier_id" value="" checked class="mt-0.5 accent-primary-700">
                <span class="font-sans text-[13px] text-[var(--text-ink)]">
                    Auto-assign by area
                    <span class="block text-[12px] text-[var(--text-muted)]">Nearest eligible Rider: linked to this barangay first, then its municipality, then station-wide.</span>
                </span>
            </label>
            @forelse ($assignable as $candidate)
                <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-[var(--border-strong)] p-3 hover:bg-[var(--bg-page)]">
                    <input type="radio" name="courier_id" value="{{ $candidate->id }}" class="mt-0.5 accent-primary-700">
                    <span class="font-sans text-[13px] text-[var(--text-ink)]">
                        {{ $candidate->rider?->fullName() ?? $candidate->name }}
                        <span class="block text-[12px] text-[var(--text-muted)]">
                            {{ $candidate->rider?->station?->name ?? 'No station' }} · {{ $candidate->activeWorkload() }}/{{ $candidate::ACTIVE_CAPACITY }} jobs · carrying {{ number_format($candidate->activeWeightGrams()) }} of {{ number_format($candidate->max_weight_grams) }} g
                        </span>
                    </span>
                </label>
            @empty
                <p class="rounded-lg border border-[var(--border-strong)] p-3 font-sans text-[12px] text-[var(--text-muted)]">
                    No eligible Rider is available at this Station Hub.
                </p>
            @endforelse
        </div>

        <div class="mt-5 flex justify-end gap-2">
            <button type="button" data-assign-close class="{{ $quietButton }}">Cancel</button>
            <button type="submit" class="{{ $buttonClass }}">Assign Rider</button>
        </div>
    </form>
</dialog>
