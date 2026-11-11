@extends('Admin.Layouts.app')
@section('title', 'Parcel incidents')
@section('content-width', 'max-w-none')
@section('content')
<section class="case-workspace" aria-labelledby="case-heading">
<x-admin.tabs :tabs="[
    ['label' => 'Returns & disputes', 'route' => 'admin.returns.index'],
    ['label' => 'Parcel incidents', 'route' => 'admin.incidents.index'],
    ...(\App\Services\Logistics\ParcelLossService::available() ? [['label' => 'Lost parcels', 'route' => 'admin.lost-parcels.index']] : []),
]" />
    <header class="case-heading">
        <div><p class="case-eyebrow">Returns &amp; disputes</p><h1 id="case-heading">Parcel incidents</h1><p class="case-description">Open and under-review lost or damaged parcel reports.</p></div>
        <span class="case-total"><i data-lucide="package-search" aria-hidden="true"></i><strong>{{ number_format($incidents->total()) }}</strong> awaiting review</span>
    </header>
    <x-logistics-flash :messages="['success', 'warning', 'error']" />
    <div class="case-panel">
        <div class="case-panel-heading"><h2>Incident review queue</h2><span><i data-lucide="arrow-down-wide-narrow" aria-hidden="true"></i>Newest reported first</span></div>
        @if ($incidents->count() > 0)
            <div class="case-table-hint"><i data-lucide="move-horizontal" aria-hidden="true"></i>Scroll to see all parcel details and actions</div>
            <div class="case-table-scroll" role="region" aria-label="Parcel incidents table" tabindex="0">
                <table class="case-table">
                    <thead><tr>@if ($lossAvailable)<th scope="col"><span class="sr-only">Select</span></th>@endif<th scope="col">Parcel</th><th scope="col">Incident type</th><th scope="col">Reporter</th><th scope="col">Status</th><th scope="col">Events</th><th scope="col">Actions</th></tr></thead>
                    <tbody>
                    @foreach ($incidents as $incident)
                        @php
                            $statusValue = $incident->status instanceof \BackedEnum ? $incident->status->value : $incident->status;
                            $eventCounts = $incident->events->countBy('event');
                        @endphp
                        <tr>
                            @if ($lossAvailable)
                                <td>@if (in_array(strtolower($incident->type), ['lost', 'missing', 'failed_transfer'], true))<input type="checkbox" name="incident_ids[]" value="{{ $incident->id }}" form="bulk-lost" aria-label="Select parcel {{ $incident->incident_seller_order_id }}" class="size-4">@endif</td>
                            @endif
                            <td><strong>Parcel #{{ $incident->incident_seller_order_id }}</strong><small>{{ $incident->sellerOrder?->tracking_number ?? 'No tracking number' }}</small></td>
                            <td><span class="case-type"><i data-lucide="{{ $incident->type === 'lost' ? 'package-search' : 'package-x' }}" aria-hidden="true"></i>{{ $incident->typeLabel() }}</span></td>
                            <td>{{ $incident->reporter_type ? ucfirst(str_replace('_', ' ', $incident->reporter_type)) : 'Unknown reporter' }}<small>{{ $incident->reporter_id ? 'ID #'.$incident->reporter_id : 'No reporter ID' }}</small></td>
                            <td><span class="case-status {{ $statusValue === 'under_review' ? 'case-status-review' : 'case-status-open' }}">{{ $incident->status instanceof \App\Enums\ParcelIncidentStatus ? $incident->status->label() : ucfirst(str_replace('_', ' ', $statusValue)) }}</span></td>
                            <td><span>{{ $eventCounts['reported'] ?? 0 }} reported</span><small>{{ $eventCounts['resolved'] ?? 0 }} resolved</small></td>
                            <td><a class="case-action" href="{{ route('admin.incidents.detail', $incident) }}" aria-label="Review incident {{ $incident->id }} for parcel {{ $incident->incident_seller_order_id }}"><i data-lucide="eye" aria-hidden="true"></i>Review</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @elseif ($incidents->total() > 0)
            <div class="case-empty"><span class="case-empty-icon"><i data-lucide="files" aria-hidden="true"></i></span><h3>No incidents on this page</h3><p>There are incidents awaiting review on an earlier page.</p><a class="case-action" href="{{ $incidents->url(1) }}">Back to first page</a></div>
        @else
            <div class="case-empty"><span class="case-empty-icon"><i data-lucide="clipboard-check" aria-hidden="true"></i></span><h3>No incidents awaiting review</h3><p>There are no open or under-review lost or damaged parcel reports.</p><a class="case-action" href="{{ route('admin.returns.index') }}"><i data-lucide="arrow-right" aria-hidden="true"></i>View returns &amp; disputes</a></div>
        @endif
        @if ($lossAvailable && $incidents->count() > 0)
            <form id="bulk-lost" method="POST" action="{{ route('admin.incidents.confirm-lost') }}" class="case-bulk" data-bulk-lost onsubmit="return confirm('Confirm every selected parcel lost? Each is closed and cannot be reopened here.');" style="padding:1rem 1.25rem;border-top:1px solid #e5e5e5">
                @csrf
                <p class="text-sm font-semibold text-neutral-950">Confirm the selected parcels lost together</p>
                <p class="mt-1 text-xs text-neutral-600">Each parcel is confirmed on its own: one that cannot be closed does not stop the others. No stock is restored.</p>
                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                    <label class="block text-xs font-semibold text-neutral-600">Reason<input name="reason" required maxlength="1000" class="mt-1 w-full rounded-lg border border-neutral-300 p-2 text-sm"></label>
                    <label class="block text-xs font-semibold text-neutral-600">Liable company
                        <select name="liable_provider_id" class="mt-1 w-full rounded-lg border border-neutral-300 p-2 text-sm"><option value="">Decide from verified custody</option>@foreach ($liableProviders as $p)<option value="{{ $p->id }}">{{ $p->business_name }}</option>@endforeach</select></label>
                </div>
                <button type="submit" class="mt-3 h-10 rounded-lg bg-primary-800 px-4 text-[13px] font-semibold text-white hover:bg-primary-900">Confirm selected as lost</button>
            </form>
        @endif
        @if ($incidents->total() > 0)
            <footer class="case-pagination"><p>Showing <strong>{{ $incidents->firstItem() ?? 0 }} to {{ $incidents->lastItem() ?? 0 }}</strong> of <strong>{{ number_format($incidents->total()) }}</strong> incidents</p>{{ $incidents->links() }}</footer>
        @endif
    </div>
</section>
@endsection
