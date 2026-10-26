@extends('Layouts.rider')

@section('title', 'Report Failed Delivery')

@section('content')
    @php($addr = $sellerOrder->order?->shipping_address ?? [])

    <div class="mb-6">
        <a href="{{ route('rider.deliveries.index') }}" class="font-sans text-[11.5px] font-semibold uppercase tracking-[0.5px] text-[var(--text-muted)] no-underline hover:text-[var(--text-ink)]">
            ← Delivery Assignments
        </a>
        <h1 class="mt-1 font-serif text-2xl text-[var(--text-ink)]">Report Failed Delivery</h1>
        <p class="font-sans text-[12.5px] text-[var(--text-muted)]">
            Parcel #{{ $sellerOrder->id }} · {{ $addr['name'] ?? $sellerOrder->order?->buyer?->fullName() ?? 'Buyer' }} · {{ $addr['municipality'] ?? '—' }}
        </p>
    </div>

    <x-rider.flash />

    <section class="max-w-2xl rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-4 shadow-sm">
        <form method="POST" action="{{ route('rider.deliveries.failed', $sellerOrder) }}" class="space-y-4">
            @csrf
            <input type="hidden" name="verification_token" value="{{ $verificationToken }}">

            <fieldset>
                <legend class="mb-2 font-sans text-[11px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)]">Reason</legend>

                <div class="space-y-2">
                    @foreach ($reasons as $reason)
                        <label class="flex cursor-pointer items-center gap-3 rounded-md border border-[var(--border-strong)] bg-[var(--bg-page)] px-3 py-2">
                            <input type="radio" name="failure_reason" value="{{ $reason->value }}"
                                   @checked(old('failure_reason') === $reason->value) required
                                   class="h-4 w-4 accent-[var(--color-primary-700)]">
                            <span class="font-sans text-[12.5px] text-[var(--text-ink)]">{{ $reason->label() }}</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <div>
                <label for="failure_notes" class="mb-1 block font-sans text-[11px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)]">
                    Notes <span class="text-[var(--text-muted)]">(required when "Other")</span>
                </label>
                <textarea id="failure_notes" name="failure_notes" rows="3" maxlength="1000"
                          class="w-full rounded-sm border border-[var(--border-strong)] bg-[var(--bg-page)] px-3 py-2 font-sans text-[12.5px] text-[var(--text-ink)]">{{ old('failure_notes') }}</textarea>
            </div>

            <button type="submit"
                class="inline-flex h-10 items-center justify-center rounded-sm bg-rose-700 px-4 font-sans text-[11.5px] font-semibold uppercase tracking-[0.5px] text-white hover:bg-rose-800"
                onclick="return confirm('Record this delivery attempt as failed?');">
                Record Failed Attempt
            </button>
        </form>
    </section>
@endsection
