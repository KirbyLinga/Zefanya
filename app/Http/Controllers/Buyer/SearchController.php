<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Shared\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SearchController extends Controller
{
    /**
     * GET /buyer/search — Display search results page
     */
    public function index(Request $request): View
    {
        $query = (string) $request->query('q', '');
        $query = trim($query);

        $products = collect();
        $categories = collect();

        if (strlen($query) >= 2) {
            $products = Product::query()->withBuyerCardData()
                ->activeInStock()
                ->when($request->filled('category'), fn ($q) => $q->where('category_id', $request->integer('category')))
                ->when($request->filled('seller'), fn ($q) => $q->where('seller_id', $request->integer('seller')))
                ->where(function ($q) use ($query) {
                    $q->whereRaw('LOWER(name) LIKE ?', ['%'.strtolower($query).'%'])
                        ->orWhereRaw('LOWER(description) LIKE ?', ['%'.strtolower($query).'%']);
                })
                ->with(['images', 'seller', 'category'])
                ->whereHas('seller', fn ($q) => $q->where('status', 'approved'))
                ->withBuyerPriceFilters($request->only(['min_price', 'max_price', 'sort']))
                ->paginate(12)->withQueryString();

            $categories = Category::query()
                ->whereRaw('LOWER(label) LIKE ?', ['%'.strtolower($query).'%'])
                ->get();
        }

        return view('Buyer.Search.index', compact('products', 'categories', 'query'));
    }

    /**
     * GET /buyer/search/suggestions — AJAX endpoint for search suggestions
     * Returns products matching the search term
     */
    public function suggestions(Request $request): JsonResponse
    {
        $term = trim($request->query('term', ''));

        $popular = collect();

        // Fetch matching products
        if (strlen($term) >= 1) {
            $popular = Product::query()->withBuyerCardData()
                ->activeInStock()
                ->where(function ($q) use ($term) {
                    $q->whereRaw('LOWER(name) LIKE ?', ['%'.strtolower($term).'%'])
                        ->orWhereRaw('LOWER(description) LIKE ?', ['%'.strtolower($term).'%']);
                })
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
