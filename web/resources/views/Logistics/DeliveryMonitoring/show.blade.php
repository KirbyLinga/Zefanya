@extends('Layouts.logistics')

@section('title', 'Parcel #'.$sellerOrder->id.' — Details')

@section('content')
<div class="flex items-center justify-between mb-6">
    <h1 class="font-serif text-2xl text-[var(--text-ink)]">Parcel #{{ $sellerOrder->id }}</h1>
    <a href="{{ route('logistics.delivery-monitoring.index') }}" class="text-sm font-semibold text-primary-600 hover:text-primary-800">
        <i data-lucide="arrow-left" class="w-4 h-4 inline mr-1"></i> Back to monitoring
    </a>
</div>

<x-logistics-flash :messages="['success', 'warning', 'error']" />

@if ($errors->any())
    <div role="alert" class="mb-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">
        @foreach ($errors->all() as $error)
            <p>{{ $error }}</p>
        @endforeach
    </div>
@endif

<div class="bg-white border border-[var(--border-strong)] rounded-lg shadow-sm overflow-hidden">
    <div class="grid sm:grid-cols-2 gap-0">
        <div class="p-6 border-b sm:border-b-0 sm:border-r border-[var(--border-strong)]">
            <p class="text-xs font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)] mb-2">Identifiers</p>
            <dl class="space-y-2 text-base">
                <div><dt class="text-xs text-[var(--text-muted)]">Order</dt><dd class="font-semibold text-[var(--text-ink)]">{{ $sellerOrder->id }}</dd></div>
                @if ($sellerOrder->tracking_number)
                    <div><dt class="text-xs text-[var(--text-muted)]">Tracking</dt><dd class="font-mono text-xs font-semibold text-[var(--text-ink)]">{{ $sellerOrder->tracking_number }}</dd></div>
                @endif
                <div><dt class="text-xs text-[var(--text-muted)]">Seller</dt><dd class="font-semibold text-[var(--text-ink)]">{{ $sellerOrder->seller?->name ?? '—' }}</dd></div>
                <div><dt class="text-xs text-[var(--text-muted)]">Buyer</dt><dd class="font-semibold text-[var(--text-ink)]">{{ $sellerOrder->order?->buyer?->fullName() ?? '—' }}</dd></div>
                <div><dt class="text-xs text-[var(--text-muted)]">Status</dt><dd class="font-semibold text-[var(--text-ink)]">{{ $sellerOrder->status->label() }}</dd></div>
                @if ($sellerOrder->needs_reassignment)
                    <div class="pt-2"><span class="inline-flex px-3 py-1 rounded-full text-xs font-semibold bg-amber-50 text-amber-700">Needs reassignment</span></div>
                @endif
            </dl>
        </div>
        <div class="p-6 border-b sm:border-b-0 sm:border-r border-[var(--border-strong)]">
            <p class="text-xs font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)] mb-2">Custody & crew</p>
            <dl class="space-y-2 text-base">
                <div><dt class="text-xs text-[var(--text-muted)]">Custody provider</dt><dd class="font-semibold text-[var(--text-ink)]">{{ $sellerOrder->currentCustodyProviderId() ? 'Provider #' : '—' }}{{ $sellerOrder->currentCustodyProviderId() ?? '—' }}</dd></div>
                @if ($sellerOrder->deliveryCourier)
                    <div><dt class="text-xs text-[var(--text-muted)]">Delivery rider</dt><dd class="font-semibold text-[var(--text-ink)]">{{ $sellerOrder->deliveryCourier->name }}</dd></div>
                    <div><dt class="text-xs text-[var(--text-muted)]">Vehicle</dt><dd class="text-xs text-[var(--text-muted)]">{{ $sellerOrder->deliveryCourier->vehicle_type ?? '—' }}</dd></div>
                @else
                    <div><dd class="text-xs text-[var(--text-muted)]">Not yet assigned</dd></div>
                @endif
                <div><dt class="text-xs text-[var(--text-muted)]">Assignments</dt><dd class="text-xs text-[var(--text-muted)]">{{ $sellerOrder->deliveryAssignments->count() }}</dd></div>
            </dl>
        </div>
        <div class="p-6 border-b sm:border-b-0 sm:border-r border-[var(--border-strong)]">
            <p class="text-xs font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)] mb-2">Payment</p>
            <dl class="space-y-2 text-base">
                @if ($sellerOrder->payment)
                    <div><dt class="text-xs text-[var(--text-muted)]">Method</dt><dd class="font-semibold text-[var(--text-ink)]">{{ $sellerOrder->payment->method?->label() ?? '—' }}</dd></div>
                    <div><dt class="text-xs text-[var(--text-muted)]">Status</dt><dd class="font-semibold text-[var(--text-ink)]">{{ $sellerOrder->payment->status?->label() ?? '—' }}</dd></div>
                    <div><dt class="text-xs text-[var(--text-muted)]">Amount</dt><dd class="text-xs text-[var(--text-muted)]">{{ $sellerOrder->payment->formattedAmount() ?? '—' }}</dd></div>
                @else
                    <div><dd class="text-xs text-[var(--text-muted)]">No payment recorded</dd></div>
                @endif
            </dl>
        </div>
        <div class="p-6 border-b sm:border-b-0 sm:border-r border-[var(--border-strong)]">
            <p class="text-xs font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)] mb-2">Delivery evidence</p>
            @if ($sellerOrder->deliveryProof)
                <div class="space-y-2 text-base">
                    <p class="font-semibold text-[var(--text-ink)]">Delivery proof logged</p>
                    <p class="text-xs text-[var(--text-muted)]">By {{ $sellerOrder->deliveryProof->deliveryCourier?->name ?? '—' }} on {{ $sellerOrder->deliveryProof->created_at->format('M d, Y H:i') ?? '—' }}</p>
                </div>
            @else
                <p class="text-xs text-[var(--text-muted)]">No delivery proof recorded.</p>
            @endif
        </div>
        <div class="p-6 border-b sm:border-b-0 sm:border-r border-[var(--border-strong)]">
            <p class="text-xs font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)] mb-2">Open incident</p>
            @if ($openIncident)
                <p class="text-sm font-semibold text-[var(--text-ink)]">{{ $incident->type ?? 'Incident' }} — open. Parcel is blocked until resolved.</p>
            @else
                <p class="text-xs text-[var(--text-muted)]">No open incidents.</p>
            @endif
        </div>
    </div>
</div>
@if ($openIncident)
<div class="mt-6 bg-amber-50 border border-amber-200 rounded-lg p-5">
    <div class="flex items-start gap-4">
        <div class="flex-shrink-0">
            <i data-lucide="alert-triangle" class="w-5 h-5 text-amber-600"></i>
        </div>
        <div class="flex-1">
            <h2 class="text-base font-semibold text-amber-900 mb-1">Open incident blocks this delivery</h2>
            <p class="text-sm text-amber-800 mb-3">Parcel {{ $sellerOrder->id }} has an open lost/damaged incident. Resolve it in the admin queue before the delivery can complete, fail, reassign, or return.</p>
            @if ($incident)
                <p class="text-sm font-semibold text-amber-900">Incident #{{ $incident->id }} ({{ $incident->type }}) — open since {{ $incident->created_at->setTimezone('UTC')->format('M d, Y H:i') }}</p>
            @endif
        </div>
    </div>
</div>

@endif

@if (!$openIncident && $sellerOrder->handover_at !== null)
    <div class="mt-6 bg-white border border-[var(--border-strong)] rounded-lg p-5">
        <h2 class="text-base font-semibold text-[var(--text-ink)] mb-3">Report lost / damaged</h2>
        <p class="text-sm text-[var(--text-muted)] mb-4">Report a missing or damaged parcel before it can continue. Incidents are reviewed by Admin. Parcel operations pause while an incident is open.</p>
        <form method="POST" enctype="multipart/form-data" action="{{ route('logistics.parcels.incidents.store', $sellerOrder) }}" class="space-y-4">
            @csrf
            <div>
                <label for="type" class="block text-sm font-semibold text-[var(--text-ink)] mb-1">Incident type</label>
                <select id="type" name="type" class="w-full border border-[var(--border-strong)] rounded-lg p-3 text-sm focus:ring-2 focus:ring-primary-400 focus:border-transparent" required>
                    <option value="lost" @selected(old('type') === 'lost')>Lost</option>
                    <option value="damaged" @selected(old('type') === 'damaged')>Damaged</option>
                </select>
            </div>
            <div>
                <label for="reason" class="block text-sm font-semibold text-[var(--text-ink)] mb-1">Reason</label>
                <input id="reason" name="reason" value="{{ old('reason') }}" maxlength="255" required class="w-full border border-[var(--border-strong)] rounded-lg p-3 text-sm">
            </div>
            <div>
                <label for="description" class="block text-sm font-semibold text-[var(--text-ink)] mb-1">Notes</label>
                <textarea id="description" name="description" rows="4" maxlength="1000" class="w-full border border-[var(--border-strong)] rounded-lg p-3 text-sm focus:ring-2 focus:ring-primary-400 focus:border-transparent" required>{{ old('description') }}</textarea>
            </div>
            <div>
                <label for="photo" class="block text-sm font-semibold text-[var(--text-ink)] mb-1">Photo (optional)</label>
                <input id="photo" name="photo" type="file" accept="image/jpeg,image/png,image/webp" class="w-full text-sm">
                <p class="mt-1 text-xs text-[var(--text-muted)]">JPEG, PNG, or WebP up to 5 MB. Photos are stored privately.</p>
            </div>
            <div class="flex justify-end">
                <button type="submit" class="h-10 px-5 bg-primary-800 text-white font-semibold text-[13px] rounded-lg hover:bg-primary-900 transition-colors">
                    <i data-lucide="alert-triangle" class="w-4 h-4 mr-2"></i> Report lost/damaged
                </button>
            </div>
        </form>
    </div>
@endif
@endsection
