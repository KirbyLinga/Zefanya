@extends('Layouts.logistics')

@section('title', 'Couriers')

@section('logistics-toolbar')
    <span hidden data-logistics-hosted-bell></span>
@endsection

@section('content')
<section class="min-w-0 [&_:is(a,button,summary,input,select,textarea):focus-visible]:outline-2 [&_:is(a,button,summary,input,select,textarea):focus-visible]:outline-offset-4 [&_:is(a,button,summary,input,select,textarea):focus-visible]:outline-[var(--link)]" aria-label="Couriers">
<x-logistics.tabs group="transfers" />

@php
    $primaryButton = 'inline-flex min-h-11 items-center justify-center gap-2 rounded-lg px-4 font-sans text-[12.5px] font-semibold no-underline transition-colors bg-[rgb(107_39_55)] text-white hover:bg-[rgb(84_29_42)]';
    $quietButton = 'inline-flex min-h-9 items-center justify-center gap-2 rounded-lg px-3 font-sans text-[12px] font-semibold no-underline transition-colors border border-[var(--border-strong)] bg-[var(--bg-surface)] text-[var(--text-ink)] hover:bg-[var(--bg-raised)]';
    $field = 'mt-1 min-h-11 w-full min-w-0 rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] px-3 font-sans text-[13px] text-[var(--text-ink)]';
    $label = 'block font-sans text-xs font-semibold text-[var(--text-muted-2)]';
    $vehicles = \App\Support\RiderValidation::VEHICLES;
@endphp

<div class="mb-6 flex flex-wrap items-center justify-between gap-4">
    <div>
        <p class="mb-2 font-sans text-[11px] font-semibold uppercase tracking-[0.12em] text-[var(--text-muted-2)]">Transport personnel</p>
        <h1 class="font-serif text-[26px] leading-tight font-semibold text-[var(--text-ink)] sm:text-[30px]">Couriers</h1>
        <p class="mt-2 max-w-2xl font-sans text-[14px] leading-relaxed text-[var(--text-muted-2)]">The people who carry your transfer batches and handoffs between stations, hubs and partner companies. Couriers are records, not accounts: they do not log in, collect cash on delivery or earn through Zefanya. Pickups and deliveries stay with your Riders.</p>
    </div>
    <x-logistics.notification-bell />
</div>

<x-logistics-flash :messages="['success', 'warning', 'error']" />
@if ($errors->any())
    <div role="alert" class="mb-5 rounded-md border border-red-200 bg-red-50 p-4 font-sans text-xs leading-relaxed text-red-900">
        <ul class="list-disc pl-4">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    </div>
@endif

<section aria-labelledby="add-courier-heading" class="mb-6 rounded-xl border border-[var(--border-strong)] bg-[var(--bg-surface)] p-4 shadow-sm sm:p-[22px]">
    <h2 id="add-courier-heading" class="font-sans text-[14px] font-semibold text-[var(--text-ink)]">Add a Courier</h2>
    <form method="POST" action="{{ route('logistics.transfer-couriers.store') }}" class="mt-4 grid items-end gap-4 sm:grid-cols-2 xl:grid-cols-[minmax(0,2fr)_minmax(0,1.3fr)_minmax(0,1fr)_minmax(0,1fr)_auto]">
        @csrf
        @include('Logistics.TransferCouriers._fields', ['courier' => null])
        <button type="submit" class="{{ $primaryButton }}"><i data-lucide="plus" class="size-4" aria-hidden="true"></i>Add Courier</button>
    </form>
</section>

<section aria-labelledby="courier-list-heading" class="mb-6 overflow-hidden rounded-xl border border-[var(--border-strong)] bg-[var(--bg-surface)] shadow-sm">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border-strong)] px-4 py-[18px] sm:px-[22px]">
        <h2 id="courier-list-heading" class="font-sans text-[14px] font-semibold text-[var(--text-ink)]">Your Couriers</h2>
        <p class="font-sans text-[12px] text-[var(--text-muted-2)]">{{ $couriers->where('status', 'active')->count() }} active &middot; {{ $couriers->count() }} total</p>
    </div>
    @if ($couriers->isEmpty())
        <div class="flex flex-col items-center px-[22px] py-[50px] text-center">
            <span class="mb-[18px] flex h-[52px] w-[52px] items-center justify-center rounded-xl border border-[var(--pill-success-border)] bg-[var(--pill-success-bg)] text-[var(--status-success)]"><i data-lucide="truck" class="size-[25px]" aria-hidden="true"></i></span>
            <h3 class="font-sans text-[16px] font-semibold text-[var(--text-ink)]">No Couriers yet</h3>
            <p class="mt-2 max-w-[470px] font-sans text-[13px] leading-[1.75] text-[var(--text-muted-2)]">Add the people who drive your transfers. New transfer batches and handoffs need one before they can be dispatched.</p>
        </div>
    @else
        <div class="overflow-x-auto">
            <table class="w-full min-w-[720px] text-left font-sans text-[13px] text-[var(--text-ink)]">
                <thead class="border-b border-[var(--border-strong)] bg-[var(--bg-raised)] text-xs">
                    <tr>
                        <th scope="col" class="px-4 py-2.5 font-semibold sm:px-[22px]">Courier</th>
                        <th scope="col" class="px-4 py-2.5 font-semibold">Contact</th>
                        <th scope="col" class="px-4 py-2.5 font-semibold">Vehicle</th>
                        <th scope="col" class="px-4 py-2.5 font-semibold">Status</th>
                        <th scope="col" class="px-4 py-2.5 text-right font-semibold sm:px-[22px]">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--border-strong)]">
                    @foreach ($couriers as $courier)
                        <tr data-courier="{{ $courier->id }}">
                            <td class="px-4 py-3 font-semibold sm:px-[22px]">{{ $courier->name }}</td>
                            <td class="px-4 py-3 font-mono text-[12px]">{{ $courier->contact_no }}</td>
                            <td class="px-4 py-3">{{ $courier->vehicle_type }}@if ($courier->plate_number)<span class="block font-mono text-[11px] text-[var(--text-muted-2)]">{{ $courier->plate_number }}</span>@endif</td>
                            <td class="px-4 py-3">
                                <span class="inline-flex rounded-full border px-2.5 py-1 text-[10px] font-semibold uppercase tracking-[0.1em] {{ $courier->isActive() ? 'border-transparent bg-[var(--pill-success-bg)] text-[var(--status-success)]' : 'border-[var(--border-strong)] bg-[var(--bg-page)] text-[var(--text-muted-2)]' }}">{{ $courier->isActive() ? 'Active' : 'Inactive' }}</span>
                            </td>
                            <td class="px-4 py-3 sm:px-[22px]">
                                <div class="flex flex-wrap justify-end gap-2">
                                    <a href="{{ route('logistics.transfer-couriers.index', ['edit' => $courier->id]) }}#courier-{{ $courier->id }}" class="{{ $quietButton }}">Edit</a>
                                    <form method="POST" action="{{ route('logistics.transfer-couriers.status', $courier) }}" onsubmit="return confirm('{{ $courier->isActive() ? 'Deactivate this Courier? They can no longer be assigned.' : 'Reactivate this Courier?' }}');">
                                        @csrf
                                        <input type="hidden" name="active" value="{{ $courier->isActive() ? 0 : 1 }}">
                                        <button type="submit" class="{{ $quietButton }}">{{ $courier->isActive() ? 'Deactivate' : 'Reactivate' }}</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        @if ($editing === $courier->id)
                            <tr id="courier-{{ $courier->id }}" class="bg-[var(--bg-raised)]">
                                <td colspan="5" class="px-4 py-4 sm:px-[22px]">
                                    <form method="POST" action="{{ route('logistics.transfer-couriers.update', $courier) }}" class="grid items-end gap-4 sm:grid-cols-2 xl:grid-cols-[minmax(0,2fr)_minmax(0,1.3fr)_minmax(0,1fr)_minmax(0,1fr)_auto]">
                                        @csrf
                                        @method('PATCH')
                                        @include('Logistics.TransferCouriers._fields', ['courier' => $courier])
                                        <div class="flex gap-2">
                                            <button type="submit" class="{{ $primaryButton }}">Save</button>
                                            <a href="{{ route('logistics.transfer-couriers.index') }}" class="{{ $quietButton }} min-h-11">Cancel</a>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>

<section aria-labelledby="assignment-history-heading" class="overflow-hidden rounded-xl border border-[var(--border-strong)] bg-[var(--bg-surface)] shadow-sm">
    <div class="border-b border-[var(--border-strong)] px-4 py-[18px] sm:px-[22px]">
        <h2 id="assignment-history-heading" class="font-sans text-[14px] font-semibold text-[var(--text-ink)]">Recent assignments</h2>
    </div>
    @if ($recent->isEmpty())
        <p class="px-4 py-5 font-sans text-[13px] text-[var(--text-muted-2)] sm:px-[22px]">Assignments appear here when you choose a Courier on a transfer batch or handoff.</p>
    @else
        <ul class="divide-y divide-[var(--border-strong)] font-sans text-[13px]">
            @foreach ($recent as $entry)
                @php
                    $route = $entry->manifest
                        ? ($entry->manifest->originHub?->name ?? '—').' → '.($entry->manifest->destinationHub?->name ?? '—')
                        : ($entry->handoff?->sendingHub?->name ?? '—').' → '.($entry->handoff?->receivingHub?->name ?? '—');
                @endphp
                <li class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 sm:px-[22px]">
                    <span class="min-w-0">
                        <span class="font-semibold text-[var(--text-ink)]">{{ $entry->courier?->name ?? 'Courier' }}</span>
                        <span class="text-[var(--text-muted-2)]">· {{ $entry->vehicle_type }}{{ $entry->plate_number ? ' · '.$entry->plate_number : '' }} · {{ $route }}</span>
                        <span class="block text-[12px] text-[var(--text-muted-2)]">{{ $entry->assigned_at->timezone(\App\Models\Seller\StoreSale::TIMEZONE)->format('M j, Y g:i A') }}{{ $entry->replaced_at ? ' · replaced' : '' }}</span>
                    </span>
                    @if ($entry->manifest_id)
                        <a href="{{ route('logistics.manifests.show', $entry->manifest_id) }}" class="{{ $quietButton }}">Batch #{{ $entry->manifest_id }}</a>
                    @elseif ($entry->parcel_handoff_id)
                        <a href="{{ route('logistics.handoffs.show', $entry->parcel_handoff_id) }}" class="{{ $quietButton }}">Handoff #{{ $entry->parcel_handoff_id }}</a>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</section>
</section>
@endsection
