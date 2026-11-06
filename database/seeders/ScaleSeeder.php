<?php

namespace Database\Seeders;

use App\Enums\AdminRole;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Large, realistic data for checking how the site behaves at scale.
 *
 * Run it through `php artisan scale:seed`, which builds a separate database.
 * Rows are bulk inserted with ids assigned here, so models, observers, mail and
 * notifications never run. It refuses any database not named *_scale.
 */
class ScaleSeeder extends Seeder
{
    public const SUFFIX = '_scale';

    public const PASSWORD = 'password';

    /** @var array<string, array{buyers: int, sellers: int, products: int, orders: int, ridersPerProvider: int}> */
    public const TIERS = [
        'small' => ['buyers' => 2000, 'sellers' => 100, 'products' => 2000, 'orders' => 10000, 'ridersPerProvider' => 10],
        'medium' => ['buyers' => 20000, 'sellers' => 1000, 'products' => 20000, 'orders' => 200000, 'ridersPerProvider' => 50],
        'large' => ['buyers' => 100000, 'sellers' => 5000, 'products' => 100000, 'orders' => 1000000, 'ridersPerProvider' => 200],
    ];

    private const BATCH = 1000;

    private const PROVIDERS = 3;

    /** Share of accounts left waiting in the Admin approval queue. */
    private const PENDING_PERCENT = 2;

    private const PROVINCE = ['code' => '043400000', 'name' => 'Laguna'];

    private const MUNICIPALITY = ['code' => '043411000', 'name' => 'Los Baños'];

    private const BARANGAYS = [['code' => '043411006', 'name' => 'Bayog'], ['code' => '043411008', 'name' => 'Maahas']];

    private const ADJECTIVES = ['Classic', 'Premium', 'Everyday', 'Vintage', 'Modern', 'Soft', 'Handmade', 'Slim', 'Compact', 'Floral', 'Wireless', 'Organic'];

    private const NOUNS = ['Blouse', 'Dress', 'Sneakers', 'Backpack', 'Earbuds', 'Serum', 'Tumbler', 'Lamp', 'Wallet', 'Jacket', 'Notebook', 'Speaker', 'Sandals', 'Watch', 'Keyboard', 'Candle'];

    /** A delivered order has walked this whole path; other outcomes stop or branch from it. */
    private const PATH = ['PLACED', 'CONFIRMED', 'PREPARING', 'READY_FOR_PICKUP', 'PICKED_UP', 'AT_SORTING_CENTER', 'SORTED', 'ASSIGNED_TO_RIDER', 'OUT_FOR_DELIVERY', 'DELIVERED', 'COMPLETED'];

    /** @var array<string, int> status => weight out of 100 */
    private const STATUS_WEIGHTS = [
        'COMPLETED' => 62, 'DELIVERED' => 8, 'OUT_FOR_DELIVERY' => 3, 'ASSIGNED_TO_RIDER' => 2, 'SORTED' => 2,
        'AT_SORTING_CENTER' => 2, 'PICKED_UP' => 2, 'READY_FOR_PICKUP' => 3, 'PREPARING' => 2, 'CONFIRMED' => 2,
        'PLACED' => 3, 'CANCELLED' => 6, 'DELIVERY_FAILED' => 1, 'RETURNED' => 2,
    ];

    /** @var array<string, list<array<string, mixed>>> */
    private array $buffers = [];

    private string $now;

    private string $passwordHash;

    public static function databaseNameFor(string $database): string
    {
        $name = strtolower($database);

        return str_ends_with($name, self::SUFFIX) ? $name : $name.self::SUFFIX;
    }

    public function run(string $tier = 'medium'): void
    {
        $this->assertScaleDatabase();

        $size = self::TIERS[$tier] ?? throw new RuntimeException("Unknown tier [{$tier}]. Use small, medium or large.");

        if (DB::table('buyers')->exists() || DB::table('orders')->exists()) {
            throw new RuntimeException('This scale database already has data. Rebuild it with: php artisan scale:seed --fresh');
        }

        ini_set('memory_limit', '-1');
        DB::connection()->disableQueryLog();
        DB::connection()->unsetEventDispatcher();
        mt_srand(20261031);

        $this->now = now()->toDateTimeString();
        $this->passwordHash = Hash::make(self::PASSWORD);

        $this->step('Admin and logistics network', fn () => $this->seedAdmin());
        $network = $this->seedNetwork($size['ridersPerProvider']);
        $approvedSellers = (int) floor($size['sellers'] * (100 - self::PENDING_PERCENT) / 100);
        $approvedBuyers = (int) floor($size['buyers'] * (100 - self::PENDING_PERCENT) / 100);

        $this->step("{$size['sellers']} sellers", fn () => $this->seedSellers($size['sellers'], $approvedSellers));
        $this->step("{$size['buyers']} buyers", fn () => $this->seedBuyers($size['buyers'], $approvedBuyers));

        $catalog = [];
        $this->step("{$size['products']} products", function () use ($size, $approvedSellers, &$catalog): void {
            $catalog = $this->seedProducts($size['products'], $approvedSellers);
        });
        $this->step("{$size['orders']} orders", fn () => $this->seedOrders($size['orders'], $approvedBuyers, $catalog, $network));
        $this->step('Carts and wishlists', fn () => $this->seedCartsAndWishlists($approvedBuyers, $catalog));
    }

    private function assertScaleDatabase(): void
    {
        $database = (string) DB::getDatabaseName();

        if (! str_ends_with(strtolower($database), self::SUFFIX)) {
            throw new RuntimeException("ScaleSeeder refuses to write to [{$database}]. Use: php artisan scale:seed");
        }
    }

    private function step(string $label, callable $work): void
    {
        $started = microtime(true);
        $work();
        $this->flushAll();
        $this->command?->info(sprintf('%s: %.1fs', $label, microtime(true) - $started));
    }

    private function seedAdmin(): void
    {
        $this->add('admins', [
            'name' => 'Scale Admin',
            'email' => 'scale.admin@example.test',
            'password' => $this->passwordHash,
            'role' => AdminRole::SuperAdmin->value,
            'email_verified_at' => $this->now,
            'created_at' => $this->now,
            'updated_at' => $this->now,
        ]);
    }

    /**
     * One Province Hub and Station Hub per provider, an area per barangay and
     * a Courier-linked Rider fleet.
     *
     * @return array<int, array{stationHubId: int, areaIds: list<int>, courierIds: list<int>}>
     */
    private function seedNetwork(int $ridersPerProvider): array
    {
        $network = [];
        $hubId = 0;
        $areaId = 0;
        $riderId = 0;

        for ($provider = 1; $provider <= self::PROVIDERS; $provider++) {
            $name = "Scale Logistics {$provider}";
            $this->add('logistics_providers', $this->person('logistics', $provider, 1) + [
                'business_name' => $name,
                'company_name_key' => Str::lower($name),
                'dti_permit_path' => 'permits/scale.jpg',
                'province_verified_at' => $this->now,
            ]);

            $provinceHubId = ++$hubId;
            $stationHubId = ++$hubId;
            $this->add('logistics_hubs', $this->hub($provinceHubId, $provider, "{$name} Province Hub", 'province_hub', null, 1));
            $this->add('logistics_hubs', $this->hub($stationHubId, $provider, "{$name} Los Baños Station", 'station_hub', $provinceHubId, null));

            $areaIds = [];
            foreach (self::BARANGAYS as $barangay) {
                $areaIds[] = ++$areaId;
                $this->add('areas', [
                    'id' => $areaId,
                    'logistics_provider_id' => $provider,
                    'station_hub_id' => $stationHubId,
                    'name' => "{$barangay['name']}, ".self::MUNICIPALITY['name'],
                    'municipality' => self::MUNICIPALITY['name'],
                    'city_municipality_code' => self::MUNICIPALITY['code'],
                    'barangay_code' => $barangay['code'],
                    'barangay_name' => $barangay['name'],
                    'is_active' => 1,
                    'coverage_validated_at' => $this->now,
                    'created_at' => $this->now,
                    'updated_at' => $this->now,
                ]);
            }

            $courierIds = [];
            for ($i = 1; $i <= $ridersPerProvider; $i++) {
                $riderId++;
                $courierIds[] = $riderId;
                $this->add('riders', [
                    'id' => $riderId,
                    'logistics_provider_id' => $provider,
                    'station_hub_id' => $stationHubId,
                    'first_name' => 'Rider',
                    'last_name' => (string) $riderId,
                    'birthday' => '1995-01-15',
                    'email' => "scale.rider{$riderId}@example.test",
                    'contact_no' => $this->phone(3, $riderId),
                    'license_no' => 'SCALE-LIC-'.$riderId,
                    'license_expiry' => '2030-01-01',
                    'vehicle_type' => 'Motorcycle',
                    'plate_number' => 'SCALE-'.$riderId,
                    'drivers_license_path' => 'riders/scale-license.jpg',
                    'or_cr_path' => 'riders/scale-orcr.jpg',
                    'status' => 'approved',
                    'password' => $this->passwordHash,
                    'email_verified_at' => $this->now,
                    'created_at' => $this->now,
                    'updated_at' => $this->now,
                ]);
                $this->add('couriers', [
                    'id' => $riderId,
                    'logistics_provider_id' => $provider,
                    'rider_id' => $riderId,
                    'area_id' => $areaIds[$i % count($areaIds)],
                    'name' => "Rider {$riderId}",
                    'phone' => $this->phone(3, $riderId),
                    'service_area' => json_encode([self::MUNICIPALITY['name']]),
                    'is_available' => 1,
                    'vehicle_type' => 'motorcycle',
                    'created_at' => $this->now,
                    'updated_at' => $this->now,
                ]);
                foreach ($areaIds as $coveredArea) {
                    $this->add('area_courier', ['area_id' => $coveredArea, 'courier_id' => $riderId]);
                }
            }

            $network[$provider] = ['stationHubId' => $stationHubId, 'areaIds' => $areaIds, 'courierIds' => $courierIds];
        }

        $this->flushAll();

        return $network;
    }

    private function seedSellers(int $count, int $approved): void
    {
        $categoryId = (int) DB::table('categories')->value('id');

        for ($id = 1; $id <= $count; $id++) {
            $name = "Scale Store {$id}";
            $this->add('sellers', $this->person('seller', $id, $id <= $approved ? 1 : 0) + [
                'business_name' => $name,
                'store_name_key' => Str::lower($name),
                'business_permit_path' => 'permits/scale.jpg',
                'line_of_business_id' => $categoryId,
            ]);

            for ($provider = 1; $provider <= self::PROVIDERS; $provider++) {
                $this->add('seller_shipping_providers', [
                    'seller_id' => $id,
                    'logistics_provider_id' => $provider,
                    'is_enabled' => 1,
                    'created_at' => $this->now,
                    'updated_at' => $this->now,
                ]);
            }
        }
    }

    private function seedBuyers(int $count, int $approved): void
    {
        for ($id = 1; $id <= $count; $id++) {
            $this->add('buyers', $this->person('buyer', $id, $id <= $approved ? 1 : 0));
        }
    }

    /**
     * Popular sellers own far more of the catalog, as on a real marketplace.
     *
     * @return array{bySeller: array<int, list<array{id: int, variantId: int, priceMinor: int, name: string}>>, variantOf: array<int, int>}
     */
    private function seedProducts(int $count, int $approvedSellers): array
    {
        $categoryIds = DB::table('categories')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $images = $this->placeholderImages();
        $catalog = ['bySeller' => [], 'variantOf' => []];
        $variantId = 0;
        $optionId = 0;
        $valueId = 0;

        for ($id = 1; $id <= $count; $id++) {
            $sellerId = $this->skewed($approvedSellers);
            $name = self::ADJECTIVES[mt_rand(0, count(self::ADJECTIVES) - 1)].' '.self::NOUNS[mt_rand(0, count(self::NOUNS) - 1)].' '.$id;
            $priceMinor = mt_rand(10, 500) * 1000;
            $stock = mt_rand(1, 100) <= 3 ? 0 : mt_rand(5, 200);
            $price = number_format($priceMinor / 100, 2, '.', '');

            $this->add('products', [
                'id' => $id,
                'seller_id' => $sellerId,
                'category_id' => $categoryIds[$id % count($categoryIds)],
                'name' => $name,
                'slug' => 'scale-product-'.$id,
                'description' => "{$name} from a seeded store, used to test the site at scale.",
                'price' => $price,
                'stock_quantity' => $stock,
                'low_stock_threshold' => 5,
                'weight_grams' => mt_rand(1, 40) * 100,
                'status' => 'active',
                'created_at' => $this->pastDate(),
                'updated_at' => $this->now,
            ]);

            foreach ([1, 0] as $sort => $primary) {
                $this->add('product_images', [
                    'product_id' => $id,
                    'path' => $images[($id + $sort) % count($images)],
                    'is_primary' => $primary,
                    'sort_order' => $sort,
                    'created_at' => $this->now,
                    'updated_at' => $this->now,
                ]);
            }

            $firstVariantId = $variantId + 1;
            $hasSizes = $id % 10 < 3;

            if ($hasSizes) {
                $optionId++;
                $this->add('product_options', ['id' => $optionId, 'product_id' => $id, 'name' => 'Size', 'sort_order' => 0, 'created_at' => $this->now, 'updated_at' => $this->now]);
            }

            foreach ($hasSizes ? ['S', 'M', 'L'] : [null] as $sort => $size) {
                $variantId++;
                $this->add('product_variants', [
                    'id' => $variantId,
                    'product_id' => $id,
                    'price' => $price,
                    'stock_quantity' => $stock,
                    'is_available' => 1,
                    'created_at' => $this->now,
                    'updated_at' => $this->now,
                ]);

                if ($size !== null) {
                    $valueId++;
                    $this->add('product_option_values', ['id' => $valueId, 'product_option_id' => $optionId, 'value' => $size, 'sort_order' => $sort, 'created_at' => $this->now, 'updated_at' => $this->now]);
                    $this->add('product_variant_option_values', ['product_variant_id' => $variantId, 'product_option_value_id' => $valueId, 'created_at' => $this->now, 'updated_at' => $this->now]);
                }
            }

            $catalog['variantOf'][$id] = $firstVariantId;
            $catalog['bySeller'][$sellerId][] = ['id' => $id, 'variantId' => $firstVariantId, 'priceMinor' => $priceMinor, 'name' => $name];
        }

        return $catalog;
    }

    /**
     * @param  array{bySeller: array<int, list<array{id: int, variantId: int, priceMinor: int, name: string}>>, variantOf: array<int, int>}  $catalog
     * @param  array<int, array{stationHubId: int, areaIds: list<int>, courierIds: list<int>}>  $network
     */
    private function seedOrders(int $count, int $approvedBuyers, array $catalog, array $network): void
    {
        $sellerIds = array_keys($catalog['bySeller']);
        $statuses = $this->weightedStatuses();
        $itemId = 0;
        $reviewChance = 30;

        for ($id = 1; $id <= $count; $id++) {
            $buyerId = $this->skewed($approvedBuyers);
            $sellerId = $sellerIds[$this->skewed(count($sellerIds)) - 1];
            $status = $statuses[mt_rand(0, 99)];
            $placedAt = strtotime($this->pastDate());
            $updatedAt = date('Y-m-d H:i:s', $placedAt + 86400 * 3);
            $createdAt = date('Y-m-d H:i:s', $placedAt);
            $providerId = mt_rand(1, self::PROVIDERS);
            $route = $network[$providerId];
            $barangay = self::BARANGAYS[$buyerId % count(self::BARANGAYS)];
            $courierId = $route['courierIds'][mt_rand(0, count($route['courierIds']) - 1)];
            $path = $this->pathTo($status);
            $collected = in_array($status, ['DELIVERED', 'COMPLETED'], true);
            $pickedUp = in_array('PICKED_UP', $path, true);
            $assigned = in_array('ASSIGNED_TO_RIDER', $path, true);
            $shippingMinor = 5000;

            $subtotal = 0;
            $firstItemId = $itemId + 1;
            $firstProductId = 0;
            $products = $catalog['bySeller'][$sellerId];

            for ($line = mt_rand(1, 3); $line > 0; $line--) {
                $product = $products[mt_rand(0, count($products) - 1)];
                $quantity = mt_rand(1, 3);
                $subtotal += $product['priceMinor'] * $quantity;
                $firstProductId = $firstProductId ?: $product['id'];
                $itemId++;
                $this->add('order_items', [
                    'id' => $itemId,
                    'seller_order_id' => $id,
                    'product_id' => $product['id'],
                    'variant_id' => $product['variantId'],
                    'product_name' => $product['name'],
                    'quantity' => $quantity,
                    'price_minor' => $product['priceMinor'],
                    'variant_options' => '[]',
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ]);
            }

            $total = $subtotal + $shippingMinor;
            $commission = (int) round($subtotal / 10);
            $completed = $status === 'COMPLETED';

            $this->add('orders', [
                'id' => $id,
                'buyer_id' => $buyerId,
                'reference' => 'ORD-SCALE-'.$id,
                'shipping_address' => json_encode([
                    'full' => "Street {$buyerId}, {$barangay['name']}, ".self::MUNICIPALITY['name'].', '.self::PROVINCE['name'],
                    'name' => "Buyer {$buyerId}",
                    'phone' => $this->phone(1, $buyerId),
                    'street' => "Street {$buyerId}",
                    'barangay' => $barangay['name'],
                    'barangay_code' => $barangay['code'],
                    'municipality' => self::MUNICIPALITY['name'],
                    'municipality_code' => self::MUNICIPALITY['code'],
                    'province' => self::PROVINCE['name'],
                    'province_code' => self::PROVINCE['code'],
                ], JSON_UNESCAPED_UNICODE),
                'subtotal_minor' => $subtotal,
                'total_minor' => $total,
                'status' => $status,
                'created_at' => $createdAt,
                'updated_at' => $updatedAt,
            ]);

            $this->add('seller_orders', [
                'id' => $id,
                'order_id' => $id,
                'seller_id' => $sellerId,
                'subtotal_minor' => $subtotal,
                'parcel_weight_grams' => mt_rand(1, 40) * 100,
                'status' => $status,
                'tracking_number' => 'TRK-SCALE-'.$id,
                'courier_id' => $pickedUp ? $courierId : null,
                'delivery_courier_id' => $assigned ? $courierId : null,
                'destination_area_id' => $route['areaIds'][$buyerId % count($route['areaIds'])],
                'origin_station_hub_id' => $route['stationHubId'],
                'carrier_provider_id' => $providerId,
                'shipping_fee_minor' => $shippingMinor,
                'handover_at' => $pickedUp ? $updatedAt : null,
                'delivery_attempts' => in_array($status, ['DELIVERY_FAILED', 'RETURNED'], true) ? 1 : 0,
                'commission_bps' => $completed ? 1000 : null,
                'commission_minor' => $completed ? $commission : null,
                'seller_net_minor' => $completed ? $subtotal - $commission : null,
                'commission_finalized_at' => $completed ? $updatedAt : null,
                'created_at' => $createdAt,
                'updated_at' => $updatedAt,
            ]);

            $this->add('payments', [
                'id' => $id,
                'seller_order_id' => $id,
                'amount_minor' => $total,
                'method' => 'cod',
                'status' => $collected ? 'paid' : 'pending',
                'paid_at' => $collected ? $updatedAt : null,
                'collected_by_type' => $collected ? 'rider' : null,
                'collected_by_id' => $collected ? $courierId : null,
                'collected_amount_minor' => $collected ? $total : null,
                'collected_at' => $collected ? $updatedAt : null,
                'created_at' => $createdAt,
                'updated_at' => $updatedAt,
            ]);

            $previous = null;
            foreach ($path as $step => $to) {
                $this->add('status_histories', [
                    'seller_order_id' => $id,
                    'from_status' => $previous,
                    'to_status' => $to,
                    'changed_by' => 'scale-seeder',
                    'source' => 'web',
                    'created_at' => date('Y-m-d H:i:s', $placedAt + $step * 3600),
                    'updated_at' => date('Y-m-d H:i:s', $placedAt + $step * 3600),
                ]);
                $previous = $to;
            }

            if ($collected) {
                $this->add('cod_remittances', [
                    'payment_id' => $id,
                    'seller_order_id' => $id,
                    'courier_id' => $courierId,
                    'logistics_provider_id' => $providerId,
                    'amount_minor' => $total,
                    'status' => $completed ? 'REMITTED_TO_LOGISTICS' : 'PENDING_REMITTANCE',
                    'confirmed_by_id' => $completed ? $providerId : null,
                    'confirmed_at' => $completed ? $updatedAt : null,
                    'received_amount_minor' => $completed ? $total : null,
                    'created_at' => $updatedAt,
                    'updated_at' => $updatedAt,
                ]);

                $this->add('notifications', [
                    'id' => (string) Str::uuid(),
                    'type' => 'App\Notifications\Buyer\OrderDelivered',
                    'notifiable_type' => 'App\Models\Buyer\Buyer',
                    'notifiable_id' => $buyerId,
                    'data' => json_encode(['type' => 'delivered', 'seller_order_id' => $id, 'order_reference' => 'ORD-SCALE-'.$id, 'tracking_number' => 'TRK-SCALE-'.$id, 'message' => 'Your order was delivered.']),
                    'read_at' => $completed ? $updatedAt : null,
                    'created_at' => $updatedAt,
                    'updated_at' => $updatedAt,
                ]);
            }

            if ($completed && mt_rand(1, 100) <= $reviewChance) {
                $this->add('reviews', [
                    'buyer_id' => $buyerId,
                    'product_id' => $firstProductId,
                    'order_item_id' => $firstItemId,
                    'rating' => mt_rand(3, 5),
                    'body' => 'Seeded review for scale testing.',
                    'created_at' => $updatedAt,
                    'updated_at' => $updatedAt,
                ]);
            }
        }
    }

    /**
     * @param  array{bySeller: array<int, list<array{id: int, variantId: int, priceMinor: int, name: string}>>, variantOf: array<int, int>}  $catalog
     */
    private function seedCartsAndWishlists(int $approvedBuyers, array $catalog): void
    {
        $productIds = array_keys($catalog['variantOf']);
        $productCount = count($productIds);

        for ($buyerId = 1; $buyerId <= $approvedBuyers; $buyerId++) {
            if ($buyerId % 5 === 0 || $buyerId === 1) {
                $start = mt_rand(0, $productCount - 1);
                for ($line = 0; $line < 3; $line++) {
                    $productId = $productIds[($start + $line) % $productCount];
                    $this->add('cart_items', ['buyer_id' => $buyerId, 'product_id' => $productId, 'variant_id' => $catalog['variantOf'][$productId], 'quantity' => mt_rand(1, 3), 'created_at' => $this->now, 'updated_at' => $this->now]);
                }
            }

            if ($buyerId % 3 === 0 || $buyerId === 1) {
                $start = mt_rand(0, $productCount - 1);
                for ($line = 0; $line < 5; $line++) {
                    $this->add('wishlists', ['buyer_id' => $buyerId, 'product_id' => $productIds[($start + $line) % $productCount], 'created_at' => $this->now, 'updated_at' => $this->now]);
                }
            }
        }
    }

    /**
     * @return list<string>
     */
    private function pathTo(string $status): array
    {
        return match ($status) {
            'CANCELLED' => ['PLACED', 'CANCELLED'],
            'DELIVERY_FAILED' => [...array_slice(self::PATH, 0, 9), 'DELIVERY_FAILED'],
            'RETURNED' => [...array_slice(self::PATH, 0, 9), 'DELIVERY_FAILED', 'RETURNED'],
            default => array_slice(self::PATH, 0, array_search($status, self::PATH, true) + 1),
        };
    }

    /**
     * @return list<string> one hundred entries, so a 0-99 pick follows the weights
     */
    private function weightedStatuses(): array
    {
        $statuses = [];
        foreach (self::STATUS_WEIGHTS as $status => $weight) {
            array_push($statuses, ...array_fill(0, $weight, $status));
        }

        return $statuses;
    }

    /**
     * Fields shared by the buyer, seller and logistics account tables.
     *
     * @return array<string, mixed>
     */
    private function person(string $role, int $id, int $approved): array
    {
        $barangay = self::BARANGAYS[$id % count(self::BARANGAYS)];

        return [
            'id' => $id,
            'last_name' => ucfirst($role),
            'first_name' => (string) $id,
            'sex' => $id % 2 === 0 ? 'female' : 'male',
            'email' => "scale.{$role}{$id}@example.test",
            'password' => $this->passwordHash,
            'contact_no' => $this->phone(['buyer' => 1, 'seller' => 2, 'logistics' => 4][$role], $id),
            'birthday' => '1995-06-20',
            'age' => 31,
            'address_mode' => 'manual',
            'province_code' => self::PROVINCE['code'],
            'province_name' => self::PROVINCE['name'],
            'municipality_code' => self::MUNICIPALITY['code'],
            'municipality_name' => self::MUNICIPALITY['name'],
            'barangay_code' => $barangay['code'],
            'barangay_name' => $barangay['name'],
            'street' => "Street {$id}",
            'upload_id_path' => 'ids/scale.jpg',
            'status' => $approved === 1 ? 'approved' : 'pending',
            'email_verified_at' => $this->now,
            'approved_at' => $approved === 1 ? $this->now : null,
            'created_at' => $this->pastDate(),
            'updated_at' => $this->now,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function hub(int $id, int $providerId, string $name, string $type, ?int $parentId, ?int $provinceSlot): array
    {
        return [
            'id' => $id,
            'logistics_provider_id' => $providerId,
            'parent_hub_id' => $parentId,
            'name' => $name,
            'hub_type' => $type,
            'province_code' => self::PROVINCE['code'],
            'province_name' => self::PROVINCE['name'],
            'city_municipality_code' => self::MUNICIPALITY['code'],
            'municipality' => self::MUNICIPALITY['name'],
            'is_active' => 1,
            'approval_status' => 'approved',
            'approved_at' => $this->now,
            'active_province_slot' => $provinceSlot,
            'created_at' => $this->now,
            'updated_at' => $this->now,
        ];
    }

    private function phone(int $rolePrefix, int $id): string
    {
        return '09'.$rolePrefix.str_pad((string) $id, 8, '0', STR_PAD_LEFT);
    }

    /** Low ids are picked far more often, so some accounts are much busier than others. */
    private function skewed(int $max): int
    {
        $roll = mt_rand() / mt_getrandmax();

        return max(1, (int) ceil($max * $roll * $roll));
    }

    private function pastDate(): string
    {
        return date('Y-m-d H:i:s', time() - mt_rand(3 * 86400, 180 * 86400));
    }

    /**
     * Real files, so product pages load images the way they do in production.
     *
     * @return list<string>
     */
    private function placeholderImages(): array
    {
        $disk = Storage::disk('public');
        $paths = [];

        foreach ([[201, 170, 160], [150, 180, 165], [170, 170, 205], [215, 195, 150], [190, 160, 190], [160, 190, 205]] as $index => [$red, $green, $blue]) {
            $path = "products/scale/{$index}.jpg";
            $paths[] = $path;

            if (! $disk->exists($path)) {
                $image = imagecreatetruecolor(600, 600);
                imagefill($image, 0, 0, imagecolorallocate($image, $red, $green, $blue));
                ob_start();
                imagejpeg($image, null, 80);
                $disk->put($path, (string) ob_get_clean());
            }
        }

        return $paths;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function add(string $table, array $row): void
    {
        $this->buffers[$table][] = $row;

        if (count($this->buffers[$table]) >= self::BATCH) {
            $this->flushAll();
        }
    }

    /** Parents flush before children so foreign keys always find their rows. */
    private function flushAll(): void
    {
        $order = ['admins', 'logistics_providers', 'logistics_hubs', 'areas', 'riders', 'couriers', 'area_courier', 'sellers', 'seller_shipping_providers', 'buyers', 'products', 'product_images', 'product_options', 'product_option_values', 'product_variants', 'product_variant_option_values', 'orders', 'seller_orders', 'order_items', 'payments', 'status_histories', 'cod_remittances', 'notifications', 'reviews', 'cart_items', 'wishlists'];

        foreach ($order as $table) {
            if (($this->buffers[$table] ?? []) !== []) {
                DB::table($table)->insert($this->buffers[$table]);
                $this->buffers[$table] = [];
            }
        }
    }
}
