<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin\AdminProductRestriction;
use App\Models\Catalog\Product;
use App\Models\Seller\Seller;
use App\Services\Seller\ProductApprovalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function index(Request $request): View
    {
        $search = mb_substr(trim((string) $request->query('search', '')), 0, 100);
        $sellerId = (int) $request->query('seller', 0);
        $status = $request->query('status', '');
        $restricted = $request->query('restricted', '');

        $validStatuses = ['', 'draft', 'pending', 'rejected', 'active', 'inactive'];
        if (! in_array($status, $validStatuses, true)) {
            $status = '';
        }

        $query = Product::query()
            ->with(['seller', 'category'])
            ->orderByDesc('updated_at');

        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function ($q) use ($like): void {
                $q->where('name', 'like', $like)
                    ->orWhere('description', 'like', $like)
                    ->orWhere('slug', 'like', $like);
            });
        }

        if ($sellerId > 0) {
            $query->where('seller_id', $sellerId);
        }

        if ($status !== '') {
            $query->where('status', $status);
        }

        if ($restricted === '1') {
            $query->whereNotNull('restriction_reason');
        } elseif ($restricted === '0') {
            $query->whereNull('restriction_reason');
        }

        $products = $query->paginate(25)->withQueryString();

        $sellers = Seller::query()->orderBy('business_name')->get(['id', 'business_name']);

        return view('Admin.Products.index', [
            'products' => $products,
            'sellers' => $sellers,
            'search' => $search,
            'sellerId' => $sellerId,
            'status' => $status,
            'restricted' => $restricted,
        ]);
    }

    public function show(Product $product): View
    {
        $with = ['seller', 'category', 'images', 'variants.optionValues'];
        if (Schema::hasTable('product_revisions')) {
            $with[] = 'latestRevision';
            $with[] = 'approvalEvents.admin';
        }
        $product->load($with);

        return view('Admin.Products.show', [
            'product' => $product,
        ]);
    }

    public function restrict(Request $request, Product $product)
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $admin = Auth::guard('admin')->user();

        DB::transaction(function () use ($product, $admin, $data): void {
            $previousStatus = $product->status;

            $product->forceFill([
                'restriction_reason' => $data['reason'],
                'restricted_at' => now(),
                'restricted_by' => $admin->id,
            ])->save();

            (new AdminProductRestriction)->forceFill([
                'admin_id' => $admin->id,
                'admin_name' => $admin->name,
                'admin_email' => $admin->email,
                'product_id' => $product->id,
                'product_name' => $product->name,
                'seller_name' => $product->seller?->business_name ?? 'Unknown',
                'reason' => $data['reason'],
                'previous_status' => $previousStatus,
                'new_status' => $product->status,
                'created_at' => now(),
            ])->save();
        });

        return back()->with('success', '"'.$product->name.'" has been restricted.');
    }

    public function unrestrict(Request $request, Product $product)
    {
        $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $admin = Auth::guard('admin')->user();

        if (! $product->isRestricted()) {
            return back()->with('error', 'This product is not restricted.');
        }

        DB::transaction(function () use ($product, $admin, $request): void {
            $previousStatus = $product->status;

            $product->forceFill([
                'restriction_reason' => null,
                'restricted_at' => null,
                'restricted_by' => null,
            ])->save();

            (new AdminProductRestriction)->forceFill([
                'admin_id' => $admin->id,
                'admin_name' => $admin->name,
                'admin_email' => $admin->email,
                'product_id' => $product->id,
                'product_name' => $product->name,
                'seller_name' => $product->seller?->business_name ?? 'Unknown',
                'reason' => $request->input('reason') ?? 'Unrestricted by admin.',
                'previous_status' => $previousStatus,
                'new_status' => $product->status,
                'created_at' => now(),
            ])->save();
        });

        return back()->with('success', '"'.$product->name.'" is no longer restricted.');
    }

    public function pending(): View
    {
        $products = Schema::hasTable('product_revisions')
            ? Product::query()
                ->with(['seller', 'category', 'images', 'variants.optionValues', 'latestRevision'])
                ->where(function ($query): void {
                    $query->where('status', 'pending')
                        ->orWhereHas('revisions', fn ($revision) => $revision->where('status', 'pending'));
                })
                ->orderByDesc('updated_at')
                ->paginate(25)
            : Product::query()->where('status', 'pending')->with(['seller', 'category', 'images', 'variants.optionValues'])->orderByDesc('updated_at')->paginate(25);

        return view('Admin.Products.pending', ['products' => $products]);
    }

    public function approve(Product $product)
    {
        $product = app(ProductApprovalService::class)
            ->approve($product, Auth::guard('admin')->user());

        return back()->with('success', '"'.$product->name.'" is approved.');
    }

    public function reject(Request $request, Product $product)
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);
        $product = app(ProductApprovalService::class)
            ->reject($product, Auth::guard('admin')->user(), $data['reason']);

        return back()->with('success', '"'.$product->name.'" was rejected. The seller can edit and resubmit.');
    }
}
