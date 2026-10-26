@extends('Layouts.logistics')

@section('title', 'Scan Parcel')

@section('content')
    <p class="mb-2 font-sans text-[11px] font-semibold uppercase tracking-[1.2px] text-[var(--text-muted)]">Operations</p>

    <x-parcel-scanner
        :verify-url="route('logistics.scan.verify')"
        heading="Scan to Confirm"
        subheading="Scan the waybill QR or enter the tracking number to verify Station receipt or sorting. Status changes only after you confirm."
    />
@endsection

@push('scripts')
    @vite('resources/js/parcel-scanner.js')
@endpush
