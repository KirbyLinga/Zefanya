<section id="reviews" class="mt-6 rounded-2xl border border-buyer-line bg-white px-5 py-4">
    <h2 class="font-serif text-lg font-semibold text-buyer-ink">Ratings and reviews</h2>
    <p class="mt-2 text-sm text-buyer-ink-soft">
        @if ($product->reviews_count)
            {{ number_format((float) $product->reviews_avg_rating, 1) }}/5 from {{ $product->reviews_count }} verified-purchase reviews
        @else
            No reviews yet.
        @endif
    </p>
    @if (session('success'))
        <p role="status" class="mt-3 text-sm text-buyer-ink">{{ session('success') }}</p>
    @endif
    @if ($canReview)
        <form method="POST" action="{{ route('buyer.products.reviews.store', $product) }}" class="mt-4 space-y-3">
            @csrf
            <label class="block text-sm text-buyer-ink" for="review-rating">Your rating</label>
            <select id="review-rating" name="rating" required class="rounded border border-buyer-line bg-cream px-3 py-2 text-buyer-ink">
                <option value="">Choose a rating</option>
                @foreach (range(1, 5) as $rating)
                    <option value="{{ $rating }}" @selected((string) old('rating', $myReview?->rating) === (string) $rating)>{{ $rating }} / 5</option>
                @endforeach
            </select>
            <label class="block text-sm text-buyer-ink" for="review-body">Your feedback (optional)</label>
            <textarea id="review-body" name="body" rows="4" maxlength="2000" class="w-full rounded border border-buyer-line bg-cream px-3 py-2 text-buyer-ink">{{ old('body', $myReview?->body) }}</textarea>
            @foreach (['rating', 'body', 'review'] as $field)
                @error($field)<p role="alert" class="text-sm text-red-600">{{ $message }}</p>@enderror
            @endforeach
            <button type="submit" class="rounded bg-primary-800 px-4 py-2 text-sm font-semibold text-white">{{ $myReview ? 'Update review' : 'Submit review' }}</button>
        </form>
    @else
        <p class="mt-3 text-sm text-buyer-ink-soft">Buy this product and confirm receipt to leave a review.</p>
    @endif
    <div class="mt-5 space-y-4">
        @foreach ($reviews as $review)
            <article class="border-t border-buyer-line pt-4">
                <p class="text-sm font-semibold text-buyer-ink">Verified buyer - {{ $review->rating }}/5</p>
                <time class="text-xs text-buyer-ink-soft">{{ $review->created_at->format('M j, Y') }}</time>
                @if ($review->body)
                    <p class="mt-2 whitespace-pre-line text-sm text-buyer-ink">{{ $review->body }}</p>
                @endif
            </article>
        @endforeach
    </div>
    <div class="mt-4">{{ $reviews->withQueryString()->fragment('reviews')->links() }}</div>
</section>
