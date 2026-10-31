<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Http\Requests\Seller\SellerVoucherRequest;
use App\Models\Catalog\Product;
use App\Models\Shared\Voucher;
use App\Services\Seller\SellerVoucherService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class VoucherController extends Controller
{
    public function index(Request $request): View
    {
        $vouchers = Voucher::query()
            ->where('seller_id', $request->user('seller')->id)
            ->withCount('products')
            ->latest('id')
            ->paginate(20);

        return view('Seller.Vouchers.index', compact('vouchers'));
    }

    public function create(Request $request): View
    {
        return view('Seller.Vouchers.create', [
            'products' => $this->pickableProducts($request),
        ]);
    }

    public function store(SellerVoucherRequest $request, SellerVoucherService $vouchers): RedirectResponse
    {
        $vouchers->create($request->user('seller'), $request->voucherAttributes(), $request->productIds());

        return to_route('seller.vouchers.index')->with('success', 'Your voucher has been created.');
    }

    public function edit(Request $request, Voucher $voucher): View|RedirectResponse
    {
        $this->authorizeForUser($request->user('seller'), 'manage', $voucher);

        if ($voucher->hasEnded()) {
            return to_route('seller.vouchers.index')->withErrors(['voucher' => 'This voucher has ended and can no longer be edited.']);
        }

        return view('Seller.Vouchers.edit', [
            'voucher' => $voucher->load('products:id'),
            'products' => $this->pickableProducts($request),
        ]);
    }

    public function update(SellerVoucherRequest $request, Voucher $voucher, SellerVoucherService $vouchers): RedirectResponse
    {
        $vouchers->update($voucher, $request->voucherAttributes(), $request->productIds());

        return to_route('seller.vouchers.index')->with('success', 'Your voucher has been updated.');
    }

    public function end(Request $request, Voucher $voucher, SellerVoucherService $vouchers): RedirectResponse
    {
        $this->authorizeForUser($request->user('seller'), 'manage', $voucher);

        $vouchers->end($voucher);

        return to_route('seller.vouchers.index')->with('success', 'Your voucher has ended and was removed from wallets where it was not used.');
    }

    public function destroy(Request $request, Voucher $voucher, SellerVoucherService $vouchers): RedirectResponse
    {
        $this->authorizeForUser($request->user('seller'), 'manage', $voucher);

        $vouchers->delete($voucher);

        return to_route('seller.vouchers.index')->with('success', 'Your voucher has been deleted.');
    }

    private function pickableProducts(Request $request)
    {
        return Product::query()
            ->forSeller($request->user('seller')->id)
            ->orderBy('name')
            ->get(['id', 'name']);
    }
}
