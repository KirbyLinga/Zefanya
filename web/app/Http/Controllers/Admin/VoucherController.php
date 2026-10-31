<?php

namespace App\Http\Controllers\Admin;

use App\Enums\VoucherScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminVoucherRequest;
use App\Models\Catalog\Product;
use App\Models\Shared\Voucher;
use App\Services\Admin\AdminVoucherService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class VoucherController extends Controller
{
    public function index(): View
    {
        $vouchers = Voucher::query()
            ->whereIn('scope', [VoucherScope::Platform->value, VoucherScope::Shipping->value])
            ->withCount('products')
            ->latest('id')
            ->paginate(20);

        return view('Admin.Vouchers.index', compact('vouchers'));
    }

    public function create(): View
    {
        return view('Admin.Vouchers.create', [
            'products' => $this->products(),
        ]);
    }

    public function store(AdminVoucherRequest $request, AdminVoucherService $vouchers): RedirectResponse
    {
        $vouchers->create($request->voucherAttributes(), $request->productIds());

        return to_route('admin.vouchers.index')->with('success', 'The voucher has been created.');
    }

    public function edit(Request $request, Voucher $voucher): View|RedirectResponse
    {
        $this->authorizeForUser($request->user('admin'), 'administer', $voucher);

        if ($voucher->hasEnded()) {
            return to_route('admin.vouchers.index')->withErrors(['voucher' => 'This voucher has ended and can no longer be edited.']);
        }

        return view('Admin.Vouchers.edit', [
            'voucher' => $voucher->load('products:id'),
            'products' => $this->products(),
        ]);
    }

    public function update(AdminVoucherRequest $request, Voucher $voucher, AdminVoucherService $vouchers): RedirectResponse
    {
        $vouchers->update($voucher, $request->voucherAttributes(), $request->productIds());

        return to_route('admin.vouchers.index')->with('success', 'The voucher has been updated.');
    }

    public function end(Request $request, Voucher $voucher, AdminVoucherService $vouchers): RedirectResponse
    {
        $this->authorizeForUser($request->user('admin'), 'administer', $voucher);

        $vouchers->end($voucher);

        return to_route('admin.vouchers.index')->with('success', 'The voucher has ended and was removed from wallets where it was not used.');
    }

    public function destroy(Request $request, Voucher $voucher, AdminVoucherService $vouchers): RedirectResponse
    {
        $this->authorizeForUser($request->user('admin'), 'administer', $voucher);

        $vouchers->delete($voucher);

        return to_route('admin.vouchers.index')->with('success', 'The voucher has been deleted.');
    }

    private function products()
    {
        return Product::query()->orderBy('name')->get(['id', 'name']);
    }
}
