<?php

namespace App\Http\Controllers\Api\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Resources\PromotionResource;
use App\Models\Promotion;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * GET /api/promotions
 *
 * The home banner campaign slides, in display order.
 *
 * ## Public, like the catalogue
 *
 * A promotion is marketing content: the same slides show to everyone,
 * signed in or not. Requiring a token would prevent the carousel from
 * loading before sign-in, which is exactly the moment it is most
 * useful.
 *
 * ## What the client does with the data
 *
 * The app maps each row to a `PromoSlide` via `PromoSlide.fromJson()` in
 * `remote_catalog.dart`. Icon names are resolved through a lookup table
 * on the app side; unknown names fall back to a neutral icon rather than
 * crashing, so a future CMS typo does not break the carousel.
 */
class PromotionController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return PromotionResource::collection(Promotion::active()->get());
    }
}
