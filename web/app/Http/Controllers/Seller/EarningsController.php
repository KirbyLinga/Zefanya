<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Services\Seller\SellerEarningsService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EarningsController extends Controller
{
    public function index(Request $request, SellerEarningsService $earnings): View
    {
        return view('Seller.Earnings.index', $earnings->summary($request->user('seller')));
    }
}
