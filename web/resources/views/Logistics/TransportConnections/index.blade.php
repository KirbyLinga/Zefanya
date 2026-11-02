@extends('Layouts.logistics')
@section('title', 'Transport connections')
@section('content')
<div class="mb-5">
    <h1 class="font-serif text-2xl text-[var(--text-ink)]">Transport connections</h1>
    <p class="mt-1 text-sm text-[var(--text-muted)]">Configure real, directional hub transfers. A provider partnership alone does not create a route. For return travel, create a separate reverse connection.</p>
</div>
<x-logistics-flash :messages="['success', 'error']" />
@if ($errors->any())
    <div role="alert" class="mb-4 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-900">
        @foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach
    </div>
@endif
<section class="mb-6 rounded-xl border border-[var(--border-strong)] bg-[var(--bg-surface)] p-5">
    <h2 class="mb-4 font-semibold text-[var(--text-ink)]">Create connection</h2>
    <form method="POST" action="{{ route('logistics.transport-connections.store') }}" class="grid gap-4 md:grid-cols-2">
        @csrf
        <label class="text-sm text-[var(--text-ink)]">Origin hub
            <select name="origin_hub_id" required class="mt-1 block h-11 w-full rounded-md border border-[var(--border-strong)] bg-[var(--bg-surface)] px-3">
                <option value="">Select your hub</option>
                @foreach ($hubs->where('logistics_provider_id', $provider->id) as $hub)
                    <option value="{{ $hub->id }}" @selected(old('origin_hub_id') == $hub->id)>{{ $hub->name }} &rarr; {{ $hub->province_name }}</option>
                @endforeach
            </select>
        </label>
        <label class="text-sm text-[var(--text-ink)]">Destination hub
            <select name="destination_hub_id" required class="mt-1 block h-11 w-full rounded-md border border-[var(--border-strong)] bg-[var(--bg-surface)] px-3">
                <option value="">Select operational hub</option>
                @foreach ($hubs as $hub)
                    <option value="{{ $hub->id }}" @selected(old('destination_hub_id') == $hub->id)>{{ $hub->name }} &rarr; {{ $hub->provider->business_name }} &rarr; {{ $hub->province_name }}</option>
                @endforeach
            </select>
        </label>
        <label class="text-sm text-[var(--text-ink)]">Transport mode
            <select name="transport_mode" required class="mt-1 block h-11 w-full rounded-md border border-[var(--border-strong)] bg-[var(--bg-surface)] px-3">
                @foreach (\App\Models\Logistics\TransportConnection::TRANSPORT_MODES as $mode)
                    <option value="{{ $mode }}" @selected(old('transport_mode', 'ROAD') === $mode)>{{ $mode }}</option>
                @endforeach
            </select>
        </label>
        <p class="self-center text-sm text-[var(--text-muted)]">Operating provider: {{ $provider->business_name }}. New connections are active. Cross-provider destinations require an approved active partnership in this direction.</p>
        <button class="min-h-11 justify-self-start rounded-md bg-primary-700 px-5 text-sm font-semibold text-white hover:bg-primary-800" type="submit">Create connection</button>
    </form>
</section>
<div class="overflow-x-auto rounded-xl border border-[var(--border-strong)] bg-[var(--bg-surface)]">
    <table class="w-full text-left text-sm text-[var(--text-ink)]">
        <caption class="p-4 text-left font-semibold">Existing connections</caption>
        <thead><tr class="border-b border-[var(--border-strong)]"><th class="p-3">Origin &rarr; Destination</th><th class="p-3">Mode</th><th class="p-3">Operating provider</th><th class="p-3">Type</th><th class="p-3">Status</th><th class="p-3">Action</th></tr></thead>
        <tbody>
        @forelse ($connections as $connection)
            <tr class="border-b border-[var(--border-strong)]">
                <td class="p-3">{{ $connection->originHub?->name }} &rarr; {{ $connection->destinationHub?->name }}<p class="text-xs text-[var(--text-muted)]">{{ $connection->originHub?->provider?->business_name }} &rarr; {{ $connection->destinationHub?->provider?->business_name }}</p></td>
                <td class="p-3">{{ $connection->transport_mode }}</td>
                <td class="p-3">{{ $connection->operatingProvider?->business_name ?? 'Unassigned' }}</td>
                <td class="p-3">{{ $connection->originHub?->logistics_provider_id === $connection->destinationHub?->logistics_provider_id ? 'Internal' : 'Cross-provider' }}</td>
                <td class="p-3">{{ $connection->is_active ? 'Active' : 'Inactive' }}</td>
                <td class="p-3">
                    @if ((int) $connection->operating_provider_id === (int) $provider->id && (int) $connection->originHub?->logistics_provider_id === (int) $provider->id)
                    <form method="POST" action="{{ route('logistics.transport-connections.status', $connection->id) }}">
                        @csrf @method('PATCH')
                        <input type="hidden" name="is_active" value="{{ $connection->is_active ? '0' : '1' }}">
                        <button type="submit" class="min-h-11 rounded-md border border-[var(--border-strong)] px-4 font-semibold hover:bg-[var(--bg-hover)]">{{ $connection->is_active ? 'Deactivate' : 'Activate' }}</button>
                    </form>
                    @else
                        <span class="text-[var(--text-muted)]">Managed by origin provider</span>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="p-5 text-[var(--text-muted)]">No transport connections configured.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $connections->links() }}</div>
<p class="mt-4 text-sm text-[var(--text-muted)]">Deactivation blocks new routes. Saved parcel routes remain intact; unavailable future legs require routing review.</p>
@endsection
