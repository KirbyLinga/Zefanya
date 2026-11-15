@extends('Admin.Layouts.app')

@section('title', 'Lost parcels')

@section('content')
    <h1 class="mb-4 text-2xl font-semibold">Lost parcels</h1>
    <x-logistics-flash :messages="['success', 'error']" />
    @forelse ($cases as $case)
        <section class="mb-4 space-y-3 rounded border border-neutral-200 p-4">
            <h2 class="font-semibold">{{ $case->sellerOrder->tracking_number }} · {{ str_replace('_', ' ', $case->compensation_status) }}</h2>
            <p>{{ $case->provider?->business_name ?? 'Liability not yet assigned' }} · ₱{{ number_format($case->merchandise_minor / 100, 2) }}</p>
            @if ($case->compensation_status === \App\Models\Logistics\ParcelLossCase::REVIEW_REQUIRED)
                <form method="POST" action="{{ route('admin.lost-parcels.assign', $case) }}" class="flex flex-wrap items-end gap-2">
                    @csrf
                    <label>Logistics company <select name="logistics_provider_id" required>
                        <option value="">Choose a company</option>
                        @foreach ($providers as $provider)
                            <option value="{{ $provider->id }}">{{ $provider->business_name }}</option>
                        @endforeach
                    </select></label>
                    <label>Reason <input name="reason" required maxlength="1000"></label>
                    <button class="case-action">Assign liability</button>
                </form>
            @elseif ($case->isPaid())
                <p>Recorded payment reference {{ $case->compensation_reference }}</p>
                <form method="POST" action="{{ route('admin.lost-parcels.adjust', $case) }}" class="flex flex-wrap items-end gap-2">
                    @csrf
                    <label>Signed amount in pesos <input name="amount" inputmode="decimal" required></label>
                    <label>Reason <input name="reason" required maxlength="1000"></label>
                    <button class="case-action">Record adjustment</button>
                </form>
            @else
                <form method="POST" action="{{ route('admin.lost-parcels.pay', $case) }}" class="flex flex-wrap items-end gap-2">
                    @csrf
                    <label>External payment reference <input name="reference" required maxlength="120"></label>
                    <label>Payment date <input name="date" type="date" required></label>
                    <button class="case-action">Record payment evidence</button>
                </form>
            @endif
            @if ($case->recovered_at && ! $case->disposition)
                <form method="POST" action="{{ route('admin.lost-parcels.disposition', $case) }}" class="flex flex-wrap items-end gap-2">
                    @csrf
                    <label>Disposition <select name="disposition" required><option value="return_to_seller">Return to Seller</option><option value="written_off">Written off</option></select></label>
                    <label>Note <input name="note" required maxlength="1000"></label>
                    <button class="case-action">Record disposition</button>
                </form>
            @endif
        </section>
    @empty
        <p>No parcels have been confirmed lost.</p>
    @endforelse
    {{ $cases->links() }}
@endsection
