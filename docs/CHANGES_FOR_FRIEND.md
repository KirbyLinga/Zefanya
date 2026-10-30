# Changes for Friend Review

This document tracks all new tables, columns, enum values, routes, and service classes introduced during mobile-backend integration.

## Phase A: Baseline & Phase A.5 Integrity Checks

- Branch created: `integration/mobile-backend` (cut from `web-api-master` at `4e2cb05`).
- Mobile codebase and documentation brought over from `wip/phases-1-4`.
- Dev database backed up via `mysqldump` to `backups/dev_db_20261029_212446.sql`.
- Backend test suite verified against in-memory SQLite (205 tests passing).
- Mobile test suite verified (709 tests passing).
- No new database tables, columns, enum values, or routes added in Phase A.

### Touched your tests

- `web/tests/Feature/HttpsUrlGenerationTest.php`:
  - **Reason**: Test failed on base `web-api-master` with `"Generated URLs must use https in production. Failed asserting that false is true."` because `config()->set('app.env', 'production')` does not update `$this->app->environment()` (which reads `$this->app['env']` in Laravel 11).
  - **Change**: Added `$this->app['env'] = 'production'` before calling `(new AppServiceProvider($this->app))->boot()`, and restored both `$this->app['env']` and `config('app.env')` in `finally`.
  - **Assertion**: Completely unchanged (`$this->assertTrue(str_starts_with(route('seller.products.index'), 'https://'), 'Generated URLs must use https in production.')`).

## Phase C: Backend Vertical Slices

### 1. New Database Migrations (Additive Only)
- `2026_10_29_230500_add_rider_fields_to_logistics_providers_table.php` (`vehicle_type`, `plate_number`, `license_number`, `license_photo_path`, `vehicle_photo_path`, `nbi_clearance_path`)
- `2026_10_29_233100_create_psgc_tables.php` (`psgc_regions`, `psgc_provinces`, `psgc_cities`, `psgc_barangays`)
- `2026_10_29_233200_create_buyer_addresses_table.php` (`buyer_addresses` with default toggle and geolocation)
- `2026_10_30_001600_create_wishlist_items_table.php` (`wishlist_items`)
- `2026_10_30_003000_add_delivery_handover_to_seller_orders_table.php` (`tracking_number`, `delivery_attempts`, `failure_reason`, `delivery_otp_hash`, `delivery_otp_attempts`, `delivery_otp_locked_until`, `delivered_at`)
- `2026_10_30_004000_add_pending_contact_changes_to_buyers_table.php` (`pending_email`, `pending_email_code`, `pending_email_expires_at`, `pending_phone`, `pending_phone_code`, `pending_phone_expires_at`)

### 2. New Enum Values
- `App\Enums\LogisticsProviderStatus`: added `Suspended = 'suspended'`

### 3. New Models & Services
- Models: `BuyerAddress`, `PsgcRegion`, `PsgcProvince`, `PsgcCity`, `PsgcBarangay`, `Review`, `WishlistItem`
- Services: `OrderPlacer`, `GoogleTokenVerifier`, `CourierRegistrationService`
- Resources: `BuyerAddressResource`, `OrderResource`, `DeliveryJobResource`, `ReviewResource`
- Seeder: `PsgcSeeder` (idempotent seeder loading PSGC data)
- Notification: `BuyerEmailChangeOtp`

### 4. New API Endpoints
- **Auth**: `POST /api/register/courier`, `POST /api/register/logistics`, `POST /api/login/courier`, `POST /api/login/logistics`, `POST /api/auth/google`, `GET /api/me/courier`, `POST /api/logout/courier`
- **Addresses & PSGC**: `GET /api/psgc/regions`, `GET /api/psgc/provinces`, `GET /api/psgc/cities`, `GET /api/psgc/barangays`, `GET/POST /api/buyer/addresses`, `GET/PUT/DELETE /api/buyer/addresses/{id}`, `PATCH /api/buyer/addresses/{id}/default`
- **Reviews & Wishlist**: `GET /api/reviews/mine`, `PATCH /api/orders/{sellerOrder}/items/{product}/review`, `GET/POST/DELETE /api/buyer/wishlist`
- **Orders & Checkout**: `GET /api/orders`, `POST /api/orders`, `GET /api/orders/{sellerOrder}/milestone`
- **Courier & Handover**: `GET /api/courier/jobs`, `PATCH /api/courier/jobs/{sellerOrder}/milestone`
- **Account Security**: `POST /api/account/password`, `POST /api/account/email`, `POST /api/account/email/verify`, `POST /api/account/phone`, `POST /api/account/phone/verify`
- Parallel `v1/` routes matching all endpoints.



## Phase D: End-to-End Acceptance

### 1. New Controllers & Routes
- `Seller\OrderController.php`:
  - `PATCH /seller/orders/{order}/confirm` (`seller.orders.confirm`)
  - `PATCH /seller/orders/{order}/ready` (`seller.orders.ready`)

### 2. New Tests & Documentation
- `web/tests/Feature/EndToEndAcceptanceTest.php`: 15-step multi-role lifecycle feature test (56 assertions).
- `docs/acceptance.md`: Verification checklist and physical device testing guide.

## Phase F: Features (Vertical Slices)

### 1. New API Endpoints (Slice 5)
- **Seller**: `GET /v1/seller/orders`, `PATCH /v1/seller/orders/{sellerOrder}/confirm`, `PATCH /v1/seller/orders/{sellerOrder}/ready`
- **Admin**: `GET /v1/admin/logistics/pending`, `POST /v1/admin/logistics/{logisticsProvider}/approve`, `POST /v1/admin/logistics/{logisticsProvider}/reject`

### 2. New Controller Classes
- `App\\Http\\Controllers\\Api\\Seller\\SellerOrderController` (index, confirm, ready)
- `App\\Http\\Controllers\\Api\\Admin\\AdminLogisticsController` (pending, approve, reject)

### 3. No New Database Tables, Columns, or Enum Values
- Purely additive route/controller surface reusing existing `SellerOrder`, `LogisticsProvider`, `OrderStatus`, and `LogisticsProviderStatus` models/enums.
