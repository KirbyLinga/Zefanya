@extends('Layouts.logistics')

@section('title', 'Lost parcels')

@section('content')
    <h1 class="mb-4 text-2xl font-semibold">Lost parcels</h1>
    <x-logistics-flash :messages="['success', 'error']" />
    @forelse ($cases as $case)
        <section class="mb-4 space-y-3 rounded border border-[var(--border-strong)] p-4">
            <h2 class="font-semibold">{{ $case->sellerOrder->tracking_number }}</h2>
            <p>₱{{ number_format($case->netCompensationMinor() / 100, 2) }} · {{ ucfirst(str_replace('_', ' ', $case->compensation_status)) }} @if ($case->compensation_reference) · {{ $case->compensation_reference }} @endif</p>
            @if ($case->recovered_at)
                <p>Found at hub #{{ $case->recovered_hub_id }}.</p>
            @elseif (session('loss_scan.id') == $case->id)
                <form method="POST" action="{{ route('logistics.lost-parcels.recover', $case) }}">
                    @csrf
                    <input type="hidden" name="verification_token" value="{{ session('loss_scan.token') }}">
                    <input type="hidden" name="hub" value="{{ session('loss_scan.hub') }}">
                    <button class="font-semibold">Record as found</button>
                </form>
            @else
                <form method="POST" action="{{ route('logistics.lost-parcels.scan.verify', $case) }}" class="flex flex-wrap items-end gap-2">
                    @csrf
                    <label>Operational hub <select name="hub" required>
                        <option value="">Select a hub</option>
                        @foreach ($hubs as $hub)
                            <option value="{{ $hub->id }}">{{ $hub->name }}</option>
                        @endforeach
                    </select></label>
                    <label>Scan tracking number <input name="tracking_number" required maxlength="64"></label>
                    <button class="font-semibold">Verify scan</button>
                </form>
                <p class="text-sm">Verify the tracking number at your hub before using Record as found.</p>
            @endif
        </section>
    @empty
        <p>No lost parcels are assigned to this company.</p>
    @endforelse
    {{ $cases->links() }}
@endsection
