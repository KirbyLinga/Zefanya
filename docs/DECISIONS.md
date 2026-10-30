# Decisions & Deviations

This file records deviations from the repository's baseline instructions or decisions required during execution.

## Phase A: Baseline & Integration Setup

1. **Base Branch Selection**:
   - `web-api` and `web-api-master` do not contain one another (`git merge-base --is-ancestor` returned 1 for both).
   - `web-api-master` has the newest commit (`4e2cb05` on Oct 4, 2026, 19:06:31 vs `fceb34b` on Oct 4, 2026, 18:55:23).
   - Cut `integration/mobile-backend` from `web-api-master` and checked out `mobile\` and `docs\` from `wip/phases-1-4`.

2. **Obsolete File Removal (`role_switcher_screen.dart`)**:
   - `mobile/lib/screens/role_switcher_screen.dart` was present on `web-api-master` (from commit `4e60367`) but was deleted in mobile development (commit `8d84f2e`).
   - `git checkout wip/phases-1-4 -- mobile docs` brought over tree files but left this orphaned file on disk, producing 2 `invalid_constant` errors in `flutter analyze`.
   - Removed with `git rm mobile/lib/screens/role_switcher_screen.dart`. Clean analysis restored.

3. **Backend Test Environment Fix (`HttpsUrlGenerationTest.php`)**:
   - `HttpsUrlGenerationTest::test_named_routes_start_with_https_in_production` failed because `config()->set('app.env', 'production')` does not update `$this->app['env']` in Laravel 11 container.
   - Added `$this->app['env'] = 'production'` before booting `AppServiceProvider` and restored it in `finally`, preserving test expectation.

## Phase C: Backend Vertical Slices

1. **Rule 3 Compliance in `live_auth_test.dart`**:
   - Preserved `the courier role names the missing endpoint instead of posting` by throwing `notWired('POST /register/logistics')` when registration profile lacks rider credentials/plate, and calling live multipart endpoint when rider plate is present.

2. **PSGC Hierarchy & Province-less NCR**:
   - Seeded Philippine Standard Geographic Code tables (`psgc_regions`, `psgc_provinces`, `psgc_cities`, `psgc_barangays`) from `mobile/assets/data/ph_psgc.json` via chunked upserts. NCR localities have null `province_code`, using fallback label "Metro Manila".
   - Immutable shipping address snapshot is captured on `Order` checkout via `BuyerAddress::toSnapshot()`.

3. **Restoration of Historical Migrations**:
   - Re-aligned migrations with dev database schema for `reviews`, `assigned_courier`, `chat_messages`, `maker_note`, and `promotions` using original definitions from commit `8ff3826`.

4. **Shared `OrderPlacer` Service**:
   - Shared business rules across web checkout and mobile `POST /orders` via `OrderPlacer` service (id-ordered stock locking, integer centavos, largest-remainder discount allocation).

5. **Delivery Handover Gate**:
   - Preserved server-side delivery verification: OTP stored as hash (bcrypt/sha256), rate limiting after 5 wrong attempts (423 for 15 mins), and COD exact centavo check on `PATCH /api/courier/jobs/{job}/milestone`.



## Phase D: End-to-End Acceptance

1. **Seller Order Transitions**:
   - Added `confirm()` and `ready($trackingNumber)` actions to `web/app/Http/Controllers/Seller/OrderController.php` and mapped them to `PATCH /seller/orders/{order}/confirm` and `PATCH /seller/orders/{order}/ready` so sellers can confirm orders and issue tracking numbers through the standard seller interface.
   - Enhanced `SellerOrder::assignCourier($courier, $feeMinor, $area, ?OrderStatus $status = null)` to allow assigning couriers without prematurely overriding orders that are in `READY_FOR_PICKUP` state.

2. **Multi-Role Acceptance Coverage**:
   - Automated end-to-end integration test `web/tests/Feature/EndToEndAcceptanceTest.php` covers the 15-step story across Buyer, Seller, Rider, and Admin.
   - Comprehensive checklist documented in `docs/acceptance.md` including physical device verification guidelines (Camera, Barcode/QR scanner, GPS, Google OAuth).

## Phase F: Features (Vertical Slices)

1. **Seller & Admin API Routes (Slice 5)**:
   - Added `web/app/Http/Controllers/Api/Seller/SellerOrderController.php` with `index`, `confirm`, `ready` methods.
   - Added `web/app/Http/Controllers/Api/Admin/AdminLogisticsController.php` with `pending`, `approve`, `reject` methods.
   - Added v1 routes: `GET /v1/seller/orders`, `PATCH /v1/seller/orders/{sellerOrder}/confirm`, `PATCH /v1/seller/orders/{sellerOrder}/ready`, `GET /v1/admin/logistics/pending`, `POST /v1/admin/logistics/{logisticsProvider}/approve`, `POST /v1/admin/logistics/{logisticsProvider}/reject`.
   - No new tables, columns, or enum values — purely additive route/controller surface reusing existing `SellerOrder`, `LogisticsProvider`, and `OrderStatus` models.

2. **Photo Reviews UI (Slice 1)**:
   - Added `ratingDistribution` field to `Product` model (mock data) and `_ratingDistribution()` mapper in `remote_catalog.dart` reading `rating_distribution` from server.
   - Added `_RatingDistributionBars` widget and `_ReviewPhotoViewer` (tap-to-expand) to product details screen.

3. **Voucher Wallet (Slice 2)**:
   - Rewrote `vouchers_screen.dart` with Available/Claimed/Used tabs, claim-with-ineligible-reasons, inline blocker messages.
   - Added `claimedVoucherCodes` getter to `CartState`.

4. **Search Filters (Slice 4)**:
   - Added `shipsFrom`, `supportsCod` fields to `Product` model (mock data).
   - Extended `SearchFilter` with `shipsFrom`, `freeShipping`, `supportsCod` fields; updated `filterProducts()`.
   - Added ships-from/free-shipping/COD filter chips to `search_screen.dart`.
