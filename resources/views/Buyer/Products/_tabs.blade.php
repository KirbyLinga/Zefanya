{{-- Single product description section, rendered below the seller card. --}}
@if (trim((string) $description) !== '')
  <section class="mt-6 rounded-2xl border border-buyer-line bg-white px-5 py-4" aria-labelledby="product-description-heading">
    <h2 id="product-description-heading" class="mb-2 font-serif text-[15px] font-semibold text-buyer-ink">Product Description</h2>
    <p class="whitespace-pre-line text-[14px] leading-relaxed text-buyer-ink-soft">{{ $description }}</p>
  </section>
@endif
