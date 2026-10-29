<?php

namespace App\Http\Controllers\Api\Buyer;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Buyer\Buyer;
use App\Models\Product;
use App\Models\WishlistItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WishlistController extends Controller
{
    /**
     * GET /api/buyer/wishlist
     * GET /api/v1/buyer/wishlist
     */
    public function index(Request $request): JsonResponse
    {
        $buyer = $request->user();
        abort_unless($buyer instanceof Buyer, 403, 'Unauthorized.');

        $items = WishlistItem::with(['product.images', 'product.seller', 'product.category'])
            ->where('buyer_id', $buyer->id)
            ->latest('id')
            ->get();

        $products = $items->pluck('product')->filter();

        return response()->json([
            'ok' => true,
            'product_ids' => $items->pluck('product_id')->map(fn ($id) => (string) $id)->values(),
            'products' => ProductResource::collection($products),
        ]);
    }

    /**
     * POST /api/buyer/wishlist/{product}
     * POST /api/v1/buyer/wishlist/{product}
     */
    public function store(Request $request, Product $product): JsonResponse
    {
        $buyer = $request->user();
        abort_unless($buyer instanceof Buyer, 403, 'Unauthorized.');

        $item = WishlistItem::firstOrCreate([
            'buyer_id' => $buyer->id,
            'product_id' => $product->id,
        ]);

        return response()->json([
            'ok' => true,
            'message' => 'Product saved to wishlist.',
            'product_id' => (string) $product->id,
        ], 201);
    }

    /**
     * DELETE /api/buyer/wishlist/{product}
     * DELETE /api/v1/buyer/wishlist/{product}
     */
    public function destroy(Request $request, Product $product): JsonResponse
    {
        $buyer = $request->user();
        abort_unless($buyer instanceof Buyer, 403, 'Unauthorized.');

        WishlistItem::where('buyer_id', $buyer->id)
            ->where('product_id', $product->id)
            ->delete();

        return response()->json([
            'ok' => true,
            'message' => 'Product removed from wishlist.',
            'product_id' => (string) $product->id,
        ]);
    }
}
