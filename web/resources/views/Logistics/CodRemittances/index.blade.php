@extends('Layouts.logistics')

@section('title', 'COD Remittances')

@section('content')
<div class="flex flex-col gap-6">
    <header class="border-b border-[var(--border-strong)] pb-6">
        <h1 class="font-serif text-[28px] text-[var(--text-ink)] sm:text-[32px]">COD Remittances</h1>
        <p class="mt-2 font-sans text-sm text-[var(--text-muted)]">Confirm each parcel's cash receipt after collecting the full amount from the Rider.</p>
    </header>

    <x-logistics-flash />

    @if ($errors->any())
        <div role="alert" class="rounded-lg border border-red-200 bg-red-50 p-4 font-sans text-sm text-red-900">
            <ul class="list-disc pl-4">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <section aria-labelledby="pendingRemittancesTitle" class="flex flex-col gap-4">
        <h2 id="pendingRemittancesTitle" class="font-serif text-xl text-[var(--text-ink)]">Pending remittances ({{ $remittances->total() }})</h2>

        <div class="overflow-x-auto rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)]">
            <table class="w-full text-left font-sans text-sm text-[var(--text-ink)]">
                <thead class="border-b border-[var(--border-strong)] text-[var(--text-muted)]">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-semibold">Collecting Rider</th>
                        <th scope="col" class="px-4 py-3 font-semibold">Parcel tracking number</th>
                        <th scope="col" class="px-4 py-3 font-semibold">Amount due</th>
                        <th scope="col" class="px-4 py-3 font-semibold">Confirm cash receipt</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($remittances as $remittance)
                        <tr class="border-b border-[var(--border-strong)] last:border-b-0">
                            <td class="px-4 py-4">{{ $remittance->courier?->rider?->fullName() ?: ($remittance->courier?->name ?: 'Courier #'.$remittance->courier_id) }}</td>
                            <td class="px-4 py-4">{{ $remittance->sellerOrder?->tracking_number ?? 'Unavailable' }}</td>
                            <td class="whitespace-nowrap px-4 py-4 font-semibold tabular-nums">&#8369;{{ intdiv($remittance->amount_minor, 100) }}.{{ str_pad((string) ($remittance->amount_minor % 100), 2, '0', STR_PAD_LEFT) }}</td>
                            <td class="px-4 py-4">
                                <form method="POST" action="{{ route('logistics.cod-remittances.confirm', $remittance) }}" class="flex flex-wrap items-end gap-3">
                                    @csrf
                                    <div>
                                        <label for="received-amount-{{ $remittance->id }}" class="mb-1 block text-xs text-[var(--text-muted)]">Cash received (PHP)</label>
                                        <input id="received-amount-{{ $remittance->id }}" name="received_amount" type="text" inputmode="decimal" required pattern="[0-9]{1,9}(\.[0-9]{1,2})?" maxlength="12" placeholder="0.00" class="h-10 w-36 rounded-sm border border-[var(--border-strong)] bg-[var(--bg-surface)] px-3 text-[var(--text-ink)] focus-visible:outline-2 focus-visible:outline-primary-700">
                                    </div>
                                    <button type="submit" class="inline-flex min-h-10 items-center justify-center rounded-sm bg-primary-800 px-4 text-xs font-semibold text-white hover:bg-primary-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-700">Confirm cash received</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-10 text-center text-[var(--text-muted)]">No pending COD remittances.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $remittances->links() }}
    </section>
</div>
@endsection
