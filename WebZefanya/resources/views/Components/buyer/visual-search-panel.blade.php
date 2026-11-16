@props(['id' => 'visual-search'])
@php($objectRecognitionEnabled = app(\App\Services\Buyer\VisualSemanticRecognition::class)->enabled())
<section data-visual-search-panel data-visual-semantic="{{ $objectRecognitionEnabled ? '1' : '0' }}" class="min-w-0">
    <p class="mb-4 max-w-2xl text-sm leading-relaxed text-[var(--text-muted)]">@if ($objectRecognitionEnabled) Upload a product photo or screenshot. Object recognition can suggest products of the same type, while photo comparison ranks close visual matches. Category recommendations are not exact matches. Choose a category to narrow the results. @else Upload a product photo or screenshot. This search compares image structure, colors and layout, not semantic AI features. Choose a category to help narrow the results. It cannot reliably recognize product types. @endif</p>
    <form data-visual-search-form method="POST" action="{{ route('buyer.visual-search.search') }}" enctype="multipart/form-data" class="rounded-xl border border-[var(--border-strong)] bg-[var(--bg-raised)] p-4 sm:p-5">
        @csrf
        <label for="{{ $id }}-category" class="mb-2 block text-sm font-semibold text-[var(--text-ink)]">Product category (optional)</label>
        <select id="{{ $id }}-category" name="category_id" data-visual-category class="mb-4 block min-h-11 w-full min-w-0 rounded-lg border border-[var(--border-input)] bg-[var(--bg-surface)] px-3 text-sm text-[var(--text-ink)] focus-visible:outline-2 focus-visible:outline-[var(--brand)]">
            <option value="">All categories</option>
            @foreach (\App\Models\Shared\Category::query()->orderBy('label')->get(['id', 'label']) as $category)
                <option value="{{ $category->id }}" @selected((string) old('category_id', request('category_id')) === (string) $category->id)>{{ $category->label }}</option>
            @endforeach
        </select>
        <label for="{{ $id }}-image" class="mb-2 block text-sm font-semibold text-[var(--text-ink)]">Choose an image</label>
        <input id="{{ $id }}-image" data-visual-file name="image" type="file" accept="image/jpeg,image/png,image/webp" required aria-describedby="{{ $id }}-hint {{ $id }}-message" class="block w-full min-w-0 rounded-lg border border-[var(--border-input)] bg-[var(--bg-surface)] p-2 text-sm text-[var(--text-ink)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--brand)]">
        <p id="{{ $id }}-hint" class="mt-2 text-xs text-[var(--text-muted)]">JPEG, PNG or WebP. Up to 5 MB and 12 megapixels. Crop closely around one product for better results. Screenshots are processed for this request and are not saved to your account.</p>
        <img data-visual-preview hidden alt="Selected image preview" class="mt-4 max-h-48 max-w-full rounded-lg border border-[var(--border-strong)] object-contain">
        <p id="{{ $id }}-message" data-visual-message role="status" aria-live="polite" class="mt-3 text-sm text-[var(--text-ink)]"></p>
        <button data-visual-submit type="submit" class="mt-3 inline-flex min-h-11 items-center justify-center rounded-lg bg-[var(--btn-accent)] px-5 text-sm font-semibold text-[var(--btn-accent-contrast)] disabled:cursor-wait disabled:opacity-60 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--brand)]">Search image</button>
    </form>
    <div data-visual-results class="mt-6" aria-live="polite"></div>
</section>
