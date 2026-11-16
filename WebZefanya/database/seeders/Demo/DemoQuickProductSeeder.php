<?php

namespace Database\Seeders\Demo;

use App\Models\Buyer\Buyer;
use App\Models\Catalog\Product;
use App\Models\Seller\Seller;
use Database\Seeders\DeliveryScenarioSeeder;
use Database\Seeders\NinjaExpressPlaygroundSeeder;
use App\Services\Buyer\BuyerAddressBook;
use Illuminate\Database\Seeder;

/**
 * Adds a few plain, active products to every demo Seller (the four from
 * DeliveryScenarioSeeder::SELLERS) so the Buyer -> Seller -> Logistics -> Rider flow can be
 * completed end to end. It creates no orders, payments or other transactions.
 *
 * Run DeliveryScenarioSeeder first (it creates the demo Sellers). Idempotent:
 * a fixed slug per Seller and item means a second run adds nothing. Like the
 * other demo seeders it refuses to run outside a _demo/test database.
 *
 *   php artisan db:seed --class="Database\Seeders\Demo\DemoQuickProductSeeder"
 */
class DemoQuickProductSeeder extends Seeder
{
    private const ITEMS = [
        'tee' => ['Demo T-Shirt', 350, 250, 100],
        'mug' => ['Demo Ceramic Mug', 180, 400, 80],
        'bag' => ['Demo Tote Bag', 520, 500, 60],
    ];

    /** Destination Buyers for the four flows: slug => [province, municipality, barangay, postal, label]. */
    private const BUYERS = [
        'BuyerLb' => [['043400000', 'Laguna'], ['043411000', 'Los Baños'], ['043411013', 'Tadlak'], '4030', 'Flow 1 (Los Baños, Laguna)'],
        'BuyerSantaCruz' => [['043400000', 'Laguna'], ['043426000', 'Santa Cruz'], ['043426001', 'Alipit'], '4009', 'Flow 2 (Santa Cruz, Laguna)'],
        'BuyerBulacan' => [['031400000', 'Bulacan'], ['031410000', 'City of Malolos'], ['031410002', 'Atlag'], '3000', 'Flow 3 (Malolos, Bulacan)'],
        'BuyerDavao' => [['112400000', 'Davao Del Sur'], ['112402000', 'City of Davao'], ['112402002', 'Agdao'], '8000', 'Flow 4 (Davao City)'],
    ];

    public function run(): void
    {
        DemoMarketplaceSeeder::assertSafeTarget();

        $this->buyers();

        $sellers = Seller::query()
            ->whereIn('email', [
                ...array_map(DeliveryScenarioSeeder::email(...), array_keys(DeliveryScenarioSeeder::SELLERS)),
                NinjaExpressPlaygroundSeeder::PREFIX.'seller-lb@zefanya.test', // Los Baños shop (Ninja playground)
            ])
            ->get();

        if ($sellers->isEmpty()) {
            $this->command?->warn('No zef-demo-seller accounts found. Run DeliveryScenarioSeeder first.');

            return;
        }

        $created = 0;

        foreach ($sellers as $seller) {
            foreach (self::ITEMS as $key => [$name, $price, $weight, $stock]) {
                $slug = 'zef-demo-quick-'.$seller->id.'-'.$key;

                if (Product::withTrashed()->where('slug', $slug)->exists()) {
                    continue;
                }

                Product::factory()->create([
                    'seller_id' => $seller->id,
                    'category_id' => $seller->line_of_business_id,
                    'name' => $name,
                    'slug' => $slug,
                    'description' => 'Demo product for completing the buyer, seller, logistics and rider flow.',
                    'price' => $price,
                    'stock_quantity' => $stock,
                    'weight_grams' => $weight,
                    'status' => 'active',
                ]);
                $created++;
            }
        }

        $this->command?->info("DemoQuickProductSeeder: {$created} product(s) added for {$sellers->count()} seller(s).");
    }

    /**
     * One Buyer per destination, with a saved default address. Emails are
     * buyerlb@, buyersantacruz@, buyerbulacan@ and buyerdavao@zefanya.test,
     * password Zefanya@Demo1. The Ninja playground's own Los Baños Buyer is left as it is.
     */
    private function buyers(): void
    {
        $created = 0;

        foreach (self::BUYERS as $slug => [$province, $municipality, $barangay, $postal, $label]) {
            $email = strtolower($slug).'@zefanya.test';

            if (Buyer::query()->where('email', $email)->exists()) {
                continue;
            }

            $buyer = Buyer::factory()->create([
                'email' => $email,
                'password' => DeliveryScenarioSeeder::PASSWORD,
                'first_name' => 'Demo',
                'last_name' => $slug,
                'address_mode' => 'manual',
                'province_code' => $province[0],
                'province_name' => $province[1],
                'municipality_code' => $municipality[0],
                'municipality_name' => $municipality[1],
                'barangay_code' => $barangay[0],
                'barangay_name' => $barangay[1],
                'street' => '1 Demo Street',
                'postal_code' => $postal,
            ]);

            app(BuyerAddressBook::class)->save($buyer, [
                'address_mode' => 'manual',
                'province' => $province[0],
                'province_name' => $province[1],
                'municipality' => $municipality[0],
                'municipality_name' => $municipality[1],
                'barangay' => $barangay[0],
                'barangay_name' => $barangay[1],
                'street' => '1 Demo Street',
                'postal_code' => $postal,
            ], asDefault: true);

            $this->command?->line("  Buyer {$email} - {$label}");
            $created++;
        }

        $this->command?->info("DemoQuickProductSeeder: {$created} buyer(s) added.");
    }
}
