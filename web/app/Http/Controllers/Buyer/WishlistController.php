<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Buyer\Wishlist;
use App\Models\Catalog\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WishlistController extends Controller
{
    /**
     * GET /buyer/wishlist — Display wishlist page
     */
    public function index(): View
    {
        $buyer = auth('buyer')->user();
        $wishlistItems = $buyer->wishlist()
            ->whereHas('product')
            ->with(['product' => fn ($query) => $query->withBuyerCardData()->with(['images', 'seller', 'category'])])
            ->paginate(12);

        return view('Buyer.Wishlist.index', compact('wishlistItems'));
    }

    /**
     * POST /buyer/wishlist/{product_id} — Add to or remove from wishlist (toggle)
     */
    public function toggle(Request $request, int $productId): JsonResponse
    {
        $buyer = auth('buyer')->user();

        $wishlist = Wishlist::query()
            ->where('buyer_id', $buyer->id)
            ->where('product_id', $productId)
            ->first();

        if ($wishlist) {
            $wishlist->delete();
            $isSaved = false;
        } else {
            Product::query()->findOrFail($productId);

            Wishlist::create([
                'buyer_id' => $buyer->id,
                'product_id' => $productId,
            ]);
            $isSaved = true;
        }

        return response()->json([
            'success' => true,
            'isSaved' => $isSaved,
            'count' => $buyer->wishlist()->whereHas('product')->count(),
        ]);
    }

    /**
     * DELETE /buyer/wishlist/{id} — Remove item from wishlist
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $buyer = auth('buyer')->user();
        $wishlist = Wishlist::query()
            ->where('id', $id)
            ->where('buyer_id', $buyer->id)
            ->firstOrFail();

        $wishlist->delete();

        return response()->json([
            'success' => true,
            'count' => $buyer->wishlist()->whereHas('product')->count(),
        ]);
    }
}
