{{--
    "Money to hand over" card on the rider's Money page.
    The rider marks the cash as handed over; this is a signal only. Logistics still confirms the
    exact amount it received, and only that confirmation moves money.
--}}
@props(['holding', 'company', 'enabled' => false])

@php
    $pending = \App\Models\Logistics\CodRemittance::PENDING_REMITTANCE;
    $toHand = $holding->filter(fn ($row) => $row->status === $pending);
    $waiting = $enabled ? $toHand->filter(fn ($row) => $row->rider_handover_declared_at !== null) : collect();
    $markable = $enabled ? $toHand->filter(fn ($row) => $row->rider_handover_declared_at === null) : collect();
    $peso = fn (int $minor): string => '₱'.number_format($minor / 100, 2);
    $markableMinor = (int) $markable->sum('amount_minor');
    $waitingMinor = (int) $waiting->sum('amount_minor');
@endphp

<section aria-labelledby="money-to-handover-heading"
         class="mb-6 rounded-lg border p-4 shadow-sm {{ $markable->isNotEmpty() ? 'border-amber-300 bg-amber-50' : 'border-[var(--border-strong)] bg-[var(--bg-surface)]' }}">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 id="money-to-handover-heading" class="font-sans text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)]">Money to hand over</h2>
            <p class="mt-1 font-sans text-[26px] font-semibold leading-none tabular-nums text-[var(--text-ink)]">{{ $peso($markableMinor) }}</p>
            <p class="mt-1 font-sans text-[12px] text-[var(--text-muted)]">
                @if ($markable->isNotEmpty())
                    {{ $markable->count() }} {{ \Illuminate\Support\Str::plural('parcel', $markable->count()) }} of cash to bring to {{ $company }}
                @else
                    Nothing left to hand over
                @endif
            </p>
            @if ($waiting->isNotEmpty())
                <p class="mt-2 inline-flex rounded-full bg-sky-100 px-2.5 py-0.5 font-sans text-[11.5px] font-semibold text-sky-900">
                    {{ $peso($waitingMinor) }} handed over &middot; waiting for {{ $company }} to confirm
                </p>
            @endif
        </div>

        @if ($markable->isNotEmpty())
            <form method="POST" action="{{ route('rider.money.handover') }}" class="flex flex-col items-end gap-1">
                @csrf
                @foreach ($markable as $row)
                    <input type="hidden" name="remittances[]" value="{{ $row->id }}">
                @endforeach
                <button type="submit"
                        class="rounded-md bg-primary-700 px-4 py-2 font-sans text-[13px] font-semibold text-white hover:bg-primary-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
                        data-confirm="Mark {{ $peso($markableMinor) }} as handed over to {{ $company }}?">
                    Mark as handed over
                </button>
                <span class="font-sans text-[11px] text-[var(--text-muted)]">Press after you give the cash. {{ $company }} still confirms the amount.</span>
            </form>
        @endif
    </div>
</section>
