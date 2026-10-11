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
        $query = $request->query('q', '');
        $query = trim($query);

        $products = collect();
        $categories = collect();

        if (strlen($query) >= 2) {
            $products = Product::query()
                ->where('status', 'active')
                ->where(function ($q) use ($query) {
                    $q->where('name', 'ilike', "%{$query}%")
                        ->orWhere('description', 'ilike', "%{$query}%");
                })
                ->with(['images', 'seller', 'category'])
                ->whereHas('seller', fn ($q) => $q->where('status', 'approved'))
                ->paginate(12);

            $categories = Category::query()
                ->where('label', 'ilike', "%{$query}%")
                ->get();
        }

        return view('Buyer.Search.index', compact('products', 'categories', 'query'));
    }

    /**
     * GET /buyer/search/suggestions — AJAX endpoint for search suggestions
     * Returns recent searches (for users) and popular products
     */
    public function suggestions(Request $request): JsonResponse
    {
        $term = trim($request->query('term', ''));
        $buyer = auth('buyer')->user();

        $recent = collect();
        $popular = collect();

        // Fetch popular (best-selling) products
        if (strlen($term) >= 1) {
            $popular = Product::query()
                ->where('status', 'active')
                ->where(function ($q) use ($term) {
                    $q->where('name', 'ilike', "%{$term}%");
                })
                ->with(['images'])
                ->whereHas('seller', fn ($q) => $q->where('status', 'approved'))
                ->limit(5)
                ->get()
                ->map(fn ($p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'image' => $p->images->first()?->path ? asset('storage/'.$p->images->first()->path) : null,
                ]);
        }

        return response()->json([
            'recent' => $recent,
            'popular' => $popular,
        ]);
    }
}
