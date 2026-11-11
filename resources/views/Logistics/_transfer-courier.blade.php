{{--
    "Who carries this" for a transfer batch or a cross-provider handoff.
    Params: $action (form URL or null when it can no longer change), $noun
    ('batch' | 'handoff'), $courier (current TransferCourier|null), $vehicle,
    $plate, $candidates (active Couriers), $history (assignments), $required.
--}}
@php
    $options = $courier && ! $candidates->contains('id', $courier->id) ? $candidates->concat([$courier]) : $candidates;
@endphp
<section class="{{ $spacing ?? 'mb-6' }} rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-4 shadow-sm" aria-labelledby="transfer-courier-title" data-transfer-courier>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <h2 id="transfer-courier-title" class="font-serif text-xl text-[var(--text-ink)]">Who carries this {{ $noun }}</h2>
            <p class="mt-1 font-sans text-xs text-[var(--text-muted)]">
                @if ($courier)
                    <span class="font-semibold text-[var(--text-ink)]">{{ $courier->name }}</span> · {{ $vehicle }}{{ $plate ? ' · '.$plate : '' }} · {{ $courier->contact_no }}
                @elseif ($required)
                    No Courier yet. One is required before dispatch.
                @else
                    No Courier recorded.
                @endif
            </p>
            <p class="mt-1 font-sans text-[11px] text-[var(--text-muted)]">The Courier only transports the parcels. Custody moves when the receiving hub scans each one.</p>
        </div>
        <a href="{{ route('logistics.transfer-couriers.index') }}" class="inline-flex min-h-9 items-center rounded-sm border border-[var(--border-strong)] px-3 font-sans text-[11px] font-semibold text-[var(--text-ink)] hover:bg-[var(--bg-page)]">Manage Couriers</a>
    </div>

    @if ($action)
        @if ($options->isEmpty())
            <p class="mt-3 font-sans text-xs text-amber-800">You have no active Courier. Add one in Couriers first.</p>
        @else
            <form method="POST" action="{{ $action }}" class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)_auto] sm:items-end">
                @csrf
                <label class="block font-sans text-[11px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)]">Courier
                    <select name="transfer_courier_id" required class="mt-1 w-full rounded-sm border border-[var(--border-strong)] bg-[var(--bg-surface)] px-3 py-2 font-sans text-[13px] normal-case tracking-normal text-[var(--text-ink)]">
                        @foreach ($options as $candidate)
                            <option value="{{ $candidate->id }}" @selected((int) old('transfer_courier_id', $courier?->id) === (int) $candidate->id)>{{ $candidate->label() }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block font-sans text-[11px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)]">Vehicle
                    <select name="transport_vehicle_type" class="mt-1 w-full rounded-sm border border-[var(--border-strong)] bg-[var(--bg-surface)] px-3 py-2 font-sans text-[13px] normal-case tracking-normal text-[var(--text-ink)]">
                        <option value="">Courier's usual</option>
                        @foreach (\App\Support\RiderValidation::VEHICLES as $option)
                            <option value="{{ $option }}" @selected(old('transport_vehicle_type', $courier ? $vehicle : '') === $option)>{{ $option }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block font-sans text-[11px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)]">Plate (optional)
                    <input name="transport_plate_number" maxlength="20" value="{{ old('transport_plate_number', $courier ? $plate : '') }}" placeholder="Courier's usual" class="mt-1 w-full rounded-sm border border-[var(--border-strong)] bg-[var(--bg-surface)] px-3 py-2 font-mono text-[13px] uppercase text-[var(--text-ink)]">
                </label>
                <button type="submit" class="inline-flex min-h-10 items-center justify-center rounded-sm bg-primary-700 px-4 font-sans text-[11px] font-semibold uppercase tracking-[0.5px] text-white hover:bg-primary-800">{{ $courier ? 'Change' : 'Assign' }}</button>
            </form>
        @endif
    @endif

    @if ($history->count() > 1)
        <details class="mt-3 font-sans text-[12px]">
            <summary class="cursor-pointer font-semibold text-[var(--text-ink)]">Assignment history ({{ $history->count() }})</summary>
            <ul class="mt-2 space-y-1 text-[var(--text-muted)]">
                @foreach ($history as $entry)
                    <li>{{ $entry->assigned_at->timezone(\App\Models\Seller\StoreSale::TIMEZONE)->format('M j, Y g:i A') }} · {{ $entry->courier?->name ?? 'Courier' }} · {{ $entry->vehicle_type }}{{ $entry->plate_number ? ' · '.$entry->plate_number : '' }}{{ $entry->replaced_at ? ' · replaced' : ' · current' }}</li>
                @endforeach
            </ul>
        </details>
    @endif
</section>
