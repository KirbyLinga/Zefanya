@php
    $returnRecord = $shipment->parcelReturn;
    $failedAttempts = (int) $shipment->delivery_attempts;
@endphp
@if ($returnRecord || in_array($shipment->status, [\App\Enums\OrderStatus::DeliveryFailed, \App\Enums\OrderStatus::Returned], true))
    <div data-buyer-shipment-next-steps class="mt-4 space-y-2 rounded-xl border border-[var(--border-strong)] bg-[var(--bg-page)] p-4">
        <h3 class="text-sm font-semibold text-[var(--text-ink)]">What happens next</h3>
        @if ($returnRecord)
            @if ($returnRecord->inDispute())
                <p role="status" class="text-sm font-semibold text-[var(--text-ink)]">In Dispute</p>
                <p class="text-xs leading-relaxed text-[var(--text-muted)]">An admin is reviewing the seller's rejection. The seller cannot change it until that review is finished.</p>
                <p class="text-xs text-[var(--text-muted)]"><span class="font-semibold text-[var(--text-ink)]">Seller's reason:</span> {{ $returnRecord->seller_decision_reason }}</p>
            @elseif ($returnRecord->status === \App\Enums\ParcelReturnStatus::Rejected)
                <p role="status" class="text-sm font-semibold text-[var(--text-ink)]">Return request rejected</p>
                <p class="text-xs text-[var(--text-muted)]"><span class="font-semibold text-[var(--text-ink)]">Seller's reason:</span> {{ $returnRecord->seller_decision_reason }}</p>
                @if ($returnRecord->dispute_outcome === \App\Models\Logistics\ParcelReturn::DISPUTE_UPHELD)
                    <p class="text-xs text-[var(--text-muted)]">An admin reviewed this and kept the rejection. {{ $returnRecord->dispute_note }}</p>
                @elseif ($returnRecord->canBuyerEscalate())
                    <p class="text-xs text-[var(--text-muted)]">You can ask an admin to review the rejection from this shipment's order details.</p>
                @endif
            @elseif ($returnRecord->awaitingSellerDecision())
                <p role="status" class="text-sm font-semibold text-[var(--text-ink)]">Return request open</p>
                <p class="text-xs leading-relaxed text-[var(--text-muted)]">{{ $returnRecord->sellerDecisionOverdue() ? 'The seller response deadline has passed. Automatic approval is pending.' : 'Waiting for the seller to review your request.' }} Deadline: {{ $returnRecord->seller_response_due_at->copy()->timezone(\App\Models\Seller\StoreSale::TIMEZONE)->format('M j, Y g:i A') }} Philippine time.</p>
                <p class="text-xs leading-relaxed text-[var(--text-muted)]">Requests without a decision are approved automatically after 3 days. Your parcel is not on its way back yet.</p>
            @elseif ($returnRecord->status === \App\Enums\ParcelReturnStatus::Cancelled)
                <p role="status" class="text-sm font-semibold text-[var(--text-ink)]">Return cancelled</p>
                <p class="text-xs leading-relaxed text-[var(--text-muted)]">{{ $returnRecord->closed_by_type === 'buyer' ? 'You cancelled this return before it was collected.' : 'An admin closed this return before it was collected.' }} You keep the item, so no refund is due. You can confirm receipt of this shipment.</p>
                @if ($returnRecord->closed_by_type === 'admin' && $returnRecord->closed_reason)
                    <p class="text-xs text-[var(--text-muted)]"><span class="font-semibold text-[var(--text-ink)]">Reason:</span> {{ $returnRecord->closed_reason }}</p>
                @endif
            @elseif (in_array($returnRecord->status, [\App\Enums\ParcelReturnStatus::Lost, \App\Enums\ParcelReturnStatus::Damaged], true))
                <p role="status" class="text-sm font-semibold text-[var(--text-ink)]">{{ $returnRecord->status->label() }}</p>
                <p class="text-xs leading-relaxed text-[var(--text-muted)]">Your returned parcel was {{ $returnRecord->status === \App\Enums\ParcelReturnStatus::Lost ? 'lost' : 'damaged' }} after it was collected from you. You are not responsible for it. Zefanya handles your refund.</p>
            @elseif ($returnRecord->status === \App\Enums\ParcelReturnStatus::Closed)
                <p role="status" class="text-sm font-semibold text-[var(--text-ink)]">Return closed by an admin</p>
                <p class="text-xs leading-relaxed text-[var(--text-muted)]">{{ $returnRecord->closed_reason }}</p>
            @elseif ($returnRecord->receiptDisputed())
                <p role="status" class="text-sm font-semibold text-[var(--text-ink)]">Return under review</p>
                <p class="text-xs leading-relaxed text-[var(--text-muted)]">The seller reported a problem when the returned parcel was handed over. An admin is reviewing it; the parcel stays with Logistics meanwhile.</p>
            @elseif ($returnRecord->status === \App\Enums\ParcelReturnStatus::Completed)
                <p role="status" class="text-sm font-semibold text-[var(--text-ink)]">Return completed</p>
                <p class="text-xs text-[var(--text-muted)]">The seller has received the parcel. This is a returned shipment, not a completed delivery.</p>
            @elseif (in_array($returnRecord->status, [\App\Enums\ParcelReturnStatus::AtOrigin, \App\Enums\ParcelReturnStatus::AwaitingSeller], true))
                <p role="status" class="text-sm font-semibold text-[var(--text-ink)]">Awaiting seller receipt</p>
                <p class="text-xs text-[var(--text-muted)]">The parcel has reached the origin return hub. Logistics is arranging the seller's verified physical receipt.</p>
            @elseif ($returnRecord->awaitingBuyerCollection())
                <p role="status" class="text-sm font-semibold text-[var(--text-ink)]">Return request approved</p>
                <p class="text-xs leading-relaxed text-[var(--text-muted)]">{{ $returnRecord->collection_courier_id ? 'A rider has been assigned to collect the parcel from you.' : 'Awaiting collection arrangements from Logistics. A rider will be assigned to collect the parcel from you; please keep it ready.' }} Your parcel is not on its way back yet.</p>
                @if ((int) $returnRecord->collection_failed_attempts > 0)
                    <p class="text-xs leading-relaxed text-[var(--status-warning)]">{{ $returnRecord->collectionEscalated() ? 'The parcel could not be collected after '.$returnRecord->collection_failed_attempts.' tries. An admin will contact you about what happens next.' : 'A rider could not collect the parcel ('.$returnRecord->collection_failed_attempts.' missed '.\Illuminate\Support\Str::plural('try', (int) $returnRecord->collection_failed_attempts).'). Please keep it ready for the next visit.' }}</p>
                @endif
            @elseif ($returnRecord->status === \App\Enums\ParcelReturnStatus::InTransit || $returnRecord->collectedFromBuyer())
                <p role="status" class="text-sm font-semibold text-[var(--text-ink)]">Return in progress</p>
                <p class="text-xs text-[var(--text-muted)]">This parcel is on its way back to the seller.</p>
            @else
                <p role="status" class="text-sm font-semibold text-[var(--text-ink)]">Return in progress</p>
                <p class="text-xs text-[var(--text-muted)]">Logistics is reviewing the return arrangements. Check this timeline for the next update.</p>
            @endif
            @if ($returnRecord->dispute_outcome === \App\Models\Logistics\ParcelReturn::DISPUTE_OVERTURNED)
                <p class="text-xs text-[var(--text-muted)]">An admin overturned the seller's rejection. {{ $returnRecord->dispute_note }}</p>
            @elseif ($returnRecord->seller_decision === \App\Models\Logistics\ParcelReturn::AUTO_APPROVED)
                <p class="text-xs text-[var(--text-muted)]">The seller did not answer in time, so your request was approved automatically.</p>
            @endif
            @if (! $returnRecord->isCancelled() && $returnRecord->isBuyerRequest() && $returnRecord->releasesPendingEarnings() && $returnRecord->status !== \App\Enums\ParcelReturnStatus::Rejected)
                <p class="text-xs text-[var(--text-muted)]">Your refund covers the item and the original shipping fee. You do not pay for return shipping.</p>
            @endif
            @if ($returnRecord->isCancelled())
            @elseif ($returnRecord->refunded_at)
                <p class="text-xs font-semibold text-[var(--text-ink)]">Refund recorded.@if ($returnRecord->refund_amount_minor !== null) Amount: &#8369;{{ number_format($returnRecord->refund_amount_minor / 100, 2) }}.@endif @if ($returnRecord->refund_reference) Reference: {{ $returnRecord->refund_reference }}@endif</p>
                @if ($returnRecord->refund_reason)
                    <p class="text-xs text-[var(--text-muted)]">Why the refund is less than you paid: {{ $returnRecord->refund_reason }}</p>
                @endif
            @elseif ($returnRecord->events()->where('event_key', 'like', 'refund_voided%')->exists())
                <p class="text-xs font-semibold text-[var(--text-ink)]">Your refund transfer did not go through. Zefanya is sending it again; no action is needed from you.</p>
            @elseif ($returnRecord->financial_review_required)
                <p class="text-xs text-[var(--text-muted)]">Your refund is being reviewed. No refund has been recorded yet.</p>
            @else
                <p class="text-xs text-[var(--text-muted)]">No refund has been recorded yet.</p>
            @endif
        @elseif ($shipment->status === \App\Enums\OrderStatus::DeliveryFailed)
            @if ($failedAttempts >= \App\Services\Seller\SellerOrderStatusService::MAX_DELIVERY_ATTEMPTS)
                <p class="text-xs leading-relaxed text-[var(--text-muted)]">Delivery attempts are exhausted. Logistics will arrange return to the seller.</p>
            @elseif ($failedAttempts > 0 && \App\Services\Seller\SellerOrderStatusService::canRetryDelivery($shipment))
                <p class="text-xs leading-relaxed text-[var(--text-muted)]">{{ $failedAttempts }} unsuccessful delivery attempt. {{ \App\Services\Seller\SellerOrderStatusService::MAX_DELIVERY_ATTEMPTS - $failedAttempts }} retry remains. Logistics arranges a retry or return; a retry has not been confirmed.</p>
            @else
                <p class="text-xs leading-relaxed text-[var(--text-muted)]">Delivery needs review. Logistics will confirm the next step; no completed delivery attempt is recorded here.</p>
            @endif
            <p class="text-xs text-[var(--text-muted)]">Check your delivery address and phone number, and contact the seller using Chat in your order details.</p>
        @else
            <p class="text-xs leading-relaxed text-[var(--text-muted)]">This shipment was returned. It is not a delivered or completed shipment. Contact the seller using Chat for the next review step.</p>
            <p class="text-xs text-[var(--text-muted)]">No refund has been recorded yet.</p>
        @endif
    </div>
@endif
@if ($shipment->status === \App\Enums\OrderStatus::Delivered && $shipment->hasActiveReturn())
    <p class="mt-3 text-xs leading-relaxed text-[var(--text-muted)]">Receipt confirmation and automatic completion are paused while this return is open.</p>
@endif
