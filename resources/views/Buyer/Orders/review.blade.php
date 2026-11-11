@extends('Buyer.Layouts.app')
@section('title', 'Review your order')

@section('content')
<div class="bg-[var(--bg-page)] py-6 sm:py-8">
    <div class="mx-auto max-w-3xl px-4 font-sans sm:px-6">
        <header class="mb-5 flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="text-xs font-semibold text-[var(--text-ink)]/80">Order {{ $order->reference }}</p>
                <h1 class="mt-1 font-serif text-2xl text-[var(--text-ink)] sm:text-3xl">Review your order</h1>
                <p class="mt-2 text-sm text-[var(--text-ink)]/80">Share your experience with the items you received. Reviews are optional.</p>
            </div>
            <a href="{{ route('buyer.orders.show', $order) }}" class="inline-flex min-h-10 items-center rounded-lg border border-[var(--border)] bg-[var(--bg-surface)] px-3 text-xs font-semibold text-[var(--text-ink)] hover:bg-[var(--bg-raised)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-700">Back to order</a>
        </header>

        @if (session('success'))
            <p role="status" class="mb-4 rounded-xl border border-[var(--border)] bg-[var(--bg-surface)] px-4 py-3 text-sm font-semibold text-[var(--text-ink)]">{{ session('success') }}</p>
        @endif
        @if ($errors->any() && ! $reviewItems->contains('id', (int) old('order_item_id')))
            <p role="alert" class="mb-4 text-sm text-red-600">{{ $errors->first() }}</p>
        @endif

        @if ($reviewItems->isEmpty())
            <section class="rounded-xl border border-[var(--border)] bg-[var(--bg-surface)] p-6 text-center">
                <h2 class="font-serif text-xl text-[var(--text-ink)]">No items to review</h2>
                <p class="mt-2 text-sm leading-relaxed text-[var(--text-ink)]/80">Items appear here after receipt is confirmed. Previously reviewed items and products that are no longer available are excluded.</p>
                <a href="{{ route('buyer.orders.index') }}" class="mt-4 inline-flex min-h-11 items-center rounded-lg bg-primary-800 px-4 text-sm font-semibold text-white hover:bg-primary-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-700">Back to orders</a>
            </section>
        @else
            <p class="mb-3 text-xs font-medium text-[var(--text-ink)]/80">{{ $reviewItems->count() }} {{ Str::plural('purchased item', $reviewItems->count()) }} ready for your review. Submit each review separately.</p>
            <div class="space-y-4">
                @foreach ($reviewItems as $item)
                    @php($isSubmittedItem = (string) old('order_item_id') === (string) $item->id)
                    <article id="review-item-{{ $item->id }}" class="rounded-xl border border-[var(--border)] bg-[var(--bg-surface)] p-4 sm:p-5">
                        <header class="mb-4 border-b border-[var(--border)] pb-3">
                            <h2 class="text-base font-semibold text-[var(--text-ink)]">{{ $item->product_name ?: $item->product->name }}</h2>
                            <p class="mt-1 text-xs text-[var(--text-ink)]/80">Quantity: {{ $item->quantity }}@if ($item->variantDescription() !== '') - {{ $item->variantDescription() }}@endif</p>
                        </header>
                        <form method="POST" action="{{ route('buyer.products.reviews.store', $item->product) }}" enctype="multipart/form-data" class="space-y-4">
                            @csrf
                            <input type="hidden" name="order_item_id" value="{{ $item->id }}">
                            <fieldset>
                                <legend class="text-sm font-semibold text-[var(--text-ink)]">Your rating <span class="text-xs font-normal text-[var(--text-ink)]/80">(required)</span></legend>
                                <div class="mt-2 grid grid-cols-5 gap-1 sm:flex sm:flex-wrap sm:gap-2">
                                    @for ($rating = 1; $rating <= 5; $rating++)
                                        <label for="rating-{{ $item->id }}-{{ $rating }}" class="flex min-h-11 cursor-pointer items-center justify-center gap-1 rounded-lg border border-[var(--border)] px-1 sm:px-2.5 text-sm text-[var(--text-ink)] focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-primary-700 has-checked:border-primary-700 has-checked:bg-[var(--bg-raised)]">
                                            <input id="rating-{{ $item->id }}-{{ $rating }}" type="radio" name="rating" value="{{ $rating }}" required @checked($isSubmittedItem && (string) old('rating') === (string) $rating) class="accent-primary-800">
                                            <span class="text-[var(--text-ink)]">{{ $rating }}<span class="text-[var(--text-ink)]" aria-hidden="true"> &#9733;</span><span class="sr-only"> out of 5</span></span>
                                        </label>
                                    @endfor
                                </div>
                            </fieldset>
                            <div>
                                <label for="body-{{ $item->id }}" class="mb-1.5 block text-sm font-semibold text-[var(--text-ink)]">Your feedback <span class="font-normal text-[var(--text-ink)]/80">(optional)</span></label>
                                <textarea id="body-{{ $item->id }}" name="body" rows="3" maxlength="{{ \App\Models\Buyer\Review::MAX_BODY_LENGTH }}" class="w-full rounded-lg border border-[var(--border)] bg-[var(--bg-page)] px-3 py-2 text-sm text-[var(--text-ink)] focus-visible:outline-2 focus-visible:outline-primary-700">{{ $isSubmittedItem ? old('body') : '' }}</textarea>
                                <p class="mt-1 text-xs text-[var(--text-ink)]/80">Up to {{ \App\Models\Buyer\Review::MAX_BODY_LENGTH }} characters.</p>
                            </div>
                            <div>
                                <label for="photos-{{ $item->id }}" class="mb-1.5 block text-sm font-semibold text-[var(--text-ink)]">Photos <span class="font-normal text-[var(--text-ink)]/80">(optional)</span></label>
                                <input id="photos-{{ $item->id }}" type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple class="block w-full min-w-0 rounded-lg border border-[var(--border)] p-2 text-xs text-[var(--text-ink)] focus-visible:outline-2 focus-visible:outline-primary-700">
                                <p class="mt-1 text-xs text-[var(--text-ink)]/80">Up to {{ \App\Models\Buyer\Review::MAX_PHOTOS }} JPG, PNG or WebP photos, 3 MB each.</p>
                            </div>
                            @if ($isSubmittedItem)
                                @include('Buyer.Products._review-errors')
                                @if ($errors->any())<p class="text-xs text-[var(--text-ink)]/80">If you attached photos, select them again before resubmitting.</p>@endif
                            @endif
                            <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-lg bg-primary-800 px-4 text-sm font-semibold text-white hover:bg-primary-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-700">Submit review</button>
                        </form>
                    </article>
                @endforeach
            </div>
            <a href="{{ route('buyer.orders.show', $order) }}" class="mt-5 inline-flex min-h-11 items-center text-sm font-semibold text-[var(--text-ink)] underline underline-offset-4 focus-visible:outline-2 focus-visible:outline-primary-700">Skip for now and return to order</a>
        @endif
    </div>
</div>
@endsection
