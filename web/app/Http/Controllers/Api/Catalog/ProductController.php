<?php

namespace App\Http\Controllers\Api\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The app's read-only door onto the catalogue: `GET /api/products`.
 *
 * ## Public, like the web's own browsing pages
 *
 * Nothing here requires a token. A catalogue is not per-buyer, and the web's
 * equivalents (`routes/buyer.php`'s `/buyer/products`) sit *outside* their
 * `auth:buyer` group for the same reason. Requiring a token would also make a
 * shop window need an account, which is a product decision nobody made.
 *
 * ## What "publicly visible" means, in one place
 *
 * A product is listed and openable only when it is `active` **and** its seller
 * has been approved. Both halves matter: the seller's own dashboard filters by
 * `status` alone (a seller may look at their own drafts), and the web buyer
 * product page checks `status` alone — but an unapproved seller's product has no
 * business being browsable, so both the list and the single read apply both
 * rules, which is why they are stated here once instead of being spread over two
 * queries that could drift.
 *
 * This is also why `scopeActiveInStock` is *not* used: it would additionally hide
 * a sold-out product, and an item that silently vanishes from a catalogue is
 * indistinguishable from a bug. Stock is the seller's business; the app has no
 * stock UI to explain an absence with.
 *
 * ## No pagination, deliberately
 *
 * The app's repository contract is "the catalogue", with no cursor, page or
 * `hasMore` in it (`CatalogRepository.products()` returns a list). A paginated
 * response would give the app its first page and quietly drop the rest — the
 * failure mode of a missing page is not an error, it is a shorter catalogue. So
 * this returns everything, and pagination arrives the day the app can ask for a
 * page. With a real catalogue behind it that is the first thing to change.
 */
class ProductController extends Controller
{
    /**
     * GET /api/products?q=&category=&seller=
     *
     * The three filters are the three the app can ask for
     * (`CatalogQuery`: `search`, `category`, `seller`), and all three are matched
     * the way the app's mock catalogue matches them, so switching a build from
     * mock to live does not change which products a query returns.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Product::query()
            ->where('status', 'active')
            ->whereHas('seller', fn (Builder $seller) => $seller->where('status', 'approved'))
            // Eager-loaded because the resource reads all three: without this the
            // list would issue three queries per product.
            ->with(['images', 'seller', 'category']);

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $like = '%'.$search.'%';
            // Name and category, not the description. The mock's comment is the
            // reason and it holds here too: matching the description makes a
            // two-word query return nearly everything, which reads as broken
            // rather than thorough.
            $query->where(function (Builder $match) use ($like) {
                $match->where('name', 'like', $like)
                    ->orWhereHas('category', fn (Builder $category) => $category->where('label', 'like', $like));
            });
        }

        // A category *label*: the app sends the same string it renders on the chip
        // and the server resolves it, so the id<->name mapping stays on the side
        // that owns the rows. An unknown label is not an error — it is a filter
        // that matches nothing, which the app already renders as an empty state.
        $category = trim((string) $request->input('category', ''));
        if ($category !== '') {
            $query->whereHas('category', fn (Builder $c) => $c->where('label', $category));
        }

        // A seller's *shop name* (`Product.sellerName` in the app), which is what
        // the shop screen holds.
        $seller = trim((string) $request->input('seller', ''));
        if ($seller !== '') {
            $query->whereHas('seller', fn (Builder $s) => $s->where('business_name', $seller));
        }

        // Newest first: an unbounded list still needs a stable order, and a
        // catalogue's natural one is "what was listed most recently".
        return ProductResource::collection($query->latest('id')->get());
    }

    /**
     * GET /api/products/{product}
     *
     * A product the buyer may not see answers 404 rather than 403: "this was
     * never here" is the truth a shop window tells about a draft item, and it
     * cannot be used to probe which ids exist.
     */
    public function show(Product $product): ProductResource
    {
        $product->loadMissing(['images', 'seller', 'category']);

        abort_unless(
            $product->status === 'active' && $product->seller?->isApproved(),
            404,
        );

        return new ProductResource($product);
    }
}
