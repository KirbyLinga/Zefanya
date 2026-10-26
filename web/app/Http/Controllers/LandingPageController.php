<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Shared\Category;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class LandingPageController extends Controller
{
    public function index(): View|RedirectResponse
    {
        if (Auth::guard('buyer')->check()) {
            return redirect()->route('buyer.home');
        }

        $products = Product::query()->withBuyerCardData()
            ->activeInStock()
            ->whereHas('seller', fn ($query) => $query->where('status', 'approved'))
            ->with(['images', 'seller', 'variants.optionValues'])
            ->latest('id')
            ->limit(4)
            ->get();

        $categories = Category::query()->orderBy('id')->get();

        return view('LandingPage.index', compact('products', 'categories'));
    }
}
