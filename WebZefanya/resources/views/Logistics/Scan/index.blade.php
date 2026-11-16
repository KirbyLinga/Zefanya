@extends('Layouts.logistics')

@section('title', 'Scan Parcel')

@section('content')
    <p class="mb-2 font-sans text-[11px] font-semibold uppercase tracking-[1.2px] text-[var(--text-muted)]">Operations</p>

    <x-parcel-scanner
        :verify-url="route('logistics.scan.verify')"
        :tracking="request()->query('tracking')"
        heading="Scan parcel"
        subheading="Scan the waybill QR or enter any tracking number. You'll see the parcel's status and its next step: confirm hub receipt or sorting here, or jump to the page that handles it. Status changes only after you confirm."
    />
@endsection

@push('scripts')
    @vite('resources/js/parcel-scanner.js')
@endpush
