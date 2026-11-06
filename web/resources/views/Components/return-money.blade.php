@props(['record', 'audience' => 'admin'])
@php
    use App\Models\Logistics\ParcelReturn;
    use App\Models\Seller\SellerDebt;

    $order = $record->sellerOrder;
    $peso = fn (?int $minor): string => '₱'.number_format(((int) $minor) / 100, 2);
    $paid = (int) ($order->payment?->amount_minor ?? 0);
    $shipping = (int) ($order->shipping_fee_minor ?? 0);
    $decided = $record->releasesPendingEarnings() || $record->fault_party !== null;
    $debt = $order->sellerDebt;
    $isSeller = $audience === 'seller';
    $failedDelivery = $record->isFailedDeliveryReturn()
        && ! \App\Models\Logistics\CodRemittance::query()->where('seller_order_id', $order->id)->exists();
@endphp
@if ($failedDelivery)
    <div data-return-money class="mt-4 space-y-1.5 rounded-md border border-[var(--border-strong)] bg-[var(--bg-page)] p-3 text-xs text-[var(--text-muted)]">
        <p class="font-semibold text-[var(--text-ink)]">Who pays for this return</p>
        <p class="text-[var(--text-ink)]">Failed delivery sent back to {{ $isSeller ? 'you' : 'the Seller' }}. No money was collected from the buyer, so there is nothing to refund.</p>
        <p>The delivery attempt and the trip back ({{ $peso(2 * $shipping) }}, twice the {{ $peso($shipping) }} delivery fee) are charged to {{ $isSeller ? 'you' : 'the Seller' }} once the parcel is received, and Zefanya pays the Logistics company for both trips.</p>
        @if ($debt && $debt->reason === SellerDebt::FAILED_DELIVERY && (int) $debt->amount_minor > 0)
            <p class="text-[var(--text-ink)]">{{ $peso($debt->amount_minor) }} is taken from {{ $isSeller ? 'your' : 'their' }} next payouts ({{ $peso($debt->outstandingMinor()) }} still to repay).</p>
        @endif
    </div>
@elseif ($record->financial_review_required && ($decided || ! $isSeller))
    <div data-return-money class="mt-4 space-y-1.5 rounded-md border border-[var(--border-strong)] bg-[var(--bg-page)] p-3 text-xs text-[var(--text-muted)]">
        <p class="font-semibold text-[var(--text-ink)]">Who pays for this return</p>
        @if ($isSeller)
            @if ($record->isLogisticsFault())
                <p class="text-[var(--text-ink)]">Logistics: the parcel was damaged in transit. You are not charged for this return.</p>
            @elseif ($record->fault_party === ParcelReturn::FAULT_SELLER)
                <p class="text-[var(--text-ink)]">You: the item was wrong, defective or not as described. You cover the buyer's refund.</p>
            @endif
        @else
            @php
                $faultSource = match (true) {
                    $record->fault_set_at !== null => ' · set by an admin '.$record->fault_set_at->timezone(\App\Models\Seller\StoreSale::TIMEZONE)->format('M j, Y g:i A'),
                    $record->fault_party !== null => ' · default after approval',
                    default => '',
                };
            @endphp
            <p class="text-[var(--text-ink)]">{{ $record->faultLabel() }}{{ $faultSource }}</p>
            @if ($record->fault_note)<p>Admin note: {{ $record->fault_note }}</p>@endif
            @if ($record->needsAdminFaultDecision())
                <p class="font-semibold text-amber-800">The buyer chose "Other" as the reason. Decide who pays before recording the refund.</p>
            @endif
            @if ($record->isLogisticsFault())
                <p>The Seller is not charged. Once the refund is recorded, the delivering Logistics company owes Zefanya that amount (see Return charges with Logistics).</p>
            @endif
        @endif

        <p class="pt-1 font-semibold text-[var(--text-ink)]">Money</p>
        <p>Buyer paid {{ $peso($paid) }}{{ $shipping > 0 ? ', including '.$peso($shipping).' shipping' : '' }}. A full refund returns all of it; the buyer never pays return shipping.</p>
        @if ($record->refunded_at)
            <p class="text-[var(--text-ink)]">Buyer refunded {{ $peso($record->refund_amount_minor) }}{{ (int) $record->refund_amount_minor < $paid ? ' (partial refund)' : '' }}.</p>
            @if ($record->refund_reason)<p>Why less than the buyer paid: {{ $record->refund_reason }}</p>@endif
        @elseif ($isSeller)
            <p>No refund recorded yet.</p>
        @endif
        @if ($record->releasesPendingEarnings())
            @php
                $breakdown = app(\App\Services\Seller\SellerSettlementService::class)->returnBreakdown($order);
                $charges = $breakdown ? $breakdown['refunded_shipping_minor'] + $breakdown['return_shipping_minor'] : 0;
                $who = $isSeller ? 'You' : 'The Seller';
            @endphp
            @if ($breakdown === null)
                <p class="text-amber-800">{{ $isSeller ? 'Your' : 'The Seller\'s' }} share of this order needs Admin review because the voucher funding is not recorded yet.</p>
            @else
                @if ($breakdown['refunded_shipping_minor'] > 0)
                    <p>Refunded original shipping charged to {{ $isSeller ? 'you' : 'the Seller' }}: {{ $peso($breakdown['refunded_shipping_minor']) }}.</p>
                @endif
                @if ($breakdown['return_shipping_minor'] > 0)
                    <p>Return shipping charged to {{ $isSeller ? 'you' : 'the Seller' }}: {{ $peso($breakdown['return_shipping_minor']) }} (the same as the original delivery fee).</p>
                @endif
                @if ($breakdown['seller_net_minor'] > 0)
                    <p class="text-[var(--text-ink)]">{{ $who }} {{ $isSeller ? 'keep' : 'keeps' }} {{ $peso($breakdown['seller_net_minor']) }} from this order after {{ $peso($breakdown['commission_minor']) }} commission{{ $charges > 0 ? ' and '.$peso($charges).' return charges' : '' }}.</p>
                @elseif ($record->refunded_at)
                    <p class="text-[var(--text-ink)]">{{ $who }} {{ $isSeller ? 'keep' : 'keeps' }} nothing from this order.</p>
                @else
                    <p>{{ $isSeller ? 'Your' : 'The Seller\'s' }} earnings from this order are on hold until the refund is recorded. A smaller refund is charged only for the amount refunded.</p>
                @endif
            @endif
        @endif
        @if ($debt && (int) $debt->amount_minor > 0)
            @if ($debt->reason === SellerDebt::RETURN_CHARGES)
                <p class="text-[var(--text-ink)]">Return charges are more than this order earned, so {{ $peso($debt->amount_minor) }} is taken from {{ $isSeller ? 'your' : 'their' }} next payouts ({{ $peso($debt->outstandingMinor()) }} still to repay).</p>
            @else
                <p class="text-[var(--text-ink)]">{{ $isSeller ? 'You were' : 'The Seller was' }} already paid for this order, so {{ $peso($debt->amount_minor) }} is taken back from {{ $isSeller ? 'your' : 'their' }} next payouts ({{ $peso($debt->outstandingMinor()) }} still to repay).</p>
            @endif
        @endif
    </div>
@endif
