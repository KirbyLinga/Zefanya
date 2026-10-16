@extends('Layouts.logistics')

@section('title', 'Rider Management')

@section('content')
<div class="flex items-center justify-between mb-6">
    <h1 class="font-serif text-2xl text-[var(--text-ink)]">Rider Management</h1>
    <a href="{{ route('logistics.riders.create') }}"
       class="inline-flex items-center gap-2 h-10 px-4 rounded-sm font-sans font-semibold text-[12.5px] tracking-[1.4px] uppercase text-white bg-neutral-900 hover:bg-[#9c5c68] transition-colors">
        <i data-lucide="plus" class="w-[16px] h-[16px]"></i>
        Add Rider
    </a>
</div>

<x-logistics-flash :messages="['success', 'warning', 'error']" />

@if ($riders->isEmpty())
    <section class="bg-[var(--bg-surface)] border border-[var(--border-strong)] rounded-lg p-8 shadow-sm flex flex-col items-center text-center">
        <div class="flex h-12 w-12 items-center justify-center rounded-full bg-[#F7D6D0] text-[#7a4550] shadow-sm mb-3">
            <i data-lucide="users" class="w-[20px] h-[20px]"></i>
        </div>
        <p class="font-sans text-[13px] font-semibold text-[var(--text-ink)] mb-1">No riders yet</p>
        <p class="font-sans text-[11.5px] text-[var(--text-muted)] max-w-[360px]">
            Riders you add will appear here as Pending Activation until they set their password through the emailed link.
        </p>
    </section>
@else
    <section class="bg-[var(--bg-surface)] border border-[var(--border-strong)] rounded-lg shadow-sm overflow-hidden">
        <table class="w-full text-left font-sans">
            <thead class="bg-[var(--bg-page)] border-b border-[var(--border-strong)]">
                <tr>
                    <th class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)] px-4 py-2.5">Rider</th>
                    <th class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)] px-4 py-2.5">Contact</th>
                    <th class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)] px-4 py-2.5">License · Plate</th>
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
                            <p class="font-sans text-[12.5px] text-[var(--text-ink)]">{{ $r->license_no }}</p>
                            <p class="font-sans text-[11px] text-[var(--text-muted)]">{{ $r->plate_number }}</p>
                        </td>
                        <td class="px-4 py-2.5">
                            <p class="font-sans text-[12.5px] text-[var(--text-ink)]">{{ $r->vehicle_type }}</p>
                        </td>
                        <td class="px-4 py-2.5">
                            @if ($r->status === 'active')
                                <span class="inline-flex items-center rounded-full bg-emerald-50 border border-emerald-200 px-2.5 py-0.5 font-sans text-[11px] font-semibold text-emerald-700">Active</span>
                            @else
                                <span class="inline-flex items-center rounded-full bg-amber-50 border border-amber-200 px-2.5 py-0.5 font-sans text-[11px] font-semibold text-amber-700">Pending Activation</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>
@endif
@endsection

