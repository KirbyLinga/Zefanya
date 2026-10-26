@extends('Layouts.logistics')

@section('title', 'Rider Management')

@section('content')
<div class="flex items-center justify-between mb-6">
    <div>
        <h1 class="font-serif text-2xl text-[var(--text-ink)]">Rider Management</h1>
        <p class="font-sans text-[12.5px] text-[var(--text-muted)] mt-1">
            Approve applications into one of your Station Hubs and manage the riders in your fleet.
        </p>
    </div>
    <a href="{{ route('logistics.riders.create') }}"
       class="inline-flex items-center gap-2 h-10 px-4 rounded-sm font-sans font-semibold text-[12.5px] tracking-[1.4px] uppercase text-white bg-neutral-900 hover:bg-[#9c5c68] transition-colors">
        <i data-lucide="plus" class="w-[16px] h-[16px]"></i>
        Add Rider
    </a>
</div>

<x-logistics-flash :messages="['success', 'warning', 'error']" />

@if ($applications->isNotEmpty())
    <section class="bg-[var(--bg-surface)] border border-[var(--border-strong)] rounded-lg shadow-sm overflow-hidden mb-6">
        <div class="border-b border-[var(--border-strong)] bg-[var(--bg-page)] px-4 py-3">
            <h2 class="font-sans text-[12px] font-semibold uppercase tracking-[0.8px] text-[var(--text-muted)]">
                Pending applications · {{ $applications->count() }}
            </h2>
        </div>
        <table class="w-full text-left font-sans">
            <thead class="bg-[var(--bg-page)] border-b border-[var(--border-strong)]">
                <tr>
                    <th class="text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)] px-4 py-2.5">Applicant</th>
                    <th class="text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)] px-4 py-2.5">Contact</th>
                    <th class="text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)] px-4 py-2.5">Vehicle</th>
                    <th class="text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)] px-4 py-2.5">Assign Station Hub</th>
                    <th class="text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)] px-4 py-2.5 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[var(--border-strong)]">
                @foreach ($applications as $application)
                    <tr>
                        <td class="px-4 py-3">
                            <a href="{{ route('logistics.riders.show', $application) }}" class="font-sans text-[12.5px] font-semibold text-[#9c5c68] hover:text-[#7a4550]">{{ $application->first_name }} {{ $application->last_name }}</a>
                            <p class="font-sans text-[11px] text-[var(--text-muted)]">{{ $application->email }}</p>
                        </td>
                        <td class="px-4 py-3 font-sans text-[12.5px] text-[var(--text-ink)]">{{ $application->contact_no }}</td>
                        <td class="px-4 py-3 font-sans text-[12.5px] text-[var(--text-ink)]">{{ $application->vehicle_type }} · {{ $application->plate_number }}</td>
                        <td class="px-4 py-3" colspan="2">
                            <form method="POST" action="{{ route('logistics.riders.approve', $application) }}" class="flex items-center justify-end gap-2">
                                @csrf
                                <select name="station_hub_id" required class="h-9 min-w-[220px] rounded-sm border border-[var(--border-strong)] bg-white px-2 font-sans text-[11.5px] text-[var(--text-ink)]">
                                    <option value="">Select Station Hub</option>
                                    @foreach ($stations as $station)
                                        <option value="{{ $station->id }}" @selected((string) old('station_hub_id') === (string) $station->id)>
                                            {{ $station->name }} · {{ $station->municipality ?: $station->province_name }}
                                        </option>
                                    @endforeach
                                </select>
                                <button type="submit" @disabled($stations->isEmpty())
                                        class="inline-flex h-9 items-center rounded-sm bg-neutral-900 px-3 font-sans text-[11.5px] font-semibold uppercase tracking-[0.8px] text-white hover:bg-[#9c5c68] disabled:cursor-not-allowed disabled:opacity-50">
                                    Approve
                                </button>
                            </form>
                            @error('station_hub_id')<p class="mt-1 text-right font-sans text-[11px] text-[#a5333d]">{{ $message }}</p>@enderror
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>
@endif

@if ($riders->isEmpty())
    <section class="bg-[var(--bg-surface)] border border-[var(--border-strong)] rounded-lg p-8 shadow-sm flex flex-col items-center text-center">
        <div class="flex h-12 w-12 items-center justify-center rounded-full bg-[#F7D6D0] text-[#7a4550] shadow-sm mb-3">
            <i data-lucide="users" class="w-[20px] h-[20px]"></i>
        </div>
        <p class="font-sans text-[13px] font-semibold text-[var(--text-ink)] mb-1">No riders yet</p>
        <p class="font-sans text-[11.5px] text-[var(--text-muted)] max-w-[360px]">
            Approved applications and riders you add will appear here with their Station Hub and status.
        </p>
    </section>
@else
    <section class="bg-[var(--bg-surface)] border border-[var(--border-strong)] rounded-lg shadow-sm overflow-hidden">
        <table class="w-full text-left font-sans">
            <thead class="bg-[var(--bg-page)] border-b border-[var(--border-strong)]">
                <tr>
                    <th class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)] px-4 py-2.5">Rider</th>
                    <th class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)] px-4 py-2.5">Contact</th>
                    <th class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)] px-4 py-2.5">Station Hub</th>
                    <th class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)] px-4 py-2.5">Vehicle</th>
                    <th class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)] px-4 py-2.5">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[var(--border-strong)]">
                @foreach ($riders as $r)
                    <tr class="group hover:bg-[var(--bg-page)]">
                        <td class="px-4 py-2.5">
                            <a href="{{ route('logistics.riders.show', $r) }}" class="font-sans text-[12.5px] font-semibold text-[#9c5c68] hover:text-[#7a4550]">{{ $r->first_name }} {{ $r->last_name }}</a>
                            <p class="font-sans text-[11px] text-[var(--text-muted)]">{{ $r->email }}</p>
                        </td>
                        <td class="px-4 py-2.5">
                            <p class="font-sans text-[12.5px] text-[var(--text-ink)]">{{ $r->contact_no }}</p>
                        </td>
                        <td class="px-4 py-2.5">
                            <p class="font-sans text-[12.5px] text-[var(--text-ink)]">{{ $r->station?->name ?? 'Unassigned' }}</p>
                            @if ($r->station?->municipality)
                                <p class="font-sans text-[11px] text-[var(--text-muted)]">{{ $r->station->municipality }}</p>
                            @endif
                        </td>
                        <td class="px-4 py-2.5">
                            <p class="font-sans text-[12.5px] text-[var(--text-ink)]">{{ $r->vehicle_type }}</p>
                            <p class="font-sans text-[11px] text-[var(--text-muted)]">{{ $r->plate_number }}</p>
                        </td>
                        <td class="px-4 py-2.5">
                            @include('Logistics.Riders.partials.status', ['status' => $r->status])
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>
@endif
@endsection
