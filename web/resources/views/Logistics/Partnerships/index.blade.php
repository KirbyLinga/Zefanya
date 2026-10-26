@extends('Layouts.logistics')

@section('title', 'Provider Partnerships')

@section('content')
<div data-partnerships-page>
    <header class="mb-7 flex flex-wrap items-end justify-between gap-4 border-b border-[var(--border-strong)] pb-6">
        <div>
            <p class="mb-2 font-sans text-[10px] font-semibold uppercase tracking-[0.18em] text-primary-700">Logistics network</p>
            <h1 class="font-serif text-[28px] leading-tight text-[var(--text-ink)] sm:text-[32px]">Provider Partnerships</h1>
            <p class="mt-2 font-sans text-xs text-[var(--text-muted)]">{{ $provider->business_name }} <span aria-hidden="true">/</span> {{ $provider->province_name ?: 'Service province' }}</p>
        </div>
        <a href="{{ route('logistics.hubs.index') }}" class="inline-flex min-h-10 items-center justify-center gap-2 rounded-sm border border-[var(--border-strong)] px-3 font-sans text-[11px] font-semibold text-[var(--text-ink)] transition hover:bg-[var(--bg-surface)] focus-visible:outline-2 focus-visible:outline-primary-700">
            <i data-lucide="network" class="size-3.5"></i>Hubs and Stations
        </a>
    </header>

    <x-logistics-flash :messages="['success', 'warning', 'error']" />
    @if($errors->any())
        <div role="alert" class="mb-5 rounded-md border border-red-200 bg-red-50 p-4 font-sans text-xs leading-relaxed text-red-900">
            <p class="font-semibold">Please review the following before saving:</p>
            <ul class="mt-1 list-disc pl-4">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    @php
        $statusStyles = [
            'PENDING' => ['bg' => 'bg-amber-50', 'text' => 'text-amber-800', 'border' => 'border-amber-200'],
            'ACTIVE' => ['bg' => 'bg-emerald-50', 'text' => 'text-emerald-800', 'border' => 'border-emerald-200'],
            'REJECTED' => ['bg' => 'bg-red-50', 'text' => 'text-red-800', 'border' => 'border-red-200'],
            'REVOKED' => ['bg' => 'bg-neutral-100', 'text' => 'text-neutral-700', 'border' => 'border-neutral-300'],
            'INACTIVE' => ['bg' => 'bg-neutral-100', 'text' => 'text-neutral-600', 'border' => 'border-neutral-200'],
        ];
    @endphp

    <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
        @foreach ([
            ['label' => 'Pending', 'key' => 'PENDING', 'icon' => 'clock'],
            ['label' => 'Active', 'key' => 'ACTIVE', 'icon' => 'circle-check'],
            ['label' => 'Rejected', 'key' => 'REJECTED', 'icon' => 'x-circle'],
            ['label' => 'Revoked', 'key' => 'REVOKED', 'icon' => 'shield-off'],
        ] as $card)
            <div class="rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-4">
                <p class="flex items-center gap-2 font-sans text-[10px] font-semibold uppercase tracking-[0.12em] text-[var(--text-muted)]"><i data-lucide="{{ $card['icon'] }}" class="size-3.5 text-primary-600"></i>{{ $card['label'] }}</p>
                <p class="mt-2 font-serif text-2xl text-[var(--text-ink)]">{{ (int) ($counts[$card['key']] ?? 0) }}</p>
            </div>
        @endforeach
    </div>

    <div class="mb-6 grid grid-cols-1 items-start gap-6 xl:grid-cols-[minmax(260px,0.8fr)_minmax(0,2fr)]">
        <aside class="rounded-lg border border-[var(--border-strong)] border-t-2 border-t-primary-300 bg-[var(--bg-surface)] p-5">
            <p class="flex items-center gap-2 font-sans text-xs font-semibold text-primary-800"><i data-lucide="send" class="size-4"></i>Request a partnership</p>
            <p class="mt-2 font-sans text-[11px] leading-relaxed text-[var(--text-muted)]">Only approved, province-verified providers with active operational coverage in their province appear here. Your request is directed and needs the destination provider's acceptance plus Admin approval.</p>

            @if($eligibleDestinations->isEmpty())
                <div class="mt-4 rounded-md border border-dashed border-[var(--border-strong)] bg-[var(--bg-page)]/50 px-4 py-4 font-sans text-[11px] leading-relaxed text-[var(--text-muted)]">
                    No eligible destination providers right now. A provider must be approved, province-verified, and have an approved active Station Hub with validated barangay coverage.
                </div>
            @else
                <form method="POST" action="{{ route('logistics.partnerships.store') }}" class="mt-4 space-y-3">
                    @csrf
                    <div>
                        <label for="destination_provider_id" class="mb-1.5 block font-sans text-[10px] font-semibold uppercase tracking-[0.12em] text-[var(--text-muted)]">Destination provider</label>
                        <select id="destination_provider_id" name="destination_provider_id" required class="h-10 w-full rounded-sm border border-[var(--border-strong)] bg-[var(--bg-surface)] px-3 font-sans text-xs text-[var(--text-ink)] focus-visible:outline-2 focus-visible:outline-primary-700">
                            <option value="">Select provider…</option>
                            @foreach($eligibleDestinations as $destination)
                                <option value="{{ $destination->id }}" @selected((string) old('destination_provider_id') === (string) $destination->id)>
                                    {{ $destination->business_name }} — {{ $destination->province_name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit" class="inline-flex min-h-10 w-full items-center justify-center gap-2 rounded-sm bg-primary-800 px-4 font-sans text-[11px] font-semibold text-white transition hover:bg-primary-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-700">
                        <i data-lucide="handshake" class="size-3.5"></i>Send partnership request
                    </button>
                </form>
            @endif
        </aside>

        <section aria-labelledby="partnershipsTitle" class="min-w-0">
            <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 id="partnershipsTitle" class="font-serif text-xl text-[var(--text-ink)]">Partnership links</h2>
                    <p class="mt-1 font-sans text-[10px] text-[var(--text-muted)]">Incoming requests need your response. Outgoing requests show the other provider's progress.</p>
                </div>
                <p class="font-sans text-[10px] text-[var(--text-muted)]">{{ $links->total() }} total</p>
            </div>

            <div class="mb-4 rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-4">
                <form method="GET" action="{{ route('logistics.partnerships.index') }}" class="flex flex-wrap items-end gap-3">
                    <div class="min-w-[180px] flex-1">
                        <label for="partnershipSearch" class="sr-only">Search</label>
                        <input id="partnershipSearch" type="search" name="search" value="{{ $search }}" placeholder="Search provider…" class="h-9 w-full rounded-sm border border-[var(--border-strong)] bg-[var(--bg-surface)] px-3 font-sans text-xs text-[var(--text-ink)] focus-visible:outline-2 focus-visible:outline-primary-700">
                    </div>
                    <div>
                        <label for="partnershipStatus" class="sr-only">Status</label>
                        <select id="partnershipStatus" name="status" class="h-9 rounded-sm border border-[var(--border-strong)] bg-[var(--bg-surface)] px-3 font-sans text-xs text-[var(--text-ink)]">
                            <option value="all" @selected($status === 'all')>All statuses</option>
                            <option value="pending" @selected($status === 'pending')>Pending</option>
                            <option value="active" @selected($status === 'active')>Active</option>
                            <option value="rejected" @selected($status === 'rejected')>Rejected</option>
                            <option value="revoked" @selected($status === 'revoked')>Revoked</option>
                            <option value="inactive" @selected($status === 'inactive')>Inactive</option>
                        </select>
                    </div>
                    <button type="submit" class="inline-flex h-9 items-center justify-center rounded-sm bg-primary-800 px-3 font-sans text-[11px] font-semibold text-white hover:bg-primary-900">Filter</button>
                    <a href="{{ route('logistics.partnerships.index') }}" class="inline-flex h-9 items-center justify-center rounded-sm border border-[var(--border-strong)] px-3 font-sans text-[11px] font-semibold text-[var(--text-muted)] hover:bg-[var(--bg-page)]">Reset</a>
                </form>
            </div>

            <div class="space-y-3">
                @forelse($links as $link)
                    @php
                        $incoming = (int) $link->destination_provider_id === (int) $provider->id;
                        $counterpart = $incoming ? $link->originProvider : $link->destinationProvider;
                        $style = $statusStyles[$link->status] ?? ['bg' => 'bg-neutral-100', 'text' => 'text-neutral-700', 'border' => 'border-neutral-200'];
                        $approved = $link->approved_at !== null;
                    @endphp
                    <article id="partnership-{{ $link->id }}" class="rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-5 shadow-sm">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="inline-flex items-center rounded-full border {{ $incoming ? 'border-indigo-200 bg-indigo-50 text-indigo-700' : 'border-sky-200 bg-sky-50 text-sky-700' }} px-2 py-0.5 font-sans text-[9px] font-semibold uppercase tracking-[0.1em]">
                                        {{ $incoming ? 'Incoming' : 'Outgoing' }}
                                    </span>
                                    <span class="inline-flex items-center rounded-full border px-2 py-0.5 font-sans text-[9px] font-semibold uppercase tracking-[0.1em] {{ $style['bg'] }} {{ $style['text'] }} {{ $style['border'] }}">
                                        {{ $link->statusLabel() }}
                                    </span>
                                </div>
                                <h3 class="mt-2 break-words font-serif text-[19px] leading-snug text-[var(--text-ink)]">{{ $counterpart?->business_name ?? 'Provider' }}</h3>
                                <p class="mt-1 font-sans text-[11px] text-[var(--text-muted)]">
                                    {{ $incoming ? 'Requested by' : 'Destination' }} <span class="font-semibold text-[var(--text-ink)]">{{ $incoming ? $link->originProvider?->business_name : $link->destinationProvider?->business_name }}</span>
                                    @if($counterpart?->province_name)
                                        <span aria-hidden="true">·</span> {{ $counterpart->province_name }}
                                    @endif
                                </p>
                            </div>
                            <div class="text-right">
                                <p class="font-sans text-[9px] font-semibold uppercase tracking-[0.12em] text-[var(--text-muted)]">Priority</p>
                                <p class="mt-0.5 font-serif text-xl text-[var(--text-ink)]">{{ $link->priority }}</p>
                            </div>
                        </div>

                        <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 font-sans text-[10px] leading-relaxed text-[var(--text-muted)]">
                            <span>Requested {{ $link->created_at?->format('M d, Y h:i A') ?? '—' }}</span>
                            @if($link->destination_consented_at)
                                <span>Accepted {{ $link->destination_consented_at->format('M d, Y h:i A') }}</span>
                            @endif
                            @if($approved)
                                <span>Admin approved {{ $link->approved_at->format('M d, Y h:i A') }}</span>
                            @endif
                        </div>

                        @if($link->rejection_reason)
                            <div class="mt-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 font-sans text-[11px] leading-relaxed text-red-900">
                                <span class="font-semibold">Rejected:</span> {{ $link->rejection_reason }}
                                @if($link->rejector?->business_name)
                                    <span class="text-red-700">— {{ $link->rejector->business_name }}</span>
                                @endif
                            </div>
                        @endif

                        @if($link->revocation_reason)
                            <div class="mt-3 rounded-md border border-neutral-300 bg-neutral-50 px-3 py-2 font-sans text-[11px] leading-relaxed text-neutral-800">
                                <span class="font-semibold">Revoked by Admin:</span> {{ $link->revocation_reason }}
                            </div>
                        @endif

                        @if($incoming && $link->isPending())
                            <div class="mt-4 border-t border-[var(--border-strong)] pt-4">
                                @if($link->destination_consented_at === null)
                                    <div class="flex flex-wrap items-center gap-2">
                                        <form method="POST" action="{{ route('logistics.partnerships.accept', $link) }}">
                                            @csrf
                                            <button type="submit" class="inline-flex min-h-9 items-center justify-center gap-1.5 rounded-sm bg-emerald-700 px-3 font-sans text-[11px] font-semibold text-white hover:bg-emerald-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700">
                                                <i data-lucide="check" class="size-3.5"></i>Accept partnership
                                            </button>
                                        </form>
                                        <details class="group">
                                            <summary class="inline-flex min-h-9 cursor-pointer list-none items-center justify-center gap-1.5 rounded-sm border border-red-200 px-3 font-sans text-[11px] font-semibold text-red-700 hover:bg-red-50 focus-visible:outline-2 focus-visible:outline-red-700 [&::-webkit-details-marker]:hidden">
                                                <i data-lucide="x" class="size-3.5"></i>Reject
                                            </summary>
                                            <form method="POST" action="{{ route('logistics.partnerships.reject', $link) }}" class="mt-3 rounded-md border border-red-200 bg-red-50/60 p-3">
                                                @csrf
                                                <label for="rejection_reason_{{ $link->id }}" class="mb-1 block font-sans text-[10px] font-semibold uppercase tracking-[0.1em] text-red-800">Reason for rejection</label>
                                                <textarea id="rejection_reason_{{ $link->id }}" name="rejection_reason" required maxlength="500" rows="2" class="w-full rounded-sm border border-red-200 bg-white p-2 font-sans text-xs text-[var(--text-ink)] focus:outline-none focus:ring-1 focus:ring-red-600" placeholder="Explain why this partnership cannot proceed.">{{ old('rejection_reason') }}</textarea>
                                                <button type="submit" class="mt-2 inline-flex min-h-8 items-center justify-center rounded-sm bg-red-700 px-3 font-sans text-[10px] font-semibold text-white hover:bg-red-800">Confirm rejection</button>
                                            </form>
                                        </details>
                                    </div>
                                @else
                                    <p class="flex items-center gap-2 font-sans text-[11px] text-amber-800"><i data-lucide="clock" class="size-3.5"></i>Accepted. Waiting for Admin approval before the partnership becomes active.</p>
                                @endif
                            </div>
                        @elseif($link->isPending())
                            <p class="mt-4 flex items-center gap-2 border-t border-[var(--border-strong)] pt-4 font-sans text-[11px] text-[var(--text-muted)]">
                                <i data-lucide="{{ $link->destination_consented_at ? 'shield-check' : 'clock' }}" class="size-3.5 shrink-0 text-primary-600"></i>
                                {{ $link->destination_consented_at ? 'Destination accepted. Waiting for Admin approval.' : 'Waiting for the destination provider to respond.' }}
                            </p>
                        @endif
                    </article>
                @empty
                    <div class="rounded-lg border border-dashed border-[var(--border-strong)] bg-[var(--bg-surface)] px-6 py-14 text-center">
                        <i data-lucide="handshake" class="mx-auto mb-4 size-8 text-primary-400"></i>
                        <h3 class="font-serif text-lg text-[var(--text-ink)]">No partnership links found</h3>
                        <p class="mx-auto mt-2 max-w-sm font-sans text-xs leading-relaxed text-[var(--text-muted)]">Send a request to an eligible destination provider, or adjust the filters above.</p>
                    </div>
                @endforelse
            </div>

            @if($links->hasPages())
                <div class="mt-5">{{ $links->links() }}</div>
            @endif
        </section>
    </div>
</div>
@endsection
