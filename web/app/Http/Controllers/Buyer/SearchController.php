<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Catalog\Product;
use App\Models\Seller\Seller;
use App\Models\Shared\Category;
use App\Services\Buyer\CatalogSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SearchController extends Controller
{
    private const MIN_LENGTH = 2;

    /**
     * GET /buyer/search — Display search results page
     */
    public function index(Request $request): View
    {
        $query = CatalogSearch::term($request->query('q'));
        ['filters' => $priceFilters, 'error' => $priceError] = CatalogSearch::priceFilters($request->only(['min_price', 'max_price']));

        $products = collect();
        $categories = collect();
        $stores = collect();
        $suggestion = null;
        $tooShort = $query !== '' && mb_strlen($query) < self::MIN_LENGTH;

        if ($query !== '' && ! $tooShort) {
            $phrase = CatalogSearch::phrasePattern($query);
            $stores = Seller::query()->where('status', 'approved')
                ->whereRaw("LOWER(business_name) LIKE ? ESCAPE '!'", [$phrase])
                ->orderBy('business_name')->limit(20)->get(['id', 'business_name', 'store_description', 'store_logo_path']);
            $products = CatalogSearch::apply(Product::query()->withBuyerCardData(), $query)
                // SEL-INV-007: a sold-out listing remains discoverable by name.
                ->where('status', 'active')
                ->whereNull('restriction_reason')
                ->whereHas('variants', fn ($query) => $query->where('is_available', true))
                ->when($request->filled('category'), fn ($q) => $q->where('category_id', $request->integer('category')))
                ->when($request->filled('seller'), fn ($q) => $q->where('seller_id', $request->integer('seller')))
                ->with(['images', 'seller', 'category'])
                ->whereHas('seller', fn ($q) => $q->where('status', 'approved'))
                ->withBuyerPriceFilters($priceFilters + $request->only(['sort']))
                ->paginate(12)
                // A rejected price must not follow the buyer from page to page.
                ->appends($request->except(array_merge(['page'], $priceError !== null ? ['min_price', 'max_price'] : [])));

            $categories = Category::query()
                ->whereRaw("LOWER(label) LIKE ? ESCAPE '!'", [$phrase])
                ->get();

            if ($products->isEmpty() && $stores->isEmpty() && $categories->isEmpty()
                && $priceFilters === [] && ! $request->filled('category') && ! $request->filled('seller')) {
                $suggestion = CatalogSearch::suggest($query);
            }
        }

        return view('Buyer.Search.index', compact('products', 'categories', 'stores', 'query', 'priceError', 'priceFilters', 'suggestion', 'tooShort'));
    }

    /**
     * GET /buyer/search/suggestions — AJAX endpoint for search suggestions
     * Returns products matching the search term
     */
    public function suggestions(Request $request): JsonResponse
    {
        $term = CatalogSearch::term($request->query('term'));

        $popular = collect();

        // Fetch matching products
        if ($term !== '') {
            $popular = CatalogSearch::apply(Product::query()->withBuyerCardData(), $term)
                ->activeInStock()
                ->with(['images', 'seller'])
                ->whereHas('seller', fn ($q) => $q->where('status', 'approved'))
                ->limit(5)
                ->get()
                ->map(fn ($p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'price' => $p->formattedPriceRange(),
                    'image' => $p->catalogImageUrl(),
                ]);
        }

        return response()->json([
            'popular' => $popular,
        ]);
    }
}
