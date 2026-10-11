<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Seller\Seller;
use App\Models\Shared\Category;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function index(): View
    {
        $buyer = auth('buyer')->user();
        $buyerName = $buyer ? $buyer->fullName() : null;
        $cartCount = 0; // TODO: replace with $buyer->cart()->count() once Cart is built

        $categories = Category::query()->orderBy('id')->get();

        $catalog = Product::query()
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
        $heroProducts = $catalog->take(3)->values();

        $stores = Seller::query()
            ->where('status', 'approved')
            ->with('lineOfBusiness')
            ->withCount(['products as active_products_count' => fn ($query) => $query->where('status', 'active')])
            ->orderByDesc('active_products_count')
            ->orderBy('id')
            ->limit(3)
            ->get();

        $storeProducts = $stores->isEmpty()
            ? collect()
            : Product::query()
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
            'cartCount',
            'categories',
            'flashProducts',
            'newArrivals',
            'forYouProducts',
            'heroProducts',
            'stores',
        ));
    }
}
