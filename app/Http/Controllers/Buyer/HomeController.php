<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;

class HomeController extends Controller
{
    public function index()
    {
        $buyer = auth('buyer')->user();
        $buyerName = $buyer ? $buyer->fullName() : null;
        $cartCount = 0; // TODO: replace with $buyer->cart()->count() once Cart is built

        return view('Buyer.Home.index', compact('buyerName', 'cartCount'));
    }
}
