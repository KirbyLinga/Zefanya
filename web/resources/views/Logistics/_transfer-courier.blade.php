<section class="{{ $spacing ?? 'mb-6' }} rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-4">
    <h2 class="font-serif text-xl text-[var(--text-ink)]">Transport Courier</h2>
    @if ($courier)
        <p class="mt-2 text-sm text-[var(--text-ink)]">{{ $courier->name }} · {{ $vehicle }}{{ $plate ? ' · '.$plate : '' }}</p>
    @elseif ($required)
        <p class="mt-2 text-sm text-[var(--text-muted)]">Assign a Courier before dispatching this {{ $noun }}.</p>
    @endif

    @if ($action)
        <form method="POST" action="{{ $action }}" class="mt-3 flex flex-wrap items-end gap-3">
            @csrf
            <label class="grid gap-1 text-sm text-[var(--text-ink)]">
                Courier
                <select name="transfer_courier_id" required class="min-h-11 rounded border border-[var(--border-strong)] bg-[var(--bg-surface)] px-3">
                    <option value="">Select a Courier</option>
                    @foreach ($candidates as $candidate)
                        <option value="{{ $candidate->id }}" @selected((int) old('transfer_courier_id', $courier?->id) === $candidate->id)>{{ $candidate->name }} · {{ $candidate->vehicle_type }}</option>
                    @endforeach
                </select>
            </label>
            <label class="grid gap-1 text-sm text-[var(--text-ink)]">
                Vehicle
                <select name="transport_vehicle_type" class="min-h-11 rounded border border-[var(--border-strong)] bg-[var(--bg-surface)] px-3">
                    <option value="">Use Courier vehicle</option>
                    @foreach (\App\Support\RiderValidation::VEHICLES as $vehicleType)
                        <option value="{{ $vehicleType }}" @selected(old('transport_vehicle_type', $vehicle) === $vehicleType)>{{ $vehicleType }}</option>
                    @endforeach
                </select>
            </label>
            <label class="grid gap-1 text-sm text-[var(--text-ink)]">
                Plate number
                <input name="transport_plate_number" maxlength="20" value="{{ old('transport_plate_number', $plate) }}" class="min-h-11 rounded border border-[var(--border-strong)] bg-[var(--bg-surface)] px-3">
            </label>
            <button type="submit" class="min-h-11 rounded border border-[var(--border-strong)] px-4 font-semibold text-[var(--text-ink)]">Assign Courier</button>
        </form>
    @endif

    @if ($history->isNotEmpty())
        <details class="mt-3 text-sm text-[var(--text-muted)]">
            <summary class="cursor-pointer">Assignment history</summary>
            <ul class="mt-2 space-y-1">
                @foreach ($history as $assignment)
                    <li>{{ $assignment->courier?->name ?? 'Courier' }} · {{ $assignment->assigned_at?->format('M j, Y H:i') }}</li>
                @endforeach
            </ul>
        </details>
    @endif
</section>
