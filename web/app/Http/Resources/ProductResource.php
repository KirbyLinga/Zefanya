<?php

namespace App\Http\Resources;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * One catalogue product, in the shape the Flutter app's `Product` wants.
 *
 * ## Money leaves here in centavos
 *
 * `products.price` is a `DECIMAL(10,2)` in **pesos** — the seller types `120.50`
 * into the web form and that is what is stored. The app's money boundary
 * (`lib/data/dto/money.dart`) reads every server amount as **integer centavos**
 * and converts once, on arrival, with the rule that no other file in the app may
 * divide or multiply by 100. So the conversion happens here, on the one side
 * that owns the column, and the field is named `price_minor` so a reader cannot
 * mistake which scale it is in. Sending `120.50` would not fail loudly — it would
 * render as ₱12,050.00.
 *
 * ## Three things the app renders that this resource deliberately omits
 *
 * The app's product page has an "ON SALE" badge (`compareAtPrice`), a star
 * average with a review count, and a "FROM THE MAKER" note. None of the three
 * exists server-side: `products` has no `compare_at_price` column, there is no
 * reviews table, and a maker note has no column of its own. The *web* buyer page
 * fakes all three (a struck-through price computed as `price / 0.75`, a hardcoded
 * 4.9 with "284 reviews", placeholder feature copy), which is exactly what this
 * project's no-invented-content rule forbids in an app. So they are absent here
 * rather than plausible: an absent field renders as no badge and no rating, and
 * the day a column exists it is added in one place.
 *
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            // A string, always. The app keys its wishlist, its recently-viewed
            // rail and its order joins on `Product.id`, and those ids are string
            // literals in the mock catalogue ('p1', 'p2'), so a live id has to
            // arrive in the same type or every set membership test would need to
            // know which mode it was in.
            'id' => (string) $this->id,
            'name' => $this->name,
            'description' => (string) ($this->description ?? ''),
            // The category *label*, not an id: the app's catalogue filter sends a
            // name back (see `CatalogQuery.category`), and the categories table is
            // a fixed curated list whose label is the thing a person reads.
            'category' => $this->category?->label ?? 'Uncategorised',
            'price_minor' => (int) round(((float) $this->price) * 100),
            // Carried because the alternative — hiding an out-of-stock product —
            // is an unexplained absence, and the app has no stock UI to explain
            // it with. It is a fact either way; the app simply does not read it.
            'in_stock' => ! $this->isOutOfStock(),
            'seller' => [
                // `products.seller_id` is NOT NULL with a cascading foreign key,
                // so a product without a seller is not a state this can be in.
                'name' => $this->seller?->business_name ?? '',
            ],
            'images' => $this->imagesCoverFirst(),
        ];
    }

    /**
     * The product's photo URLs, cover first.
     *
     * "Cover first" is the whole of the app's contract for a product's photos
     * (`Product.images`: an ordered list whose first entry is the cover), and it
     * is what makes the gallery and the stacked showcase work — both layouts
     * assume there is more than one photo of the thing.
     *
     * The cover is the image flagged `is_primary`, and the rest follow in
     * `sort_order` — which is the order the seller arranged them in, since the
     * relation itself is ordered that way. A product whose images are all
     * unflagged (possible: the flag is set on upload, not by a constraint) still
     * has a cover, because the first by `sort_order` is used.
     *
     * The images are read through the relation rather than off a loaded
     * collection so that a caller who forgets `with('images')` gets a query
     * instead of a silent empty gallery.
     *
     * @return list<string>
     */
    private function imagesCoverFirst(): array
    {
        /** @var \Illuminate\Support\Collection<int, ProductImage> $images */
        $images = $this->relationLoaded('images')
            ? $this->images
            : $this->images()->get();

        if ($images->isEmpty()) {
            return [];
        }

        $cover = $images->firstWhere('is_primary', true) ?? $images->first();

        return $images
            ->sortBy(fn (ProductImage $image) => $image->is($cover) ? -1 : $image->sort_order)
            ->map(fn (ProductImage $image) => Storage::url($image->path))
            ->values()
            ->all();
    }
}
