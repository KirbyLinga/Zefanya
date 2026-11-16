@extends('Admin.Layouts.app')
@section('title', 'Buyer reports')
@section('content')
    <h1 class="text-2xl font-semibold text-[var(--text-ink)]">Buyer reports</h1>
    <p class="mt-2 text-sm text-[var(--text-muted)]">Seller concerns awaiting review. Reports do not establish fault or apply account penalties.</p>
    <nav aria-label="Report filters" class="my-4 flex flex-wrap gap-4 text-sm font-semibold text-primary-800">
        <a href="{{ route('admin.buyer-reports.index') }}" @if ($status === 'open') aria-current="page" @endif>Unresolved reports</a>
        <a href="{{ route('admin.buyer-reports.index', ['status' => 'all']) }}" @if ($status === 'all') aria-current="page" @endif>All report history</a>
    </nav>
    <div class="space-y-3">
        @forelse ($reports as $report)
            <article class="rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-4">
                <a class="font-semibold text-primary-800 underline" href="{{ route('admin.buyer-reports.show', $report) }}">Report #{{ $report->id }} · {{ $report->sellerOrder?->order?->reference }} · shipment #{{ $report->seller_order_id }}</a>
                <p class="mt-2 text-sm text-[var(--text-ink)]">{{ $report->seller?->business_name ?? 'Seller' }} · {{ \App\Models\Shared\BuyerConductReport::REASONS[$report->reason] ?? 'Concern' }}</p>
                <p class="mt-1 text-xs text-[var(--text-muted)]">{{ $report->status === 'open' ? 'Awaiting review' : (\App\Models\Shared\BuyerConductReport::DECISIONS[$report->status] ?? 'Reviewed') }} · {{ manila($report->created_at, 'M j, Y g:i A') }}</p>
            </article>
        @empty
            <p class="rounded-lg border p-4 text-sm">No reports in this view.</p>
        @endforelse
    </div>
    <div class="mt-4">{{ $reports->links() }}</div>
@endsection
