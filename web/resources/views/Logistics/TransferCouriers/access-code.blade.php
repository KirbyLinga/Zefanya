@extends('Layouts.logistics')

@section('title', 'Courier app access code')

@section('content')
{{-- Rendered once from the POST that issued the code: never cached, flashed or put in a URL. --}}
<section class="min-w-0 max-w-2xl" aria-labelledby="access-code-heading">
    <x-logistics.tabs group="transfers" />

    <p class="mb-2 font-sans text-[11px] font-semibold uppercase tracking-[0.12em] text-[var(--text-muted-2)]">Courier app access</p>
    <h1 id="access-code-heading" class="font-serif text-[26px] leading-tight font-semibold text-[var(--text-ink)]">Give these to {{ $courier->name }}</h1>
    <p class="mt-2 font-sans text-[14px] leading-relaxed text-[var(--text-muted-2)]">Hand them over in person. The Courier opens the Zefanya Courier app, chooses <strong>Activate</strong>, enters both and sets their own password.</p>

    <dl class="mt-6 grid gap-4 rounded-xl border border-[var(--border-strong)] bg-[var(--bg-surface)] p-5 shadow-sm sm:grid-cols-2">
        <div>
            <dt class="font-sans text-xs font-semibold text-[var(--text-muted-2)]">Login ID</dt>
            <dd class="mt-1 font-mono text-[22px] font-semibold tracking-[0.08em] text-[var(--text-ink)]" data-login-id>{{ $loginId }}</dd>
        </div>
        <div>
            <dt class="font-sans text-xs font-semibold text-[var(--text-muted-2)]">One-time access code</dt>
            <dd class="mt-1 font-mono text-[22px] font-semibold tracking-[0.08em] text-[var(--text-ink)]" data-access-code>{{ $code }}</dd>
        </div>
    </dl>

    <ul class="mt-5 list-disc space-y-1 pl-5 font-sans text-[13px] leading-relaxed text-[var(--text-muted-2)]">
        <li>The code works once and expires {{ $expiresAt?->timezone(\App\Models\Seller\StoreSale::TIMEZONE)->format('M j, Y g:i A') }}. After 5 wrong tries it stops working.</li>
        <li>This page is shown only once. If the code is lost, issue a new one from Couriers.</li>
        <li>Any earlier code, password and app session for this Courier no longer work.</li>
        <li>The app shows the transfers you assign. Dispatch and receipt are still recorded only by your hub scans.</li>
    </ul>

    <a href="{{ route('logistics.transfer-couriers.index') }}" class="mt-6 inline-flex min-h-11 items-center justify-center rounded-lg bg-[rgb(107_39_55)] px-4 font-sans text-[12.5px] font-semibold text-white no-underline hover:bg-[rgb(84_29_42)]">Done, back to Couriers</a>
</section>
@endsection
