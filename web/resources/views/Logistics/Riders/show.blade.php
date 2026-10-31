@extends('Layouts.logistics')

@section('title', 'Rider Details')

@section('content')
@php
    $addressParts = collect([
        $rider->house_number,
        $rider->street,
        $rider->barangay_name,
        $rider->municipality_name,
        $rider->province_name,
        $rider->postal_code,
    ])->filter()->implode(', ');
@endphp

<div class="mb-6 flex items-center justify-between gap-4">
    <div>
        <h1 class="font-serif text-2xl text-[var(--text-ink)]">{{ $rider->first_name }} {{ $rider->last_name }}</h1>
        <p class="mt-1 font-sans text-[12.5px] text-[var(--text-muted)]">{{ $rider->email }} · {{ $rider->contact_no }}</p>
    </div>
    @include('Logistics.Riders.partials.status', ['status' => $rider->status])
</div>

<x-logistics-flash :messages="['success', 'warning', 'error']" />

<div class="grid grid-cols-1 lg:grid-cols-[1fr_360px] gap-6">
    <section class="bg-[var(--bg-surface)] border border-[var(--border-strong)] rounded-lg shadow-sm p-6">
        <h2 class="font-serif text-[17px] text-[var(--text-ink)] mb-4">Rider details</h2>
        <div class="grid grid-cols-2 gap-4 max-sm:grid-cols-1 font-sans text-[12.5px]">
            <div><p class="text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-500">Date of Birth</p><p class="mt-0.5 text-neutral-900">{{ $rider->birthday?->format('m/d/Y') }}</p></div>
            <div><p class="text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-500">Vehicle</p><p class="mt-0.5 text-neutral-900">{{ $rider->vehicle_type }} · {{ $rider->plate_number }}</p></div>
            <div><p class="text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-500">License No.</p><p class="mt-0.5 text-neutral-900">{{ $rider->license_no }}</p></div>
            <div><p class="text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-500">License Expiry</p><p class="mt-0.5 text-neutral-900">{{ $rider->license_expiry?->format('m/d/Y') }}</p></div>
            <div class="col-span-2 max-sm:col-span-1"><p class="text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-500">Residential Address</p><p class="mt-0.5 text-neutral-900">{{ $addressParts !== '' ? $addressParts : 'Not provided' }}</p></div>
            <div><p class="text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-500">Station Hub</p><p class="mt-0.5 text-neutral-900">{{ $rider->station?->name ?? 'Unassigned' }}</p></div>
            <div><p class="text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-500">Coverage</p><p class="mt-0.5 text-neutral-900">{{ $rider->courier?->area?->name ?? 'No coverage linked' }}</p></div>
            @if ($rider->status_reason)
                <div class="col-span-2 max-sm:col-span-1"><p class="text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-500">Status Reason</p><p class="mt-0.5 text-neutral-900">{{ $rider->status_reason }}</p></div>
            @endif
            @if ($rider->status_changed_at)
                <div><p class="text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-500">Status Changed</p><p class="mt-0.5 text-neutral-900">{{ $rider->status_changed_at->format('m/d/Y g:i A') }}</p></div>
            @endif
        </div>

        @if ($rider->status === 'pending')
            <div class="mt-6 border-t border-[var(--border-strong)] pt-5">
                <h3 class="font-sans text-[12px] font-semibold uppercase tracking-[0.8px] text-[var(--text-muted)] mb-3">Review application</h3>
                <form method="POST" action="{{ route('logistics.riders.approve', $rider) }}" class="flex flex-wrap items-end gap-3">
                    @csrf
                    <div class="flex-1 min-w-[220px]">
                        <label class="mb-1.5 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="riderStation">Station Hub *</label>
                        <select name="station_hub_id" id="riderStation" required class="h-11 w-full rounded-sm border border-neutral-300 bg-white px-3 font-sans text-[13px] text-neutral-900 outline-none focus:border-[#9c5c68]">
                            <option value="">Select Station Hub</option>
                            @foreach ($stations as $station)
                                <option value="{{ $station->id }}" @selected((string) old('station_hub_id') === (string) $station->id)>
                                    {{ $station->name }} · {{ $station->municipality ?: $station->province_name }}
                                </option>
                            @endforeach
                        </select>
                        @error('station_hub_id')<span class="mt-1 block font-sans text-[11.5px] text-[#a5333d]">{{ $message }}</span>@enderror
                    </div>
                    <button type="submit" @disabled($stations->isEmpty())
                            class="inline-flex h-11 items-center justify-center rounded-sm bg-neutral-900 px-5 font-sans text-[12.5px] font-semibold uppercase tracking-[1.4px] text-white transition-colors hover:bg-[#9c5c68] disabled:cursor-not-allowed disabled:opacity-50">
                        Approve
                    </button>
                </form>
                @if ($stations->isEmpty())
                    <p class="mt-2 font-sans text-[11px] text-[#a5333d]">Create and activate an approved Station Hub before approving applications.</p>
                @endif

                <form method="POST" action="{{ route('logistics.riders.reject', $rider) }}" class="mt-4 flex flex-wrap items-end gap-3"
                      onsubmit="return confirm('Reject this rider application?');">
                    @csrf
                    <div class="flex-1 min-w-[220px]">
                        <label class="mb-1.5 block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-600" for="riderRejectReason">Rejection reason (10–300 characters) *</label>
                        <input type="text" name="reason" id="riderRejectReason" value="{{ old('reason') }}" minlength="10" maxlength="300" required
                               class="h-11 w-full rounded-sm border border-neutral-300 bg-white px-3 font-sans text-[13px] text-neutral-900 outline-none focus:border-[#9c5c68]">
                        @error('reason')<span class="mt-1 block font-sans text-[11.5px] text-[#a5333d]">{{ $message }}</span>@enderror
                    </div>
                    <button type="submit" class="inline-flex h-11 items-center justify-center rounded-sm border border-rose-300 px-5 font-sans text-[12.5px] font-semibold uppercase tracking-[1.4px] text-rose-700 transition-colors hover:bg-rose-50">
                        Reject
                    </button>
                </form>
            </div>
        @endif
    </section>

    <aside class="flex flex-col gap-6">
        @if (! in_array($rider->status, ['pending', 'rejected'], true))
            <section class="bg-[var(--bg-surface)] border border-[var(--border-strong)] rounded-lg shadow-sm p-5">
                <h2 class="font-serif text-[17px] text-[var(--text-ink)] mb-3">Station Hub</h2>
                <form method="POST" action="{{ route('logistics.riders.station', $rider) }}" class="flex flex-col gap-3">
                    @csrf
                    @method('PATCH')
                    <select name="station_hub_id" required class="h-11 w-full rounded-sm border border-[var(--border-strong)] bg-white px-3 font-sans text-[13px] text-[var(--text-ink)]">
                        <option value="">Select Station Hub</option>
                        @foreach ($stations as $station)
                            <option value="{{ $station->id }}" @selected((int) old('station_hub_id', $rider->station_hub_id) === (int) $station->id)>
                                {{ $station->name }} · {{ $station->municipality ?: $station->province_name }}
                            </option>
                        @endforeach
                    </select>
                    @error('station_hub_id')<span class="font-sans text-[11.5px] text-[#a5333d]">{{ $message }}</span>@enderror
                    <button type="submit" @disabled($stations->isEmpty())
                            class="inline-flex h-10 items-center justify-center rounded-sm border border-neutral-300 font-sans text-[12px] font-semibold uppercase tracking-[1px] text-neutral-700 transition-colors hover:bg-neutral-50 disabled:cursor-not-allowed disabled:opacity-50">
                        Update Station
                    </button>
                </form>
                <p class="mt-2 font-sans text-[11px] text-[var(--text-muted)]">Moving stations clears coverage that belonged to the previous station.</p>
            </section>
        @endif

        @if ($rider->status === 'pending_activation')
            <section class="bg-[var(--bg-surface)] border border-[var(--border-strong)] rounded-lg shadow-sm p-5">
                <h2 class="font-serif text-[17px] text-[var(--text-ink)] mb-2">Activation</h2>
                <p class="mb-3 font-sans text-[11.5px] text-[var(--text-muted)]">The rider must set a password through the emailed link before they can be assigned parcels.</p>
                <form method="POST" action="{{ route('logistics.riders.resend', $rider) }}">
                    @csrf
                    <button type="submit"
                            class="inline-flex h-10 w-full items-center justify-center rounded-sm bg-neutral-900 px-5 font-sans text-[12px] font-semibold uppercase tracking-[1.4px] text-white transition-colors hover:bg-[#9c5c68]">
                        Resend Activation
                    </button>
                </form>
            </section>
        @endif

        @if (in_array($rider->status, ['approved', 'active'], true))
            <section class="bg-[var(--bg-surface)] border border-[var(--border-strong)] rounded-lg shadow-sm p-5">
                <h2 class="font-serif text-[17px] text-[var(--text-ink)] mb-2">Suspend or deactivate</h2>
                <p class="mb-3 font-sans text-[11.5px] text-[var(--text-muted)]">Parcels already assigned are flagged for reassignment; their status is not changed.</p>
                <form method="POST" action="{{ route('logistics.riders.suspend', $rider) }}" class="flex flex-col gap-3">
                    @csrf
                    <label for="riderSuspendReason" class="text-xs font-semibold text-[var(--text-ink)]">Suspension reason (required)</label>
                    <input type="text" name="reason" value="{{ old('reason') }}" id="riderSuspendReason" required minlength="10" maxlength="300" placeholder="Reason (10–300 characters)"
                           class="h-10 w-full rounded-sm border border-[var(--border-strong)] bg-white px-3 font-sans text-[12.5px] text-[var(--text-ink)]">
                    @error('reason')<span class="font-sans text-[11.5px] text-[#a5333d]">{{ $message }}</span>@enderror
                    <button type="submit" class="inline-flex h-10 items-center justify-center rounded-sm border border-amber-300 font-sans text-[12px] font-semibold uppercase tracking-[1px] text-amber-700 transition-colors hover:bg-amber-50">
                        Suspend Rider
                    </button>
                </form>
                <form method="POST" action="{{ route('logistics.riders.deactivate', $rider) }}" class="mt-3"
                      onsubmit="return confirm('Deactivate this rider? Active parcels will be flagged for reassignment.');">
                    @csrf
                    <button type="submit" class="inline-flex h-10 w-full items-center justify-center rounded-sm border border-rose-300 font-sans text-[12px] font-semibold uppercase tracking-[1px] text-rose-700 transition-colors hover:bg-rose-50">
                        Deactivate Rider
                    </button>
                </form>
            </section>
        @endif

        @if (in_array($rider->status, ['suspended', 'deactivated'], true))
            <section class="bg-[var(--bg-surface)] border border-[var(--border-strong)] rounded-lg shadow-sm p-5">
                <h2 class="font-serif text-[17px] text-[var(--text-ink)] mb-2">Reactivate</h2>
                <p class="mb-3 font-sans text-[11.5px] text-[var(--text-muted)]">The rider must still be attached to an approved, active Station Hub.</p>
                <form method="POST" action="{{ route('logistics.riders.reactivate', $rider) }}">
                    @csrf
                    <button type="submit" class="inline-flex h-10 w-full items-center justify-center rounded-sm bg-neutral-900 px-5 font-sans text-[12px] font-semibold uppercase tracking-[1.4px] text-white transition-colors hover:bg-[#9c5c68]">
                        Reactivate Rider
                    </button>
                </form>
            </section>
        @endif
    </aside>
</div>
@endsection
