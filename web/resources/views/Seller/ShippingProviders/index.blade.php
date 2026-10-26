@extends('Layouts.seller')

@section('title', 'Shipping Providers')

@section('content')
<div class="mb-6 flex flex-wrap items-end justify-between gap-4">
    <div>
        <h1 class="font-serif text-2xl text-[var(--text-ink)]">Shipping Providers</h1>
        <p class="mt-1 font-sans text-[12.5px] text-[var(--text-muted)]">
            Enable logistics companies that serve your registered pickup location
            @if ($seller->province_name || $seller->barangay_name)
                ({{ collect([$seller->barangay_name, $seller->municipality_name, $seller->province_name])->filter()->implode(', ') }})
            @endif.
            Keep at least one eligible provider enabled to offer shipping.
        </p>
    </div>
    <div class="font-sans text-[11.5px] text-[var(--text-muted)]">
        {{ $enabledCount }} enabled · {{ $eligibleCount }} available
    </div>
</div>

@if (session('success'))
    <div role="status" class="mb-4 rounded-md border border-green-200 bg-green-50 px-4 py-3 font-sans text-xs text-green-900">
        {{ session('success') }}
    </div>
@endif

@if ($errors->any())
    <div role="alert" class="mb-4 rounded-md border border-rose-200 bg-rose-50 px-4 py-3 font-sans text-xs text-rose-900">
        <ul class="list-disc pl-4">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

@unless ($canOfferShipping)
    <div role="status" class="mb-5 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 font-sans text-xs text-amber-950">
        <p class="font-semibold">Shipping is not available yet</p>
        <p class="mt-1">Enable at least one eligible logistics provider below. If none are listed, no approved provider currently covers your pickup barangay.</p>
    </div>
@endunless

@if (blank($seller->province_code) || blank($seller->barangay_code))
    <div class="rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-8 text-center">
        <p class="font-sans text-[13px] font-semibold text-[var(--text-ink)]">Pickup location incomplete</p>
        <p class="mt-2 font-sans text-[12px] text-[var(--text-muted)]">Your seller registration needs a province and barangay before shipping providers can be matched.</p>
    </div>
@elseif (count($catalog) === 0)
    <div class="rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-8 text-center">
        <p class="font-sans text-[13px] font-semibold text-[var(--text-ink)]">No shipping providers available</p>
        <p class="mt-2 font-sans text-[12px] text-[var(--text-muted)]">
            No approved logistics company currently serves
            {{ $seller->barangay_name ?: 'your barangay' }}
            in {{ $seller->province_name ?: 'your province' }}.
            Check again after providers expand coverage in your area.
        </p>
    </div>
@else
    <div class="overflow-hidden rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)]">
        <table class="w-full text-left">
            <thead>
                <tr class="border-b border-[var(--border-strong)] text-[11px] uppercase tracking-[0.6px] text-[var(--text-muted)]">
                    <th class="px-4 py-3 font-semibold">Provider</th>
                    <th class="px-4 py-3 font-semibold">Province Hub</th>
                    <th class="px-4 py-3 font-semibold">Status</th>
                    <th class="px-4 py-3 font-semibold text-right">Action</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($catalog as $row)
                    @php
                        /** @var \App\Models\Logistics\LogisticsProvider $provider */
                        $provider = $row['provider'];
                        $eligible = $row['eligible'];
                        $enabled = $row['enabled'];
                        $hub = $row['hub'];
                    @endphp
                    <tr class="border-b border-[var(--border-strong)] last:border-0">
                        <td class="px-4 py-4 align-top">
                            <p class="font-sans text-[13px] font-semibold text-[var(--text-ink)]">{{ $provider->business_name }}</p>
                            <p class="mt-0.5 font-sans text-[11.5px] text-[var(--text-muted)]">{{ $provider->province_name ?: '—' }}</p>
                        </td>
                        <td class="px-4 py-4 align-top font-sans text-[12.5px] text-[var(--text-muted)]">
                            {{ $hub?->name ?? '—' }}
                            @if ($hub)
                                <span class="mt-0.5 block text-[11px]">{{ $hub->isOperational() ? 'Operational' : 'Not operational' }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-4 align-top">
                            @if ($enabled && $eligible)
                                <span class="inline-flex rounded-sm border border-green-200 bg-green-50 px-2 py-0.5 font-sans text-[11px] font-semibold text-green-800">Enabled</span>
                            @elseif ($enabled && ! $eligible)
                                <span class="inline-flex rounded-sm border border-amber-200 bg-amber-50 px-2 py-0.5 font-sans text-[11px] font-semibold text-amber-900">Unavailable</span>
                                <p class="mt-1 max-w-[220px] font-sans text-[11px] text-amber-900">{{ $row['unavailable_reason'] }}</p>
                            @elseif ($eligible)
                                <span class="inline-flex rounded-sm border border-[var(--border-strong)] bg-[var(--bg-page)] px-2 py-0.5 font-sans text-[11px] font-semibold text-[var(--text-muted)]">Available</span>
                            @else
                                <span class="inline-flex rounded-sm border border-rose-200 bg-rose-50 px-2 py-0.5 font-sans text-[11px] font-semibold text-rose-800">Unavailable</span>
                                <p class="mt-1 max-w-[220px] font-sans text-[11px] text-rose-800">{{ $row['unavailable_reason'] }}</p>
                            @endif
                        </td>
                        <td class="px-4 py-4 align-top text-right">
                            @if ($eligible && ! $enabled)
                                <form method="POST" action="{{ route('seller.shipping-providers.enable', $provider) }}" class="inline">
                                    @csrf
                                    <button type="submit" class="inline-flex h-8 items-center rounded-sm bg-primary-800 px-3 font-sans text-[11.5px] font-semibold text-white hover:bg-primary-900">
                                        Enable
                                    </button>
                                </form>
                            @elseif ($enabled)
                                <form method="POST" action="{{ route('seller.shipping-providers.disable', $provider) }}" class="inline">
                                    @csrf
                                    <button type="submit" class="inline-flex h-8 items-center rounded-sm border border-[var(--border-strong)] px-3 font-sans text-[11.5px] font-semibold text-[var(--text-ink)] hover:bg-[var(--bg-page)]">
                                        {{ $eligible ? 'Disable' : 'Remove' }}
                                    </button>
                                </form>
                            @else
                                <span class="font-sans text-[11.5px] text-[var(--text-muted)]">—</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
@endsection
