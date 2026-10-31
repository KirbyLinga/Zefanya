<?php

namespace App\Http\Controllers\Seller;

use App\Enums\StoreSaleStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Seller\StoreSaleRequest;
use App\Models\Catalog\Product;
use App\Models\Seller\StoreSale;
use App\Services\Seller\StoreSaleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StoreSaleController extends Controller
{
    public function index(Request $request): View
    {
        $sales = StoreSale::query()
            ->where('seller_id', $request->user('seller')->id)
            ->with(['product', 'variant.optionValues'])
            ->latest('starts_at')
            ->latest('id')
            ->paginate(20);

        return view('Seller.StoreSales.index', compact('sales'));
    }

    public function create(Request $request): View
    {
        return view('Seller.StoreSales.create', [
            'products' => $this->pickableProducts($request),
        ]);
    }

    public function store(StoreSaleRequest $request, StoreSaleService $sales): RedirectResponse
    {
        $sales->create($request->user('seller'), $request->saleAttributes());

        return to_route('seller.store-sales.index')->with('success', 'Your sale has been created.');
    }

    public function edit(Request $request, StoreSale $storeSale): View|RedirectResponse
    {
        $this->authorizeForUser($request->user('seller'), 'manage', $storeSale);

        if ($storeSale->status() === StoreSaleStatus::Ended) {
            return to_route('seller.store-sales.index')->withErrors(['sale' => 'This sale has ended and can no longer be edited.']);
        }

        return view('Seller.StoreSales.edit', [
            'sale' => $storeSale->load(['product', 'variant.optionValues']),
            'products' => $this->pickableProducts($request),
        ]);
    }

    public function update(StoreSaleRequest $request, StoreSale $storeSale, StoreSaleService $sales): RedirectResponse
    {
        $sales->update($storeSale, $request->saleAttributes());

        return to_route('seller.store-sales.index')->with('success', 'Your sale has been updated.');
    }

    public function end(Request $request, StoreSale $storeSale, StoreSaleService $sales): RedirectResponse
    {
        $this->authorizeForUser($request->user('seller'), 'manage', $storeSale);

        $sales->end($storeSale);

        return to_route('seller.store-sales.index')->with('success', 'Your sale has ended.');
    }

    public function destroy(Request $request, StoreSale $storeSale, StoreSaleService $sales): RedirectResponse
    {
        $this->authorizeForUser($request->user('seller'), 'manage', $storeSale);

        $sales->delete($storeSale);

        return to_route('seller.store-sales.index')->with('success', 'Your sale has been deleted.');
    }

    private function pickableProducts(Request $request)
    {
        return Product::query()
            ->forSeller($request->user('seller')->id)
            ->with('variants.optionValues')
            ->orderBy('name')
            ->get();
    }
}
