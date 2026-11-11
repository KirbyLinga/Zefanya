@extends('Admin.Layouts.app')

@section('title', 'Transport Overview — Zefanya Admin')
@section('content-width', 'max-w-none')

@section('content')
@php
    $panel = 'min-w-0 rounded-2xl border border-neutral-200 bg-white p-5 shadow-sm sm:p-6';
    $panelTitle = 'font-sans text-[15px] font-semibold text-neutral-950';
    $th = 'whitespace-nowrap border-b border-neutral-200 px-3 py-2.5 text-left font-sans text-[11px] font-semibold uppercase tracking-[0.6px] text-neutral-600';
    $td = 'border-b border-neutral-200 px-3 py-3 font-sans text-[13px] text-neutral-950';
    $stat = 'min-w-0 rounded-xl bg-primary-50 p-4';
    $statLabel = 'font-sans text-[13px] font-medium text-neutral-600';
    $statValue = 'mt-2 break-words font-sans text-2xl font-semibold leading-tight tabular-nums text-primary-800';
    $tz = \App\Models\Seller\StoreSale::TIMEZONE;
@endphp

<div class="space-y-6">
    <div>
        <h1 class="font-serif text-3xl text-neutral-950">Transport Overview</h1>
        <p class="mt-2 max-w-3xl text-sm text-neutral-600">A read-only view of parcels moving between hubs and companies. Custody moves only when a receiving hub scans each parcel; each Logistics company manages its own transfers.</p>
    </div>

    <section data-transport-summary class="{{ $panel }}" aria-labelledby="transportSummaryTitle">
        <h2 id="transportSummaryTitle" class="{{ $panelTitle }}">Right now</h2>
        <div class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div class="{{ $stat }}"><p class="{{ $statLabel }}">Transfer batches in transit</p><p class="{{ $statValue }}">{{ number_format($batchCount) }}</p></div>
            <div class="{{ $stat }}"><p class="{{ $statLabel }}">Provider handoffs in transit</p><p class="{{ $statValue }}">{{ number_format($handoffCount) }}</p></div>
            <div class="{{ $stat }}"><p class="{{ $statLabel }}">Overdue (road {{ $thresholdHours }} h · sea {{ $seaHours }} h · air {{ $airHours }} h)</p><p class="{{ $statValue }} {{ $overdueCount > 0 ? '!text-amber-700' : '' }}">{{ number_format($overdueCount) }}</p></div>
            <div class="{{ $stat }}"><p class="{{ $statLabel }}">Cancelled, awaiting recovery</p><p class="{{ $statValue }} {{ $awaitingRecovery->isNotEmpty() ? '!text-red-700' : '' }}">{{ number_format($awaitingRecovery->count()) }}</p></div>
        </div>
    </section>

    <section data-transport-in-transit class="{{ $panel }}" aria-labelledby="inTransitTitle">
        <h2 id="inTransitTitle" class="{{ $panelTitle }}">In transit · oldest first</h2>
        @if ($inTransit->isEmpty())
            <p class="mt-3 font-sans text-[13px] text-neutral-600">Nothing is moving between hubs right now.</p>
        @else
            <div class="mt-4 overflow-x-auto">
                <table class="w-full min-w-[900px] border-collapse">
                    <thead><tr>
                        <th class="{{ $th }}">Transfer</th><th class="{{ $th }}">Company</th><th class="{{ $th }}">Route</th>
                        <th class="{{ $th }}">Parcels</th><th class="{{ $th }}">Courier</th><th class="{{ $th }}">Dispatched</th><th class="{{ $th }}">Status</th>
                    </tr></thead>
                    <tbody>
                        @foreach ($inTransit as $row)
                            <tr>
                                <td class="{{ $td }}"><span class="font-semibold">{{ $row['reference'] }}</span><span class="block text-[12px] text-neutral-600">{{ $row['kind'] }} · {{ strtolower($row['mode']) }}</span></td>
                                <td class="{{ $td }}">{{ $row['company'] }}</td>
                                <td class="{{ $td }}">{{ $row['route'] }}</td>
                                <td class="{{ $td }} tabular-nums">{{ $row['parcels'] }}</td>
                                <td class="{{ $td }}">
                                    @if ($row['courier'])
                                        {{ $row['courier']->name }}<span class="block text-[12px] text-neutral-600">{{ $row['courier']->contact_no }}{{ $row['vehicle'] !== '' ? ' · '.$row['vehicle'] : '' }}</span>
                                    @else
                                        <span class="text-neutral-600">Not recorded</span>
                                    @endif
                                </td>
                                <td class="{{ $td }} whitespace-nowrap">{{ $row['dispatchedAt']?->timezone($tz)->format('M j, Y g:i A') }}<span class="block text-[12px] text-neutral-600">{{ $row['dispatchedAt']?->diffForHumans() }}</span></td>
                                <td class="{{ $td }}"><span class="rounded-full px-2.5 py-1 text-[12px] font-semibold {{ $row['overdue'] ? 'bg-amber-50 text-amber-800' : 'bg-emerald-50 text-emerald-800' }}">{{ $row['overdue'] ? 'Overdue' : 'On time' }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section data-transport-recovery class="{{ $panel }}" aria-labelledby="recoveryTitle">
        <h2 id="recoveryTitle" class="{{ $panelTitle }}">Cancelled after dispatch, awaiting a recovery scan</h2>
        <p class="mt-1 font-sans text-[13px] text-neutral-600">These parcels left a hub before their transfer was cancelled. Each stays held as an exception until the company scans it at a hub.</p>
        @if ($awaitingRecovery->isEmpty())
            <p class="mt-3 font-sans text-[13px] text-neutral-600">None.</p>
        @else
            <div class="mt-4 overflow-x-auto">
                <table class="w-full min-w-[720px] border-collapse">
                    <thead><tr><th class="{{ $th }}">Transfer</th><th class="{{ $th }}">Company</th><th class="{{ $th }}">Route</th><th class="{{ $th }}">Cancelled</th><th class="{{ $th }}">Reason</th></tr></thead>
                    <tbody>
                        @foreach ($awaitingRecovery as $row)
                            <tr>
                                <td class="{{ $td }} font-semibold">{{ $row['reference'] }}</td>
                                <td class="{{ $td }}">{{ $row['company'] }}</td>
                                <td class="{{ $td }}">{{ $row['route'] }}</td>
                                <td class="{{ $td }} whitespace-nowrap">{{ $row['cancelledAt']?->timezone($tz)->format('M j, Y g:i A') }}</td>
                                <td class="{{ $td }}">{{ $row['reason'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
@endsection
