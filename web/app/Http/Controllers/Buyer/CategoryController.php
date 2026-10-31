<?php

namespace App\Http\Controllers\Buyer;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Catalog\Product;
use App\Models\Shared\Category;
use App\Models\Shared\OrderItem;
use Illuminate\View\View;

class CategoryController extends Controller
{
    public function index(): View
    {
        $categories = Category::all();

        return view('Buyer.Categories.index', compact('categories'));
    }

    public function show(Category $id): View
    {
        $category = $id;
        $categories = Category::all();

        $sortOptions = [
            'relevance' => 'Relevance',
            'popular' => 'Most Popular',
            'newest' => 'Newest',
            'price_asc' => 'Price: Low to High',
            'price_desc' => 'Price: High to Low',
        ];
        $sort = match (request()->query('sort')) {
            'price-low' => 'price_asc',
            'price-high' => 'price_desc',
            default => request()->string('sort')->toString(),
        };
        if ($sort === 'oldest') {
            $sortOptions['oldest'] = 'Oldest';
        }
        if (! array_key_exists($sort, $sortOptions)) {
            $sort = 'relevance';
        }
        $preservedFilters = request()->only(['min_price', 'max_price']);

        $query = Product::query()->withBuyerCardData()
            ->activeInStock()
            ->where('category_id', $category->id)
            ->with(['images', 'seller', 'category'])
            ->whereHas('seller', fn ($q) => $q->where('status', 'approved'))
            ->withBuyerPriceFilters($preservedFilters + ['sort' => $sort]);

        if ($sort === 'popular') {
            $fulfilledUnits = OrderItem::query()
                ->join('seller_orders', 'seller_orders.id', '=', 'order_items.seller_order_id')
                ->whereColumn('order_items.product_id', 'products.id')
                ->whereColumn('seller_orders.seller_id', 'products.seller_id')
                ->whereIn('seller_orders.status', [OrderStatus::Delivered->value, OrderStatus::Completed->value])
                ->selectRaw('COALESCE(SUM(order_items.quantity), 0)');

            $query->addSelect(['category_sold_units' => $fulfilledUnits])
                ->reorder()->orderByDesc('category_sold_units')->orderBy('products.id');
        }

        $products = $query->paginate(20)->withQueryString();

        return view('Buyer.Categories.show', compact('category', 'products', 'categories', 'sort', 'sortOptions', 'preservedFilters'));
    }
}
