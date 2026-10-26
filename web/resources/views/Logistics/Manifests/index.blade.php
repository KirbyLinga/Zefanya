@extends('Layouts.logistics')

@section('title', 'Manifests')

@section('content')
@use('App\Enums\ManifestStatus')

@php
    $statusStyles = [
        'DRAFT' => 'border-[var(--border-strong)] bg-[var(--bg-page)] text-[var(--text-muted)]',
        'DISPATCHED' => 'border-amber-200 bg-amber-50 text-amber-800',
        'RECEIVED' => 'border-emerald-200 bg-emerald-50 text-emerald-800',
        'CANCELLED' => 'border-rose-200 bg-rose-50 text-rose-800',
    ];
@endphp

<div class="mb-7 flex flex-wrap items-end justify-between gap-4 border-b border-[var(--border-strong)] pb-6">
    <div>
        <p class="mb-2 font-sans text-[10px] font-semibold uppercase tracking-[0.18em] text-primary-700">Internal movements</p>
        <h1 class="font-serif text-[28px] leading-tight text-[var(--text-ink)] sm:text-[32px]">Manifests</h1>
        <p class="mt-2 max-w-2xl font-sans text-xs leading-relaxed text-[var(--text-muted)]">Hub-to-hub transfers inside your own network. Dispatch and receiving require a verified scan; canonical parcel delivery status is never changed here.</p>
    </div>
    <a href="{{ route('logistics.manifests.create') }}"
        class="inline-flex min-h-10 items-center justify-center gap-2 rounded-sm bg-primary-700 px-4 font-sans text-[11px] font-semibold uppercase tracking-[0.5px] text-white hover:bg-primary-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-700">
        <i data-lucide="plus" class="size-3.5"></i>Create Manifest
    </a>
</div>

<x-logistics-flash :messages="['success', 'warning', 'error']" />

<div class="mb-5 flex flex-wrap items-center gap-2">
    <a href="{{ route('logistics.manifests.index') }}"
        class="inline-flex min-h-8 items-center rounded-full border px-3 font-sans text-[11px] font-semibold {{ $status === null ? 'border-primary-700 bg-primary-50 text-primary-800' : 'border-[var(--border-strong)] text-[var(--text-muted)] hover:bg-[var(--bg-surface)]' }}">All</a>
    @foreach ($statuses as $manifestStatus)
        <a href="{{ route('logistics.manifests.index', ['status' => $manifestStatus->value]) }}"
            class="inline-flex min-h-8 items-center rounded-full border px-3 font-sans text-[11px] font-semibold {{ $status === $manifestStatus ? 'border-primary-700 bg-primary-50 text-primary-800' : 'border-[var(--border-strong)] text-[var(--text-muted)] hover:bg-[var(--bg-surface)]' }}">{{ $manifestStatus->label() }}</a>
    @endforeach
</div>

@if ($manifests->isEmpty())
    <section class="rounded-lg border border-dashed border-[var(--border-strong)] bg-[var(--bg-surface)] px-6 py-14 text-center">
        <i data-lucide="truck" class="mx-auto mb-4 size-8 text-primary-400"></i>
        <h2 class="font-serif text-lg text-[var(--text-ink)]">No manifests yet</h2>
        <p class="mx-auto mt-2 max-w-md font-sans text-xs leading-relaxed text-[var(--text-muted)]">Create a manifest when parcels need to travel between your Station and Province Hubs. Same-station deliveries do not need one.</p>
        <a href="{{ route('logistics.manifests.create') }}" class="mt-5 inline-flex min-h-9 items-center rounded-sm border border-[var(--border-strong)] px-4 font-sans text-[11px] font-semibold text-[var(--text-ink)] hover:bg-[var(--bg-page)]">Create the first manifest</a>
    </section>
@else
    <div class="overflow-hidden rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] shadow-sm">
        <div class="overflow-x-auto">
            <table class="w-full border-collapse text-left">
                <thead>
                    <tr class="border-b border-[var(--border-strong)] bg-[var(--bg-page)]/60">
                        <th scope="col" class="px-4 py-3 font-sans text-[10px] font-semibold uppercase tracking-[0.14em] text-[var(--text-muted)]">Manifest</th>
                        <th scope="col" class="px-4 py-3 font-sans text-[10px] font-semibold uppercase tracking-[0.14em] text-[var(--text-muted)]">Route</th>
                        <th scope="col" class="px-4 py-3 font-sans text-[10px] font-semibold uppercase tracking-[0.14em] text-[var(--text-muted)]">Parcels</th>
                        <th scope="col" class="px-4 py-3 font-sans text-[10px] font-semibold uppercase tracking-[0.14em] text-[var(--text-muted)]">Status</th>
                        <th scope="col" class="px-4 py-3 text-right font-sans text-[10px] font-semibold uppercase tracking-[0.14em] text-[var(--text-muted)]">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--border-strong)]">
                    @foreach ($manifests as $manifest)
                        @php($received = (int) $manifest->received_items_count)
                        @php($total = (int) $manifest->total_items_count)
                        <tr class="align-top">
                            <td class="px-4 py-4">
                                <a href="{{ route('logistics.manifests.show', $manifest) }}" class="font-sans text-xs font-semibold text-primary-800 hover:underline">Manifest #{{ $manifest->id }}</a>
                                <p class="mt-1 font-sans text-[10px] text-[var(--text-muted)]">{{ $manifest->created_at?->format('M j, Y g:i A') }}</p>
                            </td>
                            <td class="px-4 py-4">
                                <p class="font-sans text-xs font-semibold text-[var(--text-ink)]">{{ $manifest->originHub?->name ?? 'Origin hub' }}</p>
                                <p class="mt-1 flex items-center gap-1 font-sans text-[10px] text-[var(--text-muted)]"><i data-lucide="arrow-right" class="size-3"></i>{{ $manifest->destinationHub?->name ?? 'Destination hub' }}</p>
                            </td>
                            <td class="px-4 py-4">
                                <p class="font-sans text-xs font-semibold text-[var(--text-ink)]">{{ $received }} of {{ $total }} received</p>
                                <p class="mt-1 font-sans text-[10px] text-[var(--text-muted)]">{{ max(0, $total - $received) }} pending</p>
                            </td>
                            <td class="px-4 py-4">
                                <span class="inline-flex rounded-full border px-2.5 py-1 font-sans text-[10px] font-semibold uppercase tracking-[0.5px] {{ $statusStyles[$manifest->status->value] ?? $statusStyles['DRAFT'] }}">{{ $manifest->status->label() }}</span>
                                @if ($manifest->dispatched_at)
                                    <p class="mt-2 font-sans text-[10px] text-[var(--text-muted)]">Dispatched {{ $manifest->dispatched_at->format('M j, g:i A') }}</p>
                                @endif
                            </td>
                            <td class="px-4 py-4 text-right">
                                <a href="{{ route('logistics.manifests.show', $manifest) }}" class="inline-flex min-h-8 items-center rounded-sm border border-[var(--border-strong)] px-3 font-sans text-[11px] font-semibold text-[var(--text-ink)] hover:bg-[var(--bg-page)]">View</a>
                                @if ($manifest->status === ManifestStatus::Dispatched)
                                    <a href="{{ route('logistics.manifests.receive.index', $manifest) }}" class="ml-2 inline-flex min-h-8 items-center rounded-sm bg-primary-700 px-3 font-sans text-[11px] font-semibold text-white hover:bg-primary-800">Receive</a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-5">{{ $manifests->links() }}</div>
@endif
@endsection
