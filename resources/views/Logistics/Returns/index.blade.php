@extends('Layouts.logistics')

@section('title', 'Physical Returns')

@use('App\Enums\ParcelReturnStatus')

@section('logistics-toolbar')
    <span hidden data-logistics-hosted-bell></span>
@endsection

@section('content')
@php
    $buttonBase = 'inline-flex min-h-11 items-center justify-center gap-2 rounded-lg px-4 font-sans text-[12.5px] font-semibold no-underline transition-colors';
    $primaryButton = $buttonBase.' bg-[rgb(107_39_55)] text-white hover:bg-[rgb(84_29_42)]';
    $quietButton = $buttonBase.' border border-[var(--border-strong)] bg-[var(--bg-surface)] text-[var(--text-ink)] hover:bg-[var(--bg-raised)]';
@endphp
<section aria-labelledby="returns-heading" class="min-w-0 [overflow-wrap:anywhere] [&_a:focus-visible]:outline-2 [&_a:focus-visible]:outline-offset-4 [&_a:focus-visible]:outline-[var(--link)]">
    <header class="mb-6 flex flex-wrap items-center justify-between gap-4">
        <div class="min-w-0">
            <p class="mb-2 font-sans text-[11px] font-semibold uppercase tracking-[0.12em] text-[var(--text-muted-2)]">Reverse logistics</p>
            <h1 id="returns-heading" class="font-serif text-[26px] leading-tight font-semibold text-[var(--text-ink)] sm:text-[30px]">Physical Returns</h1>
            <p class="mt-2 max-w-2xl font-sans text-[14px] leading-relaxed text-[var(--text-muted-2)]">Track parcels on their way back to the seller, through transport and verified receipt.</p>
        </div>
        <x-logistics.notification-bell />
    </header>

    <x-logistics-flash :messages="['success', 'warning', 'error']" />
    @if ($errors->any())
        <div role="alert" class="mb-4 rounded-lg border border-[var(--border-error-soft)] bg-[var(--bg-error-soft)] p-4 font-sans text-[13px] text-[var(--status-danger)]">
            @foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach
        </div>
    @endif

    <section aria-labelledby="return-queue-heading" class="overflow-hidden rounded-xl border border-[var(--border-strong)] bg-[var(--bg-surface)] shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border-strong)] px-4 py-[18px] sm:px-[22px]">
            <h2 id="return-queue-heading" class="font-sans text-[14px] font-semibold text-[var(--text-ink)]">Return transport queue</h2>
            <p class="font-sans text-[12px] text-[var(--text-muted-2)]">{{ number_format($returns->total()) }} {{ \Illuminate\Support\Str::plural('return', $returns->total()) }} in your authorized network &middot; Newest first</p>
        </div>

        @if ($returns->isEmpty())
            <div class="flex flex-col items-center px-[22px] py-[50px] text-center">
                <span class="mb-[18px] flex h-[52px] w-[52px] items-center justify-center rounded-xl border border-[var(--pill-success-border)] bg-[var(--pill-success-bg)] text-[var(--status-success)]"><i data-lucide="package-check" class="size-[25px]" aria-hidden="true"></i></span>
                <h3 class="font-sans text-[16px] font-semibold text-[var(--text-ink)]">{{ $returns->total() > 0 ? 'No physical returns on this page' : 'No physical returns in your authorized network' }}</h3>
                <p class="mb-[22px] mt-2 max-w-[470px] font-sans text-[13px] leading-[1.75] text-[var(--text-muted-2)]">{{ $returns->total() > 0 ? 'There are physical returns on an earlier page of this queue.' : 'When a parcel needs to travel back to the seller, its return transport and receipt details will appear here.' }}</p>
                <a href="{{ $returns->total() > 0 ? $returns->url(1) : route('logistics.delivery-monitoring.index') }}" class="{{ $quietButton }}">{{ $returns->total() > 0 ? 'Back to first page' : 'View delivery tracking' }}</a>
            </div>
        @else
            <div class="grid gap-4 p-4 sm:p-[22px] xl:grid-cols-2">
                @foreach ($returns as $record)
                    @php
                        $statusTone = match ($record->status) {
                            ParcelReturnStatus::Completed => 'bg-[var(--pill-success-bg)] text-[var(--status-success)]',
                            ParcelReturnStatus::Rejected, ParcelReturnStatus::InDispute => 'bg-[var(--pill-danger-bg)] text-[var(--status-danger)]',
                            ParcelReturnStatus::InTransit => 'bg-[var(--pill-primary-bg)] text-[var(--link)]',
                            default => 'bg-[var(--pill-warning-bg)] text-[var(--status-warning)]',
                        };
                    @endphp
                    <article class="flex min-w-0 flex-col overflow-hidden rounded-xl border border-[var(--border-strong)] bg-[var(--bg-surface)]">
                        <header class="border-b border-[var(--border-strong)] bg-[var(--bg-raised)] p-4">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <h3 class="font-sans text-[13px] font-semibold text-[var(--text-ink)]">Return #{{ $record->id }}</h3>
                                <span class="{{ $statusTone }} inline-flex rounded-md px-2.5 py-1 font-sans text-[11px] font-semibold">{{ ucfirst(strtolower($record->status->label())) }}</span>
                            </div>
                            <p class="mt-3 font-mono text-[12px] font-semibold text-[var(--text-ink)]">{{ $record->sellerOrder->tracking_number ?? 'Tracking requires review' }}</p>
                        </header>
                        <div class="flex flex-1 flex-col p-4">
                            <p class="mb-4 font-sans text-[13px] leading-relaxed text-[var(--text-body)]">{{ $record->reason }}</p>
                            <dl class="grid grid-cols-[90px_minmax(0,1fr)] gap-x-4 gap-y-3 font-sans text-[12px] leading-relaxed sm:grid-cols-[125px_minmax(0,1fr)]">
                                <dt class="text-[var(--text-muted-2)]">Current accountable hub</dt>
                                <dd class="text-[var(--text-ink)]">{{ $record->sellerOrder->custodyHub?->name ?? $record->sellerOrder->originStation?->name ?? 'Needs verification' }}</dd>
                                <dt class="text-[var(--text-muted-2)]">Original handover hub</dt>
                                <dd class="text-[var(--text-ink)]">{{ $record->originHub?->name ?? 'Needs Admin verification' }}</dd>
                                <dt class="text-[var(--text-muted-2)]">Return destination</dt>
                                <dd class="text-[var(--text-ink)]">{{ $record->seller_address['full'] ?? 'Original pickup address requires review' }}</dd>
                                <dt class="text-[var(--text-muted-2)]">Original carrier</dt>
                                <dd class="text-[var(--text-ink)]">{{ $record->originProvider?->business_name ?? 'Requires review' }}</dd>
                                <dt class="text-[var(--text-muted-2)]">Requested</dt>
                                <dd class="text-[var(--text-ink)]">{{ $record->requested_at->format('M j, Y g:i A') }}</dd>
                                @if ($record->seller_received_at)
                                    <dt class="text-[var(--text-muted-2)]">Seller receipt</dt>
                                    <dd class="text-[var(--text-ink)]">{{ $record->seller_received_at->format('M j, Y g:i A') }}</dd>
                                @endif
                            </dl>
                            @if ($record->exception_reason)
                                <p class="mt-4 rounded-lg border border-[var(--status-warning)] bg-[var(--pill-warning-bg)] p-3 font-sans text-[12px] leading-relaxed text-[var(--status-warning)]"><strong class="font-semibold">Exception:</strong> {{ $record->exception_reason }}</p>
                            @endif
                            <div class="mt-auto pt-5">
                                <a href="{{ route('logistics.returns.show', $record) }}" class="{{ $primaryButton }}">Open return<i data-lucide="arrow-right" class="size-4" aria-hidden="true"></i></a>
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif

        @if ($returns->total() > 0)
            <footer class="flex flex-wrap items-center justify-between gap-4 border-t border-[var(--border-strong)] px-4 py-[18px] sm:px-[22px]">
                <p class="font-sans text-[12px] text-[var(--text-muted-2)]">Showing {{ $returns->firstItem() ?? 0 }} to {{ $returns->lastItem() ?? 0 }} of {{ number_format($returns->total()) }} returns</p>
                {{ $returns->links() }}
            </footer>
        @endif
    </section>
</section>
@endsection
