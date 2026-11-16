<?php

namespace Database\Seeders;

use App\Models\Buyer\Buyer;
use App\Models\Buyer\CartItem;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\Seller\Seller;
use Illuminate\Database\Seeder;

/**
 * "Ensure" pass over the Seller's catalog and the Buyer's cart so a parcel has
 * a real weight and the buyer can reach checkout.
 *
 * Parcel weight matters: CourierAssignmentService rejects a courier whose
 * max_weight_grams is below parcelWeightGrams(). A product (and its variant)
 * at 0 g makes that check meaningless, so known dev products get an explicit
 * weight; anything else falls back to a conservative 500 g.
 *
 * Never invents products, never touches stock, prices or status.
 */
class FlowCatalogSeeder extends Seeder
{
    /**
     * Explicit parcel weights for the dev catalog, in grams.
     * Variants with a NULL weight inherit the product's value.
     *
     * @var array<string, array{grams: int, dimensions: array{length: int, width: int, height: int}}>
     */
    private const PARCEL = [
        'tulfone' => ['grams' => 900, 'dimensions' => ['length' => 20, 'width' => 15, 'height' => 10]],
        'asus-laptop' => ['grams' => 2400, 'dimensions' => ['length' => 40, 'width' => 30, 'height' => 6]],
        'womens-casual-top' => ['grams' => 300, 'dimensions' => ['length' => 30, 'width' => 20, 'height' => 5]],
        'womens-blouse' => ['grams' => 300, 'dimensions' => ['length' => 30, 'width' => 20, 'height' => 4]],
        'womens-long-sleeve-top' => ['grams' => 400, 'dimensions' => ['length' => 30, 'width' => 22, 'height' => 5]],
        'womens-crop-top' => ['grams' => 300, 'dimensions' => ['length' => 26, 'width' => 20, 'height' => 4]],
        'womens-elegant-top' => ['grams' => 350, 'dimensions' => ['length' => 30, 'width' => 20, 'height' => 4]],
    ];

    private const FALLBACK_GRAMS = 500;

    /** @var array{created: list<string>, updated: list<string>, warnings: list<string>} */
    private array $report = ['created' => [], 'updated' => [], 'warnings' => []];

    /**
     * @return array{created: list<string>, updated: list<string>, warnings: list<string>}
     */
    public function seed(Buyer $buyer, Seller $seller): array
    {
        $this->report = ['created' => [], 'updated' => [], 'warnings' => []];

        $this->shop($seller);
        $this->weights($seller);
        $this->deliveryAddress($buyer);
        $this->cart($buyer, $seller);

        return $this->report;
    }

    private function shop(Seller $seller): void
    {
        if (! $seller->isApproved() || $seller->archived_at !== null) {
            $this->report['warnings'][] = 'Seller #'.$seller->id.' is not an approved, non-archived shop.';

            return;
        }

        $missing = array_keys(array_filter([
            'street' => blank($seller->street) && blank($seller->address_detail),
            'barangay_code' => blank($seller->barangay_code),
            'municipality_code' => blank($seller->municipality_code),
            'province_code' => blank($seller->province_code),
        ]));

        if ($missing !== []) {
            $this->report['warnings'][] = 'Seller pickup address is incomplete ('.implode(', ', $missing).') — checkout/pickup coverage will fail.';
        }
    }

    private function weights(Seller $seller): void
    {
        $products = Product::query()
            ->where('seller_id', $seller->id)
            ->where('status', 'active')
            ->orderBy('id')
            ->get();

        if ($products->isEmpty()) {
            $this->report['warnings'][] = 'Seller #'.$seller->id.' has no active product — a buyer cannot build a parcel.';

            return;
        }

        foreach ($products as $product) {
            if ((int) $product->weight_grams > 0) {
                continue;
            }

            $parcel = self::PARCEL[$product->slug] ?? null;

            $grams = $parcel['grams'] ?? self::FALLBACK_GRAMS;

            $product->forceFill([
                'weight_grams' => $grams,
                'dimensions' => $parcel['dimensions'] ?? null,
            ])->save();

            // A stored 0 on a variant overrides the product weight when the
            // parcel is rated, so weightless variants inherit the real value.
            $product->variants()
                ->where(fn ($query) => $query->whereNull('weight_grams')->orWhere('weight_grams', '<=', 0))
                ->update(['weight_grams' => $grams]);

            $this->report['updated'][] = 'Seller: set parcel weight on '.$product->name
                .' to '.$grams.'g';
        }

        $inStock = $products->filter(fn (Product $product): bool => (int) $product->stock_quantity > 0)->count();

        if ($inStock === 0) {
            $this->report['warnings'][] = 'None of Seller #'.$seller->id.'\'s active products has stock.';
        }
    }

    private function deliveryAddress(Buyer $buyer): void
    {
        $missing = array_keys(array_filter([
            'barangay_code' => blank($buyer->barangay_code),
            'municipality_code' => blank($buyer->municipality_code),
            'province_code' => blank($buyer->province_code),
        ]));

        if ($missing !== []) {
            $this->report['warnings'][] = 'Buyer delivery address is incomplete ('.implode(', ', $missing).') — no carrier can be quoted.';
        }
    }

    private function cart(Buyer $buyer, Seller $seller): void
    {
        $product = Product::query()
            ->where('seller_id', $seller->id)
            ->where('status', 'active')
            ->where('stock_quantity', '>', 0)
            ->orderByRaw("CASE WHEN slug = 'tulfone' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->first();

        if ($product === null) {
            return;
        }

        $variant = ProductVariant::query()
            ->where('product_id', $product->id)
            ->where('is_available', true)
            ->where('stock_quantity', '>', 0)
            ->orderBy('id')
            ->first();

        if ($variant === null) {
            $this->report['warnings'][] = 'Product '.$product->name.' has no purchasable variant — cart line skipped.';

            return;
        }

        if (CartItem::query()->where('buyer_id', $buyer->id)->exists()) {
            // A real cart from the user wins; never overwrite their lines.
            return;
        }

        $line = CartItem::query()->create([
            'buyer_id' => $buyer->id,
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'quantity' => 1,
        ]);

        $this->report['created'][] = 'Buyer: cart line #'.$line->id.' was added ('.$product->name.' x1)';
    }
}
