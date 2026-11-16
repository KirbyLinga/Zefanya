@extends('Layouts.logistics')

@section('title', 'Riders')
@section('logistics-toolbar')<span hidden></span>@endsection

@section('content')
@php
    $viewActionClass = 'inline-flex min-h-9 items-center justify-center gap-1.5 rounded-[7px] border border-[var(--border-btn-outline)] bg-[var(--bg-surface)] px-3 py-2 text-[11px] font-semibold text-[var(--text-ink)] shadow-sm transition-colors hover:border-primary-300 hover:bg-primary-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-700';
    $statusLabels = [
        'approved' => 'Approved', 'active' => 'Active', 'pending' => 'Pending Review',
        'pending_activation' => 'Pending Activation', 'unverified' => 'Unverified',
        'suspended' => 'Suspended', 'deactivated' => 'Deactivated', 'rejected' => 'Rejected',
    ];
    $fleetStatuses = $riders->pluck('status')->filter()->unique()->sort();
@endphp

<header class="mb-4 flex items-start justify-between gap-4">
    <div class="min-w-0">
        <h1 class="font-serif text-2xl text-[var(--text-ink)]">Riders</h1>
        <p class="mt-1 font-sans text-[13px] text-[var(--text-ink)]/80">
            Review applications, assign Station Hubs, and manage your fleet.
        </p>
    </div>
    @auth('logistics')
        <x-logistics.notification-bell />
    @endauth
</header>

<x-logistics-flash :messages="['success', 'warning', 'error']" />

@if ($applications->isNotEmpty())
    <section aria-labelledby="applications-heading" class="mb-6 overflow-hidden rounded-xl border border-[var(--border-input)] bg-[var(--bg-surface)] shadow-sm">
        <div class="flex items-start gap-3 border-b border-[var(--border-strong)] px-5 py-4">
            <span class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-amber-50 text-amber-800"><i data-lucide="user-round-check" class="h-4 w-4" aria-hidden="true"></i></span>
            <div>
                <h2 id="applications-heading" class="font-sans text-sm font-semibold text-[var(--text-ink)]">Pending applications <span class="ml-1 text-[var(--text-muted)]">({{ $applications->count() }})</span></h2>
                <p class="mt-1 font-sans text-xs text-[var(--text-muted)]">Review each rider, then select a Station Hub to approve their application.</p>
                @if ($stations->isEmpty())
                    <p id="station-unavailable" class="mt-2 font-sans text-xs font-medium text-amber-800">No Station Hubs available. Set up a Station Hub before approving riders.</p>
                @endif
            </div>
        </div>
        <div class="relative overflow-x-auto focus-visible:outline-2 focus-visible:outline-primary-700" role="region" aria-label="Pending rider applications" tabindex="0">
            <table class="w-full min-w-[850px] text-left font-sans">
                <caption class="sr-only">Pending rider applications and Station Hub approval actions</caption>
                <thead class="border-b border-[var(--border-strong)] bg-[var(--bg-raised)] text-[11.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-ink)]">
                    <tr>
                        <th scope="col" class="px-5 py-3">Applicant</th>
                        <th scope="col" class="px-5 py-3">Contact</th>
                        <th scope="col" class="px-5 py-3">Vehicle</th>
                        <th scope="col" class="px-5 py-3">Assign Station Hub</th>
                        <th scope="col" class="px-5 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--border-strong)] text-[12.5px] text-[var(--text-ink)]">
                    @foreach ($applications as $application)
                        <tr class="hover:bg-[var(--bg-page)]">
                            <td class="px-5 py-4">
                                <a data-rider-view href="{{ route('logistics.riders.show', $application) }}" class="font-semibold text-[#9c5c68] hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-700">{{ $application->first_name }} {{ $application->last_name }}</a>
                                <p class="mt-1 text-[11px] text-[var(--text-muted)]">{{ $application->email }}</p>
                            </td>
                            <td class="whitespace-nowrap px-5 py-4">{{ $application->contact_no }}</td>
                            <td class="px-5 py-4">{{ $application->vehicle_type }}<p class="mt-1 text-[11px] text-[var(--text-muted)]">{{ $application->plate_number }}</p></td>
                            <td class="px-5 py-4">
                                <form id="approve-rider-{{ $application->id }}" method="POST" action="{{ route('logistics.riders.approve', $application) }}">
                                    @csrf
                                    <label for="station-hub-{{ $application->id }}" class="sr-only">Station Hub for {{ $application->first_name }} {{ $application->last_name }}</label>
                                    <select id="station-hub-{{ $application->id }}" name="station_hub_id" required @disabled($stations->isEmpty()) @if ($errors->has('station_hub_id')) aria-invalid="true" aria-describedby="station-error-{{ $application->id }}" @elseif ($stations->isEmpty()) aria-describedby="station-unavailable" @endif class="h-10 w-full min-w-[200px] rounded-[7px] border border-[var(--border-input)] bg-[var(--bg-surface)] px-3 text-[13px] text-[var(--text-ink)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-700 disabled:cursor-not-allowed disabled:opacity-50">
                                        <option value="">Select Station Hub</option>
                                        @foreach ($stations as $station)
                                            <option value="{{ $station->id }}" @selected((string) old('station_hub_id') === (string) $station->id)>{{ $station->name }} - {{ $station->municipality ?: $station->province_name }}</option>
                                        @endforeach
                                    </select>
                                    @error('station_hub_id')<p id="station-error-{{ $application->id }}" role="alert" class="mt-1 text-[11px] text-[#a5333d]">{{ $message }}</p>@enderror
                                </form>
                            </td>
                            <td class="px-5 py-4">
                                <div class="flex items-center justify-end gap-2 whitespace-nowrap">
                                    <a data-rider-view href="{{ route('logistics.riders.show', $application) }}" class="{{ $viewActionClass }}"><i data-lucide="eye" class="h-3.5 w-3.5" aria-hidden="true"></i>View<span class="sr-only"> rider {{ $application->first_name }} {{ $application->last_name }}</span></a>
                                    <button form="approve-rider-{{ $application->id }}" type="submit" @disabled($stations->isEmpty()) class="inline-flex min-h-9 items-center justify-center gap-1.5 rounded-[7px] border border-transparent bg-primary-800 px-3 py-2 text-[11px] font-semibold text-white shadow-sm transition-colors hover:bg-primary-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-700 disabled:cursor-not-allowed disabled:opacity-50"><i data-lucide="check" class="h-3.5 w-3.5" aria-hidden="true"></i>Approve<span class="sr-only"> {{ $application->first_name }} {{ $application->last_name }}</span></button>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endif

<section id="rider-fleet" aria-labelledby="fleet-heading" class="overflow-hidden rounded-xl border border-[var(--border-input)] bg-[var(--bg-surface)] shadow-sm">
    <div class="px-5 py-3">
        <h2 id="fleet-heading" class="flex items-center gap-2 font-sans text-sm font-semibold text-[var(--text-ink)]">Your fleet <span class="rounded-md bg-[var(--bg-raised)] px-2 py-0.5 text-xs text-[var(--text-ink)]">{{ $riders->count() }}</span></h2>
    </div>

    @if ($riders->isEmpty())
        <div class="flex flex-col items-center border-t border-[var(--border-strong)] px-6 py-14 text-center font-sans">
            <span class="mb-4 flex h-12 w-12 items-center justify-center rounded-xl bg-[var(--bg-page)] text-[#7a4550]"><i data-lucide="users" class="h-5 w-5" aria-hidden="true"></i></span>
            <h3 class="text-sm font-semibold text-[var(--text-ink)]">No riders yet</h3>
            <p class="mt-2 max-w-sm text-xs leading-relaxed text-[var(--text-muted)]">Riders in your fleet will appear here with their Station Hub and status. Review pending applications above when available.</p>
        </div>
    @else
        <div data-fleet-filters class="hidden border-t border-[var(--border-strong)] bg-[var(--bg-raised)] px-5 py-4 font-sans">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                <div class="min-w-0 flex-1">
                    <label for="rider-search" class="mb-1.5 block text-xs font-semibold text-[var(--text-ink)]">Search riders</label>
                    <div class="relative">
                        <i data-lucide="search" class="pointer-events-none absolute top-3 left-3 h-4 w-4 text-[var(--text-muted)]" aria-hidden="true"></i>
                        <input id="rider-search" type="search" placeholder="Name, email, contact, station, or plate..." autocomplete="off" aria-controls="rider-records" class="h-10 w-full rounded-[7px] border border-[var(--border-input)] bg-[var(--bg-surface)] pr-3 pl-9 text-[13px] text-[var(--text-ink)] placeholder:text-[var(--text-ink)]/80 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-700">
                    </div>
                </div>
                <div class="sm:w-48">
                    <label for="rider-status" class="mb-1.5 block text-xs font-semibold text-[var(--text-ink)]">Rider status</label>
                    <select id="rider-status" aria-controls="rider-records" class="h-10 w-full rounded-[7px] border border-[var(--border-input)] bg-[var(--bg-surface)] px-3 text-[13px] text-[var(--text-ink)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-700">
                        <option value="">All statuses</option>
                        @foreach ($fleetStatuses as $fleetStatus)
                            <option value="{{ $fleetStatus }}">{{ $statusLabels[$fleetStatus] ?? ucfirst(str_replace('_', ' ', $fleetStatus)) }}</option>
                        @endforeach
                    </select>
                </div>
                <button data-fleet-reset type="button" class="inline-flex h-10 items-center justify-center gap-1.5 rounded-[7px] border border-[var(--border-btn-outline)] bg-[var(--bg-surface)] px-3 text-[11px] font-semibold text-[var(--text-ink)] transition-colors hover:border-primary-300 hover:bg-primary-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-700"><i data-lucide="rotate-ccw" class="h-3.5 w-3.5" aria-hidden="true"></i>Reset</button>
            </div>
            <p data-fleet-count role="status" aria-live="polite" aria-atomic="true" class="mt-3 text-xs font-medium text-[var(--text-ink)]/80">Showing {{ $riders->count() }} of {{ $riders->count() }} riders</p>
        </div>
        <div class="relative overflow-x-auto border-t border-[var(--border-strong)] focus-visible:outline-2 focus-visible:outline-primary-700" role="region" aria-label="Fleet rider records" tabindex="0">
            <table class="w-full min-w-[1000px] text-left font-sans">
                <caption class="sr-only">Your fleet with contact details, Station Hub, vehicle, status, availability, and rider details actions</caption>
                <thead class="border-b border-[var(--border-strong)] bg-[var(--bg-raised)] text-[11.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-ink)]">
                    <tr>
                        <th scope="col" class="px-5 py-3">Rider</th>
                        <th scope="col" class="px-5 py-3">Contact</th>
                        <th scope="col" class="px-5 py-3">Station Hub</th>
                        <th scope="col" class="px-5 py-3">Vehicle</th>
                        <th scope="col" class="px-5 py-3">Status</th>
                        <th scope="col" class="px-5 py-3">Availability</th>
                        <th scope="col" class="px-5 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody id="rider-records" class="divide-y divide-[var(--border-strong)] text-[12.5px] text-[var(--text-ink)]">
                    @foreach ($riders as $r)
                        <tr data-fleet-row data-status="{{ $r->status }}" data-search="{{ implode(' ', [$r->first_name, $r->last_name, $r->email, $r->contact_no, $r->station?->name ?? 'Unassigned', $r->station?->municipality, $r->vehicle_type, $r->plate_number]) }}" class="transition-colors hover:bg-[var(--bg-page)]">
                            <td class="px-5 py-4">
                                <a data-rider-view href="{{ route('logistics.riders.show', $r) }}" class="font-semibold text-[#9c5c68] hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-700">{{ $r->first_name }} {{ $r->last_name }}</a>
                                <p class="mt-1 text-[11px] text-[var(--text-muted)]">{{ $r->email }}</p>
                            </td>
                            <td class="whitespace-nowrap px-5 py-4">{{ $r->contact_no }}</td>
                            <td class="px-5 py-4"><p>{{ $r->station?->name ?? 'Unassigned' }}</p>@if ($r->station?->municipality)<p class="mt-1 text-[11px] text-[var(--text-muted)]">{{ $r->station->municipality }}</p>@endif</td>
                            <td class="px-5 py-4"><p>{{ $r->vehicle_type }}</p><p class="mt-1 text-[11px] text-[var(--text-muted)]">{{ $r->plate_number }}</p></td>
                            <td class="whitespace-nowrap px-5 py-4">@include('Logistics.Riders.partials.status', ['status' => $r->status])</td>
                            <td class="whitespace-nowrap px-5 py-4">@include('Logistics.Riders.partials.availability', ['rider' => $r])</td>
                            <td class="px-5 py-4 text-right"><a data-rider-view href="{{ route('logistics.riders.show', $r) }}" class="{{ $viewActionClass }}"><i data-lucide="eye" class="h-3.5 w-3.5" aria-hidden="true"></i>View<span class="sr-only"> rider {{ $r->first_name }} {{ $r->last_name }}</span></a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div data-fleet-empty class="hidden px-6 py-12 text-center font-sans">
            <h3 class="text-sm font-semibold text-[var(--text-ink)]">No matching riders</h3>
            <p class="mt-2 text-xs text-[var(--text-muted)]">Try another name, station, or plate, or reset your filters.</p>
            <button data-fleet-reset type="button" class="mt-4 {{ $viewActionClass }}">Reset filters</button>
        </div>
    @endif
</section>

<dialog id="rider-dialog" aria-labelledby="rider-dialog-title" class="m-auto max-h-[90dvh] w-[calc(100%-2rem)] max-w-4xl overflow-hidden rounded-xl border border-[var(--border-strong)] bg-[var(--bg-surface)] p-0 text-[var(--text-ink)] shadow-xl backdrop:bg-black/50">
    <div class="flex max-h-[90dvh] flex-col">
        <header class="flex shrink-0 items-center justify-between gap-4 border-b border-[var(--border-strong)] px-5 py-3">
            <h2 id="rider-dialog-title" class="font-serif text-xl text-[var(--text-ink)]">Rider details</h2>
            <button data-rider-close type="button" autofocus aria-label="Close rider details" class="inline-flex h-10 w-10 items-center justify-center rounded-[7px] border border-[var(--border-btn-outline)] hover:bg-[var(--bg-raised)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-700"><i data-lucide="x" class="h-4 w-4" aria-hidden="true"></i></button>
        </header>
        <div class="min-h-0 flex-1 overflow-y-auto p-4 sm:p-5">
            <p data-rider-loading role="status" class="py-8 text-center font-sans text-sm">Loading rider details...</p>
            <div data-rider-error class="hidden py-8 text-center font-sans">
                <p role="alert" class="text-sm">Unable to load rider details. Please try again or open the full page.</p>
                <button data-rider-retry type="button" class="mt-4 {{ $viewActionClass }}"><i data-lucide="rotate-ccw" class="h-3.5 w-3.5" aria-hidden="true"></i>Try again</button>
            </div>
            <div data-rider-content></div>
        </div>
        <footer class="flex shrink-0 items-center justify-end gap-2 border-t border-[var(--border-strong)] px-5 py-3 font-sans">
            <a data-rider-full-page class="{{ $viewActionClass }}">Open full page<i data-lucide="arrow-up-right" class="h-3.5 w-3.5" aria-hidden="true"></i></a>
            <button data-rider-close type="button" class="{{ $viewActionClass }}">Close</button>
        </footer>
    </div>
</dialog>
@endsection

@push('styles')
<style>
    [data-theme="dark"] #rider-dialog [data-rider-details] :is(.text-neutral-900, .text-neutral-700) {
        color: var(--text-ink);
    }
    [data-theme="dark"] #rider-dialog [data-rider-details] :is(.text-neutral-500, .text-neutral-600) {
        color: var(--text-muted);
    }
    [data-theme="dark"] #rider-dialog [data-rider-details] :is(input:not([type="hidden"]), select) {
        color: var(--text-ink);
        background: var(--bg-page);
        border-color: var(--border-input);
    }
</style>
@endpush

@push('scripts')
<script>
    (() => {
        const fleet = document.getElementById('rider-fleet');
        const filters = fleet?.querySelector('[data-fleet-filters]');
        if (!filters) return;

        const search = fleet.querySelector('#rider-search');
        const status = fleet.querySelector('#rider-status');
        const rows = Array.from(fleet.querySelectorAll('[data-fleet-row]'));
        const count = fleet.querySelector('[data-fleet-count]');
        const empty = fleet.querySelector('[data-fleet-empty]');
        const records = rows.map(row => ({ row, search: row.dataset.search.toLocaleLowerCase() }));

        const update = () => {
            const terms = search.value.trim().toLocaleLowerCase().split(/\s+/).filter(Boolean);
            let visible = 0;
            records.forEach(record => {
                const matches = (!status.value || record.row.dataset.status === status.value)
                    && terms.every(term => record.search.includes(term));
                record.row.classList.toggle('hidden', !matches);
                if (matches) visible++;
            });
            count.textContent = `Showing ${visible} of ${rows.length} ${rows.length === 1 ? 'rider' : 'riders'}`;
            empty.classList.toggle('hidden', visible !== 0);
        };

        search.addEventListener('input', update);
        status.addEventListener('change', update);
        fleet.querySelectorAll('[data-fleet-reset]').forEach(button => {
            button.addEventListener('click', () => {
                search.value = '';
                status.value = '';
                update();
                search.focus();
            });
        });
        update();
        filters.classList.remove('hidden');
    })();

    (() => {
        const dialog = document.getElementById('rider-dialog');
        if (!dialog || typeof dialog.showModal !== 'function') return;

        const content = dialog.querySelector('[data-rider-content]');
        const loading = dialog.querySelector('[data-rider-loading]');
        const error = dialog.querySelector('[data-rider-error]');
        const fullPage = dialog.querySelector('[data-rider-full-page]');
        let controller;
        let requestId = 0;
        let currentUrl;
        let opener;
        let previousOverflow;

        const load = async () => {
            controller?.abort();
            controller = new AbortController();
            const activeRequest = ++requestId;
            content.replaceChildren();
            content.setAttribute('aria-busy', 'true');
            loading.classList.remove('hidden');
            error.classList.add('hidden');
            try {
                const response = await fetch(currentUrl, {
                    credentials: 'same-origin',
                    headers: { Accept: 'text/html' },
                    signal: controller.signal,
                });
                if (!response.ok) throw new Error('Rider details unavailable');
                const html = await response.text();
                const details = new DOMParser().parseFromString(html, 'text/html').querySelector('[data-rider-details]');
                if (!details) throw new Error('Rider details missing');
                if (activeRequest !== requestId || !dialog.open) return;
                content.replaceChildren(document.importNode(details, true));
                window.lucide?.createIcons();
            } catch (failure) {
                if (failure.name !== 'AbortError' && activeRequest === requestId && dialog.open) {
                    error.classList.remove('hidden');
                }
            } finally {
                if (activeRequest === requestId && dialog.open) {
                    loading.classList.add('hidden');
                    content.removeAttribute('aria-busy');
                }
            }
        };

        document.querySelectorAll('a[data-rider-view]').forEach(link => {
            link.addEventListener('click', event => {
                if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
                const url = new URL(link.href, window.location.href);
                if (url.origin !== window.location.origin) return;
                event.preventDefault();
                opener = link;
                currentUrl = url.href;
                fullPage.href = currentUrl;
                if (!dialog.open) {
                    previousOverflow = document.documentElement.style.overflow;
                    dialog.showModal();
                    document.documentElement.style.overflow = 'hidden';
                }
                load();
            });
        });
        dialog.querySelectorAll('[data-rider-close]').forEach(button => {
            button.addEventListener('click', () => dialog.close());
        });
        dialog.querySelector('[data-rider-retry]').addEventListener('click', load);
        dialog.addEventListener('click', event => {
            if (event.target !== dialog) return;
            const bounds = dialog.getBoundingClientRect();
            if (event.clientX < bounds.left || event.clientX > bounds.right
                || event.clientY < bounds.top || event.clientY > bounds.bottom) dialog.close();
        });
        dialog.addEventListener('close', () => {
            controller?.abort();
            requestId++;
            content.replaceChildren();
            document.documentElement.style.overflow = previousOverflow;
            if (opener?.isConnected) opener.focus({ preventScroll: true });
        });
    })();
</script>
@endpush
