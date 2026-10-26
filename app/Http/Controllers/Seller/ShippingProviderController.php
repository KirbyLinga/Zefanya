<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Logistics\LogisticsProvider;
use App\Services\SellerShippingProviderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ShippingProviderController extends Controller
{
    public function index(Request $request, SellerShippingProviderService $shipping): View
    {
        $seller = $request->user('seller');
        $catalog = $shipping->catalogForSeller($seller);

        return view('Seller.ShippingProviders.index', [
            'seller' => $seller,
            'catalog' => $catalog,
            'canOfferShipping' => $shipping->canOfferShipping($seller),
            'eligibleCount' => collect($catalog)->where('eligible', true)->count(),
            'enabledCount' => collect($catalog)->where('enabled', true)->where('eligible', true)->count(),
        ]);
    }

    public function enable(
        Request $request,
        LogisticsProvider $logisticsProvider,
        SellerShippingProviderService $shipping,
    ): RedirectResponse {
        $seller = $request->user('seller');

        try {
            $shipping->enable($seller, $logisticsProvider);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', "{$logisticsProvider->business_name} enabled for shipping.");
    }

    public function disable(
        Request $request,
        LogisticsProvider $logisticsProvider,
        SellerShippingProviderService $shipping,
    ): RedirectResponse {
        $seller = $request->user('seller');

        try {
            $shipping->disable($seller, $logisticsProvider);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', "{$logisticsProvider->business_name} disabled.");
    }
}
