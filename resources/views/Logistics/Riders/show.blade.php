@extends('Layouts.logistics')

@section('title', 'Rider Details')

@section('content')
<div class="mb-6 flex items-center justify-between">
    <div>
        <h1 class="font-serif text-2xl text-[var(--text-ink)]">{{ $rider->first_name }} {{ $rider->last_name }}</h1>
        <p class="mt-1 font-sans text-[12.5px] text-[var(--text-muted)]">{{ $rider->email }} · {{ $rider->contact_no }}</p>
    </div>
    @if ($rider->status === 'active')
        <span class="inline-flex items-center rounded-full bg-emerald-50 border border-emerald-200 px-3 py-1 font-sans text-[11px] font-semibold text-emerald-700">Active</span>
    @else
        <span class="inline-flex items-center rounded-full bg-amber-50 border border-amber-200 px-3 py-1 font-sans text-[11px] font-semibold text-amber-700">Pending Activation</span>
    @endif
</div>

<x-logistics-flash :messages="['success', 'warning', 'error']" />

<section class="bg-[var(--bg-surface)] border border-[var(--border-strong)] rounded-lg shadow-sm p-6 max-w-[720px]">
    <div class="grid grid-cols-2 gap-4 max-sm:grid-cols-1 font-sans text-[12.5px]">
        <div><p class="text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-500">Date of Birth</p><p class="mt-0.5 text-neutral-900">{{ $rider->birthday?->format('m/d/Y') }}</p></div>
        <div><p class="text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-500">Vehicle</p><p class="mt-0.5 text-neutral-900">{{ $rider->vehicle_type }} · {{ $rider->plate_number }}</p></div>
        <div><p class="text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-500">License No.</p><p class="mt-0.5 text-neutral-900">{{ $rider->license_no }}</p></div>
        <div><p class="text-[11px] font-semibold uppercase tracking-[0.8px] text-neutral-500">License Expiry</p><p class="mt-0.5 text-neutral-900">{{ $rider->license_expiry?->format('m/d/Y') }}</p></div>
    </div>
    @if ($rider->status !== 'active')
        <form method="POST" action="{{ route('logistics.riders.resend', $rider) }}" class="mt-6">
            @csrf
            <button type="submit"
                    class="inline-flex h-11 cursor-pointer items-center justify-center rounded-sm bg-neutral-900 px-5 font-sans text-[12.5px] font-semibold uppercase tracking-[1.4px] text-white transition-colors hover:bg-[#9c5c68]">Resend Activation</button>
        </form>
    @endif
</section>
@endsection
