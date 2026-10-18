# File manifest - every backend file in these bundles

## 01-api-surface.md


### Wiring

- web/bootstrap\app.php  (92 lines)
- web/config\filesystems.php  (80 lines)
- web/routes\api.php  (119 lines)
- web/.env.example  (65 lines)
- web/composer.json  (88 lines)

### API controllers - app/Http/Controllers/Api

- web/app\Http\Controllers\Api\Auth\LoginController.php  (173 lines)
- web/app\Http\Controllers\Api\Buyer\RegisterBuyerController.php  (59 lines)
- web/app\Http\Controllers\Api\Buyer\VerifyBuyerOtpController.php  (107 lines)
- web/app\Http\Controllers\Api\Cart\VoucherController.php  (43 lines)
- web/app\Http\Controllers\Api\Catalog\CategoryController.php  (32 lines)
- web/app\Http\Controllers\Api\Catalog\ProductController.php  (117 lines)
- web/app\Http\Controllers\Api\Order\OrderController.php  (242 lines)

### Shared services - both doors call these

- web/app\Services\CartSummary.php  (206 lines)
- web/app\Services\OrderPlacer.php  (291 lines)

### The other door - the web checkout, for comparison

- web/app\Http\Controllers\Buyer\CheckoutController.php  (199 lines)
- web/app\Http\Controllers\Buyer\CartController.php  (310 lines)

### API resources - the wire shape

- web/app\Http\Resources\CategoryResource.php  (37 lines)
- web/app\Http\Resources\OrderResource.php  (89 lines)
- web/app\Http\Resources\ProductResource.php  (130 lines)
- web/app\Http\Resources\VoucherResource.php  (50 lines)

### Form requests the API validates with

- web/app\Http\Requests\Buyer\StoreBuyerRegistrationRequest.php  (58 lines)
- web/app\Http\Requests\Logistics\StoreLogisticsRegistrationRequest.php  (83 lines)

### Models the API touches

- web/app\Models\Product.php  (103 lines)
- web/app\Models\ProductImage.php  (29 lines)
- web/app\Models\CartItem.php  (74 lines)
- web/app\Models\Order.php  (92 lines)
- web/app\Models\OrderItem.php  (68 lines)
- web/app\Models\SellerOrder.php  (73 lines)
- web/app\Models\Payment.php  (64 lines)
- web/app\Models\InventoryTransaction.php  (47 lines)
- web/app\Models\Voucher.php  (128 lines)
- web/app\Models\Buyer\Buyer.php  (213 lines)
- web/app\Models\Seller\Seller.php  (137 lines)
- web/app\Models\Shared\Category.php  (10 lines)
- web/app\Models\Shared\User.php  (32 lines)
- web/app\Models\Logistics\LogisticsProvider.php  (120 lines)

### Enums

- web/app\Enums\LogisticsProviderStatus.php  (38 lines)
- web/app\Enums\OrderStatus.php  (40 lines)
- web/app\Enums\PaymentMethod.php  (12 lines)
- web/app\Enums\PaymentStatus.php  (14 lines)
- web/app\Enums\VoucherType.php  (27 lines)

### Migrations

- web/database\migrations\0001_01_01_000000_create_users_table.php  (49 lines)
- web/database\migrations\0001_01_01_000001_create_cache_table.php  (35 lines)
- web/database\migrations\0001_01_01_000002_create_jobs_table.php  (59 lines)
- web/database\migrations\2024_01_01_000000_create_admins_table.php  (33 lines)
- web/database\migrations\2025_09_05_000000_create_buyers_table.php  (75 lines)
- web/database\migrations\2025_09_05_100000_create_categories_table.php  (48 lines)
- web/database\migrations\2025_09_05_100001_create_sellers_table.php  (75 lines)
- web/database\migrations\2026_09_12_000001_create_products_table.php  (36 lines)
- web/database\migrations\2026_09_12_000002_create_product_images_table.php  (25 lines)
- web/database\migrations\2026_09_20_000001_create_cart_items_table.php  (31 lines)
- web/database\migrations\2026_09_20_000002_create_vouchers_table.php  (34 lines)
- web/database\migrations\2026_09_21_000001_create_logistics_providers_table.php  (75 lines)
- web/database\migrations\2026_09_22_085043_change_products_status_to_string.php  (28 lines)
- web/database\migrations\2026_09_22_110001_create_orders_table.php  (46 lines)
- web/database\migrations\2026_09_22_110002_create_seller_orders_table.php  (41 lines)
- web/database\migrations\2026_09_22_110003_create_order_items_table.php  (37 lines)
- web/database\migrations\2026_09_22_110004_create_payments_table.php  (42 lines)
- web/database\migrations\2026_09_22_110005_create_inventory_transactions_table.php  (45 lines)
- web/database\migrations\2026_09_30_144946_create_personal_access_tokens_table.php  (33 lines)

### Seeders - the demo data the app is developed against

- web/database\seeders\DatabaseSeeder.php  (45 lines)
- web/database\seeders\DemoBuyerSeeder.php  (94 lines)
- web/database\seeders\DemoCatalogueSeeder.php  (335 lines)
- web/database\seeders\DemoOrderSeeder.php  (148 lines)
- web/database\seeders\VoucherSeeder.php  (50 lines)

## 02-api-tests.md


### Service tests - the placing rules, with no controller in the path

- web/tests\Feature\Services\OrderPlacerTest.php  (233 lines)

### API tests, including the seeder tests

- web/tests\Feature\Api\BuyerLoginApiTest.php  (234 lines)
- web/tests\Feature\Api\BuyerOtpApiTest.php  (203 lines)
- web/tests\Feature\Api\BuyerRegistrationApiTest.php  (221 lines)
- web/tests\Feature\Api\CategoryApiTest.php  (64 lines)
- web/tests\Feature\Api\DemoBuyerSeederTest.php  (85 lines)
- web/tests\Feature\Api\DemoCatalogueSeederTest.php  (142 lines)
- web/tests\Feature\Api\DemoOrderSeederTest.php  (125 lines)
- web/tests\Feature\Api\OrderApiTest.php  (184 lines)
- web/tests\Feature\Api\PlaceOrderApiTest.php  (248 lines)
- web/tests\Feature\Api\ProductCatalogApiTest.php  (370 lines)
- web/tests\Feature\Api\VoucherApiTest.php  (111 lines)

