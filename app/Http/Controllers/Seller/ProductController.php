<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Http\Requests\Seller\StoreProductRequest;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $sellerId = Auth::guard('seller')->id();

        $products = Product::forSeller($sellerId)
            ->with('images')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('q'), fn ($q) => $q->where('name', 'like', '%'.$request->q.'%'))
            ->latest()
            ->paginate(12)
            ->withQueryString();

        return view('Seller.Products.index', [
            'products' => $products,
            'filters' => $request->only(['status', 'q']),
        ]);
    }

    public function create()
    {
        $seller = Auth::guard('seller')->user();

        return view('Seller.Products.create', [
            'lockedCategory' => $seller->lineOfBusiness,
            'product' => new Product(['status' => 'draft', 'low_stock_threshold' => 5]),
        ]);
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

        return redirect()
            ->route('seller.products.index')
            ->with('success', "\"{$product->name}\" was created.");
    }

    public function edit(Product $product)
    {
        $this->authorizeOwnership($product);

        return view('Seller.Products.edit', [
            'product' => $product->load('images'),
            'lockedCategory' => Auth::guard('seller')->user()->lineOfBusiness,
        ]);
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

        return redirect()
            ->route('seller.products.index')
            ->with('success', "\"{$product->name}\" was updated.");
    }

    public function destroy(Product $product)
    {
        $this->authorizeOwnership($product);

        $name = $product->name;
        $product->delete();

        return back()->with('success', "\"{$name}\" was deleted.");
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

