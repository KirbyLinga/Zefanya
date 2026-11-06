<?php

namespace App\Http\Controllers\Buyer;

use App\Enums\OrderStatus;
use App\Enums\VoucherScope;
use App\Http\Controllers\Controller;
use App\Models\Buyer\StoreFollow;
use App\Models\Catalog\Product;
use App\Models\Seller\Seller;
use App\Models\Shared\Category;
use App\Models\Shared\OrderItem;
use App\Models\Shared\Voucher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class HomeController extends Controller
{
    private const TRENDING_DAYS = 30;

    public function index(): View
    {
        $buyer = auth('buyer')->user();
        $buyerName = $buyer ? $buyer->fullName() : null;

        $categories = Category::query()->orderBy('id')->get();

        // Only what a buyer can really buy for less. A running sale can still
        // show no discount (its variant sold out), so check the resolved price.
        $flashIds = Product::query()->discoverable()
            ->whereHas('ongoingSale')
            ->latest('id')
            ->limit(12)
            ->pluck('id')
            ->all();
        $flashProducts = $this->productCards($flashIds)
            ->filter(fn (Product $product) => $product->buyerPrice()?->badge() !== null)
            ->take(4)
            ->values();

        $onSaleProducts = $this->productCards($this->fairProductIds(
            Product::query()->discoverable()->withRealSaleDiscount(),
            4,
            $flashProducts->modelKeys(),
        ));
        $newArrivals = $this->productCards($this->fairProductIds(Product::query()->discoverable(), 4));
        $trendingProducts = $this->productCards($this->trendingProductIds(4));

        // Skip what is already on the page, unless that would leave the grid empty.
        $shownIds = array_merge(
            $flashProducts->modelKeys(),
            $onSaleProducts->modelKeys(),
            $newArrivals->modelKeys(),
            $trendingProducts->modelKeys(),
        );
        $forYouIds = $this->fairProductIds(Product::query()->discoverable(), 8, $shownIds, rotateSellers: true)
            ?: $this->fairProductIds(Product::query()->discoverable(), 8, rotateSellers: true);
        $forYouProducts = $this->productCards($forYouIds);
        $heroSlides = $this->heroSlides();

        // Rank by units really delivered, then load the expensive review
        // aggregates only for those three stores.
        $storeIds = Seller::query()
            ->openForBusiness()
            ->whereHas('products', fn ($query) => $query->activeInStock())
            ->where($this->sellerFulfilledUnits(), '>', 0)
            ->orderByDesc($this->sellerFulfilledUnits())
            ->orderBy('id')
            ->limit(3)
            ->pluck('id')
            ->all();
        $stores = $this->storeCards($storeIds);

        // Newly approved stores first, so a new seller can be found directly.
        $exploreStores = $this->storeCards(Seller::query()
            ->openForBusiness()
            ->whereHas('products', fn ($query) => $query->activeInStock())
            ->whereNotIn('id', $storeIds)
            ->latest('approved_at')
            ->latest('id')
            ->limit(4)
            ->pluck('id')
            ->all());

        $followedStoreIds = $buyer
            ? StoreFollow::query()->where('buyer_id', $buyer->id)->pluck('seller_id')->all()
            : [];

        return view('Buyer.Home.index', compact(
            'buyerName',
            'categories',
            'flashProducts',
            'onSaleProducts',
            'newArrivals',
            'trendingProducts',
            'forYouProducts',
            'heroSlides',
            'stores',
            'exploreStores',
            'followedStoreIds',
        ));
    }

    /** Build a bounded set of promotions from real catalog and fulfilled sales data. */
    private function heroSlides(): array
    {
        $slides = [];
        $featuredId = Product::query()->discoverable()
            ->whereHas('images')
            ->latest('created_at')->latest('id')
            ->value('id');
        $featured = $featuredId !== null ? Product::query()->withBuyerCardData()
            ->with(['images', 'category'])
            ->where('id', $featuredId)
            ->first() : null;

        if ($featured) {
            $slides[] = [
                'heading' => 'New arrivals',
                'title' => 'Fresh finds for every day',
                'text' => 'Just added: '.$featured->name,
                'imageProduct' => $featured,
                'alt' => $featured->name,
                'url' => route('buyer.products.index', ['sort' => 'newest']),
                'button' => 'Shop new arrivals',
            ];
        }

        // Sales means units in delivered/completed seller orders, excluding returns.
        $fulfilled = [OrderStatus::Delivered->value, OrderStatus::Completed->value];
        $topStore = Seller::query()->openForBusiness()
            ->whereHas('sellerOrders', fn ($query) => $query->whereIn('status', $fulfilled))
            ->addSelect(['sold_units' => $this->sellerFulfilledUnits()])
            ->with(['products' => fn ($query) => $query->activeInStock()->whereHas('images')
                ->with('images')->latest('id')->limit(1)])
            ->orderByDesc('sold_units')->orderBy('id')->first();
        $storeProduct = $topStore?->products->first();
        if ($storeProduct) {
            $slides[] = [
                'heading' => 'Best-selling store',
                'title' => $topStore->business_name,
                'text' => 'The store with the most items delivered on Zefanya.',
                'imageProduct' => $storeProduct,
                'alt' => $storeProduct->name.' from '.$topStore->business_name,
                'url' => route('buyer.products.index', ['seller' => $topStore->id]),
                'button' => 'Shop this store',
            ];
        }

        $spotlight = $featured?->category ? $featured : Product::query()->discoverable()->withBuyerCardData()
            ->whereHas('images')->whereHas('category')->with(['images', 'category'])
            ->latest('id')->first();
        if ($spotlight?->category) {
            $slides[] = [
                'heading' => 'Category spotlight',
                'title' => $spotlight->category->label,
                'text' => 'See what stores are offering in this category.',
                'imageProduct' => $spotlight,
                'alt' => $spotlight->name.' in '.$spotlight->category->label,
                'url' => route('buyer.categories.show', $spotlight->category_id),
                'button' => 'Explore category',
            ];
        }

        // Platform vouchers only: a Seller's code-only code must never be advertised.
        $voucher = Voucher::query()->where('is_active', true)->where('value', '>', 0)
            ->where('scope', VoucherScope::Platform->value)
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where(fn ($query) => $query->whereNull('usage_limit')->orWhereColumn('times_used', '<', 'usage_limit'))
            ->latest('id')->first();
        if ($voucher && $featured) {
            $minimum = $voucher->min_spend
                ? 'Minimum spend: ₱'.number_format($voucher->min_spend / 100, 2).'.'
                : 'Apply at checkout.';
            $slides[] = [
                'heading' => 'A little extra',
                'title' => 'Use '.$voucher->code,
                'text' => $minimum,
                'imageProduct' => $featured,
                'alt' => $featured->name,
                'url' => route('buyer.products.index'),
                'button' => 'Explore the shop',
            ];
        }

        $photos = [];
        foreach ($slides as &$slide) {
            $product = $slide['imageProduct'];
            $photos[$product->id] ??= $this->heroImage($product);
            $slide = array_merge($slide, $photos[$product->id]);
            unset($slide['imageProduct']);
        }
        unset($slide);

        return $slides ?: [[
            'heading' => 'Discover Zefanya',
            'title' => 'Find something you love',
            'text' => 'Explore our product catalog.',
            'image' => null,
            'alt' => '',
            'url' => route('buyer.products.index'),
            'button' => 'Browse the shop',
        ]];
    }

    /** Use the highest-resolution original; image relationships are already loaded. */
    private function heroImage(Product $product): array
    {
        $disk = Storage::disk('public');
        $best = ['image' => $product->catalogImageUrl(requireExistingFile: false), 'width' => 1200, 'height' => 900];
        $largestArea = 0;

        foreach ($product->images as $image) {
            $path = $disk->path($image->path);
            if (! is_file($path)) {
                continue;
            }
            $dimensions = @getimagesize($path);
            if ($dimensions === false) {
                continue;
            }
            [$width, $height] = $dimensions;
            if ($width * $height > $largestArea) {
                $largestArea = $width * $height;
                // Path-only URL — Storage::url() embeds APP_URL (often a local
                // http host) and browsers block it as mixed content on HTTPS.
                $best = [
                    'image' => $product->catalogImageUrl(collect([$image]), requireExistingFile: false),
                    'width' => $width,
                    'height' => $height,
                ];
            }
        }

        return $best;
    }

    /** Units in a seller's delivered/completed seller orders. */
    private function sellerFulfilledUnits(): QueryBuilder
    {
        return OrderItem::query()
            ->join('seller_orders', 'seller_orders.id', '=', 'order_items.seller_order_id')
            ->whereColumn('seller_orders.seller_id', 'sellers.id')
            ->whereIn('seller_orders.status', [OrderStatus::Delivered->value, OrderStatus::Completed->value])
            ->selectRaw('SUM(order_items.quantity)')
            ->toBase();
    }

    /**
     * Round-robin in SQL, over every listed product rather than a recent sample:
     * each seller's newest product first, then each seller's second newest, and so on.
     * With $rotateSellers, the sellers who go first change daily, so when there are
     * more sellers than slots each one still takes a turn.
     *
     * @param  array<int, int>  $exceptIds
     * @return array<int, int>
     */
    private function fairProductIds(Builder $products, int $limit, array $exceptIds = [], bool $rotateSellers = false): array
    {
        $products->whereNotIn('id', $exceptIds);
        $sellerCount = (clone $products)->distinct()->count('seller_id');
        if ($sellerCount === 0) {
            return [];
        }

        $ranked = $products
            ->select('id')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY seller_id ORDER BY id DESC) AS seller_rank')
            ->selectRaw('DENSE_RANK() OVER (ORDER BY seller_id) AS seller_position');

        return DB::query()->fromSub($ranked, 'ranked')
            ->orderBy('seller_rank')
            ->when($rotateSellers, fn ($query) => $query->orderByRaw(
                '(seller_position + ?) % ?',
                [(int) floor(now()->timestamp / 86400), $sellerCount],
            ))
            ->orderByDesc('id')
            ->limit($limit)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Most units in delivered/completed seller orders placed in the last 30 days.
     *
     * @return array<int, int>
     */
    private function trendingProductIds(int $limit): array
    {
        $recentUnits = OrderItem::query()
            ->join('seller_orders', 'seller_orders.id', '=', 'order_items.seller_order_id')
            ->whereColumn('order_items.product_id', 'products.id')
            ->whereIn('seller_orders.status', [OrderStatus::Delivered->value, OrderStatus::Completed->value])
            ->where('seller_orders.created_at', '>=', now()->subDays(self::TRENDING_DAYS))
            ->selectRaw('COALESCE(SUM(order_items.quantity), 0)');

        return Product::query()->discoverable()
            ->where($recentUnits, '>', 0)
            ->orderByDesc($recentUnits)
            ->orderByDesc('id')
            ->limit($limit)
            ->pluck('id')
            ->all();
    }

    /**
     * Load the expensive card aggregates for the chosen products only, keeping their order.
     *
     * @param  array<int, int>  $ids
     */
    private function productCards(array $ids): Collection
    {
        return Product::query()->withBuyerCardData()
            ->with(['images', 'seller'])
            ->whereIn('id', $ids)
            ->get()
            ->sortBy(fn (Product $product) => array_search($product->id, $ids, true))
            ->values();
    }

    /**
     * Load store cards in the given order, each with up to three live products.
     *
     * @param  array<int, int>  $ids
     */
    private function storeCards(array $ids): Collection
    {
        $stores = Seller::query()->whereIn('id', $ids)
            ->with('lineOfBusiness')->withAvg('reviews', 'rating')->withCount('reviews')
            ->withCount(['products as active_products_count' => fn ($query) => $query->where('status', 'active')])
            ->get()
            ->sortBy(fn (Seller $store) => array_search($store->id, $ids, true))
            ->values();

        $productIds = $this->fairProductIds(
            Product::query()->activeInStock()->whereIn('seller_id', $ids),
            3 * count($ids),
        );
        $storeProducts = Product::query()->withBuyerCardData()
            ->whereIn('id', $productIds)
            ->with('images')
            ->latest('id')
            ->get()
            ->groupBy('seller_id');

        $stores->each(function (Seller $store) use ($storeProducts): void {
            $store->setRelation(
                'products',
                $storeProducts->get($store->id, collect())->take(3)->values(),
            );
        });

        return $stores;
    }
}
