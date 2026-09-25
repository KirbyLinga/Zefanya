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
        // TODO: implement full product listing page with filters/pagination
        return view('Buyer.Products.index');
    }

    public function show(Product $id): View
    {
        // Route uses {id} so binding resolves as $id; alias to $product for clarity.
        $product = $id;

        // ── 404 guard ─────────────────────────────────────────────────────
        // Only publicly-visible, non-deleted, active products are accessible.
        // Soft-deleted products are excluded automatically by Eloquent.
        if ($product->status !== 'active') {
            abort(404);
        }

        // Eager-load real relations
        $product->load(['images', 'seller', 'category']);

        // ── Real data from the model ──────────────────────────────────────

        // Breadcrumb — TODO: derive middle crumbs from a real category hierarchy table
        $breadcrumb = [
            ['label' => 'Home',                                'url' => route('buyer.home')],
            ['label' => $product->category?->label ?? 'Shop', 'url' => '#'],
            ['label' => $product->name,                        'url' => null],
        ];

        // Gallery — real product images; fall back to nulls so the layout still renders
        $galleryImages = $product->images->isNotEmpty()
            ? $product->images->map(fn ($img) => Storage::url($img->path))->values()->all()
            : array_fill(0, 5, null);

        // Price — real value via formattedPrice()
        $price = $product->formattedPrice();
        $rawPrice = (float) $product->price;

        // TODO: add old_price / discount_pct columns to the products table.
        // For now we show a placeholder struck-through price for mockup purposes.
        $oldPrice = null;
        $discountPct = null;
        if ($rawPrice > 0) {
            $oldPrice = '$'.number_format($rawPrice / 0.75, 2);
            $discountPct = '25%';
        }

        // TODO: add a reviews table; these are placeholder values.
        $rating = 4.9;
        $reviewCount = 284;
        $soldCount = 1_240;

        // TODO: add a product_variants table for colours and sizes.
        $colors = [
            ['name' => 'Blush Sand',     'token' => 'bg-primary-300'],
            ['name' => 'Ivory Cream',    'token' => 'bg-secondary-200'],
            ['name' => 'Sage Mist',      'token' => 'bg-tertiary-300'],
            ['name' => 'Espresso Brown', 'token' => 'bg-neutral-700'],
        ];
        $sizes = ['XS', 'S', 'M', 'L', 'XL'];

        // Description — real column; falls back to a sensible placeholder
        $description = $product->description
            ?? 'No description has been added for this product yet.';

        // Features — TODO: add a product_features table or JSON column.
        $features = [
            ['icon' => 'leaf',   'title' => 'Ethical Silhouette',       'text' => 'GOTS-certified and Responsible Wool Standard (RWS).'],
            ['icon' => 'award',  'title' => 'Bespoke Craft Stitch',      'text' => 'French seams and hand-felled linings throughout.'],
            ['icon' => 'layers', 'title' => 'Pocket & Drape Integrity',  'text' => 'All-natural drape with precision-cut panels.'],
            ['icon' => 'globe',  'title' => 'Origin & Traceability',     'text' => 'Fully traceable supply chain from farm to garment.'],
        ];

        // Seller — real business_name and storeInitials() from the loaded relation
        $seller = $product->seller;
        $sellerData = [
            'name' => $seller?->business_name ?? 'Zefanya Marketplace',
            'initials' => $seller?->storeInitials() ?? 'ZM',
            'badge' => 'ZEFANYA PREMIUM',
            // TODO: real seller rating and followers once a seller_reviews table exists
            'rating' => 4.9,
            'followers' => '12.4K',
        ];

        // TODO: compute from a reviews table once it exists.
        $ratingBreakdown = [5 => 91, 4 => 6, 3 => 2, 2 => 1, 1 => 0];

        // TODO: replace with real Review model records.
        $reviews = [
            [
                'name' => 'Sophia Laurens',
                'verified' => true,
                'date' => 'November 12, 2024',
                'rating' => 5,
                'text' => 'Absolutely love the quality — the fabric is incredibly soft and the fit is perfect.',
                'photo' => null,
            ],
            [
                'name' => 'James Deluca',
                'verified' => true,
                'date' => 'October 8, 2024',
                'rating' => 5,
                'text' => 'Exceeded my expectations. Worth every penny and ships fast.',
                'photo' => null,
            ],
        ];

        // Related products — real query: same category, active, approved seller, exclude self
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
            'oldPrice',
            'discountPct',
            'rating',
            'reviewCount',
            'soldCount',
            'colors',
            'sizes',
            'description',
            'features',
            'sellerData',
            'ratingBreakdown',
            'reviews',
            'related',
        ));
    }
}
