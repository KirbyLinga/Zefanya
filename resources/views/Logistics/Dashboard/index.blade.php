@extends('Layouts.logistics')
@section('title', 'Sorting Center · Zefanya Logistics')
@section('content')
<div class="lg-page">
    <nav class="lg-breadcrumb" aria-label="Breadcrumb">
        <ol class="lg-breadcrumb__list">
            <li><span class="lg-breadcrumb__link">Dispatch &amp; Operations</span></li>
            <li class="lg-breadcrumb__divider" aria-hidden="true"></li>
            <li aria-current="page" class="lg-breadcrumb__current">Makati Central Studio</li>
        </ol>
    </nav>
    <header class="lg-page-head">
        <div class="lg-page-title-wrap">
            <h1 class="lg-page-title">Sorting Center</h1>
            <span class="lg-chip lg-chip--secondary">Hub 04</span>
        </div>
        <div class="lg-page-actions">
            <span class="lg-chip lg-chip--date">{{ $todayLabel }}</span>
            <button type="button" class="lg-btn lg-btn--accent lg-btn--icon">
                <i data-lucide="plus" width="18" height="18"></i> <span>New intake</span>
            </button>
        </div>
    </header>
    <section class="lg-stats" aria-label="Sorting center metrics">
        <article class="lg-card lg-card--hero">
            <div class="lg-hero-head">
                <span class="lg-hero-label">Incoming Parcels</span>
                <span class="lg-pill lg-pill--success">{{ $incoming['delta'] }}</span>
            </div>
            <p class="lg-hero-value">{{ $incoming['value'] }}</p>
            <p class="lg-hero-caption">{{ $incoming['caption'] }}</p>
        </article>
        <div class="lg-live-group">
            <p class="lg-group-label">Live Hub Status</p>
            <div class="lg-live-grid">
                @foreach ($liveHub as $hub)
                <article class="lg-card lg-card--live">
                    <p class="lg-live-label">{{ $hub['label'] }}</p>
                    <p class="lg-live-value">{{ $hub['value'] }}</p>
                    @if (! empty($hub['chip']))<span class="lg-chip lg-chip--small">{{ $hub['chip'] }}</span>@endif
                    <div class="lg-bar" role="progressbar" aria-valuenow="{{ $hub['progress'] }}" aria-valuemin="0" aria-valuemax="100">
                        <span class="lg-bar-fill" style="width:{{ $hub['progress'] }}%;"></span>
                        @if (! is_null($hub['muted'])) <span class="lg-bar-muted"></span> @endif
                    </div>
                    <p class="lg-live-caption">{{ $hub['caption'] }}</p>
                </article>
                @endforeach
            </div>
        </div>
    </section>
    <section class="lg-panel">
        <header class="lg-panel-head">
            <h2 class="lg-panel-title">Operational Status Overview</h2>
            <span class="lg-chip lg-chip--label">Live hub status</span>
        </header>
        <div class="lg-status-grid">
            @foreach ($statusOverview as $s)
            <div class="lg-status-tile lg-status-tile--{{ $s['tone'] }}" aria-label="{{ $s['label'] }} — {{ $s['value'] }}">
                <div class="lg-status-body">
                    <span class="lg-status-value">{{ $s['value'] }}</span>
                    <span class="lg-status-label">{{ $s['label'] }}</span>
                </div>
                <i class="lg-status-icon" data-lucide="{{ $s['icon'] }}" width="20" height="20"></i>
            </div>
            @endforeach
        </div>
    </section>
    <section class="lg-panel">
        <header class="lg-panel-head">
            <div>
                <h2 class="lg-panel-title">Recent Parcels</h2>
                <p class="lg-panel-sub">Latest hub activity across assigned zones.</p>
            </div>
            <div class="lg-parcel-controls">
                <div role="tablist" class="lg-tabs" id="lgParcelTabs" data-lg-tabs aria-label="Filter parcels by zone">
                    <button type="button" role="tab" aria-selected="true" data-lg-zone="all" class="lg-tab is-active">All Zones</button>
                    <button type="button" role="tab" aria-selected="false" data-lg-zone="priority" class="lg-tab">Priority Express</button>
                    <button type="button" role="tab" aria-selected="false" data-lg-zone="standard" class="lg-tab">Standard</button>
                </div>
                <div class="lg-search-wrap lg-search--compact">
                    <i data-lucide="search" class="lg-search-icon" width="18" height="18"></i>
                    <input type="search" id="lgParcelSearch" class="lg-search-input" placeholder="Search parcels…" aria-label="Search parcels">
                </div>
                <button type="button" id="lgFilterBtn" class="lg-btn lg-btn--ghost" aria-pressed="false" aria-label="Filter">
                    <i data-lucide="filter" width="18" height="18"></i> <span>Filter</span>
                </button>
            </div>
        </header>

        <div class="lg-table-scroll">
            <table class="lg-table">
                <thead>
                    <tr>
                        <th scope="col">Order ID</th>
                        <th scope="col">Customer / Merchant</th>
                        <th scope="col">Destination</th>
                        <th scope="col">Courier / Rider</th>
                        <th scope="col">Status</th>
                        <th scope="col" aria-label="Actions"><span class="lg-visually-hidden">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($recentParcels as $p)
                        @php $pill = $statusPills[$p['status']] ?? null; @endphp
                        <tr class="lg-row" data-lg-row data-lg-zone="{{ $p['zone'] }}" data-lg-order="{{ $p['order_id'] }}">
                            <td class="lg-cell--mono">{{ $p['order_id'] }}</td>
                            <td>
                                <span class="lg-cust-name">{{ $p['customer'] }}</span>
                                <span class="lg-cust-product">{{ $p['product'] }}</span>
                            </td>
                            <td>{{ $p['destination'] }}</td>
                            <td>
                                @if (in_array($p['courier']['type'], ['assigned', 'approved']))
                                    <span class="lg-courier">
                                        <span class="lg-avatar lg-avatar--sm" aria-hidden="true">{{ $p['courier']['initials'] ?? '' }}</span>
                                        {{ $p['courier']['name'] ?? '' }}
                                    </span>
                                @elseif ($p['courier']['type'] === 'assigning')
                                    <span class="lg-courier lg-courier--muted">Assigning Bay…</span>
                                @else
                                    <a href="#" class="lg-link">Approve Request</a>
                                @endif
                            </td>
                            <td>
                                @if ($pill)
                                    <span class="lg-pill {{ $pill['class'] }}">{{ $pill['label'] }}</span>
                                @endif
                            </td>
                            <td>
                                <button type="button" class="lg-icon-btn lg-icon-btn--more" aria-label="More actions for {{ $p['order_id'] }}">
                                    <i data-lucide="more-horizontal" width="18" height="18"></i>
                                </button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <footer class="lg-table-foot">
            <p class="lg-showing" id="lgShowingCount">Showing {{ $showing['from'] }} to {{ $showing['to'] }} of {{ $showing['total'] }} parcels</p>
            <nav class="lg-pagination" role="navigation" aria-label="Pagination">
                <button type="button" class="lg-page-btn" aria-label="Previous page" disabled><i data-lucide="chevron-left" width="18" height="18"></i></button>
                <button type="button" class="lg-page-btn is-active" aria-current="page" aria-label="Page 1">1</button>
                <button type="button" class="lg-page-btn" aria-label="Page 2">2</button>
                <button type="button" class="lg-page-btn" aria-label="Page 3">3</button>
                <span class="lg-page-ellipsis" aria-hidden="true">…</span>
                <button type="button" class="lg-page-btn" aria-label="Next page"><i data-lucide="chevron-right" width="18" height="18"></i></button>
            </nav>
        </footer>
    </section>
</div>
@endsection