@extends('Layouts.rider')

@section('title', 'Return Pickups')

@section('content')
    <div class="mb-6">
        <p class="font-sans text-[11px] font-semibold uppercase tracking-[1.2px] text-[var(--text-muted)]">Assignments</p>
        <h1 class="font-serif text-2xl text-[var(--text-ink)]">Return Pickups</h1>
        <p class="font-sans text-[12.5px] text-[var(--text-muted)]">
            Approved returns to fetch from the buyer. Check the waybill on the parcel, confirm collection, then bring it to your Station Hub.
        </p>
    </div>

    <x-rider.flash />
    @if ($errors->any())
        <div role="alert" class="mb-4 rounded-md border border-rose-200 bg-rose-50 p-3 font-sans text-[12px] text-rose-700">{{ $errors->first() }}</div>
    @endif

    @if ($returns->isEmpty())
        <section class="flex flex-col items-center rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-8 text-center shadow-sm">
            <i data-lucide="package-open" class="mb-3 h-[32px] w-[32px] text-[var(--icon-soft)]"></i>
            <p class="mb-1 font-sans text-[13px] font-semibold text-[var(--text-ink)]">No return pickups</p>
            <p class="max-w-[380px] font-sans text-[11.5px] text-[var(--text-muted)]">When Logistics assigns you a return to collect, it will appear here.</p>
        </section>
    @else
        <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
            @foreach ($returns as $record)
                @php
                    $parcel = $record->sellerOrder;
                    $address = $parcel->order?->shipping_address ?? [];
                @endphp
                <section class="rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-5 shadow-sm">
                    <p class="font-mono text-[12.5px] font-semibold text-[var(--text-ink)]">{{ $parcel->tracking_number }}</p>
                    <p class="mt-2 font-sans text-[13px] font-semibold text-[var(--text-ink)]">{{ $parcel->order?->buyer?->fullName() ?? 'Buyer' }}</p>
                    <p class="font-sans text-[12px] text-[var(--text-muted)]">{{ $address['full'] ?? 'Address requires review' }}</p>
                    @isset($address['phone'])
                        <p class="font-sans text-[12px] text-[var(--text-muted)]">{{ $address['phone'] }}</p>
                    @endisset
                    <p class="mt-2 font-sans text-[12px] text-[var(--text-muted)]">{{ $parcel->orderItems->sum('quantity') }} item(s). Reason: {{ $record->reason }}</p>

                    <form method="POST" action="{{ route('rider.return-pickups.collect', $record) }}" class="mt-4 flex flex-col gap-2 sm:flex-row sm:items-end">
                        @csrf
                        <label class="flex-1 font-sans text-[11.5px] text-[var(--text-ink)]">Tracking number on the parcel
                            <input name="tracking_number" required maxlength="64" autocomplete="off" class="mt-1 block w-full rounded-sm border border-[var(--border-strong)] bg-[var(--bg-surface)] p-2 font-mono text-[12px]">
                        </label>
                        <button type="submit" class="h-9 rounded-sm bg-primary-700 px-4 font-sans text-[11.5px] font-semibold uppercase tracking-[0.5px] text-white hover:bg-primary-800">Confirm collected</button>
                    </form>

                    @if (\App\Models\Logistics\ParcelReturn::recoveryAvailable())
                        @if ((int) $record->collection_failed_attempts > 0)
                            <p class="mt-3 font-sans text-[11.5px] text-[var(--status-warning)]">Previous missed {{ \Illuminate\Support\Str::plural('try', (int) $record->collection_failed_attempts) }}: {{ $record->collection_failed_attempts }} of {{ \App\Models\Logistics\ParcelReturn::MAX_COLLECTION_ATTEMPTS }}.</p>
                        @endif
                        <details class="mt-3 font-sans text-[11.5px]">
                            <summary class="cursor-pointer font-semibold text-[var(--text-ink)]">Could not collect it?</summary>
                            <form method="POST" action="{{ route('rider.return-pickups.failed', $record) }}" class="mt-2 space-y-2">
                                @csrf
                                <label class="block text-[var(--text-ink)]">What happened
                                    <select name="reason" required class="mt-1 block w-full rounded-sm border border-[var(--border-strong)] bg-[var(--bg-surface)] p-2 text-[12px]">
                                        <option value="">Choose a reason</option>
                                        @foreach (\App\Models\Logistics\ParcelReturn::COLLECTION_FAILURE_REASONS as $key => $label)
                                            <option value="{{ $key }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label class="block text-[var(--text-ink)]">Notes (required for Other)
                                    <textarea name="notes" maxlength="500" rows="2" class="mt-1 block w-full rounded-sm border border-[var(--border-strong)] bg-[var(--bg-surface)] p-2 text-[12px]"></textarea>
                                </label>
                                <button type="submit" class="h-9 rounded-sm border border-primary-700 px-4 font-semibold uppercase tracking-[0.5px] text-[var(--link)]">Report missed collection</button>
                            </form>
                        </details>
                    @endif
                </section>
            @endforeach
        </div>
    @endif
@endsection
