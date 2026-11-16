<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Catalog\Product;
use App\Models\Seller\StoreCategory;
use App\Services\Seller\StoreCategoryService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StoreCategoryController extends Controller
{
    public function index(Request $request): View
    {
        $seller = $request->user('seller');
        $selected = $request->filled('product')
            ? Product::query()->where('seller_id', $seller->id)->with('storeCategories')->findOrFail($request->integer('product')) : null;
        $categories = StoreCategory::query()->where('seller_id', $seller->id)->with('parent')->withCount('products')->displayOrder()->get();
        $categories = new Collection($categories->whereNull('parent_id')
            ->flatMap(fn ($root) => collect([$root])->concat($categories->where('parent_id', $root->id)))->values()->all());

        return view('Seller.StoreCategories.index', [
            'seller' => $seller,
            'categories' => $categories,
            'products' => Product::query()->where('seller_id', $seller->id)->orderBy('name')->get(['id', 'name']),
            'selectedProduct' => $selected,
        ]);
    }

    public function store(Request $request, StoreCategoryService $categories): RedirectResponse
    {
        $categories->save($request->user('seller'), $request->only(['name', 'parent_id']));

        return back()->with('success', 'Store category created.');
    }

    public function update(Request $request, StoreCategory $storeCategory, StoreCategoryService $categories): RedirectResponse
    {
        $categories->save($request->user('seller'), $request->only(['name', 'parent_id']), $storeCategory);

        return back()->with('success', 'Store category updated.');
    }

    public function assign(Request $request, Product $product, StoreCategoryService $categories): RedirectResponse
    {
        $data = $request->validate(['store_category_ids' => ['sometimes', 'array']]);
        $categories->assign($request->user('seller'), $product, $data['store_category_ids'] ?? []);

        return back()->with('success', 'Product store categories updated.');
    }

    public function reorder(Request $request, StoreCategoryService $categories): RedirectResponse
    {
        $data = $request->validate(['category_ids' => ['required', 'array'], 'parent_id' => ['nullable', 'integer']]);
        $categories->reorder($request->user('seller'), $data['category_ids'], isset($data['parent_id']) ? (int) $data['parent_id'] : null);

        return back()->with('success', 'Category order updated.');
    }

    public function move(Request $request, StoreCategory $storeCategory, StoreCategoryService $categories): RedirectResponse
    {
        $data = $request->validate(['direction' => ['required', 'in:up,down']]);
        $categories->move($request->user('seller'), $storeCategory, $data['direction']);

        return back()->with('success', 'Category order updated.');
    }

    public function visibility(Request $request, StoreCategory $storeCategory, StoreCategoryService $categories): RedirectResponse
    {
        $data = $request->validate(['is_visible' => ['required', 'boolean']]);
        $categories->setVisibility($request->user('seller'), $storeCategory, (bool) $data['is_visible']);

        return back()->with('success', 'Category visibility updated.');
    }

    public function deleteConfirmation(Request $request, StoreCategory $storeCategory, StoreCategoryService $categories): View
    {
        return view('Seller.StoreCategories.delete', $categories->deletionPreview($request->user('seller'), $storeCategory));
    }

    public function destroy(Request $request, StoreCategory $storeCategory, StoreCategoryService $categories): RedirectResponse
    {
        $data = $request->validate(['confirmed' => ['required', 'accepted'], 'revision' => ['required', 'string', 'size:64']]);
        $categories->remove($request->user('seller'), $storeCategory, $data['revision']);

        return to_route('seller.store-categories.index')->with('success', 'Category and subcategories deleted. Products without remaining memberships are Uncategorized.');
    }
}
