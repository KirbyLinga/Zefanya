@extends('Admin.Layouts.app')
@section('title', 'Conduct reviews')
@section('content')
    <h1 class="text-2xl font-semibold text-[var(--text-ink)]">Buyer conduct reviews</h1>
    <p class="mt-2 max-w-3xl text-sm text-[var(--text-muted)]">
        Cases for Admin review only. A case is raised when a Buyer has {{ \App\Services\Admin\BuyerConductReviewService::CANCELLATION_THRESHOLD }} Buyer-initiated cancellations within {{ \App\Services\Admin\BuyerConductReviewService::CANCELLATION_WINDOW_DAYS }} days,
        {{ \App\Services\Admin\BuyerConductReviewService::RETURN_THRESHOLD }} return rejections upheld by Admin after a dispute within {{ \App\Services\Admin\BuyerConductReviewService::RETURN_WINDOW_DAYS }} days, or a Seller submits a report.
        Raising a case does not warn, restrict or notify the Buyer. The existing COD delivery-failure restriction is separate and unchanged.
    </p>
    <x-logistics-flash :messages="['success', 'warning', 'error']" />
    <nav aria-label="Review status" class="my-4 flex flex-wrap gap-4 text-sm font-semibold text-primary-800">
        @foreach (['open' => 'Pending and in review', 'resolved' => 'Resolved', 'all' => 'All'] as $value => $label)
            <a href="{{ route('admin.conduct-reviews.index', array_filter(['status' => $value, 'category' => $category])) }}" @if ($status === $value) aria-current="page" class="underline" @endif>{{ $label }}</a>
        @endforeach
    </nav>
    <nav aria-label="Review category" class="mb-4 flex flex-wrap gap-3 text-xs font-semibold">
        <a class="rounded-full border border-[var(--border-strong)] px-3 py-1 @if ($category === null) bg-primary-800 text-white @endif" href="{{ route('admin.conduct-reviews.index', ['status' => $status]) }}">All categories</a>
        @foreach (\App\Models\Shared\BuyerConductReview::CATEGORIES as $value => $label)
            <a class="rounded-full border border-[var(--border-strong)] px-3 py-1 @if ($category === $value) bg-primary-800 text-white @endif" href="{{ route('admin.conduct-reviews.index', ['status' => $status, 'category' => $value]) }}">{{ $label }}</a>
        @endforeach
    </nav>
    <div class="space-y-3">
        @forelse ($reviews as $review)
            <article class="rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-4">
                <a class="font-semibold text-primary-800 underline" href="{{ route('admin.conduct-reviews.show', $review->id) }}">{{ $review->reference() }} · {{ $review->categoryLabel() }}</a>
                <p class="mt-2 text-sm text-[var(--text-ink)]">Buyer #{{ $review->buyer_id }} · {{ $review->buyer?->fullName() ?? 'Account removed' }} · {{ $review->qualifying_count }} qualifying {{ $review->qualifying_count === 1 ? 'record' : 'records' }}</p>
                <p class="mt-1 text-xs text-[var(--text-muted)]">
                    {{ $review->statusLabel() }}@if ($review->decision) · {{ $review->decisionLabel() }} by {{ $review->decider?->name ?? 'Admin #'.$review->decided_by }} · {{ manila($review->decided_at, 'M j, Y g:i A') }}@endif
                    · Raised {{ manila($review->created_at, 'M j, Y g:i A') }}
                </p>
            </article>
        @empty
            <p class="rounded-lg border border-[var(--border-strong)] p-4 text-sm">No conduct reviews in this view.</p>
        @endforelse
    </div>
    <div class="mt-4">{{ $reviews->links() }}</div>
@endsection
