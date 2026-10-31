<section id="reviews" class="mt-6 scroll-mt-24 rounded-2xl border border-buyer-line bg-white px-5 py-5">
    <h2 class="font-serif text-lg font-semibold text-buyer-ink">Reviews</h2>
    @php
        $averageRating = (float) $product->reviews_avg_rating;
        $averageFullStars = (int) floor($averageRating);
        $averageHasHalfStar = ($averageRating - $averageFullStars) >= 0.5;
        $ratingTotal = max(1, (int) $product->reviews_count);
    @endphp
    <div data-rating-summary class="mt-4 grid items-center gap-6 rounded-xl bg-secondary-50 p-5 sm:grid-cols-[auto_minmax(0,1fr)_auto]">
        <div class="text-center">
            <p class="font-serif text-[34px] font-semibold leading-none text-primary-800">{{ number_format($averageRating, 1) }}</p>
            <p class="mt-1 text-xs text-buyer-ink-soft">out of 5</p>
            <span class="mt-2 flex items-center justify-center gap-0.5" aria-hidden="true">
                @for ($star = 1; $star <= 5; $star++)
                    @if ($star <= $averageFullStars)
                        <i class="fa-solid fa-star text-sm text-buyer-star"></i>
                    @elseif ($star === $averageFullStars + 1 && $averageHasHalfStar)
                        <i class="fa-solid fa-star-half-stroke text-sm text-buyer-star"></i>
                    @else
                        <i class="fa-regular fa-star text-sm text-neutral-300"></i>
                    @endif
                @endfor
            </span>
            <p class="mt-1 text-xs text-buyer-ink-soft">
                {{ $product->reviews_count ? $product->reviews_count.' verified-purchase '.\Illuminate\Support\Str::plural('review', $product->reviews_count) : 'No ratings yet' }}
            </p>
        </div>
        <ul class="flex min-w-0 flex-col gap-1.5" aria-label="Ratings by number of stars">
            @foreach ([5, 4, 3, 2, 1] as $stars)
                @php
                    $ratingCount = (int) ($ratingCounts[$stars] ?? 0);
                @endphp
                <li class="grid grid-cols-[32px_minmax(0,1fr)_28px] items-center gap-2 text-xs text-buyer-ink-soft">
                    <span>{{ $stars }} <span aria-hidden="true">★</span><span class="sr-only">stars</span></span>
                    <span class="h-1.5 overflow-hidden rounded-full bg-buyer-line" aria-hidden="true"><span class="block h-full rounded-full bg-primary-800" style="width: {{ $ratingCount / $ratingTotal * 100 }}%"></span></span>
                    <span class="text-right tabular-nums">{{ $ratingCount }}</span>
                </li>
            @endforeach
        </ul>
        @unless ($canReview)
            <div class="text-right text-[13px] max-sm:text-left">
                <p class="text-buyer-ink-soft">Bought this item?</p>
                <a href="{{ route('buyer.orders.index') }}" class="font-semibold text-primary-800 underline underline-offset-4">Rate it from your orders</a>
            </div>
        @endunless
    </div>
    @unless ($product->reviews_count)
        <p class="mt-3 rounded-xl bg-secondary-50 px-4 py-3 text-sm text-buyer-ink-soft">No reviews yet. Ratings from buyers will appear here.</p>
    @endunless
    @if (session('success'))
        <p role="status" class="mt-3 text-sm text-buyer-ink">{{ session('success') }}</p>
    @endif

    @auth('buyer')
        @if ($canReview)
            <form method="POST" action="{{ route('buyer.products.reviews.store', $product) }}" enctype="multipart/form-data" class="mt-4 space-y-3">
                @csrf
                @if ($reviewableItems->count() > 1)
                    <label class="block text-sm text-buyer-ink" for="review-order-item">Purchase to review</label>
                    <select id="review-order-item" name="order_item_id" required class="w-full rounded border border-buyer-line bg-cream px-3 py-2 text-buyer-ink">
                        @foreach ($reviewableItems as $item)
                            <option value="{{ $item->id }}" @selected((string) old('order_item_id') === (string) $item->id)>
                                {{ $item->product_name }}@if ($item->sellerOrder?->order?->reference) — {{ $item->sellerOrder->order->reference }}@endif
                            </option>
                        @endforeach
                    </select>
                @else
                    <input type="hidden" name="order_item_id" value="{{ $reviewableItems->first()->id }}">
                @endif

                <fieldset>
                    <legend class="block text-sm text-buyer-ink">Your rating</legend>
                    <div data-star-rating class="mt-1.5 flex items-center gap-1" role="radiogroup" aria-label="Your rating">
                        @for ($rating = 1; $rating <= 5; $rating++)
                            @php $ratingSelected = (string) old('rating') === (string) $rating; @endphp
                            <label class="cursor-pointer rounded p-0.5 focus-within:ring-2 focus-within:ring-primary-400/50">
                                <input type="radio" name="rating" value="{{ $rating }}" class="peer sr-only" @checked($ratingSelected) required>
                                <i data-star-icon class="fa-{{ $ratingSelected ? 'solid' : 'regular' }} fa-star text-2xl {{ $ratingSelected ? 'text-buyer-star' : 'text-neutral-300' }} transition-colors peer-checked:text-buyer-star" aria-hidden="true"></i>
                                <span class="sr-only">{{ $rating }} out of 5</span>
                            </label>
                        @endfor
                    </div>
                </fieldset>

                <label class="block text-sm text-buyer-ink" for="review-body">Your feedback (optional)</label>
                <textarea id="review-body" name="body" rows="4" maxlength="{{ \App\Models\Buyer\Review::MAX_BODY_LENGTH }}" class="w-full rounded border border-buyer-line bg-cream px-3 py-2 text-buyer-ink">{{ old('body') }}</textarea>
                <p class="text-xs text-buyer-ink-soft">Up to {{ \App\Models\Buyer\Review::MAX_BODY_LENGTH }} characters.</p>

                <label class="block text-sm text-buyer-ink" for="review-photos">Photos (optional)</label>
                <input id="review-photos" type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple class="block w-full text-sm text-buyer-ink-soft">
                <p class="text-xs text-buyer-ink-soft">Up to {{ \App\Models\Buyer\Review::MAX_PHOTOS }} JPG, PNG or WebP photos, 3 MB each.</p>

                @include('Buyer.Products._review-errors')

                <button type="submit" class="rounded bg-primary-800 px-4 py-2 text-sm font-semibold text-white">Submit review</button>
            </form>
        @endif

        @if ($myReviews->isNotEmpty())
            <div class="mt-5 space-y-4">
                <h3 class="text-sm font-semibold text-buyer-ink">Your reviews</h3>
                @foreach ($myReviews as $myReview)
                    <article class="rounded-xl border border-buyer-line p-4">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="flex items-center gap-0.5" aria-label="{{ $myReview->rating }} out of 5 stars">
                                @for ($star = 1; $star <= 5; $star++)
                                    <i class="{{ $star <= $myReview->rating ? 'fa-solid' : 'fa-regular' }} fa-star text-sm {{ $star <= $myReview->rating ? 'text-buyer-star' : 'text-neutral-300' }}"></i>
                                @endfor
                            </span>
                            <span class="text-sm font-semibold text-buyer-ink">{{ $myReview->rating }}/5</span>
                            @if ($myReview->buyer_edited_at)
                                <span class="text-xs font-normal text-buyer-ink-soft">Edited {{ $myReview->buyer_edited_at->format('M j, Y') }}</span>
                            @endif
                        </div>
                        @if ($myReview->body)
                            <p class="mt-2 whitespace-pre-line text-sm text-buyer-ink">{{ $myReview->body }}</p>
                        @endif
                        @if ($myReview->photos->isNotEmpty())
                            <div class="mt-3 flex flex-wrap gap-2">
                                @foreach ($myReview->photos as $photo)
                                    <img src="{{ Storage::disk('public')->url($photo->path) }}" alt="Review photo" class="size-20 rounded-lg border border-buyer-line object-cover">
                                @endforeach
                            </div>
                        @endif

                        @if ($myReview->seller_reply)
                            <div class="mt-3 rounded-lg bg-cream px-3 py-2">
                                <p class="text-xs font-semibold text-buyer-ink">Seller reply</p>
                                <p class="mt-1 whitespace-pre-line text-sm text-buyer-ink">{{ $myReview->seller_reply }}</p>
                            </div>
                        @endif

                        @if ($myReview->isEditableByBuyer())
                            <form method="POST" action="{{ route('buyer.reviews.update', $myReview) }}" enctype="multipart/form-data" class="mt-4 space-y-3 border-t border-buyer-line pt-4">
                                @csrf
                                @method('PATCH')
                                <p class="text-xs text-buyer-ink-soft">You may edit this review once, within 30 days of posting it.</p>

                                <fieldset>
                                    <legend class="block text-sm text-buyer-ink">Your rating</legend>
                                    <div data-star-rating class="mt-1.5 flex items-center gap-1" role="radiogroup" aria-label="Your rating">
                                        @for ($rating = 1; $rating <= 5; $rating++)
                                            @php $editRatingSelected = (string) old('rating', $myReview->rating) === (string) $rating; @endphp
                                            <label class="cursor-pointer rounded p-0.5 focus-within:ring-2 focus-within:ring-primary-400/50">
                                                <input type="radio" name="rating" value="{{ $rating }}" class="peer sr-only" @checked($editRatingSelected) required>
                                                <i data-star-icon class="fa-{{ $editRatingSelected ? 'solid' : 'regular' }} fa-star text-2xl {{ $editRatingSelected ? 'text-buyer-star' : 'text-neutral-300' }} transition-colors peer-checked:text-buyer-star" aria-hidden="true"></i>
                                                <span class="sr-only">{{ $rating }} out of 5</span>
                                            </label>
                                        @endfor
                                    </div>
                                </fieldset>

                                <label class="block text-sm text-buyer-ink" for="edit-review-body-{{ $myReview->id }}">Your feedback (optional)</label>
                                <textarea id="edit-review-body-{{ $myReview->id }}" name="body" rows="4" maxlength="{{ \App\Models\Buyer\Review::MAX_BODY_LENGTH }}" class="w-full rounded border border-buyer-line bg-cream px-3 py-2 text-buyer-ink">{{ old('body', $myReview->body) }}</textarea>

                                @if ($myReview->photos->isNotEmpty())
                                    <fieldset>
                                        <legend class="text-sm text-buyer-ink">Remove existing photos</legend>
                                        <div class="mt-2 flex flex-wrap gap-2">
                                            @foreach ($myReview->photos as $photo)
                                                <label class="flex items-center gap-2 text-xs text-buyer-ink-soft">
                                                    <input type="checkbox" name="remove_photos[]" value="{{ $photo->id }}">
                                                    <img src="{{ Storage::disk('public')->url($photo->path) }}" alt="Review photo" class="size-16 rounded border border-buyer-line object-cover">
                                                </label>
                                            @endforeach
                                        </div>
                                    </fieldset>
                                @endif

                                <label class="block text-sm text-buyer-ink" for="edit-review-photos-{{ $myReview->id }}">Add photos (optional)</label>
                                <input id="edit-review-photos-{{ $myReview->id }}" type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple class="block w-full text-sm text-buyer-ink-soft">

                                @include('Buyer.Products._review-errors')

                                <button type="submit" class="rounded bg-primary-800 px-4 py-2 text-sm font-semibold text-white">Update review</button>
                            </form>
                        @else
                            <p class="mt-3 text-xs text-buyer-ink-soft">
                                This review can no longer be edited. Buyer reviews may be edited once, within 30 days of posting.
                            </p>
                        @endif
                    </article>
                @endforeach
            </div>
        @endif

        @if (! $canReview && $myReviews->isEmpty())
            <p class="mt-3 text-sm text-buyer-ink-soft">Buy this product and confirm receipt to leave a review.</p>
        @endif
    @else
        <p class="mt-3 text-sm text-buyer-ink-soft">Buy this product and confirm receipt to leave a review.</p>
    @endauth

    <div class="mt-5 space-y-4">
        @foreach ($reviews as $review)
            <article class="border-t border-buyer-line pt-4">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-sm font-semibold text-buyer-ink">{{ $review->buyer?->maskedName() ?: 'Verified buyer' }}</span>
                    <span class="flex items-center gap-0.5" aria-label="{{ $review->rating }} out of 5 stars">
                        @for ($star = 1; $star <= 5; $star++)
                            <i class="{{ $star <= $review->rating ? 'fa-solid' : 'fa-regular' }} fa-star text-xs {{ $star <= $review->rating ? 'text-buyer-star' : 'text-neutral-300' }}"></i>
                        @endfor
                    </span>
                    <span class="text-xs font-semibold text-buyer-ink">{{ $review->rating }}/5</span>
                    <time class="text-xs text-buyer-ink-soft">{{ $review->created_at->format('M j, Y') }}</time>
                </div>
                @if ($review->body)
                    <p class="mt-2 whitespace-pre-line text-sm text-buyer-ink">{{ $review->body }}</p>
                @endif
                @if ($review->photos->isNotEmpty())
                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach ($review->photos as $photo)
                            <img src="{{ Storage::disk('public')->url($photo->path) }}" alt="Review photo" class="size-20 rounded-lg border border-buyer-line object-cover">
                        @endforeach
                    </div>
                @endif
                @if ($review->seller_reply)
                    <div class="mt-3 rounded-lg bg-cream px-3 py-2">
                        <p class="text-xs font-semibold text-buyer-ink">
                            Seller reply
                            @if ($review->seller_reply_edited_at)
                                <span class="font-normal text-buyer-ink-soft">(edited)</span>
                            @endif
                        </p>
                        <p class="mt-1 whitespace-pre-line text-sm text-buyer-ink">{{ $review->seller_reply }}</p>
                    </div>
                @endif
            </article>
        @endforeach
    </div>
    <div class="mt-4">{{ $reviews->withQueryString()->fragment('reviews')->links() }}</div>
</section>

@push('scripts')
<script>
    (() => {
        const paintStars = (group) => {
            const selected = group.querySelector('input[type="radio"]:checked');
            const value = selected ? Number(selected.value) : 0;

            group.querySelectorAll('[data-star-icon]').forEach((icon, index) => {
                const active = index < value;
                icon.classList.toggle('fa-solid', active);
                icon.classList.toggle('fa-regular', !active);
                icon.classList.toggle('text-buyer-star', active);
                icon.classList.toggle('text-neutral-300', !active);
            });
        };

        document.querySelectorAll('[data-star-rating]').forEach((group) => {
            group.addEventListener('change', () => paintStars(group));
            paintStars(group);
        });
    })();
</script>
@endpush
