<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Http\Requests\Seller\SellerPromotionRequest;
use App\Models\Catalog\Product;
use App\Models\Seller\SellerPromotion;
use App\Services\Seller\SellerPromotionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PromotionController extends Controller
{
    public function index(Request $request): View
    {
        $sellerId = $request->user('seller')->id;

        return view('Seller.Promotions.index', [
            'promotions' => SellerPromotion::query()
                ->where('seller_id', $sellerId)
                ->with('products')
                ->latest('starts_at')
                ->latest('id')
                ->get(),
            'products' => Product::query()
                ->forSeller($sellerId)
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(SellerPromotionRequest $request, SellerPromotionService $promotions): RedirectResponse
    {
        $promotions->create($request->user('seller'), $request->promotionAttributes());

        return to_route('seller.promotions.index')->with('success', 'Your deal has been created.');
    }

    public function destroy(Request $request, SellerPromotion $promotion): RedirectResponse
    {
        abort_unless($promotion->seller_id === $request->user('seller')->id, 403);

        $promotion->delete();

        return to_route('seller.promotions.index')->with('success', 'Your deal has been deleted.');
    }
}
