@extends('Layouts.seller')

@section('title', 'Feedback')

@section('content')
<div class="mx-auto max-w-5xl font-sans">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold text-[var(--text-ink)]">Customer Feedback</h1>
            <p class="mt-1 text-sm text-[var(--text-muted)]">Read customer reviews and manage your replies. Select Reply to review to respond.</p>
        </div>
        @if (session('success'))
            <p role="status" class="text-sm text-[var(--text-ink)]">{{ session('success') }}</p>
        @endif
    </div>

    <div class="mt-5 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-[var(--border-strong)] bg-[var(--bg-surface)] px-4 py-3">
        <p class="text-sm font-semibold text-[var(--text-ink)]">{{ number_format($reviews->total()) }} {{ $reviews->total() === 1 ? 'review' : 'reviews' }}</p>
        <p class="text-xs text-[var(--text-muted)]">Showing {{ $reviews->firstItem() ?? 0 }} to {{ $reviews->lastItem() ?? 0 }} of {{ number_format($reviews->total()) }}</p>
    </div>
    <div class="mt-4 space-y-3">
        @forelse ($reviews as $review)
            {{-- The page has one form per review; a failed submit only belongs to its own. --}}
            @php($submitted = (int) old('review_id') === $review->id)
            <article class="rounded-2xl border border-[var(--border)] bg-[var(--bg-surface)] p-5 shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-sm font-semibold text-[var(--text-ink)]">
                            {{ $review->product?->name ?? $review->orderItem?->product_name ?? 'Product' }}
                        </p>
                        <p class="mt-1 text-xs text-[var(--text-muted)]">
                            {{ $review->buyer ? trim($review->buyer->first_name.' '.$review->buyer->last_name) : 'Buyer' }}
                            · {{ $review->created_at->format('M j, Y') }}
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="rounded-full bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-900" aria-label="Rated {{ $review->rating }} out of 5">&#9733; {{ $review->rating }}/5</span>
                        <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $review->seller_reply ? 'bg-teal-50 text-teal-900' : 'bg-primary-50 text-primary-900' }}">{{ $review->seller_reply ? 'Replied' : 'Awaiting reply' }}</span>
                    </div>
                </div>

                @if ($review->body)
                    <p class="mt-3 whitespace-pre-line break-words text-sm leading-relaxed text-[var(--text-ink)]">{{ $review->body }}</p>
                @endif

                @if ($review->photos->isNotEmpty())
                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach ($review->photos as $photo)
                            <img loading="lazy" src="{{ Storage::disk('public')->url($photo->path) }}" alt="Review photo" class="size-16 rounded-lg border border-[var(--border)] object-cover">
                        @endforeach
                    </div>
                @endif

                @if ($review->seller_reply)
                    <div class="mt-4 rounded-xl bg-[var(--bg-page)] px-3 py-3">
                        <p class="text-xs font-semibold text-[var(--text-ink)]">
                            Your reply
                            @if ($review->seller_replied_at)
                                · {{ $review->seller_replied_at->format('M j, Y') }}
                            @endif
                            @if ($review->seller_reply_edited_at)
                                <span class="font-normal text-[var(--text-muted)]">(edited {{ $review->seller_reply_edited_at->format('M j, Y') }})</span>
                            @endif
                        </p>
                        <p class="mt-1 whitespace-pre-line break-words text-sm leading-relaxed text-[var(--text-ink)]">{{ $review->seller_reply }}</p>
                    </div>

                    <details class="group mt-3" @if($submitted) open @endif>

                        <summary class="inline-flex min-h-10 cursor-pointer items-center gap-2 rounded-lg border border-primary-200 bg-primary-50 px-4 py-2 text-xs font-semibold text-primary-900 transition-colors hover:border-primary-700 hover:bg-primary-700 hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-700">

                            <span class="text-inherit">{{ $review->seller_reply ? 'Edit reply' : 'Reply to review' }}</span>

                            <span class="text-inherit transition-transform group-open:rotate-180" aria-hidden="true">&#8964;</span>

                        </summary>

                    <form method="POST" action="{{ route('seller.feedback.reply.update', $review) }}" class="mt-3 space-y-2">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="review_id" value="{{ $review->id }}">
                        <label class="block text-sm text-[var(--text-ink)]" for="reply-{{ $review->id }}">Edit your reply</label>
                        <textarea id="reply-{{ $review->id }}" name="reply" rows="3" required maxlength="1000" class="w-full rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] px-3 py-2 text-sm text-[var(--text-ink)]">{{ $submitted ? old('reply', $review->seller_reply) : $review->seller_reply }}</textarea>
                        @if($submitted)
                            @error('reply')<p role="alert" class="text-sm text-red-600">{{ $message }}</p>@enderror
                        @endif
                        <button type="submit" class="rounded-lg bg-primary-800 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-primary-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-700">Update reply</button>
                    </form>
                    </details>
                @else
                    <details class="group mt-3" @if($submitted) open @endif>
                        <summary class="inline-flex min-h-10 cursor-pointer items-center gap-2 rounded-lg border border-primary-200 bg-primary-50 px-4 py-2 text-xs font-semibold text-primary-900 transition-colors hover:border-primary-700 hover:bg-primary-700 hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-700">
                            <span class="text-inherit">{{ $review->seller_reply ? 'Edit reply' : 'Reply to review' }}</span>
                            <span class="text-inherit transition-transform group-open:rotate-180" aria-hidden="true">&#8964;</span>
                        </summary>
                    <form method="POST" action="{{ route('seller.feedback.reply', $review) }}" class="mt-4 space-y-2">
                        @csrf
                        <input type="hidden" name="review_id" value="{{ $review->id }}">
                        <label class="block text-sm text-[var(--text-ink)]" for="reply-{{ $review->id }}">Reply to this review</label>
                        <textarea id="reply-{{ $review->id }}" name="reply" rows="3" required maxlength="1000" class="w-full rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] px-3 py-2 text-sm text-[var(--text-ink)]">{{ $submitted ? old('reply') : '' }}</textarea>
                        @if($submitted)
                            @error('reply')<p role="alert" class="text-sm text-red-600">{{ $message }}</p>@enderror
                        @endif
                        <button type="submit" class="rounded-lg bg-primary-800 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-primary-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-700">Post reply</button>
                    </form>
                    </details>
                @endif
            </article>
        @empty
            <p class="rounded-2xl border border-[var(--border)] bg-[var(--bg-surface)] p-5 text-sm text-[var(--text-muted)]">No buyer reviews yet.</p>
        @endforelse
    </div>

    <div class="mt-5">{{ $reviews->withQueryString()->links() }}</div>
</div>
@endsection
