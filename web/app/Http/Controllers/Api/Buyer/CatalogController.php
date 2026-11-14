<?php

namespace App\Http\Controllers\Api\Buyer;

use App\Http\Controllers\Controller;
use App\Http\Middleware\Traits\ApiResponses;
use App\Http\Resources\Mobile\ProductCardResource;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\Shared\Category;
use App\Services\Buyer\CatalogSearch;
use App\Services\Buyer\ProductCatalogQuery;
use App\Services\Shared\PriceResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public Buyer mobile catalog: categories, listing/search/filters, detail.
 *
 * Listing uses ProductCatalogQuery (shared with the web shop); detail uses
 * the same visibility gate (Product::isListedForSale) and PriceResolver as
 * the web product page. Guests may browse, as on the web.
 */
class CatalogController extends Controller
{
    use ApiResponses;

    public function categories(): JsonResponse
    {
        return $this->success('Categories retrieved.', Category::orderBy('label')->get()
            ->map(fn (Category $category): array => [
                'id' => $category->id,
                'label' => $category->label,
                'image_url' => $category->image_url,
            ])->values());
    }

    public function products(Request $request, ProductCatalogQuery $catalog): JsonResponse
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'category' => ['nullable', 'integer'],
            'seller' => ['nullable', 'integer'],
            'on_sale' => ['nullable', 'boolean'],
            'min_price' => ['nullable'],
            'max_price' => ['nullable'],
            'sort' => ['nullable', 'string', 'max:30'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $result = $catalog->run($request->query(), (int) $request->integer('page', 1));

        if ($result['price_error'] !== null) {
            return $this->error($result['price_error'], ['min_price' => [$result['price_error']]]);
        }

        $suggestion = $result['products']->isEmpty() && $result['search_term'] !== ''
            ? CatalogSearch::suggest($result['search_term'])
            : null;

        return $this->success('Products retrieved.', [
            'data' => ProductCardResource::collection($result['products'])->resolve($request),
            'meta' => [
                'page' => $result['page'],
                'per_page' => $result['per_page'],
                'total' => $result['total'],
                'has_more' => $result['total'] > $result['page'] * $result['per_page'],
                'suggestion' => $suggestion,
            ],
        ]);
    }

    public function show(Request $request, Product $product, PriceResolver $prices): JsonResponse
    {
        abort_unless($product->isListedForSale(), 404);

        $product->load(['images', 'seller', 'category', 'variants.optionValues.option', 'options.values']);
        $product->loadAvg('reviews', 'rating')->loadCount('reviews');

        $images = $product->images->sortByDesc('is_primary')->unique('path')
            ->map(fn ($image) => $product->catalogImageUrl(collect([$image]), requireExistingFile: false))
            ->filter()->map(fn (string $url) => ProductCardResource::absoluteUrl($url))->values();
        $cover = ProductCardResource::absoluteUrl($product->catalogImageUrl(requireExistingFile: false));
        if ($cover !== null) {
            $images = $images->prepend($cover)->unique()->values();
        }

        $options = $product->options->filter(fn ($option) => $option->values->isNotEmpty())->values();

        return $this->success('Product retrieved.', [
            'id' => $product->id,
            'name' => $product->name,
            'description' => $product->description,
            'category' => $product->category === null ? null : ['id' => $product->category->id, 'label' => $product->category->label],
            'seller' => $product->seller === null ? null : ['id' => $product->seller->id, 'name' => $product->seller->business_name],
            'images' => $images,
            'price' => ProductCardResource::price($product->buyerPrice()),
            'rating' => $product->reviews_avg_rating !== null ? round((float) $product->reviews_avg_rating, 1) : null,
            'review_count' => (int) $product->reviews_count,
            'weight_grams' => (int) $product->weight_grams,
            'options' => $options->map(fn ($option): array => [
                'name' => $option->name,
                'values' => $option->values->pluck('value')->values(),
            ]),
            'variants' => $product->variants->map(fn (ProductVariant $variant): array => [
                'id' => $variant->id,
                'price' => ProductCardResource::price($prices->forVariant($product, $variant)),
                'stock' => (int) $variant->stock_quantity,
                'available' => (bool) $variant->is_available,
                'values' => $options->map(fn ($option) => $variant->optionValues->firstWhere('product_option_id', $option->id)?->value)->values(),
            ])->values(),
        ]);
    }
}
