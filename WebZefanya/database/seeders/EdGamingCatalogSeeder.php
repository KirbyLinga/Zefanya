<?php

namespace Database\Seeders;

use App\Models\Seller\Seller;
use App\Models\Shared\Category;
use App\Services\Seller\ProductPublishingService;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Ramsey\Uuid\Uuid;

/**
 * Lists the EdGaming store's catalog with real product photos.
 *
 * Saves through ProductPublishingService, as a seller's upload would, so the
 * stock ledger, default variants and publish rules all apply. Each product has
 * a fixed creation key, so running this again never creates a duplicate.
 *
 * Photos and their licence credits live in database/seeders/data/edgaming.
 */
class EdGamingCatalogSeeder extends Seeder
{
    private const BUSINESS_NAME = 'EdGaming';

    private const CATEGORY = 'Electronics and Gadgets';

    private const KEY_NAMESPACE = '6ba7b811-9dad-11d1-80b4-00c04fd430c8';

    public function run(ProductPublishingService $publishing): void
    {
        $seller = Seller::query()->where('business_name', self::BUSINESS_NAME)->firstOrFail();
        $categoryId = Category::query()->where('label', self::CATEGORY)->value('id');
        $directory = database_path('seeders/data/edgaming');
        $products = json_decode(file_get_contents($directory.'/products.json'), true, flags: JSON_THROW_ON_ERROR);

        foreach ($products as $product) {
            $publishing->save($seller->products()->make(), $seller, [
                'creation_key' => Uuid::uuid5(self::KEY_NAMESPACE, $product['name'])->toString(),
                'category_id' => $categoryId,
                'name' => $product['name'],
                'description' => $product['description'],
                'brand' => $product['brand'],
                'condition' => $product['condition'],
                'price' => $product['price'],
                'stock_quantity' => $product['stock'],
                'weight_grams' => $product['weight_grams'],
                'dimensions' => $product['dimensions'],
                'status' => 'active',
                'images' => array_map(
                    fn (array $image): UploadedFile => new UploadedFile($directory.'/'.$image['file'], $image['file'], 'image/jpeg', null, true),
                    $product['images'],
                ),
            ]);
        }

        $this->command?->info(count($products).' EdGaming products are listed.');
    }
}
