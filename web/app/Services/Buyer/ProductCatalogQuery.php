<?php

namespace App\Services\Buyer;

use App\Models\Catalog\Product;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * The buyer product listing: discoverable products filtered by seller,
 * category, on-sale, search term and price range, sorted and paged.
 *
 * Extracted verbatim from Buyer\ProductController::index/loadMore (which
 * duplicated it) so the web catalog and the mobile API share one query.
 */
class ProductCatalogQuery
{
    public const PER_PAGE = 20;

    /**
     * @param  array<string, mixed>  $input  seller, category, on_sale, q, min_price, max_price, sort
     * @return array{products: Collection<int, Product>, total: int, page: int, per_page: int, search_term: string, price_filters: array<string, string>, price_error: ?string, on_sale: bool}
     */
    public function run(array $input, int $page, int $perPage = self::PER_PAGE): array
    {
        $base = Product::query()
            ->discoverable();

        if (filled($input['seller'] ?? null)) {
            $base->where('seller_id', (int) $input['seller']);
        }

        $categoryId = $input['category'] ?? null;
        if ($categoryId) {
            $base->where('category_id', (int) $categoryId);
        }

        $onSaleOnly = filter_var($input['on_sale'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($onSaleOnly) {
            $base->withRealSaleDiscount();
        }

        $searchTerm = CatalogSearch::term($input['q'] ?? null);
        if ($searchTerm !== '') {
            CatalogSearch::apply($base, $searchTerm);
        }

        ['filters' => $priceFilters, 'error' => $priceError] = CatalogSearch::priceFilters(
            array_intersect_key($input, array_flip(['min_price', 'max_price']))
        );

        $page = max(1, $page);
        $idQuery = clone $base;
        $idQuery->withBuyerPriceFilters($priceFilters + array_intersect_key($input, array_flip(['sort'])));

        $total = (int) $idQuery->count();
        $ids = $idQuery->forPage($page, $perPage)->pluck('id')->all();

        $products = Product::query()->withBuyerCardData()
            ->with(['images', 'seller', 'category'])
            ->whereIn('id', $ids)
            ->get()
            ->sortBy(fn (Product $product) => array_search($product->id, $ids, true))
            ->values();

        return [
            'products' => $products,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'search_term' => $searchTerm,
            'price_filters' => $priceFilters,
            'price_error' => $priceError,
            'on_sale' => $onSaleOnly,
        ];
    }

    /** @param  array<string, mixed>  $result  from run() */
    public function paginator(array $result, string $path): LengthAwarePaginator
    {
        return new LengthAwarePaginator(
            $result['products'], $result['total'], $result['per_page'], $result['page'],
            ['path' => $path]
        );
    }
}
