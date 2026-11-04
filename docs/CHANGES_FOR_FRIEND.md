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

---

## Security Hardening (fix/security-hardening branch — H1–H8)

> ⚠️ **Changed behaviour in this section.** Several existing code paths now enforce
> gates that previously had none. Read this section before merging into integration.

### New Columns (Additive Only)

**`seller_orders` table** (migration `2026_10_31_100000`):
- `delivery_otp_expires_at` (timestamp, nullable)
- `delivery_otp_used_at` (timestamp, nullable)
- `delivery_proof_path` (string, nullable)

**`reviews` table** (migration `2026_11_01_100000`):
- `order_item_id` (FK → `order_items`, nullable, unique index `reviews_order_item_unique`)
- `photo_paths` (JSON, nullable)
- `verified_purchase` (boolean, default false)

**`users`, `buyers`, `logistics_providers`** (migration `2026_10_31_120000`):
- `google_sub` (string 255, nullable, unique)

### New Tables

- `delivery_handover_audits` (`seller_order_id`, `actor_type`, `actor_id`, `action`, `metadata` JSON, `ip_address`, timestamps)

### New Services

- `App\Services\OrderDeliveryService` — single authoritative path for all delivery handovers. Methods: `issuePass()`, `markDelivered()`, `adminOverride()`.
- `App\Services\Auth\GoogleIdTokenVerifier` (interface) + `App\Services\Auth\GoogleApiTokenVerifier` (implementation using `google/apiclient`).

### New Middleware

- `App\Http\Middleware\Courier\EnsureCourierApproved` — returns 403 for pending/rejected couriers on all courier routes.

### New Factory

- `Database\Factories\BuyerAddressFactory` — used by access-control tests.

### Changed Rate Limits ⚠️

Previously the login/register routes used inline `throttle:5,1,api-login`. They now use named buckets (`throttle:api-login`) keyed on `lowercase_email|ip` for login. The **legacy `/api/...` and `/api/v1/...` spellings share one counter** — you cannot bypass the login limit by hitting the other prefix.

The api group now has a floor: `throttle:api-default` (60/min per actor) is appended to every route via `$middleware->api(append: ...)`. A new route that forgets to declare a limit still gets this floor.

### Changed Route Behaviour ⚠️

- `PATCH /courier/jobs/{job}/milestone` with `milestone=DELIVERED` now **requires**:
  1. A server-issued 6-digit OTP (requested by the buyer via `POST /orders/{job}/delivery-pass`) validated by HMAC-SHA256 hash comparison, **OR** a signed QR token from the same issuance; and
  2. A proof-of-delivery photo (`proof_photo` field, jpg/png/webp, max 5 MB).
  
  **There is no bypass for COD, admin, or any other case.** A null hash is always rejected. This closes the previous hole where a courier could set DELIVERED without any verification.

- `GET /courier/jobs` and `PATCH /courier/jobs/{job}/milestone` now return **403** for couriers with `status != approved` (pending/rejected). Previously any sanctum token reached these routes.

### New Routes

- `POST /orders/{sellerOrder}/delivery-pass` — buyer requests a 6-digit OTP + signed token
- `GET /orders/{sellerOrder}/delivery-proof` — view proof-of-delivery photo (authorised only)
- (v1 mirrors of both)

### Changed Google Sign-In ⚠️

- `POST /auth/google` no longer falls back to the tokeninfo HTTP endpoint. It will return **401** (not try another verification method) if `google/apiclient` cannot verify the token cryptographically.
- Returns **503** if `GOOGLE_CLIENT_IDS` env var is empty or missing — fail-closed.
- New riders created through Google sign-in start as **pending_approval** and receive no token.

### Test Counts

| Suite | Count |
|---|---|
| `php artisan test` (fix/security-hardening) | 391 passed, 0 failed |
| `flutter test` (feature/phase-f + H8 fix) | 760 passed, 0 failed |
| `flutter analyze` | 0 errors, 0 warnings (3 pre-existing info hints) |

---

## Step 2: Mass Assignment Hardening (integration/hardened)

### Touched your tests

- `web/tests/Feature/Api/ProductCatalogApiTest.php`:
  - **Reason**: `$product->seller->update(['status' => 'pending_approval'])` silently dropped the status after `status` was moved to `$guarded` on the `Seller` model. The test setup now uses `forceFill` to match the service-layer pattern.
  - **Change**: Test setup line changed from `->update(['status' => 'pending_approval'])` to `->forceFill(['status' => 'pending_approval'])->save()`.
  - **Assertion**: Completely unchanged.

---

## Hardening H1–H6 (integration/hardened, 2026-11-04)

Base for this ledger: `web-api-master` at `4e2cb05`. "Touched" means the file differs from that base. No web file was deleted.

### Touched your files

| File | Why it changed |
|---|---|
| `web/AGENTS.md` | H6 branch policy now names `integration/hardened`, requires `--ff-only` merges, and forbids branching from `feature/phase-f` / `snapshot/pre-hardening-merge`. |
| `web/app/Enums/LogisticsProviderStatus.php` | Added `Suspended = 'suspended'` and its label. |
| `web/app/Http/Controllers/Admin/RegistrationController.php` | Buyer/seller/logistics approve and reject writes now use `forceFill()->save()` for guarded decision fields. |
| `web/app/Http/Controllers/Admin/SellerRegistrationController.php` | Seller approve/reject writes now use `forceFill()->save()`. |
| `web/app/Http/Controllers/Api/Auth/LoginController.php` | `/me` recognises logistics-provider tokens and returns the courier gate/payload; buyer-only guard remains for buyer tokens. |
| `web/app/Http/Controllers/Logistics/RegisterLogisticsController.php` | `status` is set explicitly after construction because it is guarded, never mass-assigned. |
| `web/app/Http/Controllers/Seller/RegisterSellerController.php` | `status` is set explicitly after construction because it is guarded, never mass-assigned. |
| `web/app/Http/Resources/ProductResource.php` | Added `stock`, `rating_avg`, `rating_count`, and seller `maker_note`. |
| `web/app/Models/Admin/Admin.php` | H1: moved `role` and `email_verified_at` from `$fillable` to `$guarded`; removed unused `AdminFactory` import. |
| `web/app/Models/Buyer/Buyer.php` | Added pending email/phone change fields and helpers; `status`, `email_verified_at`, `is_admin`, `role` are guarded; registration writes `status` explicitly. |
| `web/app/Models/Logistics/LogisticsProvider.php` | Added Sanctum tokens, rider/licence fields, status helpers; H1 removed approval metadata from `$fillable`. |
| `web/app/Models/Product.php` | Added `reviews()` relation and `priceCentavos()`. |
| `web/app/Models/Seller/Seller.php` | Moved privileged attributes (`status`, `email_verified_at`, `is_admin`, `role`) to `$guarded`. |
| `web/app/Models/SellerOrder.php` | Added handover/assignment columns, hid OTP hash and proof path, added casts and transition helpers. |
| `web/app/Providers/AppServiceProvider.php` | Google verifier binding, HTTPS enforcement, named API rate limiters, and H1 `preventSilentlyDiscardingAttributes()` guard. |
| `web/bootstrap/app.php` | Appends `throttle:api-default` to the API group; registers `courier.approved`. |
| `web/composer.json` | Added `google/apiclient:2.14`. |
| `web/composer.lock` | Lockfile for the Google client dependency. |
| `web/config/services.php` | Added `services.google.client_ids` from `GOOGLE_CLIENT_IDS`. |
| `web/database/factories/LogisticsProviderFactory.php` | Added `rejected()` and `suspended()` states. |
| `web/phpunit.xml` | Added a test `APP_KEY` so encrypted features run without a local `.env`. |
| `web/routes/admin.php` | Added the admin delivery-override route (reason + proof + audit row). |
| `web/routes/api.php` | Added mobile/`v1` auth, PSGC, address, wishlist, review, order, courier, seller and admin endpoints, including delivery-pass/proof and throttles. |
| `web/routes/seller.php` | Added seller order confirm/ready routes. |
| `web/routes/web.php` | Added the `/auth/google` web alias. |
| `web/tests/Feature/Api/ProductCatalogApiTest.php` | Setup-only `forceFill` change; assertion unchanged (details below). |
| `web/tests/Feature/HttpsUrlGenerationTest.php` | Environment setup only; assertion unchanged (details below). |

### Touched your tests

- `web/tests/Feature/Api/ProductCatalogApiTest.php`
  - **Reason**: `status` is guarded on `Seller`, so `update(['status' => ...])` now throws instead of silently discarding.
  - **Diff**:
    ```diff
    -        $product->seller->update(['status' => 'pending_approval']);
    +        $product->seller->forceFill(['status' => 'pending_approval'])->save();
    ```
  - **Assertion**: Completely unchanged.

- `web/tests/Feature/HttpsUrlGenerationTest.php`
  - **Reason**: `AppServiceProvider::boot()` reads `$this->app->environment()`, not `config('app.env')`; the old setup could not actually enter production mode.
  - **Change**: Sets `$this->app['env'] = 'production'`, then restores both `$this->app['env']` and `config('app.env')` in `finally`.
  - **Assertion**: Completely unchanged.

- `web/tests/Feature/MassAssignmentGuardTest.php`
  - **Reason**: H1 makes guarded mass assignment throw outside production. The old probes asserted silent discard, which is the behaviour being removed.
  - **Changed assertions**: Three existing probes (`test_buyer_profile_update_ignores_status`, `test_admin_approval_uses_forcefill_not_mass_assignment`, `test_seller_approval_uses_forcefill_not_mass_assignment`) now expect `MassAssignmentException` containing the guarded attribute name. The allowed attributes and the `forceFill` half of each test are unchanged.
  - **Added tests**: `test_admin_role_is_not_mass_assignable`, `test_logistics_approval_metadata_is_not_mass_assignable`.
  - **Note**: This is an intentional exception to the "never change web tests" rule: the old assertions encoded the silent discard that H1 removes. No assertion was weakened.

- `web/tests/Feature/Security/DeliveryHandoverSecurityTest.php`
  - **Reason**: `Admin::create(['role' => ...])` now throws because `role` is guarded.
  - **Change**: Three admin setups create the row, then call `forceFill(['role' => ...])->save()`.
  - **Assertion**: Completely unchanged.

- `web/tests/Feature/EndToEndAcceptanceTest.php`
  - **Reason**: Same guarded `role` setup as above.
  - **Change**: One admin setup now uses `forceFill(['role' => 'admin'])->save()`. Pint also removed three unused imports (`PsgcSeeder`, `Hash`, `PersonalAccessToken`), reordered the `Category` import, and removed one stray blank line; no test logic changed.
  - **Assertion**: Completely unchanged.

- `web/tests/Feature/Security/RouteMatrixTest.php`
  - **Reason**: H4 requires the delivery-pass/proof routes to have explicit 403, 422, success and throttle coverage.
  - **Added tests** (eight): buyer-B cannot request another buyer's pass (403); buyer-B cannot download another buyer's proof (403); pass is refused before `OUT_FOR_DELIVERY` (422); pass returns a 6-digit code and token; owner can download the stored proof; proof is 404 when no photo is stored; the 61st pass request is throttled (429); the 61st proof request is throttled (429). `delivery-proof` has no 422 branch; its negative cases are 403 (not owner) and 404 (no photo).
  - **Refactor**: Extracted `parcelFor()`; the existing milestone test uses it. Its assertion is unchanged.

- `web/tests/Feature/AdminApprovalFlowTest.php`
  - **New file** (three tests): seller approval persists `status`/`approved_at`/`approved_by` and sends the decision notification; seller rejection persists the reason; a buyer cannot approve.

