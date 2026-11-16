<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Catalog\Product;
use App\Models\Seller\StoreSale;
use App\Models\Shared\Voucher;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** One page for the seller's store sales and shop vouchers. Create, edit and delete stay on their own controllers. */
class DiscountController extends Controller
{
    public function index(Request $request): View
    {
        return $this->listing($request);
    }

    public function createVoucher(Request $request): View
    {
        return $this->listing($request, openCreateVoucher: true);
    }

    private function listing(Request $request, bool $openCreateVoucher = false): View
    {
        $sellerId = $request->user('seller')->id;

        return view($openCreateVoucher ? 'Seller.Discounts.create' : 'Seller.Discounts.index', [
            'sales' => StoreSale::query()
                ->where('seller_id', $sellerId)
                ->with(['product', 'variant.optionValues'])
                ->latest('starts_at')
                ->latest('id')
                ->paginate(20, ['*'], 'sales_page'),
            'vouchers' => Voucher::query()
                ->where('seller_id', $sellerId)
                ->withCount('products')
                ->latest('id')
                ->paginate(20, ['*'], 'vouchers_page'),
            'products' => Product::query()
                ->forSeller($sellerId)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->load('images:id,product_id,path,thumbnail_path,is_primary,sort_order'),
            'openCreateVoucher' => $openCreateVoucher,
        ]);
    }
}
