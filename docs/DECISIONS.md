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

5. **Buyer Tracking Map & TrackingRepository**:
   - Moved the tracker's milestone poll out of `CourierTrackerScreen` into `TrackingRepository` (mock/live implementations). Mock mode derives a deterministic Pila, Laguna demo route from the milestone; live mode polls `GET /orders/{sellerOrder}/milestone` every 30 s.
   - Added `TrackingMapCard` using the already-present `flutter_map`/`latlong2`: static camera, pickup/rider/dropoff pins, route line, ETA pill, OSM attribution.
   - Live mode has no coordinates because `GET /orders/{sellerOrder}/tracking` is not implemented. `snapshotFor` returns null there and the tracker omits the map rather than inventing a position; the intended shape is documented as `docs/api-contract.md` §6.4.
   - Widget/golden tests install `debugTrackingTileProvider` (`TransparentTileProvider`) so no test fetches OSM tiles.

6. **Checkout Voucher Picker**:
   - Checkout lists the cart's applied vouchers with per-code discount, a savings row and per-code remove, and opens `VouchersScreen` to apply another claimed code through `CartState.applyVoucher`.
   - The picker is cart-checkout only; Buy Now hides it because those lines have no cart-derived voucher eligibility.
   - The app's stacked-voucher pricing is local-only: `POST /orders` accepts one `voucher_code` and the live repository still sends none (§0.2 of `docs/api-contract.md`).

## Security Hardening (fix/security-hardening branch)

**Base branch:** `feature/phase-f` (commit `563a59e`) — the most recent backend work containing Phases A–F. Cut worktree at `c:\Users\Lenovo\Documents\lavarel\zefanya-security` (`fix/security-hardening`).

### H1 — Fail-Closed Delivery Handover

1. **Single writer gate:** All delivered-status writes now route through `OrderDeliveryService`. The two application code paths found were: (a) `app/Enums/OrderStatus.php` (the enum definition itself), and (b) `app/Services/OrderDeliveryService.php`. The `CourierMilestoneController` calls the service; it does not set the status directly.

2. **OTP storage:** Plain code never persisted. HMAC-SHA256 keyed on `APP_KEY` stored in `delivery_otp_hash`. Additive columns `delivery_otp_expires_at`, `delivery_otp_used_at`, `delivery_proof_path` added to `seller_orders`. New `delivery_handover_audits` table for full audit trail.



## S8B-lite (feature/s1-lite, 2026-11-06)

1. **Portrait lock:** `main.dart` locks the app to `portraitUp|portraitDown` via `SystemChrome.setPreferredOrientations([...])` before `runApp`. The app is a full-screen consumer (catalogue, orders, tracking) and never needs split-screen/slide-over.
2. **No-internet banner with Retry:** `no_internet_banner.dart` shows a toolbar-height banner when the connection is offline. `connection_watcher.dart` probes `8.8.8.8` via `InternetAddress.lookup` every 3s and sets `connectionState`. Banner is silent when online.
3. **Overflow sweep:** `overflow_sweep_test.dart` renders the 5 main buyer screens at 360x640 with textScale 1.3 and checks the top-level `RenderBox` for `hasVisualOverflow`. No real overflows found.
4. **Error handling:** `NetworkException` in `api_exception.dart` already has `isRetryable = true`, allowing screens to show a Retry button. The no-internet banner complements this with a persistent top-of-shell alert.
5. **Discard-changes confirm:** already exists in `dirty_guard.dart` (S8B L4). No new work needed.
6. **Back behaviour after logout:** already correct in `AuthGate` (single Navigator root, `popUntil((route) => route.isFirst)`). No new work needed.

## Open
- Avatar is local-only: no server upload route exists (DEVIATION in DECISIONS.md).
- API admin logistics approve/reject lacks a success test; Admin lacks HasApiTokens. Carry to S10.

3. **Signed QR token:** Payload `{order_id, nonce, expires_at, version}`; HMAC-signed with app key; never contains the code; version is the truncated hash so a reissued code immediately stales all older tokens.

4. **Admin override:** Available only to `role = 'admin'` (not `'support'`). Requires reason string + proof photo. Writes an audit row with `action = 'admin_override'`.

5. **COD bypass removed:** `DELIVERED` requires code + photo regardless of payment method. No exception for COD.

### H2 — Fail-Closed Google Sign-In

1. **Tokeninfo fallback removed entirely.** The old `GoogleTokenVerifier` service (which called `https://www.googleapis.com/oauth2/v3/tokeninfo`) was replaced with `GoogleApiTokenVerifier` using `google/apiclient`'s `Client::verifyIdToken()` (cryptographic verification, no HTTP).

2. **503 fail-closed:** If `GOOGLE_CLIENT_IDS` env var is empty, the endpoint returns 503 rather than accepting any token.

3. **Rider role via Google:** New riders created through Google arrive in `pending_approval` status. The endpoint returns `403` rather than a token — the client cannot reach authenticated routes until an admin approves.

4. **Admin role via Google:** Attempting `role=admin` via this endpoint returns `422`. Admin accounts are created out-of-band only.

5. **google_sub column:** Added nullable unique `google_sub` to `users`, `buyers`, `logistics_providers` (migration `2026_10_31_120000`).

### H3 — Named Rate Limits

All rate limits are named buckets in `AppServiceProvider::configureRateLimiting()` keyed on the actor (not the URL), so the legacy `/api/...` and `/api/v1/...` spellings of the same action share one counter and cannot bypass each other.

| Bucket | Limit | Key |
|---|---|---|
| `api-login` | 5/min | `lowercase_email\|ip` |
| `api-register` | 5/min | `ip:...` |
| `api-google` | 10/min | `ip:...` |
| `api-delivery-verify` | 10/min | courier model+id |
| `api-account-password` | 3/min | actor model+id |
| `api-uploads` | 20/min | actor model+id |
| `api-default` | 60/min | actor or ip (appended to api group) |

### H4 — Reviews

1. `order_item_id` FK added to `reviews` (migration `2026_11_01_100000`). Unique index `reviews_order_item_unique` enforces one review per line item at the DB level.

2. `verified_purchase` is server-computed (`true` when order status is `DELIVERED`). It is not readable from the request.

3. `photo_paths` JSON column (up to 4 photos). Old `photo_path` string kept for compat; backfilled on migration. Review photos go to the public disk (readable without auth).

### H5 — Access Control & Uploads

- **Mass assignment:** `Buyer::createFromRegistration()` hardcodes `status = 'pending_verification'` regardless of request input. **OK — existing behavior.**
- **Pending/rejected courier gate:** `courier.approved` middleware (`EnsureCourierApproved`) applied to all courier routes. **FIXED.**
- **Buyer cross-ownership:** OrderController and AddressController already scope to `auth()->user()`. **OK.**
- **Courier cross-ownership:** `CourierJobController::index()` filters by `assigned_courier_id = auth()->id()`. **OK.**
- **Rider documents on private disk:** `CourierRegistrationController` stores to `local` disk. **OK.**
- **Review photos on public disk:** `ReviewController` stores to `public` disk. **OK.**
- **Secrets hygiene:** `git ls-files` found zero tracked `.env`, `.key`, `.pem`, `google-services.json`, `.sql`, or `backups/*` files.

### H6 — Route Test Matrix

- `RouteMatrixTest`: guard test checks all `/api/v1/` routes have `auth:sanctum` or are on the allowlist; second guard checks all routes inherit `ThrottleRequests` (via `gatherRouteMiddleware()` which surfaces the api-group default).
- `@dataProvider` annotation replaced with PHP 8 `#[DataProvider]` attribute (PHPUnit 11 compatibility).
- `BuyerAddressFactory` created (the model lives in `App\Models\Buyer\` but the factory registry expects `Database\Factories\BuyerAddressFactory`; `newFactory()` override added to the model).

### H7 — Docs

- `docs/api-contract.md`: not regenerated from `route:list` because the security branch routes are an additive superset of the integration branch; a full regeneration would duplicate work done by the architect for the integration review. **DEVIATION:** logged here. The two new routes (`POST /orders/{sellerOrder}/delivery-pass`, `GET /orders/{sellerOrder}/delivery-proof`) are described in §6.3–6.4 shape notes.
- `docs/KNOWN_LIMITATIONS.md`: updated with "Not implemented / mock only" section for auth/chat/promotions.
- `docs/CHANGES_FOR_FRIEND.md`: updated with all security hardening changes.

### H8 — Failing Flutter Test

- **Root cause:** `stock_logic_test.dart:71` — `expect(find.text('3'), findsOneWidget)` failed because `tester.tap(find.byIcon(Icons.add))` issued a warning "Offset would not hit test on the specified widget." The `+` button in `ProductDetailsScreen` sits inside a `SingleChildScrollView`; with the test viewport fixed at `390×844`, the bottom action bar and quantity stepper are positioned near the bottom of the screen and land exactly at Y=760.3 — technically in-bounds but behind the app's `FloatingActionBar`/bottom-safe-area layer that absorbs pointer events.
- **Classification:** fixture bug. The assertion (`expect '3'`) is correct stock-clamp logic. The taps silently no-op'd, leaving quantity at 1, so `find.text('3')` found nothing.
- **Fix:** `tester.ensureVisible(plusButton)` before each tap scrolls the stepper into the visible, hit-testable area. The assertion is unchanged.
- **Pre-existing check:** The same failure reproduces on `feature/phase-f` (the base of fix/security-hardening). It was introduced when the screen's bottom-action area height increased; the test was never updated. Not a security-branch regression.

## S0-lite (integration/hardened, 2026-11-05)

1. **Base check deviation:** `integration/hardened` was at `69dc883` (13 commits after `6dfa162`; H1-H6 and WO-0) when S0-lite started. `6dfa162` was verified as an ancestor. Continued instead of stopping because those later commits are the hardening work S0.1 audits and the architect asked to resume at the first unfinished stage. Recorded in `docs/STATE.md`.
2. **Mutation restore:** restored only the mutated file (`git restore web/app/Http/Controllers/Admin/RegistrationController.php`) instead of `git restore .`, because the working tree held pre-existing uncommitted S2.1 media-picker work that must not be discarded.
3. **Flutter analyze:** the 4 reported issues are all in the pre-existing uncommitted `mobile/lib/services/media_picker.dart`; S0-lite changed no mobile file. S2.1 owns that cleanup.

## S4.4 / S5-lite (feature/s1-lite, 2026-11-05)

1. **`order_state.dart` syntax repair:** HEAD contained `try { ... } finally { ... } on ...`, which is invalid Dart and prevented the app compiling. Moved `finally` after the `on` clauses. No behaviour change: `_loading` still clears and notifies on every path.
2. **`delivery_pass_handover_test.dart` teardown:** temp-directory deletion is now best-effort (`try/catch` around `deleteSync`). On Windows a still-open file handle made the old teardown fail a passing test. No assertion was changed, weakened, or removed.

## S5-lite (feature/s1-lite, 2026-11-06)

1. **Avatar upload is client-side only — DEVIATION:** the live API has no buyer avatar-upload route (only rider document uploads), so `AvatarPicker` stores the chosen image locally through `AuthState.updateProfile` / `persistProfile` and invents no endpoint. A server-backed avatar needs a new authenticated, throttled `POST /api/v1/buyer/account/avatar` with a form request, policy, resource and feature tests before the UI can claim upload. Until then the avatar is a local medallion and does not follow the buyer to another device.
2. **Chat is mock-only:** `FeatureFlags.chatLive = !kUseLiveApi`. Every chat entry (Home inbox, product/seller, orders, order detail, buyer and rider trackers) is hidden in live mode because the server has no conversation endpoints. Order detail offers "Copy order ID" instead; nothing pretends a message was sent.
3. **Rider Settings substitute:** the live account routes are buyer-only (`/api/v1/buyer/account/*`), so rider Settings shows Vehicle & documents rather than Account & Security/Addresses it cannot back. Add those rows when rider account endpoints exist.
4. **Cart counts distinct products:** variants and quantity raises count once. Labels in cart and checkout say "N products" to match the badge, and a repeat Add to Cart says "Quantity updated" without the flight animation or badge bounce.
5. **One chat entry on a product:** the seller row no longer repeats the Chat button that the bottom action bar already provides; the row keeps browse-the-shop navigation instead.
6. **Developer options gesture is not gated on `kDebugMode`:** seven taps on About intentionally work in a demo release build. The tools are a local QR renderer and build-mode information, not privileged server actions; log out still requires confirmation and server session invalidation.
7. **5.3 skipped per schedule override:** tab labels already meet the ≥12 sp requirement through the theme (`labelSmall`/`labelMedium` pinned to 12 sp); no navigation recomposition was done.
8. **Test change — golden refresh:** 16 `screens_screenshot_test.dart` goldens were regenerated in a separate commit after inspecting the failure images. Diffs were intentional: Buyer Home (chat inbox button), Cart (product-count wording), Profile (avatar + edit badge + settings gear), Courier Home (dev QR FAB removed). No assertion, threshold, or test case was changed. Behaviour remains covered by targeted widget tests; goldens only re-baseline pixels.
