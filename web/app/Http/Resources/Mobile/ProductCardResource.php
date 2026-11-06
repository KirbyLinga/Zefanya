<?php

namespace App\Http\Resources\Mobile;

use App\Models\Catalog\Product;
use App\Services\Shared\ResolvedPrice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A product card for the Buyer mobile catalog.
 *
 * Prices come from PriceResolver (the same resolver the web cards use) and
 * are sent in centavos; the app displays them and never computes a price.
 *
 * @mixin Product
 */
class ProductCardResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Product $product */
        $product = $this->resource;

        return [
            'id' => $product->id,
            'name' => $product->name,
            'category' => $product->category === null ? null : ['id' => $product->category->id, 'label' => $product->category->label],
            'seller' => $product->seller === null ? null : ['id' => $product->seller->id, 'name' => $product->seller->business_name],
            'image_url' => self::absoluteUrl($product->catalogImageUrl(requireExistingFile: false, thumbnail: true)),
            'price' => self::price($product->buyerPrice()),
            'rating' => $product->reviews_avg_rating !== null ? round((float) $product->reviews_avg_rating, 1) : null,
            'review_count' => (int) ($product->reviews_count ?? 0),
            'sold' => (int) ($product->sold_units ?? 0),
            'in_stock' => (int) ($product->catalog_stock_quantity ?? 0) > 0,
            'stock' => (int) ($product->catalog_stock_quantity ?? 0),
        ];
    }

    /**
     * The web pages use same-origin paths (`/storage/...`), which a browser
     * resolves against the page. A mobile app has no page, so API image URLs
     * are made absolute against the host the request arrived on.
     */
    public static function absoluteUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        return preg_match('#^https?://#i', $url) === 1 ? $url : url($url);
    }

    /** @return array<string, mixed>|null */
    public static function price(?ResolvedPrice $price): ?array
    {
        if ($price === null) {
            return null;
        }

        return [
            'min_minor' => $price->finalMinCentavos,
            'max_minor' => $price->finalMaxCentavos,
            'regular_min_minor' => $price->regularMinCentavos,
            'regular_max_minor' => $price->regularMaxCentavos,
            'formatted' => $price->formatted(),
            'formatted_regular' => $price->formattedRegular(),
            'badge' => $price->badge(),
            'sale_id' => $price->saleId,
        ];
    }
}
