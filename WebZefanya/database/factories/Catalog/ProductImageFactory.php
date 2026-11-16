<?php

namespace Database\Factories\Catalog;

use App\Models\Catalog\Product;
use App\Models\Catalog\ProductImage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Writes REAL image files, not just rows.
 *
 * Seeded images have to live where real seller uploads land, so this factory
 * copies one of the bundled placeholder JPEGs (database/seeders/placeholders)
 * onto disk "public" as products/{product_id}/{40-char}.jpg — exactly the shape
 * ProductController::storeImages() produces with
 * `$file->store('products/'.$product->id, 'public')`. No GD/Imagick and no
 * external URLs, so seeding works fully offline.
 *
 * @extends Factory<ProductImage>
 */
class ProductImageFactory extends Factory
{
    /**
     * Sentinel written by definition() and replaced with a real path once the
     * row exists (`product_images.path` is NOT NULL, so it cannot stay null).
     */
    public const UNSET_PATH = '';

    /** Number of bundled placeholders in database/seeders/placeholders. */
    private const PLACEHOLDER_COUNT = 12;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'path' => self::UNSET_PATH,
            'is_primary' => true,
            'sort_order' => 0,
        ];
    }

    /**
     * A bare ProductImage::factory()->create() still ends up with a real file:
     * the row is inserted, then its path is filled from the placeholder pool.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (ProductImage $image): void {
            if ($image->path !== self::UNSET_PATH) {
                return; // the caller supplied a real path already
            }

            $image->forceFill([
                'path' => static::writePlaceholder((int) $image->product_id, (int) $image->sort_order),
            ])->save();
        });
    }

    /**
     * The Nth image of an existing product: one INSERT, one file, following the
     * app's conventions (first image is primary, sort_order is the image index).
     */
    public function forProduct(Product $product, int $index = 0): static
    {
        return $this->state(fn (): array => [
            'product_id' => $product->id,
            'path' => static::writePlaceholder($product->id, $index),
            'is_primary' => $index === 0,
            'sort_order' => $index,
        ]);
    }

    /**
     * Copy one bundled placeholder onto the public disk and return the relative
     * path to store in product_images.path.
     */
    public static function writePlaceholder(int $productId, int $variant = 0): string
    {
        $source = database_path('seeders/placeholders/'.static::placeholderFilename($productId, $variant));

        if (! is_file($source)) {
            throw new RuntimeException("Product placeholder image is missing: {$source}");
        }

        $path = 'products/'.$productId.'/'.Str::random(40).'.jpg';

        Storage::disk('public')->put($path, file_get_contents($source));

        return $path;
    }

    /**
     * Deterministic but varied: each extra image of a product gets a different
     * placeholder, and neighbouring products start on different ones.
     */
    private static function placeholderFilename(int $productId, int $variant): string
    {
        $number = (($productId * 5 + $variant) % self::PLACEHOLDER_COUNT) + 1;

        return sprintf('placeholder-%02d.jpg', $number);
    }
}
