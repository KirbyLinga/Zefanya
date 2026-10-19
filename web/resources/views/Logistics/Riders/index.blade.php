@extends('Layouts.logistics')

@section('title', 'Rider Management')

@section('content')
<div class="flex items-center justify-between mb-6">
    <h1 class="font-serif text-2xl text-[var(--text-ink)]">Rider Management</h1>

</div>

<x-logistics-flash :messages="['success', 'warning', 'error']" />

@if ($riders->isEmpty())
    <section class="bg-[var(--bg-surface)] border border-[var(--border-strong)] rounded-lg p-8 shadow-sm flex flex-col items-center text-center">
        <div class="flex h-12 w-12 items-center justify-center rounded-full bg-[#F7D6D0] text-[#7a4550] shadow-sm mb-3">
            <i data-lucide="users" class="w-[20px] h-[20px]"></i>
        </div>
        <p class="font-sans text-[13px] font-semibold text-[var(--text-ink)] mb-1">No riders yet</p>
        <p class="font-sans text-[11.5px] text-[var(--text-muted)] max-w-[360px]">
            Verified Rider applications sent to your Logistics provider will appear here for review.
        </p>
    </section>
@else
    <section class="bg-[var(--bg-surface)] border border-[var(--border-strong)] rounded-lg shadow-sm overflow-hidden">
        <table class="w-full text-left font-sans">
            <thead class="bg-[var(--bg-page)] border-b border-[var(--border-strong)]">
                <tr>
                    <th class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)] px-4 py-2.5">Rider</th>
                    <th class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)] px-4 py-2.5">Contact</th>
                    <th class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)] px-4 py-2.5">License Â· Plate</th>
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
                            <p class="font-sans text-[12.5px] text-[var(--text-ink)]">{{ $r->fullName() }}</p>
                            <p class="font-sans text-[11px] text-[var(--text-muted)]">{{ $r->plate_number }}</p>
                        </td>
                        <td class="px-4 py-2.5">
                            <p class="font-sans text-[12.5px] text-[var(--text-ink)]">{{ $r->vehicle_type }}</p>
                        </td>
                        <td class="px-4 py-2.5">
                            @if ($r->status === 'approved')
                                <span class="inline-flex items-center rounded-full bg-emerald-50 border border-emerald-200 px-2.5 py-0.5 font-sans text-[11px] font-semibold text-emerald-700">Approved</span>
                            @else
                                <span class="inline-flex items-center rounded-full bg-amber-50 border border-amber-200 px-2.5 py-0.5 font-sans text-[11px] font-semibold text-amber-700">{{ ucfirst($r->status) }}</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>
@endif
{{ $riders->links() }}
@endsection
