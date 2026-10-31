@extends('Layouts.rider')

@section('title', 'Proof of Delivery')

@section('content')
    @php
        $addr = $sellerOrder->order?->shipping_address ?? [];
        $payment = $sellerOrder->payment;
        $collect = $payment?->requiresCollection() ?? false;
        $due = $payment !== null ? number_format($payment->amount_minor / 100, 2, '.', '') : null;
    @endphp

    <div class="mb-6">
        <a href="{{ route('rider.deliveries.index') }}" class="font-sans text-[11.5px] font-semibold uppercase tracking-[0.5px] text-[var(--text-muted)] no-underline hover:text-[var(--text-ink)]">
            ← Delivery Assignments
        </a>
        <h1 class="mt-1 font-serif text-2xl text-[var(--text-ink)]">Proof of Delivery</h1>
        <p class="font-sans text-[12.5px] text-[var(--text-muted)]">Parcel #{{ $sellerOrder->id }} · {{ $sellerOrder->tracking_number ?? 'No tracking number' }}</p>
    </div>

    <x-rider.flash />

    <div class="grid grid-cols-1 gap-4 xl:grid-cols-[1fr_1.2fr]">
        <section class="rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-4 shadow-sm">
            <h2 class="mb-3 font-sans text-[13px] font-semibold text-[var(--text-ink)]">Delivery details</h2>

            <dl class="space-y-2">
                <div>
                    <dt class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)]">Recipient</dt>
                    <dd class="font-sans text-[12.5px] text-[var(--text-ink)]">{{ $addr['name'] ?? $sellerOrder->order?->buyer?->fullName() ?? 'Buyer' }}</dd>
                </div>
                <div>
                    <dt class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)]">Address</dt>
                    <dd class="font-sans text-[12.5px] text-[var(--text-ink)]">{{ $addr['full'] ?? 'Address not recorded' }}</dd>
                </div>
                <div>
                    <dt class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)]">Payment</dt>
                    <dd class="font-sans text-[12.5px] text-[var(--text-ink)]">
                        {{ $payment?->method?->label() ?? 'Not recorded' }}
                        @if ($collect)
                            <span class="ml-1 font-semibold text-amber-700">· Collect ₱{{ $due }}</span>
                        @elseif ($payment?->method?->value === 'cod')
                            <span class="ml-1 text-emerald-700">· Collected</span>
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)]">Items</dt>
                    <dd class="font-sans text-[12.5px] text-[var(--text-ink)]">{{ $sellerOrder->orderItems->sum('quantity') }} item(s) · {{ $sellerOrder->formattedParcelWeight() }}</dd>
                </div>
            </dl>

            @if ($collect)
                <p class="mt-4 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 font-sans text-[11.5px] text-amber-800">
                    Collect cash only at the buyer's door. Enter the exact amount handed over — it must match ₱{{ $due }}.
                </p>
            @endif
        </section>

        <section class="rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-4 shadow-sm">
            <h2 class="mb-3 font-sans text-[13px] font-semibold text-[var(--text-ink)]">Delivery evidence</h2>

            <div class="mb-5 rounded-xl border border-primary-200 bg-primary-50 p-4">
                <h3 class="text-sm font-semibold text-primary-900">Buyer delivery confirmation</h3>
                <p class="mt-1 text-xs leading-relaxed text-[var(--text-ink)]">Send a six-digit code to the buyer's verified email. Ask for it only after handing over the parcel. Codes expire in 10 minutes; wait 60 seconds between requests.</p>
                <form method="POST" action="{{ route('rider.deliveries.otp', $sellerOrder) }}" class="mt-3">
                    @csrf
                    <input type="hidden" name="verification_token" value="{{ $verificationToken }}">
                    <button type="submit" class="min-h-10 rounded-lg border border-primary-300 bg-[var(--bg-surface)] px-4 text-xs font-semibold text-primary-900 transition-colors hover:bg-primary-800 hover:text-white focus-visible:outline-2 focus-visible:outline-primary-700">Send / resend delivery code</button>
                </form>
                @error('delivery_otp')<p role="alert" class="mt-2 text-xs text-red-800">{{ $message }}</p>@enderror
            </div>
            <form
                id="proofForm"
                data-seller-order-id="{{ $sellerOrder->id }}"
                method="POST"
                action="{{ route('rider.deliveries.deliver', $sellerOrder) }}"
                enctype="multipart/form-data"
                class="space-y-4"
            >
                @csrf
                @php
                    // Do not cache this in sessionStorage. It is a single-use, 10-minute
                    // handoff token (App\Services\Logistics\ParcelHandoffVerification). The route
                    // already re-issues it, so the client only forwards the current one.
                @endphp
                <input type="hidden" name="verification_token" value="{{ $verificationToken }}">

                <div>
                    <label for="delivery_otp" class="mb-1 block text-xs font-semibold text-[var(--text-ink)]">Buyer delivery code *</label>
                    <input id="delivery_otp" name="delivery_otp" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" minlength="6" maxlength="6" required class="min-h-11 w-full rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] px-3 font-mono text-lg tracking-widest text-[var(--text-ink)] focus:border-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-200" placeholder="6-digit code">
                </div>
                <div>
                    <label for="proof_photo" class="mb-1 block font-sans text-[11px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)]">
                        Photo of the delivered parcel <span class="text-rose-700">*</span>
                    </label>
                    <input id="proof_photo" name="proof_photo" type="file" accept="image/jpeg,image/png,image/webp" required
                           class="block w-full rounded-sm border border-[var(--border-strong)] bg-[var(--bg-page)] px-3 py-2 font-sans text-[12.5px] text-[var(--text-ink)]">
                    <p class="mt-1 font-sans text-[11px] text-[var(--text-muted)]">JPG, PNG or WebP · up to 5 MB. Stored privately.</p>
                </div>

                <div>
                    <label for="received_by" class="mb-1 block font-sans text-[11px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)]">
                        Received by
                    </label>
                    <input id="received_by" name="received_by" type="text" maxlength="120"
                           value="{{ old('received_by', $addr['name'] ?? '') }}"
                           placeholder="Name of the person who accepted the parcel"
                           class="h-10 w-full rounded-sm border border-[var(--border-strong)] bg-[var(--bg-page)] px-3 font-sans text-[12.5px] text-[var(--text-ink)]"
                           autocomplete="off">
                </div>

                @if ($collect)
                    <div>
                        <label for="cod_amount" class="mb-1 block font-sans text-[11px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)]">
                            COD amount collected (₱) <span class="text-rose-700">*</span>
                        </label>
                        <input id="cod_amount" name="cod_amount" type="text" inputmode="decimal" required
                               value="{{ old('cod_amount', $due) }}"
                               class="h-10 w-full rounded-sm border border-[var(--border-strong)] bg-[var(--bg-page)] px-3 font-sans text-[12.5px] text-[var(--text-ink)]"
                               autocomplete="off">
                    </div>
                @endif

                <div>
                    <label for="delivery_notes" class="mb-1 block font-sans text-[11px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)]">
                        Notes
                    </label>
                    <textarea id="delivery_notes" name="delivery_notes" rows="3" maxlength="1000"
                              class="w-full rounded-sm border border-[var(--border-strong)] bg-[var(--bg-page)] px-3 py-2 font-sans text-[12.5px] text-[var(--text-ink)]"
                              placeholder="Anything the buyer or recipient should know">{{ old('delivery_notes') }}</textarea>
                </div>

                <div id="proofSessionWarn" class="hidden rounded-md border border-rose-200 bg-rose-50 px-3 py-2 font-sans text-[11.5px] text-rose-800" role="alert">
                    <span class="font-semibold">Your session may have expired.</span>
                    If you submit now, this tab can lose the photo, the amount collected, and the name you typed.
                    Reload the page, sign in again if needed, then finish the proof from the delivery list.
                </div>

                <button type="submit"
                    class="inline-flex h-10 items-center justify-center rounded-sm bg-emerald-700 px-4 font-sans text-[11.5px] font-semibold uppercase tracking-[0.5px] text-white hover:bg-emerald-800"
                    onclick="return confirm('Confirm the parcel was delivered to the buyer? This cannot be undone.');">
                    Confirm Delivery
                </button>
            </form>
        </section>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('js/rider/proof.js') }}" defer></script>
@endpush
