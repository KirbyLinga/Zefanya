<?php

namespace App\Http\Controllers\Buyer;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Seller\Seller;
use App\Models\Shared\Category;
use App\Models\Voucher;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function index(): View
    {
        $buyer = auth('buyer')->user();
        $buyerName = $buyer ? $buyer->fullName() : null;

        $categories = Category::query()->orderBy('id')->get();

        $catalog = Product::query()->withBuyerCardData()
            ->activeInStock()
            ->whereHas('seller', fn ($query) => $query->where('status', 'approved'))
            ->with(['images', 'seller'])
            ->latest('id')
            ->limit(20)
            ->get();

        $flashProducts = $catalog->take(4)->values();
        $newArrivals = $catalog->slice(4, 4)->values();
        $forYouProducts = $catalog->slice(8, 6)->values();
        if ($forYouProducts->isEmpty()) {
            $forYouProducts = $catalog->take(6)->values();
        }
        $heroSlides = $this->heroSlides();

        $stores = Seller::query()
            ->where('status', 'approved')
            ->with('lineOfBusiness')->withAvg('reviews', 'rating')->withCount('reviews')
            ->withCount(['products as active_products_count' => fn ($query) => $query->where('status', 'active')])
            ->orderByDesc('active_products_count')
            ->orderBy('id')
            ->limit(3)
            ->get();

        $storeProducts = $stores->isEmpty()
            ? collect()
            : Product::query()->withBuyerCardData()
                ->whereIn('seller_id', $stores->modelKeys())
                ->activeInStock()
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

        return view('Buyer.Home.index', compact(
            'buyerName',
            'categories',
            'flashProducts',
            'newArrivals',
            'forYouProducts',
            'heroSlides',
            'stores',
        ));
    }

    /** Build a bounded set of promotions from real catalog and fulfilled sales data. */
    private function heroSlides(): array
    {
        $slides = [];
        $featured = Product::query()->withBuyerCardData()->activeInStock()
            ->whereHas('seller', fn ($query) => $query->where('status', 'approved'))
            ->whereHas('images')
            ->with(['images', 'category'])
            ->latest('created_at')->latest('id')->first();

        if ($featured) {
            $slides[] = [
                'heading' => 'New arrivals',
                'text' => $featured->name,
                'imageProduct' => $featured,
                'alt' => $featured->name,
                'url' => route('buyer.products.index', ['sort' => 'newest']),
                'button' => 'Shop new arrivals',
            ];
        }

        // Sales means units in delivered/completed seller orders, excluding returns.
        $fulfilled = [OrderStatus::Delivered->value, OrderStatus::Completed->value];
        $sales = OrderItem::query()
            ->join('seller_orders', 'seller_orders.id', '=', 'order_items.seller_order_id')
            ->whereColumn('seller_orders.seller_id', 'sellers.id')
            ->whereIn('seller_orders.status', $fulfilled)
            ->selectRaw('SUM(order_items.quantity)');
        $topStore = Seller::query()->where('status', 'approved')
            ->whereHas('sellerOrders', fn ($query) => $query->whereIn('status', $fulfilled))
            ->addSelect(['sold_units' => $sales])
            ->with(['products' => fn ($query) => $query->activeInStock()->whereHas('images')
                ->with('images')->latest('id')->limit(1)])
            ->orderByDesc('sold_units')->orderBy('id')->first();
        $storeProduct = $topStore?->products->first();
        if ($storeProduct) {
            $slides[] = [
                'heading' => 'Best-selling store',
                'text' => $topStore->business_name,
                'imageProduct' => $storeProduct,
                'alt' => $storeProduct->name.' from '.$topStore->business_name,
                'url' => route('buyer.products.index', ['seller' => $topStore->id]),
                'button' => 'Shop this store',
            ];
        }

        $spotlight = $featured?->category ? $featured : Product::query()->withBuyerCardData()->activeInStock()
            ->whereHas('seller', fn ($query) => $query->where('status', 'approved'))
            ->whereHas('images')->whereHas('category')->with(['images', 'category'])
            ->latest('id')->first();
        if ($spotlight?->category) {
            $slides[] = [
                'heading' => 'Category spotlight',
                'text' => $spotlight->category->label,
                'imageProduct' => $spotlight,
                'alt' => $spotlight->name.' in '.$spotlight->category->label,
                'url' => route('buyer.categories.show', $spotlight->category_id),
                'button' => 'Explore category',
            ];
        }

        $voucher = Voucher::query()->where('is_active', true)->where('value', '>', 0)
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where(fn ($query) => $query->whereNull('usage_limit')->orWhereColumn('times_used', '<', 'usage_limit'))
            ->latest('id')->first();
        if ($voucher && $featured) {
            $minimum = $voucher->min_spend
                ? 'Minimum spend: PHP '.number_format($voucher->min_spend / 100, 2).'.'
                : 'Apply at checkout.';
            $slides[] = [
                'heading' => 'A little extra',
                'text' => 'Use '.$voucher->code.'. '.$minimum,
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
                $best = ['image' => $disk->url($image->path), 'width' => $width, 'height' => $height];
            }
        }

        return $best;
    }
}
