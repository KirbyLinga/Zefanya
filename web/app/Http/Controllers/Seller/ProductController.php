<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Http\Requests\Seller\StoreProductRequest;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Shared\Category;
use App\Services\VariantSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

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
                ->with('images')
                ->find((int) old('_product_id'));
        }

        $products = $query->paginate($perPage)->withQueryString();

        return view('Seller.Products.index', [
            'products' => $products,
            'filters' => [
                'q' => $search !== '' ? $search : null,
                'status' => $status,
                'category' => $categoryId,
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

    public function store(StoreProductRequest $request)
    {
        $seller = Auth::guard('seller')->user();
        $data = $request->validated();

        $product = Product::create([
            'seller_id' => $seller->id,
            'category_id' => $seller->line_of_business_id,
            'name' => $data['name'],
            'slug' => Product::generateUniqueSlug($data['name']),
            'description' => $data['description'] ?? null,
            'price' => $data['price'],
            'stock_quantity' => $data['stock_quantity'],
            'low_stock_threshold' => $data['low_stock_threshold'] ?? 5,
            'status' => $data['status'],
        ]);

        $this->storeImages($product, $request);
        $this->syncVariants($product, $request);

        return redirect()
            ->route('seller.products.index')
            ->with('success', "\"{$product->name}\" was created.");
    }

    public function edit(Request $request, Product $product)
    {
        $this->authorizeOwnership($product);
        $product->load(['images', 'variants.optionValues.option', 'options.values']);

        // The products index opens this route through the Edit Product modal.
        if ($request->ajax()) {
            return response()->view('Seller.Products._form', [
                'product' => $product,
                'lockedCategory' => Auth::guard('seller')->user()->lineOfBusiness,
            ]);
        }

        // Direct visits (bookmarks, no-JS) fall back to the products page, whose
        // JS auto-opens the modal for this product (?edit={id}).
        return redirect()->route('seller.products.index', ['edit' => $product->id]);
    }

    public function update(StoreProductRequest $request, Product $product)
    {
        $this->authorizeOwnership($product);
        $data = $request->validated();

        $product->update([
            'category_id' => Auth::guard('seller')->user()->line_of_business_id,
            'name' => $data['name'],
            'slug' => $data['name'] !== $product->name
                ? Product::generateUniqueSlug($data['name'])
                : $product->slug,
            'description' => $data['description'] ?? null,
            'price' => $data['price'],
            'stock_quantity' => $data['stock_quantity'],
            'low_stock_threshold' => $data['low_stock_threshold'] ?? 5,
            'status' => $data['status'],
        ]);

        if ($request->hasFile('images')) {
            $this->storeImages($product, $request);
        }

        $this->syncVariants($product, $request);
        if ($request->hasFile('variant_images')) {
            $this->storeVariantImages($product, $request);
        }

        // Back to the products index (the modal lives there), preserving filters/page.
        return back()
            ->with('success', "\"{$product->name}\" was updated.");
    }

    public function destroy(Request $request, Product $product)
    {
        $this->authorizeOwnership($product);

        $name = $product->name;
        $product->delete();

        $message = "\"{$name}\" was deleted.";

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'message' => $message]);
        }

        return back()->with('success', $message);
    }

    public function toggleStatus(Request $request, Product $product)
    {
        $this->authorizeOwnership($product);

        $validated = $request->validate([
            'status' => ['required', 'in:active,inactive'],
        ]);

        $product->update(['status' => $validated['status']]);

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

    public function deleteImage(Product $product, ProductImage $image)
    {
        $this->authorizeOwnership($product);
        abort_unless($image->product_id === $product->id, 403);

        Storage::disk('public')->delete($image->path);
        $wasPrimary = $image->is_primary;
        $image->delete();

        if ($wasPrimary) {
            $product->images()->first()?->update(['is_primary' => true]);
        }

        return back()->with('success', 'Image removed.');
    }

    private function authorizeOwnership(Product $product): void
    {
        abort_unless($product->seller_id === Auth::guard('seller')->id(), 403);
    }

    /**
     * Apply the Variant Options submission (no-op for legacy single-SKU
     * products). The products.price/stock rollups stay correct either way.
     */
    private function syncVariants(Product $product, Request $request): void
    {
        $payload = $request->boolean('variants_exist')
            ? $request->variantPayload()
            : ['options' => [], 'variants' => []];

        app(VariantSyncService::class)->sync(
            $product,
            $payload['options'],
            $payload['variants'],
            $request->boolean('variants_exist'),
        );
    }

    /**
     * Attach uploaded photos to specific variants (variant_images[{variantId}]).
     * Stored under the same products/{id} folder convention; a product-level
     * image has variant_id = NULL.
     */
    private function storeVariantImages(Product $product, Request $request): void
    {
        $variantIds = $product->variants()->pluck('id')->flip();

        foreach ($request->file('variant_images', []) as $variantId => $files) {
            $variantId = (int) $variantId;

            if (! $variantIds->has($variantId)) {
                continue; // not this product's variant — ignore
            }

            foreach ((array) $files as $file) {
                $path = $file->store('products/'.$product->id, 'public');

                ProductImage::create([
                    'product_id' => $product->id,
                    'variant_id' => $variantId,
                    'path' => $path,
                    'is_primary' => false,
                    'sort_order' => ProductImage::query()->where('product_id', $product->id)->count(),
                ]);
            }
        }
    }

    private function storeImages(Product $product, Request $request): void
    {
        if (! $request->hasFile('images')) {
            return;
        }

        $hasPrimaryAlready = $product->images()->where('is_primary', true)->exists();

        foreach ($request->file('images') as $index => $file) {
            $path = $file->store('products/'.$product->id, 'public');

            ProductImage::create([
                'product_id' => $product->id,
                'path' => $path,
                'is_primary' => ! $hasPrimaryAlready && $index === 0,
                'sort_order' => $product->images()->count() + $index,
            ]);
        }
    }
}
