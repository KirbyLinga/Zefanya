<?php

namespace App\Http\Controllers\Admin;

use App\Enums\VoucherScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminVoucherRequest;
use App\Models\Catalog\Product;
use App\Models\Shared\Voucher;
use App\Services\Admin\AdminVoucherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class VoucherController extends Controller
{
    public function index(): View
    {
        return $this->listing();
    }

    public function create(): View
    {
        return $this->listing(openCreateVoucher: true);
    }

    private function listing(bool $openCreateVoucher = false, ?Voucher $editVoucher = null): View
    {
        $vouchers = Voucher::query()
            ->whereIn('scope', [VoucherScope::Platform->value, VoucherScope::Shipping->value])
            ->withCount('products')
            ->latest('id')
            ->paginate(20);

        return view($editVoucher ? 'Admin.Vouchers.edit' : ($openCreateVoucher ? 'Admin.Vouchers.create' : 'Admin.Vouchers.index'), [
            'vouchers' => $vouchers,
            'products' => $this->products()->load('images:id,product_id,path,thumbnail_path,is_primary,sort_order'),
            'openCreateVoucher' => $openCreateVoucher,
            'editVoucher' => $editVoucher,
        ]);
    }

    public function codeAvailability(Request $request): JsonResponse
    {
        if (is_string($request->input('code'))) {
            $request->merge(['code' => strtoupper(trim($request->input('code')))]);
        }

        $validated = $request->validate([
            'code' => ['required', 'string', 'regex:/^[A-Z0-9]{4,12}$/'],
        ]);

        return response()->json([
            'available' => ! Voucher::query()->where('code', $validated['code'])->exists(),
        ])->header('Cache-Control', 'no-store');
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

        return $this->listing(editVoucher: $voucher->load('products:id'));
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
