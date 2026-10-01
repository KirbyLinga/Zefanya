<?php

namespace App\Http\Controllers\Api\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Models\Shared\Category;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * `GET /api/categories` — the chips the app's category row renders.
 *
 * The list is curated by migration (`create_categories_table` inserts the
 * fourteen rows and says not to build a CRUD for them), so it is ordered by id,
 * which is the curation's own order. Ordering by label would be tidier
 * alphabetically and would throw away the one thing the file says matters.
 *
 * The app's own list is `['All', 'Fashion', 'Home & Living', 'Beauty']` — four
 * names, three of which do not exist here. The app prepends its `'All'` sentinel
 * itself (it is a UI affordance, not a row) and renders whatever this returns, so
 * a live build shows the real fourteen rather than the mock's four. That
 * difference is the answer to OPEN QUESTIONS §0.3, not a bug to paper over.
 */
class CategoryController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return CategoryResource::collection(
            Category::query()->orderBy('id')->get(),
        );
    }
}
