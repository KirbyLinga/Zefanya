<?php

namespace Database\Seeders\Demo;

use App\Enums\StoreSaleType;
use App\Models\Catalog\Product;
use App\Models\Seller\Seller;
use App\Models\Seller\StoreSale;
use App\Services\Seller\ProductPublishingService;
use App\Services\Seller\StoreSaleService;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Ramsey\Uuid\Uuid;

/**
 * Lists each demo Seller's products with real, licensed photographs.
 *
 * Every product goes through ProductPublishingService::save(), exactly as a
 * Seller's own upload would, so the stock ledger, default variants and
 * publish rules apply. A fixed creation key per Seller and product means a
 * second run adds nothing. Photos are bundled in database/seeders/data/demo
 * with their source, author and licence in catalog.json.
 *
 * Only inventory and a few percentage sales are seeded. No orders, payments,
 * reviews or any other transaction is created.
 */
class DemoCatalogSeeder extends Seeder
{
    private const KEY_NAMESPACE = '6ba7b811-9dad-11d1-80b4-00c04fd430c8';

    /** Seller position within an area => price factor, so the same item is not priced identically everywhere. */
    private const PRICE_FACTOR = [1.0, 1.05, 0.97, 1.02];

    /** Sales run on the first product of each shop. */
    private const SALE_PERCENT = [10, 15, 20, 12];

    /** @var array{products: int, sales: int} */
    public array $created = ['products' => 0, 'sales' => 0];

    public function run(ProductPublishingService $publishing, StoreSaleService $sales): void
    {
        $directory = database_path('seeders/data/demo');
        $catalog = collect(json_decode((string) file_get_contents($directory.'/catalog.json'), true, flags: JSON_THROW_ON_ERROR)['themes'])->keyBy('key');

        foreach (json_decode((string) file_get_contents($directory.'/shops.json'), true, flags: JSON_THROW_ON_ERROR) as $shop) {
            $seller = Seller::query()->where('email', DemoNetwork::email($shop['handle']))->first();
            if ($seller === null) {
                continue;
            }

            $theme = $catalog[$shop['theme']];
            $position = (int) substr($shop['handle'], -2) - 1;
            $categoryId = DemoNetwork::categoryId($theme['category']);

            foreach ($theme['products'] as $index => $item) {
                $key = Uuid::uuid5(self::KEY_NAMESPACE, $shop['handle'].'/'.$item['key'])->toString();
                $existing = Product::withTrashed()->where('seller_id', $seller->id)->where('creation_key', $key)->first();

                $product = $existing ?? $this->publish($publishing, $seller, $shop['handle'], $item, $key, $categoryId, $position, $directory);

                if ($index === 0) {
                    $this->sale($sales, $seller, $product, self::SALE_PERCENT[$position % count(self::SALE_PERCENT)]);
                }
            }
        }
    }

    /** @param  array<string, mixed>  $item */
    private function publish(ProductPublishingService $publishing, Seller $seller, string $handle, array $item, string $key, ?int $categoryId, int $position, string $directory): Product
    {
        $price = (int) round($item['price'] * self::PRICE_FACTOR[$position % count(self::PRICE_FACTOR)]);
        $data = [
            'creation_key' => $key,
            'category_id' => $categoryId,
            'name' => $item['name'],
            'description' => $item['description'],
            'brand' => $item['brand'],
            'condition' => $item['condition'],
            'price' => $price,
            'weight_grams' => $item['weight_grams'],
            'dimensions' => $item['dimensions'],
            'status' => 'active',
            'images' => array_map(
                fn (array $image): UploadedFile => new UploadedFile($directory.'/images/'.$image['file'], basename($image['file']), 'image/jpeg', null, true),
                $item['images'],
            ),
        ];

        if ($item['options'] === null) {
            $data += ['stock_quantity' => $item['stock']];
        } else {
            $values = $item['options']['values'];
            $each = intdiv($item['stock'], count($values));
            $data += [
                'variants_exist' => true,
                'options' => [$item['options']],
                'variants' => array_map(fn (string $value): array => [
                    'values' => [$value],
                    'sku' => strtoupper($handle.'-'.$item['key'].'-'.$value),
                    'price' => $price,
                    'weight_grams' => $item['weight_grams'],
                    'stock_quantity' => $each,
                ], $values),
            ];
        }

        $this->created['products']++;

        return $publishing->save($seller->products()->make(), $seller, $data);
    }

    private function sale(StoreSaleService $sales, Seller $seller, Product $product, int $percent): void
    {
        if (StoreSale::query()->where('product_id', $product->id)->exists()) {
            return;
        }

        $sales->create($seller, [
            'product_id' => $product->id,
            'variant_id' => null,
            'type' => StoreSaleType::Percent,
            'value' => $percent,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(90),
        ]);
        $this->created['sales']++;
    }
}
