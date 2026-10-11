<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function index(): View
    {
        // All active products, paginated, with seller eager-loaded
        $products = Product::query()
            ->where('status', 'active')
            ->with(['images', 'seller', 'category'])
            ->whereHas('seller', fn ($q) => $q->where('status', 'approved'))
            ->latest('id')
            ->paginate(20);

        return view('Buyer.Products.index', compact('products'));
    }

    public function show(Product $id): View
    {
        $product = $id;

        if ($product->status !== 'active') {
            abort(404);
        }

        $product->load(['images', 'seller', 'category', 'variants.optionValues.option']);

        $breadcrumb = [
            ['label' => 'Home', 'url' => route('buyer.home')],
            ['label' => $product->category?->label ?? 'Shop'],
            ['label' => $product->name, 'url' => null],
        ];

        $galleryImages = $product->images->isNotEmpty()
            ? $product->images->map(fn ($img) => Storage::url($img->path))->values()->all()
            : array_fill(0, 5, null);

        $price = $product->formattedPrice();

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
                    'values' => $variant->optionValues->pluck('value')->all(),
                ])
                ->values()
                ->all();
        }

        $description = $product->description
            ?? 'No description has been added for this product yet.';

        $seller = $product->seller;
        $sellerData = [
            'name' => $seller?->business_name ?? 'Zefanya Marketplace',
            'initials' => $seller?->storeInitials() ?? 'ZM',
        ];

        $related = Product::query()
            ->where('status', 'active')
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
