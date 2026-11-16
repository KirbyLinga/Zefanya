<?php

namespace App\Http\Controllers\Buyer;

use App\Exceptions\VisualSearchUnavailable;
use App\Http\Controllers\Controller;
use App\Http\Requests\Buyer\VisualSearchRequest;
use App\Models\Catalog\Product;
use App\Models\Shared\Category;
use App\Services\Buyer\VisualSearchService;

class VisualSearchController extends Controller
{
    public function index()
    {
        return response()->view('Buyer.VisualSearch.index')->header('Cache-Control', 'no-store');
    }

    public function search(VisualSearchRequest $request, VisualSearchService $search)
    {
        try {
            $result = $search->search($request->file('image')->getPathname(), $request->filled('category_id') ? $request->integer('category_id') : null);
        } catch (VisualSearchUnavailable $exception) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $exception->getMessage()], 503)->header('Cache-Control', 'no-store');
            }

            return response()->view('Buyer.VisualSearch.index', ['visualSearchError' => $exception->getMessage()], 503)
                ->header('Cache-Control', 'no-store');
        }
        // Navigation choices are not image matches or inferred object categories.
        $result['browseCategories'] = $result['products']->isEmpty() && ($result['recommendations'] ?? collect())->isEmpty()
            ? Category::query()->whereIn('id', Product::query()->discoverable()->select('category_id'))
                ->when($request->filled('category_id'), fn ($query) => $query->whereKey($request->integer('category_id')))
                ->orderBy('label')->get(['id', 'label'])
            : collect();
        if ($request->expectsJson()) {
            return response()->json(['html' => view('Buyer.VisualSearch._results', $result)->render(),
                'count' => $result['products']->count() + ($result['recommendations'] ?? collect())->count(),
                'visual_count' => $result['products']->count(), 'category_count' => ($result['recommendations'] ?? collect())->count()])->header('Cache-Control', 'no-store');
        }

        return response()->view('Buyer.VisualSearch.index', $result)->header('Cache-Control', 'no-store');
    }
}
