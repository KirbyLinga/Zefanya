@extends('Layouts.logistics')

@section('title', 'Parcel exceptions')

@section('content')
<div class="mb-6 flex flex-wrap items-end justify-between gap-4">
    <div>
        <h1 class="font-serif text-2xl text-[var(--text-ink)]">Parcel exceptions</h1>
        <p class="mt-1 text-sm text-[var(--text-muted)]">Review parcel incidents, custody recovery and hub delays for your company.</p>
    </div>
</div>

<x-logistics-flash :messages="['success', 'warning', 'error']" />

<form method="GET" action="{{ route('logistics.exceptions.index') }}" class="mb-5 grid gap-3 rounded-lg border border-[var(--border-strong)] bg-white p-4 sm:grid-cols-4">
    <div>
        <label for="status" class="mb-1 block text-xs font-semibold text-[var(--text-muted)]">Status</label>
        <select id="status" name="status" class="min-h-10 w-full rounded-lg border border-[var(--border-strong)] px-3 text-sm">
            @foreach (['active' => 'Active', 'open' => 'Open', 'under_review' => 'Under review', 'resolved' => 'Resolved', 'all' => 'All'] as $value => $label)
                <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label for="type" class="mb-1 block text-xs font-semibold text-[var(--text-muted)]">Type</label>
        <select id="type" name="type" class="min-h-10 w-full rounded-lg border border-[var(--border-strong)] px-3 text-sm">
            <option value="">All types</option>
            @foreach ($types as $value => $label)
                @if (in_array($value, \App\Services\Logistics\ParcelIncidentService::QUEUE_TYPES, true))
                    <option value="{{ $value }}" @selected(request('type') === $value)>{{ $label }}</option>
                @endif
            @endforeach
        </select>
    </div>
    <div>
        <label for="search" class="mb-1 block text-xs font-semibold text-[var(--text-muted)]">Search</label>
        <input id="search" name="search" value="{{ request('search') }}" maxlength="100" placeholder="Tracking or parcel #" class="min-h-10 w-full rounded-lg border border-[var(--border-strong)] px-3 text-sm">
    </div>
    <div class="flex items-end gap-2">
        <button type="submit" class="min-h-10 rounded-lg bg-primary-800 px-4 text-sm font-semibold text-white hover:bg-primary-900">Filter</button>
        <a href="{{ route('logistics.exceptions.index') }}" class="min-h-10 rounded-lg border border-[var(--border-strong)] px-4 py-2 text-sm font-semibold text-[var(--text-ink)]">Reset</a>
    </div>
</form>

<div class="overflow-hidden rounded-lg border border-[var(--border-strong)] bg-white">
    @if ($exceptions->count() > 0)
        <table class="w-full text-sm">
            <thead class="border-b border-[var(--border-strong)] bg-[var(--bg-page)]">
                <tr>
                    <th class="px-4 py-3 text-left font-semibold">Parcel</th>
                    <th class="px-4 py-3 text-left font-semibold">Type</th>
                    <th class="px-4 py-3 text-left font-semibold">Status</th>
                    <th class="px-4 py-3 text-left font-semibold">Custody</th>
                    <th class="px-4 py-3 text-left font-semibold">Blocked leg</th>
                    <th class="px-4 py-3 text-left font-semibold">Age</th>
                    <th class="px-4 py-3 text-right font-semibold">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[var(--border-strong)]">
                @foreach ($exceptions as $incident)
                    <tr class="hover:bg-[var(--bg-page)]">
                        <td class="px-4 py-3 font-semibold text-[var(--text-ink)]">
                            #{{ $incident->incident_seller_order_id }}
                            @if ($incident->sellerOrder?->tracking_number)
                                <span class="ml-1 font-mono text-xs text-[var(--text-muted)]">{{ $incident->sellerOrder->tracking_number }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            {{ $incident->typeLabel() }}
                            @if ($incident->type === 'hub_delay')
                                <p class="text-xs text-[var(--text-muted)]">{{ \App\Services\Logistics\HubDelayService::LEVELS[$incident->custody_snapshot['level']] }}</p>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @php($statusValue = $incident->status?->value ?? $incident->status)
                            <span class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ $statusValue === 'resolved' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700' }}">
                                {{ strtoupper(str_replace('_', ' ', $statusValue)) }}
                                @if ($incident->isHeld()) - ON HOLD @endif
                            </span>
                        </td>
                        <td class="px-4 py-3 text-xs text-[var(--text-muted)]">
                            Provider {{ $incident->sellerOrder?->currentCustodyProviderId() ?? '—' }}
                            · Hub {{ $incident->sellerOrder?->custody_hub_id ?? '—' }}
                        </td>
                        <td class="px-4 py-3 text-xs">{{ $incident->blocked_leg_index ?? '—' }}</td>
                        <td class="px-4 py-3 text-xs text-[var(--text-muted)]">{{ ($incident->type === 'hub_delay' ? $incident->happened_at : $incident->created_at)?->diffForHumans() ?? '—' }}</td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('logistics.exceptions.show', $incident) }}" class="text-xs font-semibold text-primary-700 hover:text-primary-900">Open</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <div class="border-t border-[var(--border-strong)] px-4 py-3">{{ $exceptions->links() }}</div>
    @else
        <div class="px-6 py-16 text-center text-sm text-[var(--text-muted)]">
            <p class="font-semibold text-[var(--text-ink)]">No parcel exceptions</p>
            <p class="mt-1">Exceptions reported from a parcel detail page appear here.</p>
        </div>
    @endif
</div>
@endsection
