<?php

namespace App\Http\Controllers\Api\Buyer;

use App\Http\Controllers\Controller;
use App\Http\Middleware\Traits\ApiResponses;
use App\Http\Resources\Mobile\ProductCardResource;
use App\Models\Buyer\Wishlist;
use App\Models\Catalog\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Buyer mobile wishlist; same rows and rules as Buyer\WishlistController. */
class WishlistController extends Controller
{
    use ApiResponses;

    public function index(Request $request): JsonResponse
    {
        $page = $request->user()->wishlist()
            ->whereHas('product')
            ->with(['product' => fn ($query) => $query->withBuyerCardData()->with(['images', 'seller', 'category'])])
            ->latest('id')
            ->paginate(20);

        return $this->success('Wishlist retrieved.', [
            'data' => $page->getCollection()->map(fn (Wishlist $row): array => [
                'id' => $row->id,
                'product' => (new ProductCardResource($row->product))->resolve($request),
            ])->values(),
            'meta' => ['page' => $page->currentPage(), 'has_more' => $page->hasMorePages(), 'total' => $page->total()],
        ]);
    }

    /** PUT /wishlist/{product} — idempotent save. */
    public function store(Request $request, int $product): JsonResponse
    {
        Product::query()->findOrFail($product);
        Wishlist::query()->firstOrCreate(['buyer_id' => $request->user()->id, 'product_id' => $product]);

        return $this->success('Saved to wishlist.', ['saved' => true, 'count' => $this->count($request)]);
    }

    /** DELETE /wishlist/{product} — idempotent remove. */
    public function destroy(Request $request, int $product): JsonResponse
    {
        Wishlist::query()->where('buyer_id', $request->user()->id)->where('product_id', $product)->delete();

        return $this->success('Removed from wishlist.', ['saved' => false, 'count' => $this->count($request)]);
    }

    private function count(Request $request): int
    {
        return $request->user()->wishlist()->whereHas('product')->count();
    }
}
