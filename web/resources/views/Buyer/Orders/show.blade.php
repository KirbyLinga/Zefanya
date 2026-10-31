@extends('Buyer.Layouts.app')

@section('title')
    Order {{ $order->reference }}
@endsection

@use('App\Enums\OrderStatus')

@section('content')
    <div class="bg-[var(--bg-page)] py-8 sm:py-10">
        <div class="mx-auto w-full max-w-[1240px] break-words px-4 sm:px-6 lg:px-8">
            <header class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="min-w-0">
                    <p class="mb-2 font-sans text-sm font-semibold text-[var(--brand)]">Order details</p>
                    <h1 class="break-words font-serif text-2xl leading-tight text-[var(--text-ink)] sm:text-[32px]">{{ $order->reference }}</h1>
                </div>
                <a href="{{ route('buyer.orders.index') }}" class="inline-flex shrink-0 items-center justify-center rounded-xl border border-[var(--border)] bg-[var(--bg-surface)] px-4 py-2.5 font-sans text-sm font-semibold text-[var(--brand)] transition-colors hover:bg-secondary-200 focus:outline-none focus:ring-2 focus:ring-[var(--hover-rose)]">← Back to orders</a>
            </header>

            @if (session('success'))
                <p role="status" class="mb-4 text-sm text-buyer-ink">{{ session('success') }}</p>
            @endif
            @if ($errors->any())
                <p role="alert" class="mb-4 text-sm text-red-600">{{ $errors->first() }}</p>
            @endif
            <div class="grid grid-cols-1 items-start gap-6 lg:grid-cols-[1fr_360px]">
                {{-- ── Left: items + timeline ────────────────────────────────── --}}
                <div class="min-w-0 space-y-6">
                    {{-- Shipping address --}}
                    @php($addr = $order->shipping_address ?? [])
                    <section class="rounded-2xl border border-[var(--border)] bg-[var(--bg-surface)] p-5 shadow-sm sm:p-6">
                        <h2 class="mb-4 font-sans text-sm font-semibold text-[var(--text-ink)]">Shipping address</h2>
                        <p class="font-sans text-sm leading-relaxed text-[var(--text-muted)]">{{ $addr['full'] ?? '—' }}</p>
                        @isset($addr['phone'])
                            <p class="font-sans text-sm leading-relaxed text-[var(--text-muted)] mt-1">{{ $addr['phone'] }}</p>
                        @endisset
                    </section>

                    {{-- Line items across all seller_orders --}}
                    <section class="rounded-2xl border border-[var(--border)] bg-[var(--bg-surface)] p-5 shadow-sm sm:p-6">
                        <h2 class="mb-4 font-sans text-sm font-semibold text-[var(--text-ink)]">Items</h2>
                        <ul class="divide-y divide-[var(--border-strong)]">
                            @foreach ($order->sellerOrders as $sellerOrder)
                                @foreach ($sellerOrder->orderItems as $item)
                                    <li class="flex items-start justify-between gap-4 py-4">
                                        <div class="min-w-0 break-words">
                                            <p class="font-sans text-sm font-semibold text-[var(--text-ink)]">{{ $item->product_name }}</p>
                                            @if ($item->variantDescription() !== '')
                                                <p class="font-sans text-sm text-[var(--text-muted)]">{{ $item->variantDescription() }}</p>
                                            @endif
                                            <p class="font-sans text-sm text-[var(--text-muted)]">QTY × {{ $item->quantity }} · {{ $sellerOrder->seller?->business_name ?? '—' }}</p>
                                        </div>
                                        <span class="shrink-0 font-sans text-sm font-semibold text-[var(--text-ink)]">{{ '₱'.number_format($item->price_minor / 100 * $item->quantity, 2) }}</span>
                                    </li>
                                @endforeach
                            @endforeach
                        </ul>
                        <div class="mt-4 flex justify-end border-t border-[var(--border)] pt-4">
                            <div class="w-full space-y-2 sm:w-[260px]">
                                <p class="flex justify-between gap-4 font-sans text-sm leading-relaxed text-[var(--text-muted)]">
                                    <span>Subtotal</span><span>{{ $order->formattedSubtotal() }}</span>
                                </p>
                                @if ($order->discount_minor > 0)
                                    <p class="flex justify-between gap-4 font-sans text-sm leading-relaxed text-[var(--text-muted)] mt-1">
                                        <span>Discount</span><span>{{ $order->formattedDiscount() }}</span>
                                    </p>
                                @endif
                                <p class="flex justify-between gap-4 font-sans text-lg font-bold text-[var(--text-ink)] mt-2">
                                    <span>Total</span><span>{{ $order->formattedTotal() }}</span>
                                </p>
                            </div>
                        </div>
                    </section>

                    {{-- Status timeline per seller_order --}}
                    @foreach ($order->sellerOrders as $sellerOrder)
                        <section class="rounded-2xl border border-[var(--border)] bg-[var(--bg-surface)] p-5 shadow-sm sm:p-6">
                            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                                <h2 class="font-sans text-sm font-semibold text-[var(--text-ink)]">
                                    {{ $sellerOrder->seller?->business_name ?? 'Seller' }} — Status timeline
                                </h2>
                                @if ($sellerOrder->seller && Auth::guard('buyer')->check())
                                    <button
                                        type="button"
                                        class="chat-with-seller-btn inline-flex shrink-0 items-center justify-center rounded-xl border border-[var(--border)] px-3 py-2 text-sm font-semibold text-[var(--brand)] transition-colors hover:bg-secondary-200 focus:outline-none focus:ring-2 focus:ring-[var(--hover-rose)]"
                                        data-seller-id="{{ $sellerOrder->seller->id }}"
                                        data-order-id="{{ $order->id }}"
                                    >
                                        💬 Chat
                                    </button>
                                @endif
                            </div>
                            <x-shipment-tracking :shipment="$sellerOrder" />

                            @php($hubMovements = $sellerOrder->manifestMovementEvents())
                            @if ($hubMovements !== [])
                                <div class="mt-6 border-t border-[var(--border-strong)] pt-5">
                                    <h3 class="font-sans text-sm font-semibold text-[var(--text-ink)]">Hub movements</h3>
                                    <p class="mt-1 font-sans text-xs leading-relaxed text-[var(--text-muted)]">Internal transfers inside the logistics network. These events do not replace the delivery status above.</p>
                                    <ul class="mt-4 space-y-4">
                                        @foreach ($hubMovements as $movement)
                                            <li class="flex items-start gap-3">
                                                <span class="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full border {{ $movement['type'] === 'dispatch' ? 'border-amber-200 bg-amber-50 text-amber-800' : 'border-emerald-200 bg-emerald-50 text-emerald-800' }}">
                                                    <i data-lucide="{{ $movement['type'] === 'dispatch' ? 'truck' : 'warehouse' }}" class="size-3"></i>
                                                </span>
                                                <div class="min-w-0">
                                                    <p class="font-sans text-sm font-semibold text-[var(--text-ink)]">{{ $movement['title'] }}</p>
                                                    <p class="font-sans text-xs text-[var(--text-muted)]">{{ $movement['at']->format('M d, Y h:i A') }} · {{ $movement['detail'] }}</p>
                                                    @if (filled($movement['custody'] ?? null))
                                                        <p class="mt-1 font-sans text-xs leading-relaxed text-[var(--text-muted)]">{{ $movement['custody'] }}</p>
                                                    @endif
                                                </div>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                        </section>
                    @endforeach
                </div>

                {{-- ── Right: tracking + confirm ─────────────────────────────── --}}
                <div class="min-w-0 space-y-6">
                    @foreach ($order->sellerOrders as $sellerOrder)
                        @php($courier = $sellerOrder->courier)
                        <section class="rounded-2xl border border-[var(--border)] bg-[var(--bg-surface)] p-5 shadow-sm sm:p-6">
                            <h2 class="mb-4 font-sans text-sm font-semibold text-[var(--text-ink)]">
                                {{ $sellerOrder->seller?->business_name ?? 'Seller' }} — Shipment
                            </h2>
                            <div class="space-y-4">
                                <div>
                                    <p class="mb-2 font-sans text-xs font-semibold uppercase tracking-wide text-[var(--text-muted)]">Status</p>
                                    <p class="inline-flex rounded-full bg-secondary-100 px-3 py-1.5 font-sans text-sm font-semibold text-[var(--brand)]">{{ $sellerOrder->status->label() }}</p>
                                </div>
                                @if ($sellerOrder->tracking_number)
                                    <div>
                                        <p class="mb-2 font-sans text-xs font-semibold uppercase tracking-wide text-[var(--text-muted)]">Tracking number</p>
                                        <p class="font-mono text-sm text-[var(--text-ink)] break-all">{{ $sellerOrder->tracking_number }}</p>
                                    </div>
                                @endif
                                @if (in_array($sellerOrder->id, $returnableShipmentIds, true))
                                    <form method="POST" action="{{ route('buyer.orders.requestReturn', [$order, $sellerOrder]) }}" enctype="multipart/form-data" class="space-y-3">
                                        @csrf
                                        <label for="return-reason-{{ $sellerOrder->id }}" class="block text-sm font-semibold text-[var(--text-ink)]">Return reason</label>
                                        <select id="return-reason-{{ $sellerOrder->id }}" name="reason" required class="w-full rounded-xl border border-[var(--border)] bg-[var(--bg-page)] px-3 py-2 text-sm text-[var(--text-ink)]">
                                            <option value="">Choose a reason</option>
                                            @foreach (\App\Services\Logistics\ParcelReturnService::BUYER_REASONS as $reason)
                                                <option value="{{ $reason }}" @selected(old('reason') === $reason)>{{ $reason }}</option>
                                            @endforeach
                                        </select>
                                        <label for="return-description-{{ $sellerOrder->id }}" class="block text-sm font-semibold text-[var(--text-ink)]">What happened? (10–500 characters)</label>
                                        <textarea id="return-description-{{ $sellerOrder->id }}" name="description" required minlength="10" maxlength="500" class="w-full rounded-xl border border-[var(--border)] bg-[var(--bg-page)] px-3 py-2 text-sm text-[var(--text-ink)]">{{ old('description') }}</textarea>
                                        <label for="return-photos-{{ $sellerOrder->id }}" class="block text-sm font-semibold text-[var(--text-ink)]">Photos (1–5 JPG or PNG, up to 5 MB each)</label>
                                        <input id="return-photos-{{ $sellerOrder->id }}" type="file" name="photos[]" required multiple accept=".jpg,.jpeg,.png,image/jpeg,image/png" class="block w-full text-sm text-[var(--text-ink)]">
                                        <button type="submit" class="w-full rounded-xl border border-primary-800 px-4 py-3 text-sm font-semibold text-primary-800 hover:bg-primary-50">Request return</button>
                                    </form>
                                @elseif ($sellerOrder->parcelReturn?->inDispute())
                                    <p role="status" class="text-sm font-semibold text-[var(--text-ink)]">In Dispute</p>
                                    <p class="text-sm text-[var(--text-ink)]">An admin is reviewing the seller's rejection. The seller cannot change it until that review is finished.</p>
                                    <p class="text-sm text-[var(--text-ink)]"><span class="font-semibold">Seller's reason:</span> {{ $sellerOrder->parcelReturn->seller_decision_reason }}</p>
                                @elseif ($sellerOrder->parcelReturn?->status === \App\Enums\ParcelReturnStatus::Rejected)
                                    <p role="status" class="text-sm font-semibold text-[var(--text-ink)]">Return request rejected</p>
                                    <p class="text-sm text-[var(--text-ink)]"><span class="font-semibold">Seller's reason:</span> {{ $sellerOrder->parcelReturn->seller_decision_reason }}</p>
                                    @if ($sellerOrder->parcelReturn->canBuyerEscalate())
                                        <form method="POST" action="{{ route('buyer.orders.escalateReturn', [$order, $sellerOrder]) }}" class="space-y-3">
                                            @csrf
                                            <label for="escalate-reason-{{ $sellerOrder->id }}" class="block text-sm font-semibold text-[var(--text-ink)]">Ask an admin to review this rejection</label>
                                            <textarea id="escalate-reason-{{ $sellerOrder->id }}" name="reason" required minlength="10" maxlength="1000" class="w-full rounded-xl border border-[var(--border)] bg-[var(--bg-page)] px-3 py-2 text-sm text-[var(--text-ink)]">{{ old('reason') }}</textarea>
                                            <button type="submit" class="w-full rounded-xl border border-primary-800 px-4 py-3 text-sm font-semibold text-primary-800 hover:bg-primary-50">Escalate</button>
                                        </form>
                                    @elseif ($sellerOrder->parcelReturn->dispute_outcome === \App\Models\Logistics\ParcelReturn::DISPUTE_UPHELD)
                                        <p class="text-sm text-[var(--text-ink)]">An admin reviewed this and kept the rejection. {{ $sellerOrder->parcelReturn->dispute_note }}</p>
                                    @endif
                                @elseif ($sellerOrder->parcelReturn?->isActive() && $sellerOrder->parcelReturn->seller_decision !== null)
                                    <p role="status" class="text-sm font-semibold text-[var(--text-ink)]">Return request approved</p>
                                    @if ($sellerOrder->parcelReturn->dispute_outcome === \App\Models\Logistics\ParcelReturn::DISPUTE_OVERTURNED)
                                        <p class="text-sm text-[var(--text-ink)]">An admin overturned the seller's rejection. {{ $sellerOrder->parcelReturn->dispute_note }}</p>
                                    @elseif ($sellerOrder->parcelReturn->seller_decision === \App\Models\Logistics\ParcelReturn::AUTO_APPROVED)
                                        <p class="text-sm text-[var(--text-ink)]">The seller did not answer in time, so your request was approved automatically.</p>
                                    @endif
                                    @if ($sellerOrder->parcelReturn->collectedFromBuyer())
                                        <p class="text-sm text-[var(--text-ink)]">Your parcel was collected and is on its way back to the seller.</p>
                                    @elseif ($sellerOrder->parcelReturn->collection_courier_id)
                                        <p class="text-sm text-[var(--text-ink)]">A rider has been assigned to collect the parcel from you.</p>
                                    @else
                                        <p class="text-sm text-[var(--text-ink)]">Please keep the parcel ready. A rider will be assigned to collect it.</p>
                                    @endif
                                @elseif ($sellerOrder->parcelReturn?->awaitingSellerDecision())
                                    <p role="status" class="text-sm font-semibold text-[var(--text-ink)]">Return request open</p>
                                    <p class="text-sm text-[var(--text-ink)]">Waiting for the seller to decide by {{ $sellerOrder->parcelReturn->seller_response_due_at->timezone('Asia/Manila')->format('M j, Y g:i A') }}.</p>
                                @elseif ($sellerOrder->parcelReturn?->isActive())
                                    <p role="status" class="text-sm font-semibold text-[var(--text-ink)]">Return in progress</p>
                                    <p class="text-sm text-[var(--text-ink)]">This parcel is on its way back to the seller.</p>
                                @elseif ($sellerOrder->parcelReturn?->status === \App\Enums\ParcelReturnStatus::Completed)
                                    <p role="status" class="text-sm font-semibold text-[var(--text-ink)]">Return completed</p>
                                    <p class="text-sm text-[var(--text-ink)]">The seller has received the parcel.</p>
                                    @if ($sellerOrder->parcelReturn->refunded_at)
                                        <p class="text-sm text-[var(--text-ink)]">Refund recorded. Reference: {{ $sellerOrder->parcelReturn->refund_reference }}</p>
                                    @elseif ($sellerOrder->parcelReturn->financial_review_required)
                                        <p class="text-sm text-[var(--text-ink)]">Your refund is being reviewed.</p>
                                    @endif
                                @endif
                                @if (in_array($sellerOrder->status, [OrderStatus::Placed, OrderStatus::Confirmed, OrderStatus::Preparing], true))
                                    <div class="pt-2" data-cancel-action="{{ $sellerOrder->id }}">
                                        <button
                                            type="button"
                                            class="buyer-cancel-order-btn w-full rounded-xl border border-red-300 bg-transparent px-4 py-3 text-sm font-semibold text-red-700 transition-colors hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-200"
                                            data-cancel-url="{{ route('buyer.orders.cancelSellerOrder', [$order, $sellerOrder]) }}"
                                            data-seller-order-id="{{ $sellerOrder->id }}"
                                            data-shop-name="{{ $sellerOrder->seller?->business_name ?? 'this seller' }}"
                                        >
                                            Cancel Order
                                        </button>
                                        <p class="buyer-cancel-feedback mt-2 hidden font-sans text-sm" role="status"></p>
                                    </div>
                                @endif
                            </div>
                        </section>
                    @endforeach

                    @foreach ($order->sellerOrders as $shipment)
                        @if ($shipment->status === OrderStatus::Delivered && ! $shipment->hasActiveReturn())
                            <section class="rounded-2xl border border-[var(--border)] bg-[var(--bg-surface)] p-5 shadow-sm sm:p-6">
                                <h2 class="text-sm font-semibold text-buyer-ink">{{ $shipment->seller?->business_name ?? 'Seller' }}</h2>
                                <form method="POST" action="{{ route('buyer.orders.confirmReceived', $order) }}" class="mt-3">
                                    @csrf
                                    <input type="hidden" name="seller_order_id" value="{{ $shipment->id }}">
                                    <button type="submit" class="w-full rounded-xl bg-primary-800 px-4 py-3 text-sm font-semibold text-white transition-colors hover:bg-primary-900 focus:outline-none focus:ring-2 focus:ring-[var(--hover-rose)]">Order Received</button>
                                </form>
                            </section>
                        @elseif ($shipment->status === OrderStatus::Completed)
                            <section class="rounded-2xl border border-[var(--border)] bg-[var(--bg-surface)] p-5 shadow-sm sm:p-6">
                                <p class="text-sm text-buyer-ink">Shipment received. Review your purchased products:</p>
                                @foreach ($shipment->orderItems as $item)
                                    @if ($item->product && $item->product->status === 'active')
                                        <a class="mt-2 block text-sm text-primary-800 underline" href="{{ route('buyer.products.show', $item->product_id) }}#reviews">Review {{ $item->product_name }}</a>
                                    @endif
                                @endforeach
                            </section>
                        @endif
                    @endforeach
                </div>
            </div>

        </div>
    </div>

    {{-- Cancel confirmation modal --}}
    <div id="buyer-cancel-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40 p-4" role="dialog" aria-modal="true" aria-labelledby="buyer-cancel-modal-title">
        <div class="w-full max-w-md rounded-2xl border border-[var(--border)] bg-[var(--bg-surface)] p-5 shadow-lg sm:p-6">
            <h2 id="buyer-cancel-modal-title" class="font-sans text-lg font-semibold text-[var(--text-ink)]">Cancel this shipment?</h2>
            <p id="buyer-cancel-modal-copy" class="mt-2 font-sans text-sm leading-relaxed text-[var(--text-muted)]">
                This cannot be undone. The seller will be notified and stock will be restored.
            </p>
            <label for="buyer-cancel-reason" class="mt-4 block font-sans text-sm font-semibold text-[var(--text-ink)]">Reason <span class="text-red-600">*</span></label>
            <textarea
                id="buyer-cancel-reason"
                maxlength="500"
                rows="3"
                class="mt-2 w-full rounded-xl border border-[var(--border)] bg-[var(--bg-page)] px-3 py-2 font-sans text-sm text-[var(--text-ink)] focus:outline-none focus:ring-2 focus:ring-[var(--hover-rose)]"
                placeholder="Tell the seller why you are cancelling"
            ></textarea>
            <p id="buyer-cancel-modal-error" class="mt-2 hidden font-sans text-sm text-red-600" role="alert"></p>
            <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <button type="button" id="buyer-cancel-modal-dismiss" class="inline-flex items-center justify-center rounded-xl border border-[var(--border)] px-4 py-2.5 font-sans text-sm font-semibold text-[var(--text-ink)] transition-colors hover:bg-secondary-200">
                    Keep order
                </button>
                <button type="button" id="buyer-cancel-modal-confirm" class="inline-flex items-center justify-center rounded-xl bg-red-700 px-4 py-2.5 font-sans text-sm font-semibold text-white transition-colors hover:bg-red-800 disabled:cursor-not-allowed disabled:opacity-60">
                    Confirm cancel
                </button>
            </div>
        </div>
    </div>

    <script>
        document.querySelectorAll('.chat-with-seller-btn').forEach(btn => {
            btn.addEventListener('click', async () => {
                const sellerId = btn.dataset.sellerId;
                const orderId = btn.dataset.orderId;

                try {
                    const response = await fetch('{{ route("buyer.messages.getOrCreate") }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            'Accept': 'application/json',
                        },
                        body: JSON.stringify({
                            seller_id: sellerId,
                            order_id: orderId,
                        }),
                    });

                    if (response.ok) {
                        const data = await response.json();
                        window.location.href = `/buyer/messages/${data.conversation_id}`;
                    } else {
                        alert('Failed to start conversation');
                    }
                } catch (error) {
                    console.error('Error starting conversation:', error);
                    alert('Error starting conversation');
                }
            });
        });

        (function () {
            const modal = document.getElementById('buyer-cancel-modal');
            const reasonInput = document.getElementById('buyer-cancel-reason');
            const errorEl = document.getElementById('buyer-cancel-modal-error');
            const copyEl = document.getElementById('buyer-cancel-modal-copy');
            const confirmBtn = document.getElementById('buyer-cancel-modal-confirm');
            const dismissBtn = document.getElementById('buyer-cancel-modal-dismiss');
            let activeUrl = null;
            let activeSellerOrderId = null;

            function openModal(url, sellerOrderId, shopName) {
                activeUrl = url;
                activeSellerOrderId = sellerOrderId;
                reasonInput.value = '';
                errorEl.classList.add('hidden');
                errorEl.textContent = '';
                copyEl.textContent = `Cancel your shipment from ${shopName}? This cannot be undone. The seller will be notified and stock will be restored.`;
                modal.classList.remove('hidden');
                modal.classList.add('flex');
                reasonInput.focus();
            }

            function closeModal() {
                modal.classList.add('hidden');
                modal.classList.remove('flex');
                activeUrl = null;
                activeSellerOrderId = null;
                confirmBtn.disabled = false;
                confirmBtn.textContent = 'Confirm cancel';
            }

            document.querySelectorAll('.buyer-cancel-order-btn').forEach((btn) => {
                btn.addEventListener('click', () => {
                    openModal(btn.dataset.cancelUrl, btn.dataset.sellerOrderId, btn.dataset.shopName || 'this seller');
                });
            });

            dismissBtn.addEventListener('click', closeModal);
            modal.addEventListener('click', (event) => {
                if (event.target === modal) {
                    closeModal();
                }
            });

            confirmBtn.addEventListener('click', async () => {
                if (!activeUrl || !activeSellerOrderId) {
                    return;
                }

                const reason = reasonInput.value.trim();
                if (reason === '') {
                    errorEl.textContent = 'Please provide a cancellation reason.';
                    errorEl.classList.remove('hidden');
                    return;
                }

                confirmBtn.disabled = true;
                confirmBtn.textContent = 'Cancelling…';
                errorEl.classList.add('hidden');

                try {
                    const response = await fetch(activeUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            'Accept': 'application/json',
                        },
                        body: JSON.stringify({ reason }),
                    });

                    const data = await response.json().catch(() => ({}));

                    if (!response.ok) {
                        const message = data.message
                            || data.errors?.reason?.[0]
                            || 'Could not cancel this shipment. Please try again.';
                        errorEl.textContent = message;
                        errorEl.classList.remove('hidden');
                        confirmBtn.disabled = false;
                        confirmBtn.textContent = 'Confirm cancel';
                        return;
                    }

                    // Refresh so timeline shows “Cancelled by Buyer” with reason/timestamp
                    // and the Cancel Order action is gone for this slice.
                    window.location.reload();
                } catch (error) {
                    console.error('Cancel failed', error);
                    errorEl.textContent = 'Could not cancel this shipment. Please try again.';
                    errorEl.classList.remove('hidden');
                    confirmBtn.disabled = false;
                    confirmBtn.textContent = 'Confirm cancel';
                }
            });
        })();
    </script>
@endsection
