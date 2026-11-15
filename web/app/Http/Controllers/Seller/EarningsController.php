<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Logistics\ParcelLossCase;
use App\Services\Logistics\ParcelLossService;
use App\Services\Seller\SellerEarningsService;
use App\Services\Seller\SellerTransactionService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EarningsController extends Controller
{
    public function index(Request $request, SellerEarningsService $earnings, SellerTransactionService $transactions): View
    {
        $seller = $request->user('seller');

        return view('Seller.Earnings.index', $earnings->summary($seller) + [
            'transactions' => $transactions->paginate($seller),
            'lostCases' => ParcelLossService::available()
                ? ParcelLossCase::query()->where('seller_id', $seller->id)
                    ->with(['sellerOrder', 'provider', 'adjustments'])->latest('id')->limit(50)->get()
                : collect(),
        ]);
    }
}
