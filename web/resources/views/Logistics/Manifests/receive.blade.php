@extends('Layouts.logistics')

@section('title', 'Receive Manifest #'.$manifest->id)

@section('content')
@use('App\Enums\ManifestStatus')

@php
    $isDispatched = $manifest->status === ManifestStatus::Dispatched;
    $isReceived = $manifest->status === ManifestStatus::Received;
    $actorName = $manifest->provider?->business_name ?? $provider->business_name;
    $receivedPercent = $counts['total'] > 0 ? (int) round(($counts['received'] / $counts['total']) * 100) : 0;
@endphp

<div class="mb-6 border-b border-[var(--border-strong)] pb-6">
    <a href="{{ route('logistics.manifests.show', $manifest) }}" class="mb-2 inline-flex items-center gap-1 font-sans text-[10px] font-semibold uppercase tracking-[0.14em] text-primary-700 hover:underline">
        <i data-lucide="arrow-left" class="size-3"></i>Manifest #{{ $manifest->id }}
    </a>
    <div class="flex flex-wrap items-center gap-3">
        <h1 class="font-serif text-[28px] leading-tight text-[var(--text-ink)] sm:text-[32px]">Receiving at {{ $manifest->destinationHub?->name }}</h1>
        <span class="inline-flex rounded-full border border-[var(--border-strong)] bg-[var(--bg-page)] px-2.5 py-1 font-sans text-[10px] font-semibold uppercase tracking-[0.5px] text-[var(--text-muted)]">{{ $manifest->status->label() }}</span>
    </div>
    <p class="mt-2 font-sans text-xs text-[var(--text-muted)]">From {{ $manifest->originHub?->name }} · Dispatched {{ $manifest->dispatched_at?->format('M j, Y g:i A') ?? 'pending' }} by {{ $actorName }}</p>
</div>

<x-logistics-flash :messages="['success', 'warning', 'error']" />

@if ($errors->any())
    <div class="mb-5 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 font-sans text-[12px] text-rose-800">
        <p class="font-semibold">Receiving was not recorded:</p>
        <ul class="mt-1 list-disc pl-4">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    </div>
@endif

<section class="mb-6 rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-4 shadow-sm">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <p class="font-sans text-[10px] font-semibold uppercase tracking-[0.14em] text-[var(--text-muted)]">Receiving progress</p>
            <p class="mt-1 font-sans text-lg font-semibold text-[var(--text-ink)]">{{ $counts['received'] }} <span class="font-normal text-[var(--text-muted)]">of {{ $counts['total'] }} parcels</span></p>
        </div>
        <p class="font-sans text-[11px] text-[var(--text-muted)]">{{ $counts['pending'] }} pending</p>
    </div>
    <div class="mt-3 h-2 overflow-hidden rounded-full bg-[var(--bg-page)]" role="progressbar" aria-valuenow="{{ $receivedPercent }}" aria-valuemin="0" aria-valuemax="100">
        <div class="h-full rounded-full bg-emerald-600" style="width: {{ $receivedPercent }}%"></div>
    </div>
</section>

@if ($isReceived)
    <section class="mb-6 rounded-lg border border-emerald-200 bg-emerald-50 px-5 py-5">
        <div class="flex items-start gap-3">
            <i data-lucide="check-circle-2" class="mt-0.5 size-5 shrink-0 text-emerald-700"></i>
            <div>
                <h2 class="font-serif text-lg text-emerald-900">Manifest fully received</h2>
                <p class="mt-1 font-sans text-xs leading-relaxed text-emerald-800">All {{ $counts['total'] }} parcels were verified at {{ $manifest->destinationHub?->name }} on {{ $manifest->received_at?->format('M j, Y g:i A') }}. This transfer did not change any parcel's delivery status.</p>
            </div>
        </div>
    </section>
@elseif ($isDispatched && $counts['pending'] > 0)
    <div class="mb-6">
        <x-parcel-scanner
            :verify-url="route('logistics.manifests.scan.verify', $manifest)"
            heading="Scan each incoming parcel"
            subheading="Scan the waybill QR or enter the tracking number. The receiving action appears only after the parcel passes the same secure verification used by dispatch. Partial receipt is allowed."
        />
    </div>
@elseif ($isDispatched)
    <section class="mb-6 rounded-lg border border-amber-200 bg-amber-50 px-5 py-4 font-sans text-xs text-amber-900">No pending parcels are available to receive. Refresh the manifest before scanning again.</section>
@else
    <section class="mb-6 rounded-lg border border-amber-200 bg-amber-50 px-5 py-4 font-sans text-xs leading-relaxed text-amber-900">
        @if ($manifest->status === ManifestStatus::Draft)
            This manifest is still a draft. Dispatch it from the manifest page before receiving parcels.
        @else
            This manifest is {{ strtolower($manifest->status->label()) }} and cannot receive parcels.
        @endif
    </section>
@endif

<section class="overflow-hidden rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] shadow-sm" aria-labelledby="receiving-list-title">
    <div class="border-b border-[var(--border-strong)] px-4 py-4">
        <h2 id="receiving-list-title" class="font-serif text-xl text-[var(--text-ink)]">Expected parcels</h2>
        <p class="mt-1 font-sans text-[11px] text-[var(--text-muted)]">Each parcel needs its own valid receiving scan. A dispatched manifest stays open until the final parcel is received.</p>
    </div>

    @if ($items->isEmpty())
        <div class="px-4 py-10 text-center font-sans text-xs text-[var(--text-muted)]">No parcels on this manifest.</div>
    @else
        <div class="overflow-x-auto">
            <table class="w-full border-collapse text-left">
                <thead>
                    <tr class="border-b border-[var(--border-strong)] bg-[var(--bg-page)]/60">
                        <th scope="col" class="px-4 py-3 font-sans text-[10px] font-semibold uppercase tracking-[0.14em] text-[var(--text-muted)]">Parcel</th>
                        <th scope="col" class="px-4 py-3 font-sans text-[10px] font-semibold uppercase tracking-[0.14em] text-[var(--text-muted)]">Order / seller</th>
                        <th scope="col" class="px-4 py-3 font-sans text-[10px] font-semibold uppercase tracking-[0.14em] text-[var(--text-muted)]">Receiving</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--border-strong)]">
                    @foreach ($items as $item)
                        <tr>
                            <td class="px-4 py-3">
                                <p class="font-mono text-[11px] font-semibold text-[var(--text-ink)]">{{ $item->tracking_number }}</p>
                                <p class="mt-0.5 font-sans text-[10px] text-[var(--text-muted)]">{{ $item->sellerOrder?->order?->reference }} · {{ $item->sellerOrder?->buyerMunicipality() }}</p>
                            </td>
                            <td class="px-4 py-3">
                                <p class="font-sans text-[11px] text-[var(--text-ink)]">{{ $item->sellerOrder?->seller?->business_name }}</p>
                                <p class="mt-0.5 font-sans text-[10px] text-[var(--text-muted)]">{{ $item->sellerOrder?->order?->buyer?->fullName() }}</p>
                            </td>
                            <td class="px-4 py-3">
                                @if ($item->received_at)
                                    <span class="inline-flex items-center gap-1 rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-1 font-sans text-[10px] font-semibold text-emerald-800"><i data-lucide="check" class="size-3"></i>Received</span>
                                    <p class="mt-1 font-sans text-[10px] text-[var(--text-muted)]">{{ $item->received_at->format('M j, Y g:i A') }} · {{ $actorName }}</p>
                                @else
                                    <span class="inline-flex rounded-full border border-amber-200 bg-amber-50 px-2.5 py-1 font-sans text-[10px] font-semibold text-amber-800">Pending scan</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
@endsection

@if ($isDispatched && $counts['pending'] > 0)
    @push('scripts')
        @vite('resources/js/parcel-scanner.js')
    @endpush
@endif
