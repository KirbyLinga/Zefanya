<?php

namespace App\Http\Controllers\Buyer;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Buyer\StoreFollow;
use App\Models\Catalog\Product;
use App\Models\Seller\Seller;
use App\Models\Seller\StoreCategory;
use App\Models\Shared\OrderItem;
use App\Models\Shared\Voucher;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StoreCategoryController extends Controller
{
    public function show(Request $request, Seller $seller, ?StoreCategory $storeCategory = null): View
    {
        abort_unless($seller->isApproved() && ! $seller->isArchived(), 404);
        abort_if($storeCategory !== null && (int) $storeCategory->seller_id !== (int) $seller->id, 404);
        abort_if($storeCategory !== null && ! $storeCategory->isPubliclyVisible(), 404);

        $tab = (string) $request->query('tab', 'products');
        if (! in_array($tab, ['products', 'reviews', 'about'], true)) {
            $tab = 'products';
        }

        $search = trim((string) $request->query('q', ''));
        $sort = (string) $request->query('sort', 'relevance');
        if (! in_array($sort, ['relevance', 'newest', 'oldest', 'price_asc', 'price_desc'], true)) {
            $sort = 'relevance';
        }

        $uncategorized = $request->routeIs('buyer.stores.uncategorized');
        $categoryParam = $request->query('category');

        if ($storeCategory === null && filled($categoryParam)) {
            if ($categoryParam === 'uncategorized') {
                $uncategorized = true;
            } elseif (ctype_digit((string) $categoryParam)) {
                $storeCategory = StoreCategory::query()
                    ->where('seller_id', $seller->id)
                    ->whereKey((int) $categoryParam)
                    ->where('is_visible', true)
                    ->first();

                abort_if($storeCategory === null || ! $storeCategory->isPubliclyVisible(), 404);
            }
        }

        $storeCategories = StoreCategory::query()
            ->where('seller_id', $seller->id)
            ->whereNull('parent_id')
            ->where('is_visible', true)
            ->with(['children' => fn ($query) => $query->where('seller_id', $seller->id)->where('is_visible', true)])
            ->displayOrder()
            ->get();

        $products = Product::query()
            ->forSeller($seller->id)
            ->activeInStock()
            ->withBuyerCardData()
            ->with(['images', 'seller', 'category'])
            ->when($storeCategory !== null, fn ($query) => $query->whereHas(
                'storeCategories',
                fn ($categories) => $categories->whereKey($storeCategory->id)
            ))
            ->when($uncategorized, fn ($query) => $query->whereDoesntHave('storeCategories'))
            ->when($search !== '', function ($query) use ($search): void {
                $like = '%'.mb_strtolower($search).'%';
                $query->where(function ($query) use ($like): void {
                    $query->whereRaw('LOWER(products.name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(products.description) LIKE ?', [$like]);
                });
            })
            ->withBuyerPriceFilters(['sort' => $sort])
            ->paginate(20)
            ->withQueryString();

        $seller->loadAvg('reviews', 'rating')->loadCount('reviews');

        $activeProductsCount = Product::query()
            ->forSeller($seller->id)
            ->where('status', 'active')
            ->whereNull('restriction_reason')
            ->count();

        $fulfilled = [OrderStatus::Delivered->value, OrderStatus::Completed->value];
        $itemsSold = (int) OrderItem::query()
            ->join('seller_orders', 'seller_orders.id', '=', 'order_items.seller_order_id')
            ->where('seller_orders.seller_id', $seller->id)
            ->whereIn('seller_orders.status', $fulfilled)
            ->sum('order_items.quantity');

        $reviews = $seller->reviews()
            ->with(['buyer:id,first_name,middle_initial,last_name', 'product:id,name', 'photos'])
            ->latest('reviews.created_at')
            ->paginate(10, ['reviews.*'], 'reviews_page')
            ->withQueryString();

        return view('Buyer.StoreCategories.show', [
            'seller' => $seller,
            'followersCount' => StoreFollow::query()->where('seller_id', $seller->id)->count(),
            'isFollowing' => auth('buyer')->check() && StoreFollow::query()->where('buyer_id', auth('buyer')->id())->where('seller_id', $seller->id)->exists(),
            'shopVouchers' => Voucher::offeredBy([(int) $seller->id], auth('buyer')->id()),
            'selectedCategory' => $storeCategory,
            'uncategorized' => $uncategorized,
            'storeCategories' => $storeCategories,
            'products' => $products,
            'reviews' => $reviews,
            'tab' => $tab,
            'search' => $search,
            'sort' => $sort,
            'activeProductsCount' => $activeProductsCount,
            'itemsSold' => $itemsSold,
        ]);
    }
}
