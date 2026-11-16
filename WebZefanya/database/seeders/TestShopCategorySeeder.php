<?php

namespace Database\Seeders;

use App\Models\Catalog\Product;
use App\Models\Seller\Seller;
use App\Models\Shared\Category;
use Illuminate\Database\Seeder;

/**
 * Gives the four test shops (seller1..seller4@zefanya.test) different lines of
 * business. They were created with SellerFactory, whose default is the first
 * category, so all four were "Books and Media".
 *
 * New products are locked to the store's line of business, so this decides the
 * category of everything each shop lists from now on. Existing products keep
 * their own category, except listed misfiled ones, which are moved.
 *
 * Touches only these four accounts and the listed product slugs. Creates no
 * orders, stock, payments or accounts. Idempotent: a second run changes nothing.
 *
 *   php artisan db:seed --class="Database\Seeders\TestShopCategorySeeder"
 */
class TestShopCategorySeeder extends Seeder
{
    /** Seller email => line of business. */
    public const SHOPS = [
        'seller1@zefanya.test' => 'Books and Media',        // Test Shop 1 sells books
        'seller2@zefanya.test' => 'Health and Beauty',
        'seller3@zefanya.test' => 'Home and Garden',
        'seller4@zefanya.test' => 'Electronics and Gadgets',
    ];

    /** Seller email => [product slug => category] for products filed in the wrong category. */
    public const MISFILED = [
        'seller1@zefanya.test' => ['game-cases' => 'Electronics and Gadgets'],
    ];

    /** @var list<string> */
    public array $changes = [];

    public function run(): void
    {
        $this->changes = [];
        $categories = Category::query()->whereIn('label', [...array_values(self::SHOPS), ...array_merge(...array_values(array_map('array_values', self::MISFILED)))])
            ->pluck('id', 'label');

        foreach (self::SHOPS as $email => $label) {
            $seller = Seller::query()->where('email', $email)->first();
            $categoryId = $categories[$label] ?? null;
            if ($seller === null || $categoryId === null) {
                $this->command?->warn("Skipped {$email}: ".($seller === null ? 'account not found' : "category \"{$label}\" not found").'.');

                continue;
            }
            if ((int) $seller->line_of_business_id !== (int) $categoryId) {
                $seller->forceFill(['line_of_business_id' => $categoryId])->save();
                $this->changes[] = "{$seller->business_name}: line of business -> {$label}";
            }

            foreach (self::MISFILED[$email] ?? [] as $slug => $productLabel) {
                $product = Product::withTrashed()->where('seller_id', $seller->id)->where('slug', $slug)->first();
                $productCategoryId = $categories[$productLabel] ?? null;
                if ($product !== null && $productCategoryId !== null && (int) $product->category_id !== (int) $productCategoryId) {
                    $product->forceFill(['category_id' => $productCategoryId])->save();
                    $this->changes[] = "{$product->name}: category -> {$productLabel}";
                }
            }
        }

        $this->command?->info($this->changes === [] ? 'TestShopCategorySeeder: nothing to change.' : 'TestShopCategorySeeder: '.implode('; ', $this->changes));
    }
}
