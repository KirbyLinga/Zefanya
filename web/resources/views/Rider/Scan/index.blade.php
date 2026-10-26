@extends('Layouts.rider')

@section('title', 'Scan Parcel')

@section('content')
    <p class="mb-2 font-sans text-[11px] font-semibold uppercase tracking-[1.2px] text-[var(--text-muted)]">Assignments</p>

    <x-parcel-scanner
        :verify-url="route('rider.scan.verify')"
        heading="Scan parcel"
        subheading="Scan the seller waybill QR or enter the tracking number. Confirm collection or delivery only after the parcel matches your assignment."
    />
@endsection

@push('scripts')
    @vite('resources/js/parcel-scanner.js')
@endpush
