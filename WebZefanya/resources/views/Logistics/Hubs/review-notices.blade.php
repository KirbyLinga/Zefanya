@if ($hub->pendingLocationChange)
    <div class="mt-3 rounded-sm border border-amber-200 bg-amber-50 px-3 py-2 font-sans text-[11px] leading-relaxed text-amber-900">
        <p class="font-semibold">Location change awaiting Admin approval</p>
        <p class="mt-0.5 break-words">Proposed: {{ $hub->pendingLocationChange->proposedAddress() }}</p>
        <p class="mt-0.5">The address above remains the approved, operational location until reviewed.</p>
    </div>
@endif
@if ($hub->isRejected() && $hub->rejection_reason)
    <p class="mt-3 rounded-sm border border-red-200 bg-red-50 px-3 py-2 font-sans text-xs leading-relaxed text-red-900"><strong>Rejection Reason:</strong> {{ $hub->rejection_reason }}</p>
@endif
