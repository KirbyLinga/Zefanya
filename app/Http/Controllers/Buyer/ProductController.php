<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Shared\Category;
use App\Services\ProductReviewService;
use Illuminate\Support\Facades\Storage;
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

        // Search by product name or description
        $search = request('q');
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->whereRaw('LOWER(name) LIKE ?', ['%'.strtolower($search).'%'])
                    ->orWhereRaw('LOWER(description) LIKE ?', ['%'.strtolower($search).'%']);
            });
        }

        $query->withBuyerPriceFilters(request()->only(['min_price', 'max_price', 'sort']));

        // Paginate with query string preservation
        $products = $query->paginate(12)->withQueryString();

        // Load all categories for filters
        $categories = Category::orderBy('label')->get();

        return view('Buyer.Products.index', compact(
            'products',
            'categories',
        ));
    }

    public function show(Product $id): View
    {
        $product = $id;

        if (! $product->isListedForSale()) {
            abort(404);
        }

        $product->load(['images', 'seller', 'category', 'variants.optionValues.option']);

        $product->loadAvg('reviews', 'rating')->loadCount('reviews');
        $reviews = $product->reviews()->latest('id')->paginate(10, ['rating', 'body', 'created_at'], 'reviews_page');
        $reviewBuyer = auth('buyer')->user();
        $canReview = $reviewBuyer && app(ProductReviewService::class)->eligibleItem($product, $reviewBuyer);
        $myReview = $reviewBuyer ? $product->reviews()->where('buyer_id', $reviewBuyer->id)->first() : null;

        $breadcrumb = [
            ['label' => 'Home', 'url' => route('buyer.home')],
            ['label' => $product->category?->label ?? 'Shop'],
            ['label' => $product->name, 'url' => null],
        ];

        $galleryImages = $product->images->isNotEmpty()
            ? $product->images->sortByDesc('is_primary')->unique('path')->map(fn ($img) => Storage::url($img->path))->values()->all()
            : array_fill(0, 5, null);

        $price = $product->formattedPriceRange();

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
                    'price' => (float) $variant->price,
                    'stock' => (int) $variant->stock_quantity,
                    'available' => $variant->is_available,
                    'images' => $product->images->where('variant_id', $variant->id)->unique('path')
                        ->map(fn ($image) => Storage::url($image->path))->values()->all(),
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
                'id' => $variant->id, 'price' => (float) $variant->price,
                'stock' => $variant->stock_quantity, 'available' => $variant->is_available,
                'values' => ['Option '.$variant->id],
            ])->all();
        }

        $description = $product->description
            ?? 'No description has been added for this product yet.';

        $seller = $product->seller;
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
            'myReview',
            'breadcrumb',
            'galleryImages',
            'price',
            'description',
            'sellerData',
            'related',
            'variantAxes',
            'variantData',
        ));
    }
}
