@extends('Layouts.logistics')
@section('title', 'Transport connections')
@section('logistics-toolbar')<span hidden data-logistics-hosted-bell></span>@endsection
@section('content')
<x-logistics.tabs group="setup" />
<x-logistics.setup-header title="Transport connections" description="One-way transfer lanes between operational hubs. Parcel routes can only use active connections." />
<x-logistics-flash :messages="['success', 'error']" />
@if ($errors->any())
    <div role="alert" class="mb-4 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-900">
        @foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach
    </div>
@endif
<section class="mb-4 rounded-xl border border-[var(--border-strong)] bg-[var(--bg-surface)] p-4 shadow-sm">
    <h2 class="mb-1 font-sans text-sm font-semibold text-[var(--text-ink)]">Create connection</h2>
    <p class="mb-4 font-sans text-xs text-[var(--text-ink)]/80">A connection goes from its origin to its destination. For return travel, create a separate reverse connection.</p>
    <form method="POST" action="{{ route('logistics.transport-connections.store') }}" class="grid gap-4 xl:grid-cols-3">
        @csrf
        <label class="font-sans text-xs font-semibold text-[var(--text-ink)]">Origin hub
            <select name="origin_hub_id" required class="mt-1.5 block h-11 w-full rounded-[7px] border border-[var(--border-input)] bg-[var(--bg-surface)] px-3 font-sans text-[13px] font-normal text-[var(--text-ink)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-700">
                <option value="">Select your hub</option>
                @foreach ($hubs->where('logistics_provider_id', $provider->id) as $hub)
                    <option value="{{ $hub->id }}" @selected(old('origin_hub_id') == $hub->id)>{{ $hub->name }} &rarr; {{ $hub->province_name }}</option>
                @endforeach
            </select>
        </label>
        <label class="font-sans text-xs font-semibold text-[var(--text-ink)]">Destination hub
            <select name="destination_hub_id" required class="mt-1.5 block h-11 w-full rounded-[7px] border border-[var(--border-input)] bg-[var(--bg-surface)] px-3 font-sans text-[13px] font-normal text-[var(--text-ink)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-700">
                <option value="">Select operational hub</option>
                @foreach ($hubs as $hub)
                    <option value="{{ $hub->id }}" @selected(old('destination_hub_id') == $hub->id)>{{ $hub->name }} &rarr; {{ $hub->provider->business_name }} &rarr; {{ $hub->province_name }}</option>
                @endforeach
            </select>
        </label>
        <label class="font-sans text-xs font-semibold text-[var(--text-ink)]">Transport mode
            <select name="transport_mode" required class="mt-1.5 block h-11 w-full rounded-[7px] border border-[var(--border-input)] bg-[var(--bg-surface)] px-3 font-sans text-[13px] font-normal text-[var(--text-ink)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-700">
                @foreach (\App\Models\Logistics\TransportConnection::TRANSPORT_MODES as $mode)
                    <option value="{{ $mode }}" @selected(old('transport_mode', 'ROAD') === $mode)>{{ $mode }}</option>
                @endforeach
            </select>
        </label>
        <div class="flex flex-col gap-3 border-t border-[var(--border-strong)] pt-3 sm:flex-row sm:items-center sm:justify-between xl:col-span-3">
            <div class="min-w-0 font-sans text-xs text-[var(--text-ink)]/80">
                <p class="text-[var(--text-ink)]/80">Operating provider: <span class="font-semibold text-[var(--text-ink)]">{{ $provider->business_name }}</span>. New connections are active.</p>
                <details class="mt-1">
                    <summary class="cursor-pointer font-semibold text-[var(--text-ink)] focus-visible:outline-2 focus-visible:outline-primary-700">Cross-provider connection requirements</summary>
                    <p class="mt-1 max-w-2xl leading-relaxed">A provider partnership alone does not create a route. Cross-provider destinations require an approved active partnership in this direction.</p>
                </details>
            </div>
            <button class="inline-flex min-h-11 shrink-0 items-center justify-center gap-2 rounded-[7px] bg-primary-800 px-4 font-sans text-xs font-semibold text-white hover:bg-primary-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-700" type="submit"><i data-lucide="plus" class="size-4" aria-hidden="true"></i>Create connection</button>
        </div>
    </form>
</section>
<section aria-labelledby="connections-heading" class="overflow-hidden rounded-xl border border-[var(--border-strong)] bg-[var(--bg-surface)] shadow-sm">
    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-[var(--border-strong)] px-4 py-3">
        <h2 id="connections-heading" class="font-sans text-sm font-semibold text-[var(--text-ink)]">Existing connections</h2>
        <p class="font-sans text-xs text-[var(--text-ink)]/80">Showing {{ $connections->count() }} of {{ $connections->total() }} connections</p>
    </div>
@if ($connections->isEmpty())
    <p class="px-4 py-6 font-sans text-[13px] text-[var(--text-ink)]/80">No transport connections configured. Choose an origin, destination, and transport mode above to create one.</p>
@else
<div role="region" aria-label="Transport connection records" tabindex="0" class="relative overflow-x-auto focus-visible:outline-2 focus-visible:outline-primary-700">
    <table class="w-full min-w-[900px] text-left font-sans text-[13px] text-[var(--text-ink)]">
        <caption class="sr-only">Directional transport connections and operating providers</caption>
        <thead class="bg-[var(--bg-raised)] text-xs font-semibold text-[var(--text-ink)]"><tr class="border-b border-[var(--border-strong)]"><th scope="col" class="px-4 py-3">Origin &rarr; Destination</th><th scope="col" class="px-4 py-3">Mode</th><th scope="col" class="px-4 py-3">Operating provider</th><th scope="col" class="px-4 py-3">Type</th><th scope="col" class="px-4 py-3">Status</th><th scope="col" class="px-4 py-3 text-right">Action</th></tr></thead>
        <tbody>
        @forelse ($connections as $connection)
            <tr class="border-b border-[var(--border-strong)]">
                <td class="px-4 py-3"><p class="font-semibold text-[var(--text-ink)]">{{ $connection->originHub?->name }} <span class="px-1 text-[var(--link)]">&rarr;</span> {{ $connection->destinationHub?->name }}</p><p class="text-xs text-[var(--text-ink)]/80">{{ $connection->originHub?->provider?->business_name }} &rarr; {{ $connection->destinationHub?->provider?->business_name }}</p></td>
                <td class="px-4 py-3">{{ $connection->transport_mode }}</td>
                <td class="px-4 py-3">{{ $connection->operatingProvider?->business_name ?? 'Unassigned' }}</td>
                <td class="px-4 py-3">{{ $connection->originHub?->logistics_provider_id === $connection->destinationHub?->logistics_provider_id ? 'Internal' : 'Cross-provider' }}</td>
                <td class="px-4 py-3"><span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $connection->is_active ? 'bg-[var(--pill-success-bg)] text-[var(--status-success)]' : 'bg-[var(--bg-raised)] text-[var(--text-ink)]/80' }}">{{ $connection->is_active ? 'Active' : 'Inactive' }}</span></td>
                <td class="px-4 py-3 text-right">
                    @if ((int) $connection->operating_provider_id === (int) $provider->id && (int) $connection->originHub?->logistics_provider_id === (int) $provider->id)
                    <form method="POST" action="{{ route('logistics.transport-connections.status', $connection->id) }}">
                        @csrf @method('PATCH')
                        <input type="hidden" name="is_active" value="{{ $connection->is_active ? '0' : '1' }}">
                        <button type="submit" class="inline-flex min-h-10 items-center justify-center gap-1.5 rounded-[7px] border border-[var(--border-btn-outline)] px-3 text-xs font-semibold text-[var(--text-ink)] shadow-sm hover:bg-[var(--bg-raised)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-700"><i data-lucide="{{ $connection->is_active ? 'pause' : 'play' }}" class="size-3.5" aria-hidden="true"></i>{{ $connection->is_active ? 'Deactivate' : 'Activate' }}</button>
                    </form>
                    @else
                        <span class="text-[var(--text-ink)]/80">Managed by origin provider</span>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="p-5 text-[var(--text-ink)]/80">No transport connections configured.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endif
</section>
<div class="mt-4">{{ $connections->links() }}</div>
<p class="mt-4 text-sm text-[var(--text-ink)]/80">Deactivation blocks new routes. Saved parcel routes remain intact; unavailable future legs require routing review.</p>
@endsection
