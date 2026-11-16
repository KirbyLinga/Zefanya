@extends('Layouts.logistics')
@section('title', 'Verify original delivery address')
@section('content')
<section class="mx-auto max-w-2xl rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-6">
    <h1 class="font-serif text-xl text-[var(--text-ink)]">Verify original delivery address for parcel #{{ $parcel->id }}</h1>
    <p class="mt-3 text-sm text-[var(--text-muted)]">Review the original checkout evidence below. Verification adds an audit record; the shipping snapshot, status and existing rider assignments stay unchanged.</p>
    <dl class="mt-4 grid grid-cols-2 gap-2 text-sm text-[var(--text-ink)]">
        @foreach (['province', 'province_name', 'municipality', 'municipality_name', 'barangay', 'barangay_name', 'street', 'house_number', 'address_detail'] as $field)
            @if (filled($parcel->order->shipping_address[$field] ?? null))
                <dt>{{ ucfirst(str_replace('_', ' ', $field)) }}</dt><dd>{{ $parcel->order->shipping_address[$field] }}</dd>
            @endif
        @endforeach
    </dl>
    <p class="mt-4 text-sm font-semibold">Unique PSGC match: {{ $resolved['barangay_name'] }}, {{ $resolved['municipality_name'] }}, {{ $resolved['province_name'] }} ({{ $resolved['barangay_code'] }})</p>
    <form method="POST" action="{{ route('logistics.parcel-sorting.address.verify', $parcel) }}" class="mt-4 flex flex-col gap-3">
        @csrf
        <input type="hidden" name="snapshot_hash" value="{{ $resolved['snapshot_hash'] }}">
        <input type="hidden" name="barangay_code" value="{{ $resolved['barangay_code'] }}">
        <label class="text-sm" for="evidence">Original evidence reviewed</label>
        <textarea id="evidence" name="evidence" required maxlength="1000" class="rounded-sm border border-[var(--border-strong)] bg-[var(--bg-surface)] p-3">{{ old('evidence') }}</textarea>
        @foreach ($errors->all() as $error)<p role="alert" class="text-sm text-red-700">{{ $error }}</p>@endforeach
        <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="confirmed" value="1" required> I verified this match against the original shipping evidence, not a current profile or an assumed address.</label>
        <button type="submit" class="rounded-sm bg-primary-800 px-4 py-2 text-sm font-semibold text-white">Confirm verified barangay and reconcile coverage</button>
        <a href="{{ route('logistics.parcel-sorting.index') }}" class="text-sm text-primary-800">Back to parcel sorting</a>
    </form>
</section>
@endsection
