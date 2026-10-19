@extends('Layouts.logistics')

@section('title', 'Delivery Areas')

@section('content')
<div class="flex items-center justify-between mb-6">
    <div>
        <h1 class="font-serif text-2xl text-[var(--text-ink)]">Delivery Areas</h1>
        <p class="font-sans text-[12.5px] text-[var(--text-muted)] mt-1">
            Areas you cover. The sorting center routes each parcel to the area matching the buyer's municipality, and riders are linked to an area.
        </p>
    </div>
</div>

<x-logistics-flash :messages="['success', 'warning', 'error']" />

<div class="grid grid-cols-1 lg:grid-cols-[1fr_360px] gap-6">
    {{-- Areas list --}}
    <section class="bg-[var(--bg-surface)] border border-[var(--border-strong)] rounded-lg shadow-sm overflow-hidden">
        @if ($areas->isEmpty())
            <div class="p-8 text-center">
                <p class="font-sans text-[13px] font-semibold text-[var(--text-ink)] mb-1">No areas yet</p>
                <p class="font-sans text-[11.5px] text-[var(--text-muted)]">Add your first coverage area on the right.</p>
            </div>
        @else
            <table class="w-full text-left font-sans">
                <thead class="bg-[var(--bg-page)] border-b border-[var(--border-strong)]">
                    <tr>
                        <th class="text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)] px-4 py-2.5">Area</th>
                        <th class="text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)] px-4 py-2.5">Municipality</th>
                        <th class="text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)] px-4 py-2.5">Riders</th>
                        <th class="text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)] px-4 py-2.5 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--border-strong)]">
                    @foreach ($areas as $area)
                        <tr>
                            <td class="px-4 py-3">
                                <p class="font-sans text-[12.5px] font-semibold text-[var(--text-ink)]">{{ $area->name }}</p>
                                @unless ($area->is_active)
                                    <span class="font-sans text-[10.5px] text-amber-700">Inactive</span>
                                @endunless
                            </td>
                            <td class="px-4 py-3 font-sans text-[12.5px] text-[var(--text-muted)]">{{ $area->municipality }}</td>
                            <td class="px-4 py-3 font-sans text-[12.5px] text-[var(--text-ink)]">{{ $area->couriers_count }}</td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-2">
                                    <form method="POST" action="{{ route('logistics.areas.couriers.assign', $area) }}" class="flex items-center gap-2">
                                        @csrf
                                        <select name="courier_id" class="h-8 rounded-sm border border-[var(--border-strong)] bg-[var(--bg-page)] px-2 font-sans text-[11.5px] text-[var(--text-ink)]">
                                            <option value="">Add rider…</option>
                                            @foreach ($couriers as $courier)
                                                @if ($courier->area_id !== $area->id)
                                                    <option value="{{ $courier->id }}">{{ $courier->name }}</option>
                                                @endif
                                            @endforeach
                                        </select>
                                        <button type="submit" class="inline-flex items-center h-8 px-3 rounded-sm font-sans font-semibold text-[11.5px] uppercase text-white bg-primary-700 hover:bg-primary-800">Link</button>
                                    </form>
                                    <form method="POST" action="{{ route('logistics.areas.destroy', $area) }}"
                                          onsubmit="return confirm('Remove this area? Riders linked to it keep their record but lose the area.');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="inline-flex items-center h-8 px-3 rounded-sm font-sans font-semibold text-[11.5px] uppercase text-rose-700 hover:bg-rose-50">Remove</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>

    {{-- Add / update area --}}
    <aside class="flex flex-col gap-6">
        <section class="bg-[var(--bg-surface)] border border-[var(--border-strong)] rounded-lg shadow-sm p-5">
            <h2 class="font-serif text-[17px] text-[var(--text-ink)] mb-4">Add an area</h2>
            <form method="POST" action="{{ route('logistics.areas.store') }}" class="flex flex-col gap-4">
                @csrf
                <div>
                    <label class="block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-[var(--text-muted)] mb-1" for="areaName">Area name</label>
                    <input id="areaName" name="name" required value="{{ old('name') }}"
                           class="h-11 w-full rounded-sm border border-[var(--border-strong)] bg-white px-4 font-sans text-sm text-[var(--text-ink)] outline-none focus:border-[var(--link)]"
                           placeholder="e.g. North Cebu">
                </div>
                <div>
                    <label class="block font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-[var(--text-muted)] mb-1" for="areaMunicipality">Municipality / City</label>
                    <input id="areaMunicipality" name="municipality" required value="{{ old('municipality') }}"
                           class="h-11 w-full rounded-sm border border-[var(--border-strong)] bg-white px-4 font-sans text-sm text-[var(--text-ink)] outline-none focus:border-[var(--link)]"
                           placeholder="Must match the buyer's municipality">
                    <p class="mt-1 font-sans text-[11px] text-[var(--text-muted)]">Parcels whose buyer municipality matches this are routed here automatically.</p>
                </div>
                <button type="submit" class="inline-flex items-center justify-center h-11 rounded-sm font-sans font-semibold text-[12px] tracking-[0.5px] uppercase text-white bg-primary-800 hover:bg-primary-900">Save area</button>
            </form>
        </section>

        <section class="bg-[var(--bg-surface)] border border-[var(--border-strong)] rounded-lg shadow-sm p-5">
            <h2 class="font-serif text-[17px] text-[var(--text-ink)] mb-3">Riders &amp; their areas</h2>
            @if ($couriers->isEmpty())
                <p class="font-sans text-[11.5px] text-[var(--text-muted)]">No riders in your fleet yet.</p>
            @else
                <ul class="flex flex-col divide-y divide-[var(--border-strong)]">
                    @foreach ($couriers as $courier)
                        <li class="flex items-center justify-between py-2">
                            <span class="font-sans text-[12.5px] text-[var(--text-ink)]">{{ $courier->name }}</span>
                            <span class="font-sans text-[11.5px] text-[var(--text-muted)]">{{ $courier->area?->name ?? '— unassigned —' }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </aside>
</div>
@endsection
