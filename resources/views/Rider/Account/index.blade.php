@extends('Layouts.rider')

@section('title', 'My Account')

@section('content')
    <div class="mb-6">
        <p class="font-sans text-[11px] font-semibold uppercase tracking-[1.2px] text-[var(--text-muted)]">Settings</p>
        <h1 class="font-serif text-2xl text-[var(--text-ink)]">My Account</h1>
        <p class="font-sans text-[12.5px] text-[var(--text-muted)]">
            Company, station and coverage are managed by your Logistics company and cannot be changed here.
        </p>
    </div>

    <x-rider.flash />

    @php
        $address = collect([
            $rider->house_number,
            $rider->street,
            $rider->barangay_name,
            $rider->municipality_name,
            $rider->province_name,
            $rider->postal_code,
        ])->filter()->implode(', ');
    @endphp

    <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
        <section class="rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-4 shadow-sm">
            <h2 class="mb-3 font-sans text-[13px] font-semibold text-[var(--text-ink)]">Personal details</h2>

            <dl class="space-y-2">
                <div>
                    <dt class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)]">Name</dt>
                    <dd class="font-sans text-[12.5px] text-[var(--text-ink)]">{{ $rider->fullName() }}</dd>
                </div>
                <div>
                    <dt class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)]">Email</dt>
                    <dd class="font-sans text-[12.5px] text-[var(--text-ink)]">{{ $rider->email }}</dd>
                </div>
                <div>
                    <dt class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)]">Contact number</dt>
                    <dd class="font-sans text-[12.5px] text-[var(--text-ink)]">{{ $rider->contact_no ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)]">Home address</dt>
                    <dd class="font-sans text-[12.5px] text-[var(--text-ink)]">{{ $address !== '' ? $address : '—' }}</dd>
                </div>
            </dl>
        </section>

        <section class="rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-4 shadow-sm">
            <h2 class="mb-3 font-sans text-[13px] font-semibold text-[var(--text-ink)]">Assignment</h2>

            <dl class="space-y-2">
                <div>
                    <dt class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)]">Logistics company</dt>
                    <dd class="font-sans text-[12.5px] text-[var(--text-ink)]">{{ $rider->provider?->business_name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)]">Station Hub</dt>
                    <dd class="font-sans text-[12.5px] text-[var(--text-ink)]">{{ $rider->station?->name ?? '—' }}</dd>
                    <dd class="font-sans text-[11.5px] text-[var(--text-muted)]">{{ $rider->station?->municipality ?? '' }}</dd>
                </div>
                <div>
                    <dt class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)]">Account status</dt>
                    <dd class="font-sans text-[12.5px] capitalize text-[var(--text-ink)]">{{ str_replace('_', ' ', $rider->status) }}</dd>
                </div>
                <div>
                    <dt class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)]">Vehicle</dt>
                    <dd class="font-sans text-[12.5px] text-[var(--text-ink)]">
                        {{ $rider->vehicle_type ?? '—' }}
                        @if ($rider->plate_number)
                            <span class="ml-1 font-mono text-[11px] text-[var(--text-muted)]">{{ $rider->plate_number }}</span>
                        @endif
                    </dd>
                </div>
            </dl>

            <form method="POST" action="{{ route('unified.logout') }}" class="mt-5">
                @csrf
                <button type="submit"
                    class="inline-flex h-9 items-center justify-center rounded-sm border border-[var(--border-strong)] bg-[var(--bg-page)] px-4 font-sans text-[11.5px] font-semibold uppercase tracking-[0.5px] text-[var(--text-ink)] hover:bg-[var(--sidebar-hover)]">
                    Log out
                </button>
            </form>
        </section>
    </div>
@endsection
