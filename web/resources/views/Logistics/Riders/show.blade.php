@extends('Layouts.logistics')
@section('title', 'Rider Application')
@section('content')
<div class="mb-6"><h1 class="font-serif text-2xl text-[var(--text-ink)]">{{ $rider->fullName() }}</h1><p class="mt-1 font-sans text-sm text-[var(--text-muted)]">{{ $rider->email }} ? {{ $rider->contact_no }}</p></div>
<x-logistics-flash :messages="['success', 'warning', 'error']" />
<section class="max-w-3xl rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-6 shadow-sm">
    <dl class="grid grid-cols-2 gap-4 font-sans text-sm max-sm:grid-cols-1">
        <div><dt class="text-neutral-500">Status</dt><dd>{{ ucfirst($rider->status) }}</dd></div>
        <div><dt class="text-neutral-500">Birthday / age</dt><dd>{{ $rider->birthday->format('m/d/Y') }} ? {{ $rider->age }}</dd></div>
        <div><dt class="text-neutral-500">Vehicle / plate</dt><dd>{{ ucfirst($rider->vehicle_type) }} ? {{ $rider->plate_number }}</dd></div>
        <div><dt class="text-neutral-500">Address</dt><dd>{{ $rider->house_number }} {{ $rider->street }}, {{ $rider->barangay_name }}, {{ $rider->municipality_name }}, {{ $rider->province_name }} {{ $rider->address_detail }}</dd></div>
    </dl>
    <div class="mt-5 flex gap-4 font-sans text-sm text-[#9c5c68]"><a href="{{ route('logistics.rider-applications.documents.show', [$rider, 'id']) }}">Download ID / license</a><a href="{{ route('logistics.rider-applications.documents.show', [$rider, 'or-cr']) }}">Download OR/CR</a></div>
    @if($rider->status === 'pending')
        <form method="POST" action="{{ route('logistics.riders.decide', $rider) }}" class="mt-6">@csrf
            <label for="reason" class="block font-sans text-sm">Rejection reason (required when rejecting)</label>
            <textarea id="reason" name="reason" maxlength="1000" class="mt-2 w-full rounded-sm border border-neutral-300 p-3 font-sans text-sm">{{ old('reason') }}</textarea>
            @foreach($errors->all() as $error)<p class="font-sans text-sm text-red-700">{{ $error }}</p>@endforeach
            <div class="mt-4 flex gap-3"><button name="decision" value="approved" class="rounded-sm bg-neutral-900 px-5 py-3 font-sans text-sm text-white">Approve</button><button name="decision" value="rejected" class="rounded-sm border border-red-300 px-5 py-3 font-sans text-sm text-red-700">Reject</button></div>
        </form>
    @endif
    @foreach($decisions as $decision)<p class="mt-4 font-sans text-sm">{{ $decision->created_at }} ? {{ ucfirst($decision->to_status) }} @if($decision->reason)? {{ $decision->reason }}@endif</p>@endforeach
</section>
@endsection
