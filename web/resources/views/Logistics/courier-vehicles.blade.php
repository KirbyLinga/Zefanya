@extends('Layouts.logistics')
@section('title', 'Courier records')
@section('content')
<section class="rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-6 text-[var(--text-ink)]">
    <h1 class="mb-4 text-xl font-semibold">Courier records</h1>
    <p class="mb-5">Review your company's hub-to-hub Couriers, their vehicles and private documents. Riders are managed separately.</p>
    @forelse ($records as $record)
        <div class="border-t border-[var(--border-strong)] py-4">
            <a class="font-semibold underline" href="{{ route('logistics.couriers.vehicle', $record['driver_id']) }}">{{ $record['name'] }}</a>
            <p>{{ $record['status'] }} · {{ $record['setup_required'] ? 'Setup pending' : ($record['profile']['review_status'] ?? 'No records submitted') }}</p>
            @if ($record['profile'])<p>{{ $record['profile']['plate_number'] }} · {{ $record['profile']['capacity_kg'] }} kg</p>@endif
        </div>
    @empty
        <p>No Couriers have been provisioned for your company.</p>
    @endforelse
    {{ $drivers->links() }}
</section>
@endsection
