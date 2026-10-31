<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Http\Requests\Seller\StoreProductRequest;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductImage;
use App\Models\Seller\StoreCategory;
use App\Models\Shared\Category;
use App\Services\Seller\ProductPublishingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    /** Allowed "Rows:" page sizes for the products listing. */
    private const PER_PAGE_OPTIONS = [10, 25, 50];

    private const DEFAULT_PER_PAGE = 10;

    public function index(Request $request)
    {
        $seller = Auth::guard('seller')->user();
        $sellerId = $seller->id;

        $search = trim((string) $request->input('q', ''));

        $status = $request->input('status');
        if (! in_array($status, ['active', 'draft', 'inactive'], true)) {
            $status = null;
        }

        $categoryId = (int) $request->input('category');
        if ($categoryId <= 0) {
            $categoryId = null;
        }

        $stock = $request->input('stock');
        if ($stock !== 'low') {
            $stock = null;
        }

        $perPage = (int) $request->input('per_page', self::DEFAULT_PER_PAGE);
        if (! in_array($perPage, self::PER_PAGE_OPTIONS, true)) {
            $perPage = self::DEFAULT_PER_PAGE;
        }

        $sort = $request->input('sort', 'newest');
        $sorts = [
            'newest' => fn ($q) => $q->latest(),
            'oldest' => fn ($q) => $q->oldest(),
            'name_asc' => fn ($q) => $q->orderBy('name'),
            'price_asc' => fn ($q) => $q->orderBy('price'),
            'price_desc' => fn ($q) => $q->orderByDesc('price'),
            'stock_asc' => fn ($q) => $q->orderBy('stock_quantity'),
        ];
        if (! array_key_exists($sort, $sorts)) {
            $sort = 'newest';
        }

        $query = Product::forSeller($sellerId)
            ->with(['images', 'category', 'variants'])
            ->withSum('variants as variants_sum_stock_quantity', 'stock_quantity')
            ->when($categoryId, fn ($q) => $q->where('category_id', $categoryId))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($stock === 'low', fn ($q) => $q->whereHas('variants', fn ($variants) => $variants
                ->select('product_id')
                ->groupBy('product_id')
                ->havingRaw('SUM(stock_quantity) <= products.low_stock_threshold')))
            ->when($search !== '', fn ($q) => $q->where('name', 'like', '%'.$search.'%'));

        $sorts[$sort]($query);

        // 'create' only opens the Add Product modal on this page — keep it out of pagination links.
        $openCreateModal = $request->boolean('create');
        $request->query->remove('create');

        // 'edit' only tells the page's JS to auto-open the Edit Product modal for that id.
        $openEditId = (int) $request->input('edit');
        $request->query->remove('edit');

        // A failed update() POST redirects back here: re-render the modal in edit mode
        // for the same product, with old() input and errors.
        $editProduct = null;
        if (old('_modal') === 'product-edit') {
            $editProduct = Product::forSeller($sellerId)
                ->with(['images', 'variants.optionValues.option', 'options.values', 'storeCategories'])
                ->find((int) old('_product_id'));
        }

        $products = $query->paginate($perPage)->withQueryString();

        return view('Seller.Products.index', [
            'products' => $products,
            'filters' => [
                'q' => $search !== '' ? $search : null,
                'status' => $status,
                'category' => $categoryId,
                'stock' => $stock,
            ],
            'sort' => $sort,
            'perPage' => $perPage,
            'perPageOptions' => self::PER_PAGE_OPTIONS,
            // Only the categories this seller actually uses, for the filter select.
            'categories' => Category::query()
                ->whereIn('id', Product::forSeller($sellerId)->whereNotNull('category_id')->distinct()->pluck('category_id'))
                ->orderBy('label')
                ->get(['id', 'label']),
            'newProduct' => new Product(['status' => 'draft', 'low_stock_threshold' => 5]),
            'lockedCategory' => $seller->lineOfBusiness,
            'platformCategories' => Category::query()->orderBy('label')->get(),
            'storeCategories' => StoreCategory::query()->where('seller_id', $sellerId)->orderBy('name')->get(),
            'openCreateModal' => $openCreateModal,
            'editProduct' => $editProduct,
            'openEditId' => $openEditId ?: null,
        ]);
    }

    /**
     * "Add Product" is a modal on the products index, kept for old bookmarks/links.
     */
    public function create()
    {
        return redirect()->route('seller.products.index', ['create' => 1]);
    }

    public function store(StoreProductRequest $request, ProductPublishingService $publishing)
    {
        $product = $publishing->save(new Product, Auth::guard('seller')->user(), $request->validated());

        return redirect()
            ->route('seller.products.index')
            ->with('success', "\"{$product->name}\" was created.");
    }

    public function edit(Request $request, Product $product)
    {
        $this->authorizeOwnership($product);
        $product->load(['images', 'variants.optionValues.option', 'options.values', 'storeCategories']);

        // The products index opens this route through the Edit Product modal.
        if ($request->ajax()) {
            return response()->view('Seller.Products._form', [
                'product' => $product,
                'lockedCategory' => Auth::guard('seller')->user()->lineOfBusiness,
                'platformCategories' => Category::query()->orderBy('label')->get(),
                'storeCategories' => StoreCategory::query()->where('seller_id', $product->seller_id)->orderBy('name')->get(),
            ]);
        }

        // Direct visits (bookmarks, no-JS) fall back to the products page, whose
        // JS auto-opens the modal for this product (?edit={id}).
        return redirect()->route('seller.products.index', ['edit' => $product->id]);
    }

    public function update(StoreProductRequest $request, Product $product, ProductPublishingService $publishing)
    {
        $this->authorizeOwnership($product);
        $this->authorizeNotRestricted($product);
        $publishing->save($product, Auth::guard('seller')->user(), $request->validated());

        return back()->with('success', "\"{$product->name}\" was updated.");
    }

    public function destroy(Request $request, Product $product, ProductPublishingService $publishing)
    {
        $this->authorizeOwnership($product);
        $this->authorizeNotRestricted($product);

        $name = $product->name;
        if (! $publishing->archive($product, Auth::guard('seller')->user())) {
            throw ValidationException::withMessages(['product' => 'This product has unfinished orders and cannot be deleted.']);
        }

        $message = "\"{$name}\" was archived.";

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'message' => $message]);
        }

        return back()->with('success', $message);
    }

    public function toggleStatus(Request $request, Product $product, ProductPublishingService $publishing)
    {
        $this->authorizeOwnership($product);
        $this->authorizeNotRestricted($product);

        $validated = $request->validate([
            'status' => ['required', 'in:active,inactive'],
        ]);

        $product = $publishing->setStatus($product, Auth::guard('seller')->user(), $validated['status']);

        $message = "\"{$product->name}\" was marked as {$product->status}.";

        if ($request->wantsJson()) {
            return response()->json([
                'id' => $product->id,
                'status' => $product->status,
                'label' => ucfirst($product->status),
                'message' => $message,
            ]);
        }

        return back()->with('success', $message);
    }

    public function deleteImage(Product $product, ProductImage $image, ProductPublishingService $publishing)
    {
        $this->authorizeOwnership($product);
        $this->authorizeNotRestricted($product);
        $publishing->removeImage($product, $image);

        return back()->with('success', 'Image removed.');
    }

    public function duplicate(Product $product, ProductPublishingService $publishing)
    {
        $this->authorizeOwnership($product);
        $this->authorizeNotRestricted($product);
        $copy = $publishing->duplicate($product, Auth::guard('seller')->user());

        return to_route('seller.products.index', ['edit' => $copy->id])->with('success', 'Product copied as a draft. Unique SKUs must be assigned to the copy.');
    }

    public function bulk(Request $request, ProductPublishingService $publishing)
    {
        $data = $request->validate([
            'product_ids' => ['required', 'array', 'min:1', 'max:100'],
            'product_ids.*' => ['required', 'integer', 'distinct'],
            'action' => ['required', 'in:activate,inactivate,archive'],
        ]);
        $seller = Auth::guard('seller')->user();
        $products = Product::forSeller($seller->id)->whereIn('id', $data['product_ids'])->get();
        abort_unless($products->count() === count($data['product_ids']), 403);
        $skipped = [];
        $skipMessages = [];
        foreach ($products as $product) {
            if ($product->isRestricted()) {
                $skipped[] = $product->name.' (admin-restricted)';
                $skipMessages[] = $product->name.' (admin-restricted)';

                continue;
            }
            if ($data['action'] === 'archive') {
                if (! $publishing->archive($product, $seller)) {
                    $skipped[] = $product->name;
                    $skipMessages[] = $product->name.' (unfinished orders)';
                }
            } else {
                $publishing->setStatus($product, $seller, $data['action'] === 'activate' ? 'active' : 'inactive');
            }
        }
        $message = 'Product action completed.'.($skipped === [] ? '' : ' Skipped products: '.implode(', ', $skipMessages).'.');

        return $request->expectsJson() ? response()->json(['message' => $message, 'skipped' => $skipped]) : back()->with('success', $message);
    }

    private function authorizeOwnership(Product $product): void
    {
        abort_unless($product->seller_id === Auth::guard('seller')->id(), 403);
    }

    private function authorizeNotRestricted(Product $product): void
    {
        abort_unless(! $product->isRestricted(), 403, 'This product is restricted by an administrator and cannot be modified.');
    }
}
