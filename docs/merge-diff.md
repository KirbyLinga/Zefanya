# Merge Diff & Architecture Analysis: Web-Mobile Integration

**Date:** October 29, 2026  
**Base Branch:** `web-api-master` (commit `4e2cb05`)  
**Target Integration Branch:** `integration/mobile-backend`  
**Current Working Branch:** `feature/phase-a`  
**Test Baselines:** Mobile: 709/709 passed; Backend: 205/205 passed (SQLite in-memory)

---

## 1. Tables and Columns Audit

### Existing Tables on `web-api-master`
| Table | Key Columns | Purpose |
|---|---|---|
| `users` | `id`, `name`, `email`, `password`, `role` | Shared web user accounts |
| `admins` | `id`, `name`, `email`, `password` | Platform administrator accounts |
| `buyers` | `id`, `first_name`, `last_name`, `email`, `password`, `contact_no`, `province_code`, `municipality_code`, `barangay_code`, `status` | Registered buyers |
| `sellers` | `id`, `user_id`, `business_name`, `status`, `address` | Seller shops and profiles |
| `logistics_providers` | `id`, `first_name`, `last_name`, `email`, `password`, `contact_no`, `business_name`, `status` | Courier / rider accounts |
| `categories` | `id`, `name`, `slug`, `icon` | Product taxonomy |
| `products` | `id`, `seller_id`, `category_id`, `name`, `price`, `stock_quantity`, `status` | Catalogue items |
| `product_images` | `id`, `product_id`, `image_path`, `is_primary` | Product galleries |
| `cart_items` | `id`, `buyer_id`, `product_id`, `quantity` | Web buyer cart (server-side) |
| `vouchers` | `id`, `code`, `type` (`fixed`/`percent`), `discount_value`, `min_spend`, `status` | Platform & seller vouchers |
| `orders` | `id`, `buyer_id`, `reference`, `shipping_address` (JSON), `subtotal_minor`, `status` | High-level checkout order |
| `seller_orders` | `id`, `order_id`, `seller_id`, `subtotal_minor`, `status` | Per-shop order fulfillment unit |
| `order_items` | `id`, `seller_order_id`, `product_id`, `quantity`, `price_minor` | Line items snapshot |
| `payments` | `id`, `seller_order_id`, `amount_minor`, `method` (`cod`), `status` (`pending`/`paid`) | COD payment records |
| `inventory_transactions` | `id`, `product_id`, `seller_order_id`, `quantity_before`, `quantity_after`, `delta`, `reason` | Stock movement audit trail |
| `personal_access_tokens` | `id`, `tokenable_type`, `tokenable_id`, `name`, `token`, `abilities`, `last_used_at` | Laravel Sanctum API tokens |

### Additive Tables & Columns Required for Mobile Integration
- **`reviews` table**:
  - `id`, `seller_order_id` (FK), `product_id` (FK nullable), `buyer_id` (FK), `stars` (1-5), `comment`, `photo_paths` (JSON, up to 4 photos), `verified_purchase` (bool), `created_at`, `updated_at`.
- **`psgc_regions`, `psgc_provinces`, `psgc_cities`, `psgc_barangays` reference tables**:
  - Normalized PSGC tables seeded from `mobile/assets/data/ph_psgc.json` via idempotent seeder.
- **`chat_messages` table**:
  - `id`, `sender_type`, `sender_id`, `recipient_type`, `recipient_id`, `thread_key`, `body`, `created_at`.
- **`promotions` table**:
  - `id`, `eyebrow`, `title`, `subtitle`, `layout`, `ground`, `icon`, `big_icon`, `active`, `sort_order`.
- **Additive columns on `seller_orders`**:
  - `assigned_courier_id` (FK nullable to `logistics_providers`).
  - `delivery_area` (varchar nullable).
  - `delivery_fee_minor` (int default 0).
  - `handover_code_hash` (varchar nullable, 6-digit code bcrypt/sha256).
  - `handover_expires_at` (timestamp nullable).
  - `handover_attempts` (tinyint default 0).
  - `handover_token_nonce` (varchar nullable).
  - `pickup_verified_at` (timestamp nullable).
  - `delivered_at` (timestamp nullable).
  - `failure_reason` (varchar nullable).
  - `failure_attempt_count` (tinyint default 0).
  - `failure_photo_path` (varchar nullable).
- **Additive columns on `buyers`**:
  - `pending_email`, `pending_email_otp`, `pending_phone`, `pending_phone_otp`.
- **Additive columns on `sellers`**:
  - `maker_note` (text nullable).

---

## 2. Order and Delivery Statuses

### Server `OrderStatus` Enum (`web/app/Enums/OrderStatus.php`)
13 uppercase values:
- `PLACED`: Order committed by buyer.
- `CONFIRMED`: Acknowledged by seller.
- `PREPARING`: Packed by seller.
- `READY_FOR_PICKUP`: Marked by seller ready for courier collection.
- `PICKED_UP`: Courier collected parcel from seller.
- `AT_SORTING_CENTER`: Arrived at logistics sorting hub.
- `SORTED`: Processed at hub.
- `ASSIGNED_TO_RIDER`: Assigned to local delivery rider.
- `OUT_FOR_DELIVERY`: Rider is en route to buyer address.
- `DELIVERED`: Handed over to buyer (confirmed by code / signed QR).
- `COMPLETED`: Delivery finalized and payment collected.
- `DELIVERY_FAILED`: Attempted but unsuccessful.
- `RETURNED`: Returned back to seller after max failures (3 attempts).

### Mobile Status Projections
1. **Buyer 4-Tab View**:
   - `toShip`: `PLACED`, `CONFIRMED`, `PREPARING`, `READY_FOR_PICKUP`.
   - `inTransit`: `PICKED_UP`, `AT_SORTING_CENTER`, `SORTED`, `ASSIGNED_TO_RIDER`.
   - `outForDelivery`: `OUT_FOR_DELIVERY`.
   - `toRate`: `DELIVERED`, `COMPLETED`.
2. **Courier 10-Milestone Pipeline**:
   - `assigned` -> `arrivedAtSeller` -> `pickedUp` -> `arrivedAtHub` -> `sorted` -> `departedHub` -> `arrivedAtDeliveryArea` -> `outForDelivery` -> `arrivedAtBuyer` -> `delivered` (or `deliveryFailed`).
   - Intermediate micro-steps (e.g. `arrivedAtSeller`) are tracked in courier milestone logs and mapped to the closest canonical `OrderStatus`.

---

## 3. Role Names and Guard Mapping

| Context | Web Model & Guard | Mobile App DTO | UI Presentation | Authority Source |
|---|---|---|---|---|
| Buyer | `App\Models\Buyer\Buyer`<br>`auth:buyer` / `auth:sanctum` | `UserRole.buyer` | "Shopper" / "Buyer" | Authenticated token |
| Rider / Courier | `App\Models\Logistics\LogisticsProvider`<br>`auth:logistics` / `auth:sanctum` | `UserRole.courier` | "Rider" | Authenticated token (`logistics_providers` table) |
| Seller | `App\Models\Seller\Seller`<br>`auth:seller` | N/A (Web & API Hooks) | "Seller" | Authenticated session / token |
| Admin | `App\Models\Admin\Admin`<br>`auth:admin` | N/A (Web & Admin API) | "Admin" | Authenticated session |

**Rule**: The server determines role authority strictly from the token holder's database model (`Buyer` vs `LogisticsProvider`). The client never submits a role claim to authorise access.

---

## 4. Auth Methods

- **Web Portal**: Stateful HTTP sessions with independent guard cookies (`buyer`, `seller`, `admin`, `logistics`).
- **Mobile Client**: Stateless bearer tokens issued by Laravel Sanctum (`personal_access_tokens`).
- **Endpoints**:
  - `POST /api/login/buyer` -> Sanctum token for `Buyer`.
  - `POST /api/login/courier` -> Sanctum token for `LogisticsProvider`.
  - `POST /api/register/buyer` -> Creates buyer with OTP verification.
  - `POST /api/register/courier` -> Submits rider application with documents (`status: pending_approval`).
  - `GET /api/me` / `GET /api/me/courier` -> Current user profile & verified role.
  - `POST /api/logout` / `POST /api/logout/courier` -> Revokes current Sanctum token.
- **Google Sign-In**:
  - Client sends Google ID token.
  - Server verifies token cryptographically using `google/apiclient` with configured client IDs.
  - Links account by verified email.
  - If new account, user selects role: buyer is active; rider is assigned `pending_approval` awaiting admin review.

---

## 5. Existing Routes Inventory

### Web Routes (`routes/web.php`, `routes/buyer.php`, `routes/seller.php`, `routes/admin.php`, `routes/logistics.php`)
- **Public & Guest**: Landing, Buyer login/register, Seller login/register, Admin login.
- **Buyer (`routes/buyer.php`)**: Cart (`buyer.cart.index`, `store`, `update`, `destroy`), Checkout (`buyer.checkout.index`, `store`), Orders list & detail.
- **Seller (`routes/seller.php`)**: Dashboard, Product management (CRUD), Order fulfillment.
- **Logistics (`routes/logistics.php`)**: Dashboard (`logistics.dashboard`).
- **Admin (`routes/admin.php`)**: Dashboard, unified registration review (`registrations.buyer.approve/reject`, `registrations.seller.approve/reject`, `registrations.logistics.approve/reject`).

### Existing API Routes (`routes/api.php` on base)
- Public:
  - `POST /api/register/buyer`
  - `POST /api/register/buyer/verify-otp/{buyer}`
  - `POST /api/register/buyer/verify-otp/{buyer}/resend`
  - `POST /api/login/buyer`
  - `GET /api/products`, `GET /api/products/{product}`
  - `GET /api/categories`
  - `GET /api/vouchers`
- Authenticated (`auth:sanctum`):
  - `GET /api/me`
  - `POST /api/logout`

---

## 6. Rules Living in Controllers & Refactoring Plan

| Controller | Business Logic Embedded in Controller | Target Service / Action Class |
|---|---|---|
| `Buyer/CheckoutController.php` | Row-level stock locking, order generation, multi-seller splitting, COD payment creation, inventory ledger writes, largest-remainder voucher discount allocation. | `App\Services\OrderPlacer` (reused across Web and Mobile API). |
| `Buyer/RegisterBuyerController.php` | Profile data validation, password hashing, 6-digit OTP generation, welcome notification. | `App\Services\Auth\BuyerRegistrationService`. |
| `Admin/RegistrationController.php` | Approval/rejection transitions for buyers, sellers, and logistics providers. | `App\Actions\ApproveRegistrationAction`, `App\Actions\RejectRegistrationAction`. |
| New Handover Flow | 6-digit hashed code generation, HMAC token signing for QR, attempt rate limiting, COD collection verification. | `App\Services\DeliveryHandoverService`. |

---

## 7. The 10 Highest-Risk Conflicts with the Mobile App (Ranked)

1. **Handover Security & COD Delivery Gate (Rank 1)**:
   - *Risk*: Discrepancy between mobile client-side simulation and server authority. Delivery could be spoofed without valid physical proof or cash handover.
   - *Mitigation*: Server generates 6-digit code (hashed, valid 30m, 5 attempts max) and HMAC signed QR token (`order_id`, `nonce`, `expiry`). Server validates code/QR before transitioning COD order to `DELIVERED`.

2. **Role Naming & Guard Fragmentation (`logistics` vs `courier`/`rider`) (Rank 2)**:
   - *Risk*: Web uses table `logistics_providers` and guard `logistics`; mobile uses `courier` and UI copy "Rider". Cross-role authentication failure if client sends ambiguous claims.
   - *Mitigation*: Separate endpoints `/login/buyer` and `/login/courier`. The server determines role strictly from table identity. DTO mapper handles `logistics` <-> `courier`.

3. **Multi-Seller Order Splitting & Row Locking (Rank 3)**:
   - *Risk*: Race conditions during simultaneous checkouts causing negative inventory or mismatched totals across seller sub-orders.
   - *Mitigation*: Reusable `OrderPlacer` service wraps row locks (`lockForUpdate` sorted by ID) and largest-remainder voucher allocation in a single DB transaction.

4. **Address Structure & PSGC Normalization (Rank 4)**:
   - *Risk*: Mobile has a rich 5-level address cascade with NCR special cases; Web stores loose strings (`province_name`, `municipality_name`).
   - *Mitigation*: Load official PSGC data into idempotent reference tables; orders preserve an immutable snapshot in `shipping_address` JSON.

5. **Delivery Failure & Return-to-Seller Circuit (Rank 5)**:
   - *Risk*: Web lacks structured failure handling, attempt counting, and return-to-seller transitions.
   - *Mitigation*: Add `failure_reason`, `failure_attempt_count`, and `failure_photo_path` to `seller_orders`. Automatically transition to `RETURNING_TO_SELLER` on 3rd failure.

6. **Google Sign-In Server Verification & Pending Rider State (Rank 6)**:
   - *Risk*: Insecure client-side auth tokens or unauthorized riders gaining instant active status.
   - *Mitigation*: Server verifies Google ID token via `google/apiclient`. Riders are always created in `pending_approval` status requiring admin review.

7. **Reviews & Ratings Constraints (Rank 7)**:
   - *Risk*: Spam reviews on non-delivered items or unverified purchases.
   - *Mitigation*: Review creation constrained strictly to delivered order items (1 review per item), up to 4 photos, verified badge, masked user display name.

8. **OrderStatus Granularity Mismatch (13 states vs 4/10 states) (Rank 8)**:
   - *Risk*: Unhandled server status crashes client or displays inaccurate progress.
   - *Mitigation*: `RemoteOrderStatus` enum in `remote_status.dart` maps server strings to buyer tabs and courier milestones with a safe `unknown` fallback.

9. **Cart Architecture Duality (Client vs Server) (Rank 9)**:
   - *Risk*: Web uses `cart_items` table; mobile uses client-side `CartState`.
   - *Mitigation*: Mobile sends line item list `[{product_id, quantity}]` to `POST /api/orders`; server validates lines against live DB rows without requiring server-side cart sync.

10. **Sanctum Multi-Table Token Resolution (Rank 10)**:
    - *Risk*: Sanctum default resolving tokenable model ambiguously if IDs overlap between `buyers` and `logistics_providers`.
    - *Mitigation*: Explicit morph map or table resolution on `auth:sanctum` guards and dedicated role profile routes (`/me` vs `/me/courier`).

---

## 8. Merge Order

1. **Phase A (Baseline)**: Establish `integration/mobile-backend` from `web-api-master`, retain full mobile test suite (709 passing), mysqldump DB backup, baseline verification.
2. **Phase B (Mobile Fixes)**:
   - B1: Login switch (buyer <-> rider toggle: presentation only, server role routing, Google rider application flow).
   - B2: Account details contrast & layout visibility across profile, edit profile, and rider vehicle cards.
3. **Phase C (Backend Vertical Slices)**:
   - C1 Auth: Sanctum multi-guard login/logout/me, rider document registration, Google ID token verification.
   - C2 Addresses: PSGC tables & seeder, address CRUD, order snapshot.
   - C3 Catalog & Reviews: Product search/filtering, review submission with photos, ratings summary.
   - C4 Orders & Delivery: `OrderPlacer` service, seller order splitting, rider job feed, failure flow with return-to-seller.
   - C5 Delivery Handover: 6-digit code hash, HMAC signed token QR, pickup verification, proof-of-delivery flow.
4. **Phase D (End-to-End Acceptance)**: Automated & manual end-to-end verification across real database & HTTP API.
5. **Phase E (Design Polish)**: Screen-by-screen editorial token fidelity.
6. **Phase F (Features Polish)**: Photo reviews UI, voucher wallet, home category grid, motion polish.
