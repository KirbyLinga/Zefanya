<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Buyer\CartItem;
use App\Models\Catalog\Product;
use App\Models\Shared\Category;
use App\Models\Shared\Voucher;
use App\Services\Buyer\BuyerPurchaseLimit;
use App\Services\Buyer\CatalogSearch;
use App\Services\Buyer\ProductReviewService;
use App\Services\Shared\PriceResolver;
use App\Services\Shared\ResolvedPrice;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function index(): View
    {
        $query = Product::query()->withBuyerCardData()
            ->activeInStock()
            ->with(['images', 'seller', 'category'])
            ->whereHas('seller', fn ($q) => $q->where('status', 'approved'));

        if (request()->filled('seller')) {
            $query->where('seller_id', request()->integer('seller'));
        }

        // Filter by category if provided
        $categoryId = request('category');
        if ($categoryId) {
            $query->where('category_id', (int) $categoryId);
        }

        // Search by product name or description (the navbar searches here).
        $searchTerm = CatalogSearch::term(request()->query('q'));
        if ($searchTerm !== '') {
            CatalogSearch::apply($query, $searchTerm);
        }

        ['filters' => $priceFilters, 'error' => $priceError] = CatalogSearch::priceFilters(request()->only(['min_price', 'max_price']));

        $query->withBuyerPriceFilters($priceFilters + request()->only(['sort']));

        // Paginate with query string preservation; a rejected price must not
        // follow the buyer from page to page.
        $products = $query->paginate(12)
            ->appends(request()->except(array_merge(['page'], $priceError !== null ? ['min_price', 'max_price'] : [])));

        // Load all categories for filters
        $categories = Category::orderBy('label')->get();

        $suggestion = $products->isEmpty() && $searchTerm !== '' && $priceFilters === []
            && ! request()->filled('category') && ! request()->filled('seller')
            ? CatalogSearch::suggest($searchTerm)
            : null;

        return view('Buyer.Products.index', compact(
            'products',
            'categories',
            'searchTerm',
            'priceFilters',
            'priceError',
            'suggestion',
        ));
    }

    public function show(Product $id): View
    {
        $product = $id;

        if (! $product->isListedForSale()) {
            abort(404);
        }

        $product->load(['images', 'seller', 'category', 'variants.optionValues.option', 'options.values', 'storeCategories.parent']);

        $product->loadAvg('reviews', 'rating')->loadCount('reviews');
        $ratingCounts = $product->reviews()->selectRaw('rating, COUNT(*) as total')->groupBy('rating')->pluck('total', 'rating');
        $reviews = $product->reviews()->with(['photos', 'orderItem', 'buyer:id,first_name,middle_initial,last_name'])->latest('id')->paginate(10, ['id', 'buyer_id', 'order_item_id', 'rating', 'body', 'seller_reply', 'seller_replied_at', 'seller_reply_edited_at', 'created_at'], 'reviews_page');
        $reviewBuyer = auth('buyer')->user();
        $reviewService = app(ProductReviewService::class);
        $reviewableItems = $reviewBuyer ? $reviewService->reviewableItems($product, $reviewBuyer) : collect();
        $canReview = $reviewableItems->isNotEmpty();
        $myReviews = $reviewBuyer
            ? $product->reviews()->where('buyer_id', $reviewBuyer->id)->with(['photos', 'orderItem'])->latest('id')->get()
            : collect();

        $breadcrumb = [
            ['label' => 'Home', 'url' => route('buyer.home')],
            ['label' => $product->category?->label ?? 'Shop'],
            ['label' => $product->name, 'url' => null],
        ];

        $galleryImages = $product->images->isNotEmpty()
            ? $product->images->sortByDesc('is_primary')->unique('path')
                ->map(fn ($img) => $product->catalogImageUrl(collect([$img]), requireExistingFile: false))
                ->filter()->values()->all()
            : array_fill(0, 5, null);

        // Prefer a surviving upload over a missing primary image, while keeping
        // URLs available for uploads served outside this PHP instance.
        $coverImage = $product->catalogImageUrl(requireExistingFile: false);
        if ($coverImage !== null) {
            $galleryImages = array_values(array_unique([$coverImage, ...$galleryImages]));
        }

        $pricing = $product->buyerPrice();
        $price = $pricing?->formatted() ?? 'Unavailable';
        $resolver = app(PriceResolver::class);
        $buyerId = auth('buyer')->id();
        $purchasedQuantity = $buyerId === null
            ? 0
            : app(BuyerPurchaseLimit::class)->purchased((int) $buyerId, $product->id);
        $cartRows = $buyerId === null
            ? collect()
            : CartItem::query()->where('buyer_id', $buyerId)->where('product_id', $product->id)->get(['variant_id', 'quantity']);
        $cartQuantity = (int) $cartRows->sum('quantity');
        $cartByVariant = $cartRows->mapWithKeys(fn (CartItem $row): array => [(int) $row->variant_id => (int) $row->quantity]);

        $variantAxes = null;
        $variantData = null;

        if ($product->hasVariants()) {
            $product->load('options.values');

            $variantAxes = $product->options
                ->filter(fn ($option) => $option->values->isNotEmpty())
                ->map(fn ($option) => [
                    'name' => $option->name,
                    'values' => $option->values->pluck('value')->all(),
                ])
                ->values()
                ->all();

            $variantData = $product->variants
                ->filter->hasOptions()
                ->map(fn ($variant) => [
                    'id' => $variant->id,
                    ...$this->variantPricing($resolver->forVariant($product, $variant)),
                    'stock' => (int) $variant->stock_quantity,
                    'cart_quantity' => (int) ($cartByVariant[$variant->id] ?? 0),
                    'available' => $variant->is_available,
                    'images' => ($variantImage = $product->catalogImageUrl(
                        $product->images->where('variant_id', $variant->id), requireExistingFile: false,
                    )) !== null ? [$variantImage] : [],
                    'color_axis' => $product->options->values()->search(fn ($option) => in_array(mb_strtolower($option->name), ['color', 'colour'], true)),
                    'values' => $product->options
                        ->filter(fn ($option) => $option->values->isNotEmpty())
                        ->map(fn ($option) => $variant->optionValues->firstWhere('product_option_id', $option->id)?->value)
                        ->values()->all(),
                ])
                ->values()
                ->all();
        }

        if ($variantAxes === null && $product->variants->count() > 1) {
            $variantAxes = [['name' => 'Option', 'values' => $product->variants->map(fn ($variant) => 'Option '.$variant->id)->all()]];
            $variantData = $product->variants->map(fn ($variant) => [
                'id' => $variant->id, ...$this->variantPricing($resolver->forVariant($product, $variant)),
                'stock' => $variant->stock_quantity, 'cart_quantity' => (int) ($cartByVariant[$variant->id] ?? 0), 'available' => $variant->is_available,
                'values' => ['Option '.$variant->id],
            ])->all();
        }

        $description = $product->description
            ?? 'No description has been added for this product yet.';

        $seller = $product->seller;
        $shopVouchers = $seller === null ? collect() : Voucher::offeredBy([(int) $seller->id], auth('buyer')->id())
            ->filter(fn (Voucher $voucher): bool => $voucher->appliesToProduct($product->id))
            ->values();
        $sellerData = [
            'name' => $seller?->business_name ?? 'Zefanya Marketplace',
            'initials' => $seller?->storeInitials() ?? 'ZM',
        ];

        $related = Product::query()->withBuyerCardData()
            ->activeInStock()
            ->when(
                $product->category_id,
                fn ($q) => $q->where('category_id', $product->category_id),
            )
            ->whereHas('seller', fn ($q) => $q->where('status', 'approved'))
            ->where('id', '!=', $product->id)
            ->with(['images', 'seller'])
            ->latest('id')
            ->limit(8)
            ->get();

        return view('Buyer.Products.show', compact(
            'product',
            'reviews',
            'canReview',
            'reviewableItems',
            'ratingCounts',
            'myReviews',
            'breadcrumb',
            'galleryImages',
            'price',
            'pricing',
            'shopVouchers',
            'description',
            'sellerData',
            'related',
            'variantAxes',
            'variantData',
            'purchasedQuantity',
            'cartQuantity',
        ));
    }

    /**
     * The picker's price text comes from the server, never from browser math.
     *
     * @return array{price: string, regular_price: string|null, badge: string|null}
     */
    private function variantPricing(ResolvedPrice $price): array
    {
        return [
            'price' => $price->formatted(),
            'regular_price' => $price->formattedRegular(),
            'badge' => $price->badge(),
        ];
    }
}
