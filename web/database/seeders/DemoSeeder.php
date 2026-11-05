<?php

namespace Database\Seeders;

use App\Enums\LogisticsProviderStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\VoucherType;
use App\Models\Buyer\Buyer;
use App\Models\Buyer\BuyerAddress;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Seller\Seller;
use App\Models\Shared\Category;
use App\Models\Seller\Product;
use App\Models\Voucher;
use App\Services\OrderDeliveryService;
use App\Services\OrderPlacer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $this->seedCategories();
        $this->seedAccounts($now);
        $this->seedAddresses();
        $this->seedProducts($now);
        $this->seedVouchers($now);
        $this->seedShippingRates($now);
        $this->seedDeliveryAreas($now);
        $this->seedSampleOrders($now);
    }

    private function seedCategories(): void
    {
        $categories = [
            ['label' => 'Bags', 'icon' => 'bag'],
            ['label' => 'Home Decor', 'icon' => 'home'],
            ['label' => 'Lamps', 'icon' => 'light'],
            ['label' => 'Baskets', 'icon' => 'basket'],
            ['label' => 'Kitchen & Dining', 'icon' => 'utensils'],
        ];

        foreach ($categories as $category) {
            Category::updateOrCreate(
                ['label' => $category['label']],
                ['icon' => $category['icon']],
            );
        }
    }

    private function seedAccounts($now): void
    {
        $password = fn (string $p): string => Hash::make($p);

        // Buyers (approved, email verified)
        $buyers = [
            [
                'email' => 'juanmiguel.delacruz@example.com',
                'first_name' => 'Juan Miguel',
                'last_name' => 'Dela Cruz',
                'contact_no' => '09171234567',
                'password' => 'Mamimili@2026',
                'birthday' => '1998-05-14',
                'province_name' => 'Metro Manila',
                'city' => 'Quezon City',
                'barangay' => 'Central',
                'street' => '45 Kalayaan Ave.',
            ],
            [
                'email' => 'anamarie.reyes@example.com',
                'first_name' => 'Ana Marie',
                'last_name' => 'Reyes',
                'contact_no' => '09173456789',
                'password' => 'Suki#2026Ana',
                'birthday' => '2001-11-02',
                'province_name' => 'Cebu',
                'city' => 'Cebu City',
                'barangay' => 'Capitol Site',
                'street' => '78 Osmena Blvd.',
            ],
        ];

        foreach ($buyers as $b) {
            Buyer::updateOrCreate(
                ['email' => $b['email']],
                [
                    'first_name' => $b['first_name'],
                    'last_name' => $b['last_name'],
                    'sex' => 'male',
                    'password' => $password($b['password']),
                    'contact_no' => $b['contact_no'],
                    'birthday' => $b['birthday'],
                    'age' => Carbon::parse($b['birthday'])->age,
                    'address_mode' => 'manual',
                    'province_name' => $b['province_name'],
                    'municipality_name' => $b['city'],
                    'barangay_name' => $b['barangay'],
                    'street' => $b['street'],
                    'upload_id_path' => 'ids/test.jpg',
                    'status' => 'approved',
                    'email_verified_at' => $now,
                    'approved_at' => $now,
                ],
            );
        }

        // Sellers (approved and pending)
        $sellers = [
            [
                'email' => 'mariaclara.santos@example.com',
                'first_name' => 'Maria Clara',
                'last_name' => 'Santos',
                'contact_no' => '09189876543',
                'password' => 'Tindahan@2026',
                'birthday' => '1990-03-15',
                'province_name' => 'Metro Manila',
                'city' => 'Quezon City',
                'barangay' => 'Commonwealth',
                'street' => 'Blk 12 Lot 8, Sampaguita St.',
                'business_name' => 'Maria Clara Handicrafts',
                'status' => 'approved',
            ],
            [
                'email' => 'roberto.villanueva@example.com',
                'first_name' => 'Roberto',
                'last_name' => 'Villanueva',
                'contact_no' => '09194567890',
                'password' => 'Gadget#Hub26',
                'birthday' => '1988-07-22',
                'province_name' => 'Cebu',
                'city' => 'Cebu City',
                'barangay' => 'Lahug',
                'street' => '2F, 45 Gorordo Ave.',
                'business_name' => 'RV Gadget Hub',
                'status' => 'approved',
            ],
            [
                'email' => 'seller.test01@example.com',
                'first_name' => 'Test',
                'last_name' => 'Seller',
                'contact_no' => '09170000001',
                'password' => 'Password123!',
                'birthday' => '1995-01-01',
                'province_name' => 'Metro Manila',
                'city' => 'Pasig City',
                'barangay' => 'San Antonio',
                'street' => 'Unit 101, Test Building',
                'business_name' => 'Test Store',
                'status' => 'pending_approval',
            ],
        ];

        $homeLiving = Category::where('label', 'Home & Living')->first();

        foreach ($sellers as $s) {
            Seller::updateOrCreate(
                ['email' => $s['email']],
                [
                    'first_name' => $s['first_name'],
                    'last_name' => $s['last_name'],
                    'sex' => 'female',
                    'password' => $password($s['password']),
                    'contact_no' => $s['contact_no'],
                    'birthday' => $s['birthday'],
                    'age' => Carbon::parse($s['birthday'])->age,
                    'address_mode' => 'manual',
                    'province_name' => $s['province_name'],
                    'municipality_name' => $s['city'],
                    'barangay_name' => $s['barangay'],
                    'street' => $s['street'],
                    'business_name' => $s['business_name'],
                    'line_of_business_id' => $homeLiving?->id,
                    'upload_id_path' => 'ids/test.jpg',
                    'business_permit_path' => 'permits/test.jpg',
                    'status' => $s['status'],
                    'email_verified_at' => $now,
                    'approved_at' => $s['status'] === 'approved' ? $now : null,
                ],
            );
        }

    }

    private function seedAddresses(): void
    {
        $addresses = [
            [
                'buyer_email' => 'juanmiguel.delacruz@example.com',
                'recipient' => 'Juan Miguel Dela Cruz',
                'phone' => '09171234567',
                'label' => 'Home (default)',
                'province' => 'Metro Manila',
                'city' => 'Quezon City',
                'barangay' => 'Central',
                'street' => '45 Kalayaan Ave.',
                'postal_code' => '1100',
                'is_default' => true,
            ],
            [
                'buyer_email' => 'juanmiguel.delacruz@example.com',
                'recipient' => 'Juan Miguel Dela Cruz',
                'phone' => '09171234567',
                'label' => 'Office',
                'province' => 'Metro Manila',
                'city' => 'Makati City',
                'barangay' => 'San Lorenzo',
                'street' => '12F Pacific Tower, 6750 Ayala Ave.',
                'postal_code' => '1223',
                'is_default' => false,
            ],
            [
                'buyer_email' => 'anamarie.reyes@example.com',
                'recipient' => 'Ana Marie Reyes',
                'phone' => '09173456789',
                'label' => 'Home (default)',
                'province' => 'Cebu',
                'city' => 'Cebu City',
                'barangay' => 'Capitol Site',
                'street' => '78 Osmena Blvd.',
                'postal_code' => '6000',
                'is_default' => true,
            ],
            [
                'buyer_email' => 'juanmiguel.delacruz@example.com',
                'recipient' => 'Juan Miguel Dela Cruz',
                'phone' => '09171234567',
                'label' => 'Province',
                'province' => 'Batanes',
                'city' => 'Itbayat',
                'barangay' => 'Raele',
                'street' => 'Purok 2',
                'postal_code' => '3905',
                'is_default' => false,
            ],
        ];

        foreach ($addresses as $a) {
            $buyer = Buyer::where('email', $a['buyer_email'])->first();
            if ($buyer === null) {
                continue;
            }

            BuyerAddress::updateOrCreate(
                ['buyer_id' => $buyer->id, 'label' => $a['label']],
                [
                    'recipient' => $a['recipient'],
                    'phone' => $a['phone'],
                    'province_name' => $a['province'],
                    'city' => $a['city'],
                    'barangay' => $a['barangay'],
                    'street' => $a['street'],
                    'postal_code' => $a['postal_code'],
                    'is_default' => $a['is_default'],
                ],
            );
        }
    }

    private function seedProducts($now): void
    {
        $seller1 = Seller::where('email', 'mariaclara.santos@example.com')->first();
        $seller2 = Seller::where('email', 'roberto.villanueva@example.com')->first();
        $bags = Category::where('label', 'Bags')->first();
        $homeDecor = Category::where('label', 'Home Decor')->first();
        $lamps = Category::where('label', 'Lamps')->first();
        $baskets = Category::where('label', 'Baskets')->first();
        $kitchen = Category::where('label', 'Kitchen & Dining')->first();

        $products = [
            [
                'seller' => $seller1,
                'category' => $bags,
                'name' => 'Abaca Woven Tote Bag',
                'slug' => 'abaca-woven-tote-bag',
                'description' => 'Color: Natural, Black x Size: S ₱199, M ₱249, L ₱299 (6 variants). SKU MCH-TOTE-<COLOR>-<SIZE>. Natural-S 10, Natural-M 8, Natural-L 5, Black-S 10, Black-M 0, Black-L 3. 0.4 kg; 40 x 35 x 5 cm.',
                'price' => 249.00,
                'stock' => 8,
                'weight' => '0.4 kg',
            ],
            [
                'seller' => $seller1,
                'category' => $baskets,
                'name' => 'Rattan Fruit Basket',
                'slug' => 'rattan-fruit-basket',
                'description' => '₱450.00 (no variants). 0.8 kg; 30 x 30 x 15 cm.',
                'price' => 450.00,
                'stock' => 20,
                'weight' => '0.8 kg',
            ],
            [
                'seller' => $seller1,
                'category' => $lamps,
                'name' => 'Capiz Shell Lamp',
                'slug' => 'capiz-shell-lamp',
                'description' => '₱1,250.00 (for the oversell case). 1.5 kg; 30 x 30 x 40 cm.',
                'price' => 1250.00,
                'stock' => 1,
                'weight' => '1.5 kg',
            ],
            [
                'seller' => $seller1,
                'category' => $kitchen,
                'name' => 'Banig Placemat Set of 4',
                'slug' => 'banig-placemat-set-of-4',
                'description' => '₱350.00, out of stock. 0.5 kg.',
                'price' => 350.00,
                'stock' => 0,
                'weight' => '0.5 kg',
            ],
            [
                'seller' => $seller2,
                'category' => $homeDecor,
                'name' => 'USB-C Fast Charger 20W',
                'slug' => 'usb-c-fast-charger-20w',
                'description' => '₱399.00, stock 50. 0.2 kg; 10 x 8 x 5 cm.',
                'price' => 399.00,
                'stock' => 50,
                'weight' => '0.2 kg',
            ],
            [
                'seller' => $seller2,
                'category' => $homeDecor,
                'name' => 'Wireless Earbuds X2',
                'slug' => 'wireless-earbuds-x2',
                'description' => 'Color: Black, White; ₱1,499.00 each. Black 10, White 10. 0.3 kg.',
                'price' => 1499.00,
                'stock' => 20,
                'weight' => '0.3 kg',
            ],
            [
                'seller' => $seller2,
                'category' => $homeDecor,
                'name' => 'Power Bank 10000mAh',
                'slug' => 'power-bank-10000mah',
                'description' => '₱899.00; per-buyer purchase limit 2. Stock 5. 0.4 kg.',
                'price' => 899.00,
                'stock' => 5,
                'weight' => '0.4 kg',
            ],
            [
                'seller' => $seller1,
                'category' => $homeDecor,
                'name' => 'Narra Coffee Table',
                'slug' => 'narra-coffee-table',
                'description' => '₱4,500.00, stock 50. Seed only for the trip-capacity case. 25.0 kg each.',
                'price' => 4500.00,
                'stock' => 50,
                'weight' => '25.0 kg',
            ],
        ];

        foreach ($products as $p) {
            Product::updateOrCreate(
                ['slug' => $p['slug']],
                [
                    'seller_id' => $p['seller']->id,
                    'category_id' => $p['category']->id,
                    'name' => $p['name'],
                    'description' => $p['description'],
                    'price' => $p['price'],
                    'stock_quantity' => $p['stock'],
                    'low_stock_threshold' => 2,
                    'status' => 'active',
                ],
            );
        }
    }

    private function seedVouchers($now): void
    {
        $vouchers = [
            [
                'code' => 'MCH50',
                'type' => VoucherType::Fixed,
                'value' => 5000,
                'min_spend' => 30000,
                'usage_limit' => 100,
                'times_used' => 0,
                'is_active' => true,
                'expires_at' => null,
            ],
            [
                'code' => 'MCH10',
                'type' => VoucherType::Percent,
                'value' => 10,
                'min_spend' => 50000,
                'usage_limit' => 50,
                'times_used' => 0,
                'is_active' => true,
                'expires_at' => null,
            ],
            [
                'code' => 'RVSECRET',
                'type' => VoucherType::Fixed,
                'value' => 10000,
                'min_spend' => 100000,
                'usage_limit' => 20,
                'times_used' => 0,
                'is_active' => true,
                'expires_at' => null,
            ],
            [
                'code' => 'MCHOLD',
                'type' => VoucherType::Fixed,
                'value' => 3000,
                'min_spend' => 20000,
                'usage_limit' => null,
                'times_used' => 0,
                'is_active' => true,
                'expires_at' => $now->subDay(),
            ],
            [
                'code' => 'MCHSOON',
                'type' => VoucherType::Fixed,
                'value' => 3000,
                'min_spend' => 20000,
                'usage_limit' => null,
                'times_used' => 0,
                'is_active' => true,
                'expires_at' => $now->addDay(),
            ],
            [
                'code' => 'MCHMAX',
                'type' => VoucherType::Fixed,
                'value' => 2000,
                'min_spend' => 10000,
                'usage_limit' => 1,
                'times_used' => 1,
                'is_active' => true,
                'expires_at' => null,
            ],
            [
                'code' => 'PLAT100',
                'type' => VoucherType::Fixed,
                'value' => 10000,
                'min_spend' => 100000,
                'usage_limit' => null,
                'times_used' => 0,
                'is_active' => true,
                'expires_at' => null,
            ],
            [
                'code' => 'FREESHIP',
                'type' => VoucherType::Fixed,
                'value' => 5000,
                'min_spend' => 30000,
                'usage_limit' => null,
                'times_used' => 0,
                'is_active' => true,
                'expires_at' => null,
            ],
        ];

        foreach ($vouchers as $v) {
            Voucher::updateOrCreate(
                ['code' => $v['code']],
                [
                    'type' => $v['type'],
                    'value' => $v['value'],
                    'min_spend' => $v['min_spend'],
                    'usage_limit' => $v['usage_limit'],
                    'times_used' => $v['times_used'],
                    'is_active' => $v['is_active'],
                    'expires_at' => $v['expires_at'],
                ],
            );
        }
    }

    private function seedShippingRates($now): void
    {
        $rates = [
            ['route' => 'Within NCR', 'first_kg_centavos' => 5000, 'additional_kg_centavos' => 1500],
            ['route' => 'Visayas to NCR', 'first_kg_centavos' => 12000, 'additional_kg_centavos' => 4000],
            ['route' => 'NCR to Visayas', 'first_kg_centavos' => 12000, 'additional_kg_centavos' => 4000],
            ['route' => 'Within Visayas', 'first_kg_centavos' => 6000, 'additional_kg_centavos' => 2000],
        ];

        foreach ($rates as $r) {
            DB::table('shipping_rates')->updateOrInsert(
                ['route' => $r['route']],
                [
                    'first_kg_centavos' => $r['first_kg_centavos'],
                    'additional_kg_centavos' => $r['additional_kg_centavos'],
                    'updated_at' => $now,
                ],
            );
        }
    }

    private function seedDeliveryAreas($now): void
    {
        $rider1 = LogisticsProvider::where('email', 'pedrojose.garcia@example.com')->first();
        $rider2 = LogisticsProvider::where('email', 'markanthony.bautista@example.com')->first();

        $areas = [
            [
                'code' => 'AREA-A',
                'name' => 'Area A',
                'covers' => 'Quezon City (ADDR-1)',
                'assigned_rider_id' => $rider1?->id,
                'is_sorting_center' => true,
            ],
            [
                'code' => 'AREA-B',
                'name' => 'Area B',
                'covers' => 'Makati, Pasig, Mandaluyong (ADDR-2)',
                'assigned_rider_id' => $rider2?->id,
                'is_sorting_center' => false,
            ],
            [
                'code' => 'AREA-C',
                'name' => 'Area C',
                'covers' => 'Marikina',
                'assigned_rider_id' => null,
                'is_sorting_center' => false,
            ],
        ];

        foreach ($areas as $a) {
            DB::table('delivery_areas')->updateOrInsert(
                ['code' => $a['code']],
                [
                    'name' => $a['name'],
                    'covers' => $a['covers'],
                    'logistics_company_id' => null,
                    'assigned_rider_id' => $a['assigned_rider_id'],
                    'is_sorting_center' => $a['is_sorting_center'],
                    'updated_at' => $now,
                ],
            );
        }
    }

    private function seedSampleOrders($now): void
    {
        $buyer1 = Buyer::where('email', 'juanmiguel.delacruz@example.com')->first();
        $seller1 = Seller::where('email', 'mariaclara.santos@example.com')->first();
        $seller2 = Seller::where('email', 'roberto.villanueva@example.com')->first();
        $product1 = Product::where('slug', 'abaca-woven-tote-bag')->first();
        $product2 = Product::where('slug', 'rattan-fruit-basket')->first();
        $product3 = Product::where('slug', 'usb-c-fast-charger-20w')->first();

        $statuses = [
            OrderStatus::Placed,
            OrderStatus::Confirmed,
            OrderStatus::Preparing,
            OrderStatus::ReadyForPickup,
            OrderStatus::OutForDelivery,
            OrderStatus::Delivered,
            OrderStatus::Completed,
        ];

        $shippingAddress = [
            'name' => 'Juan Miguel Dela Cruz',
            'phone' => '09171234567',
            'province' => 'Metro Manila',
            'city' => 'Quezon City',
            'barangay' => 'Central',
            'street' => '45 Kalayaan Ave.',
            'postal_code' => '1100',
            'summary' => '45 Kalayaan Ave., Central, Quezon City, Metro Manila 1100',
        ];

        foreach ($statuses as $index => $status) {
            $reference = 'ORD-DEMO-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT);

            $orderId = DB::table('orders')->updateOrInsert(
                ['reference' => $reference],
                [
                    'buyer_id' => $buyer1->id,
                    'shipping_address' => $shippingAddress,
                    'subtotal_minor' => 69900,
                    'discount_minor' => 0,
                    'total_minor' => 69900,
                    'status' => $status->value,
                    'created_at' => $now->subDays(count($statuses) - $index),
                    'updated_at' => $now,
                ],
            );

            $orderId = is_numeric($orderId) ? $orderId : $orderId->id;

            // One seller order per order (Seller 1)
            $sellerOrderRef = $reference.'-SO1';
            DB::table('seller_orders')->updateOrInsert(
                ['tracking_number' => $sellerOrderRef],
                [
                    'order_id' => $orderId,
                    'seller_id' => $seller1->id,
                    'subtotal_minor' => 69900,
                    'assigned_courier_id' => null,
                    'delivery_fee_minor' => 6500,
                    'delivery_area' => 'AREA-A',
                    'tracking_number' => $status->value === 'READY_FOR_PICKUP' ? $sellerOrderRef : null,
                    'status' => $status->value,
                    'created_at' => $now->subDays(count($statuses) - $index),
                    'updated_at' => $now,
                ],
            );

            // Order item
            DB::table('order_items')->updateOrInsert(
                ['seller_order_id' => $orderId, 'product_id' => $product1?->id],
                [
                    'product_id' => $product1?->id,
                    'quantity' => 1,
                    'unit_price_minor' => 24900,
                    'line_total_minor' => 24900,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );

            // Payment (per seller order)
            DB::table('payments')->updateOrInsert(
                ['seller_order_id' => $orderId],
                [
                    'method' => PaymentMethod::Cod->value,
                    'amount_minor' => 69900,
                    'status' => $status->value === OrderStatus::Delivered->value ? PaymentStatus::Paid->value : PaymentStatus::Pending->value,
                    'paid_at' => $status->value === OrderStatus::Delivered->value ? $now : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );

            // Delivered orders are created through OrderDeliveryService,
            // never by direct status assignment.
            if ($status === OrderStatus::Delivered) {
                $this->createDeliveredDemoOrder($buyer1, $seller1, $product2, $product3, $now);
            }
        }
    }

    /**
     * Create one delivered demo order through OrderDeliveryService so the
     * delivered status and its handover facts come from the real service,
     * never by direct status assignment.
     */
    private function createDeliveredDemoOrder(
        Buyer $buyer,
        Seller $seller,
        ?Product $product2,
        ?Product $product3,
        Carbon $now,
    ): void {
        $placer = app(OrderPlacer::class);

        $order = $placer->place(
            $buyer,
            [
                ['product_id' => $product2?->id, 'quantity' => 1],
                ['product_id' => $product3?->id, 'quantity' => 1],
            ],
        );

        $sellerOrder = $order->sellerOrders()->first();
        if ($sellerOrder === null) {
            Log::warning('DemoSeeder: placed order has no seller order; skipping delivery handover.');
            return;
        }

        $sellerOrder->confirm();
        $sellerOrder->markPreparing();
        $sellerOrder->markReadyForPickup('ZEF-'.$sellerOrder->id.'-01');

        $courier = LogisticsProvider::where('email', 'pedrojose.garcia@example.com')->first();
        if ($courier !== null) {
            $sellerOrder->assignCourier($courier, 6500, 'AREA-A');
        }

        // Move to Out for Delivery then Delivered via the delivery service.
        $sellerOrder->forceFill(['status' => OrderStatus::OutForDelivery->value])->save();

        $deliveryService = app(OrderDeliveryService::class);
        $deliveryService->issuePass($sellerOrder, $buyer);

        $deliveryService->markDelivered(
            $sellerOrder,
            $courier ?? LogisticsProvider::first(),
            ['cod_amount' => $sellerOrder->subtotal_minor / 100],
            null,
        );
    }
}
