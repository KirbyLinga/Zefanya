@extends('Admin.Layouts.app')

@section('title', 'Transport Overview')

@section('content')
    <h1 class="mb-4 font-serif text-3xl text-neutral-950">Transport Overview</h1>
    @foreach (['Handoffs' => $handoffs, 'Transfer batches' => $manifests] as $title => $rows)
        <section class="mb-6 rounded-lg border border-neutral-200 bg-white p-4">
            <h2 class="mb-3 font-serif text-xl">{{ $title }}</h2>
            <ul class="divide-y divide-neutral-200">
                @forelse ($rows as $transfer)
                    @php
                        $batch = $transfer instanceof \App\Models\Logistics\Manifest;
                        $cancelled = $transfer->status->value === 'CANCELLED';
                        $from = $batch ? $transfer->originHub : $transfer->sendingHub;
                        $to = $batch ? $transfer->destinationHub : $transfer->receivingHub;
                    @endphp
                    <li class="py-3 text-sm">
                        <p><strong>{{ $batch ? 'Batch' : 'Handoff' }} #{{ $transfer->id }}</strong> · {{ $from?->name }} → {{ $to?->name }}</p>
                        @if ($cancelled)
                            <p>{{ ! $batch && $transfer->awaitsRecovery() ? 'Cancelled after dispatch, awaiting a recovery scan' : 'Cancelled' }}{{ $transfer->cancellation_reason ? ' · '.$transfer->cancellation_reason : '' }}</p>
                        @else
                            <p>{{ $transfers->isOverdue($transfer) ? 'Overdue' : 'On time' }}</p>
                        @endif
                    </li>
                @empty
                    <li class="py-3 text-sm text-neutral-600">No transfers in this view.</li>
                @endforelse
            </ul>
            {{ $rows->links() }}
        </section>
    @endforeach
@endsection
