@extends('Layouts.logistics')

@section('title', 'Transfer Batches')

@section('logistics-toolbar')
    <span hidden data-logistics-hosted-bell></span>
@endsection

@section('content')
<section class="min-w-0 [&_:is(a,button,summary,input,select,textarea):focus-visible]:outline-2 [&_:is(a,button,summary,input,select,textarea):focus-visible]:outline-offset-4 [&_:is(a,button,summary,input,select,textarea):focus-visible]:outline-[var(--link)]" aria-label="Transfer batches">
<x-logistics.tabs group="transfers" />
@use('App\Enums\ManifestStatus')

@php
    $primaryButton = 'inline-flex min-h-11 items-center justify-center gap-2 rounded-lg px-4 font-sans text-[12.5px] font-semibold no-underline transition-colors bg-[rgb(107_39_55)] text-white hover:bg-[rgb(84_29_42)]';
    $quietButton = 'inline-flex min-h-11 items-center justify-center gap-2 rounded-lg px-4 font-sans text-[12.5px] font-semibold no-underline transition-colors border border-[var(--border-strong)] bg-[var(--bg-surface)] text-[var(--text-ink)] hover:bg-[var(--bg-raised)]';
    $isFiltered = $tab !== 'incoming' && $status !== null;
    $statusStyles = [
        'DRAFT' => 'border-[var(--border-strong)] bg-[var(--bg-page)] text-[var(--text-muted-2)]',
        'DISPATCHED' => 'border-transparent bg-[var(--pill-warning-bg)] text-[var(--status-warning)]',
        'RECEIVED' => 'border-transparent bg-[var(--pill-success-bg)] text-[var(--status-success)]',
        'RECEIVED_WITH_EXCEPTIONS' => 'border-transparent bg-[var(--pill-warning-bg)] text-[var(--status-warning)]',
        'CANCELLED' => 'border-transparent bg-[var(--pill-danger-bg)] text-[var(--status-danger)]',
    ];
@endphp

<div class="mb-6 flex flex-wrap items-center justify-between gap-4">
    <div>
        <p class="mb-2 font-sans text-[11px] font-semibold uppercase tracking-[0.12em] text-[var(--text-muted-2)]">Internal movements</p>
        <h1 class="font-serif text-[26px] leading-tight font-semibold text-[var(--text-ink)] sm:text-[30px]">Transfer Batches</h1>
        <p class="mt-2 max-w-2xl font-sans text-[14px] leading-relaxed text-[var(--text-muted-2)]">Move parcels between your hubs with verified dispatch and receiving scans. Track transfer progress separately from delivery progress.</p>
    </div>
    <div class="flex flex-wrap items-center gap-3">
        <a href="{{ route('logistics.manifests.create') }}" class="{{ $primaryButton }}"><i data-lucide="plus" class="size-4" aria-hidden="true"></i>Create Transfer Batch</a>
        <x-logistics.notification-bell />
    </div>
</div>

<x-logistics-flash :messages="['success', 'warning', 'error']" />

<section aria-labelledby="batch-queue-heading" class="overflow-hidden rounded-xl border border-[var(--border-strong)] bg-[var(--bg-surface)] shadow-sm">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border-strong)] px-4 py-[18px] sm:px-[22px]">
        <h2 id="batch-queue-heading" class="font-sans text-[14px] font-semibold text-[var(--text-ink)]">{{ $tab === 'incoming' ? 'Incoming dispatched batches' : 'Transfer batch queue' }}</h2>
        <p class="font-sans text-[12px] text-[var(--text-muted-2)]">{{ number_format($manifests->total()) }} {{ \Illuminate\Support\Str::plural('batch', $manifests->total()) }}{{ $isFiltered ? ' matching status' : ' in this queue' }} &middot; Newest first</p>
    </div>
@if ($tab !== 'incoming')
    <nav aria-label="Transfer batch status" class="flex flex-wrap gap-2 border-b border-[var(--border-strong)] bg-[var(--bg-raised)] p-4 sm:px-[22px]">
        <a href="{{ route('logistics.manifests.index') }}" @if ($status === null) aria-current="page" @endif class="{{ $quietButton }} {{ $status === null ? '!border-[var(--link)] !bg-[var(--pill-primary-bg)] !text-[var(--link)]' : '' }}">All</a>
        @foreach ($statuses as $manifestStatus)
            <a href="{{ route('logistics.manifests.index', ['status' => $manifestStatus->value]) }}" @if ($status === $manifestStatus) aria-current="page" @endif class="{{ $quietButton }} {{ $status === $manifestStatus ? '!border-[var(--link)] !bg-[var(--pill-primary-bg)] !text-[var(--link)]' : '' }}">{{ $manifestStatus->label() }}</a>
        @endforeach
    </nav>
@endif

@if ($manifests->isEmpty())
    <div class="flex flex-col items-center px-[22px] py-[50px] text-center">
        <span class="mb-[18px] flex h-[52px] w-[52px] items-center justify-center rounded-xl border border-[var(--pill-success-border)] bg-[var(--pill-success-bg)] text-[var(--status-success)]"><i data-lucide="truck" class="size-[25px]" aria-hidden="true"></i></span>
        <h3 class="font-sans text-[16px] font-semibold text-[var(--text-ink)]">{{ $manifests->total() > 0 ? 'No transfer batches on this page' : ($isFiltered ? 'No matching transfer batches' : 'No transfer batches yet') }}</h3>
        <p class="mb-[22px] mt-2 max-w-[470px] font-sans text-[13px] leading-[1.75] text-[var(--text-muted-2)]">{{ $manifests->total() > 0 ? 'There are transfer batches on an earlier page of this queue.' : ($isFiltered ? 'There are no transfer batches with this status. Choose another status or clear the filter.' : ($tab === 'incoming' ? 'Dispatched transfer batches heading to your receiving hub appear here.' : 'Create a transfer batch when parcels need to travel between your Station and Province Hubs. Same-station deliveries do not need one.')) }}</p>
        @if ($manifests->total() > 0)
            <a href="{{ $manifests->url(1) }}" class="{{ $quietButton }}">Back to first page</a>
        @elseif ($isFiltered)
            <a href="{{ route('logistics.manifests.index') }}" class="{{ $quietButton }}">Clear filter</a>
        @else
            <a href="{{ route('logistics.manifests.create') }}" class="{{ $quietButton }}">Create the first transfer batch</a>
        @endif
    </div>
@else
    <div>
        <p class="border-b border-[var(--border-strong)] bg-[var(--bg-raised)] px-4 py-3 font-sans text-[11px] text-[var(--text-muted-2)] sm:px-[22px]">Scroll horizontally to see all batch details and actions.</p>
        <div role="region" aria-label="Transfer batches table" tabindex="0" class="overflow-x-auto overscroll-x-contain focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-[var(--link)]">
            <table class="w-full min-w-[900px] table-fixed border-collapse text-left [&_td]:[overflow-wrap:anywhere]">
                <thead>
                    <tr class="border-b border-[var(--border-strong)] bg-[var(--bg-page)]/60">
                        <th scope="col" class="px-4 py-3 font-sans text-[11px] font-semibold uppercase tracking-[0.14em] text-[var(--text-muted-2)]">Batch</th>
                        <th scope="col" class="px-4 py-3 font-sans text-[11px] font-semibold uppercase tracking-[0.14em] text-[var(--text-muted-2)]">Route</th>
                        <th scope="col" class="px-4 py-3 font-sans text-[11px] font-semibold uppercase tracking-[0.14em] text-[var(--text-muted-2)]">Parcels</th>
                        <th scope="col" class="px-4 py-3 font-sans text-[11px] font-semibold uppercase tracking-[0.14em] text-[var(--text-muted-2)]">Status</th>
                        <th scope="col" class="px-4 py-3 text-right font-sans text-[11px] font-semibold uppercase tracking-[0.14em] text-[var(--text-muted-2)]">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--border-strong)]">
                    @foreach ($manifests as $manifest)
                        @php($received = (int) $manifest->received_items_count)
                        @php($total = (int) $manifest->total_items_count)
                        <tr class="align-top hover:bg-[var(--bg-raised)]">
                            <td class="px-4 py-4">
                                <a href="{{ route('logistics.manifests.show', $manifest) }}" class="inline-flex min-h-11 items-center font-sans text-[13px] font-semibold text-[var(--link)] hover:underline">Batch #{{ $manifest->id }}</a>
                                <p class="mt-1 font-sans text-[11px] text-[var(--text-muted-2)]">{{ $manifest->created_at?->format('M j, Y g:i A') }}</p>
                            </td>
                            <td class="px-4 py-4">
                                <p class="font-sans text-[13px] font-semibold text-[var(--text-ink)]">{{ $manifest->originHub?->name ?? 'Origin hub' }}</p>
                                <p class="mt-1 flex items-center gap-1 font-sans text-[11px] text-[var(--text-muted-2)]"><i data-lucide="arrow-right" class="size-3"></i>{{ $manifest->destinationHub?->name ?? 'Destination hub' }}</p>
                            </td>
                            <td class="px-4 py-4">
                                <p class="font-sans text-[13px] font-semibold text-[var(--text-ink)]">{{ $received }} of {{ $total }} received</p>
                                <p class="mt-1 font-sans text-[11px] text-[var(--text-muted-2)]">{{ max(0, $total - $received) }} pending</p>
                            </td>
                            <td class="px-4 py-4">
                                <span class="inline-flex rounded-md border px-2.5 py-1 font-sans text-[11px] font-semibold uppercase tracking-[0.5px] {{ $statusStyles[$manifest->status->value] ?? $statusStyles['DRAFT'] }}">{{ $manifest->status->label() }}</span>
                                @if ($manifest->dispatched_at)
                                    <p class="mt-2 font-sans text-[11px] text-[var(--text-muted-2)]">Dispatched {{ $manifest->dispatched_at->format('M j, g:i A') }}</p>
                                @endif
                            </td>
                            <td class="px-4 py-4 text-right">
                                <a href="{{ route('logistics.manifests.show', $manifest) }}" class="{{ $quietButton }}">View</a>
                                @if ($manifest->status === ManifestStatus::Dispatched)
                                    <a href="{{ route('logistics.manifests.receive.index', $manifest) }}" class="{{ $primaryButton }} mt-2">Receive</a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <footer class="flex flex-wrap items-center justify-between gap-4 border-t border-[var(--border-strong)] px-4 py-[18px] sm:px-[22px]">
        <p class="font-sans text-[12px] text-[var(--text-muted-2)]">Showing {{ $manifests->firstItem() ?? 0 }} to {{ $manifests->lastItem() ?? 0 }} of {{ number_format($manifests->total()) }} batches</p>
        {{ $manifests->links() }}
    </footer>
@endif
</section>
</section>
@endsection
