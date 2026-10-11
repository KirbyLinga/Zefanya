# Zefanya — Project Context

> **Purpose:** A single-source-of-truth context document for any AI agent or developer joining the Zefanya codebase. Replaces the outdated `SYSTEM_FLOW_AUDIT.md` (dated 2026-09-25/10-02) with the current state as of 2026-10-05.
> **Verification:** `php artisan test --compact` → **341 passed (1840 assertions)**; `vendor/bin/pint --dirty` clean; `php artisan route:list` → 112 routes.

---

## 1. Overview

**Zefanya** is a **multi-vendor e-commerce marketplace** (Shopee/Lazada-style workflow, original UI) built as a **monolithic Laravel 13 app** on PHP 8.5.

| Tech | Stack |
|------|-------|
| Framework | Laravel 13 (PHP 8.5) |
| Database | MySQL primary, SQLite test; portable SQL (no `JSON_CONTAINS` raw queries) |
| Frontend | Blade + Tailwind CSS v4 (via Vite) |
| Auth | 5 session guards: `web`, `admin`, `buyer`, `seller`, `logistics` |
| Icons | Lucide (`<i data-lucide>`) + Font Awesome |
| Fonts | Montserrat (sans), Playfair Display (serif) |
| Testing | PHPUnit via `php artisan test` |
| Code style | Laravel Pint (`--dirty --format agent` required after PHP edits) |

### Five Roles

| Role | Guard | Session table | Scope |
|------|-------|---------------|-------|
| **Buyer** | `buyer` | `buyers` | Browse catalog, cart, checkout, track orders, manage account |
| **Seller** | `seller` | `sellers` | Product management, inventory, order fulfilment through READY_FOR_PICKUP |
| **Logistics** | `logistics` | `logistics_providers` | Owns entire courier lifecycle — pickup, sorting, delivery |
| **Admin** | `admin` | `admins` | Marketplace admin only; does **not** manage couriers (logistics owns that) |
| **Courier** | — | `couriers` | **Not implemented as a login actor** (see §8) |

---

## 2. Architecture

### Core Data Flow

```
Checkout → orders(PLACED) → seller_orders(N×PLACED, one per shop) → order_items
                          → payments(1:1 per seller_order, pending COD)
                          → inventory_transactions(append-only ledger)
                          → status_histories(audit trail per transition)
```

### Key Conventions

- **Money** = unsigned integer **centavos**, columns suffixed `*_minor`. Never floats.
- **Status columns** = VARCHAR + PHP backed enums (never MySQL ENUM).
- `orders.shipping_address` = JSON **snapshot** (not a live FK).
- `orders.reference` = `ORD-YYYYMMDD-XXXXXX` (unique).
- **No API layer** — `routes/api.php` does not exist. All routes are web/UI.
- **No queues/jobs/events** — fully synchronous.
- **Single writer for status**: only `SellerOrderStatusService::transition()` mutates `seller_orders.status`.

### Auth & Guards (`config/auth.php`)

```
guards: web, admin, buyer, seller, logistics
providers: users → Shared\User, admins → Admin\Admin, buyers → Buyer\Buyer,
           sellers → Seller\Seller, logistics → Logistics\LogisticsProvider
```

- `UnifiedLoginController` tries guards in order: **seller → logistics → buyer**.
- `GoogleAuthController` is **LINK-only** — never creates accounts; matches Google email to existing rows.
- All buyer/seller/logistics accounts must have `status = 'approved'` to log in.
- `redirectGuestsTo` in `bootstrap/app.php`: buyer paths → `buyer.login`, seller → `seller.login`, admin → `admin.login`, logistics → `login`.

---

## 3. Order Lifecycle (Current State)

### 13-State Pipeline (`App\Enums\OrderStatus`)

```
PLACED → CONFIRMED → PREPARING → READY_FOR_PICKUP
  → ASSIGNED_TO_RIDER → PICKED_UP → AT_SORTING_CENTER
  → SORTED → ASSIGNED_TO_RIDER(delivery) → OUT_FOR_DELIVERY
  → DELIVERED → COMPLETED
```

| Status | Who Sets It | How |
|--------|-------------|-----|
| `PLACED` | Buyer (checkout) | `CheckoutController@store` |
| `CONFIRMED` | Seller | `Seller\OrderController@acceptOrder` (`POST /seller/orders/{sellerOrder}/accept`) |
| `PREPARING` | Seller | `Seller\OrderController@markPreparing` (admin-markReady path) |
| `READY_FOR_PICKUP` | Seller | `Seller\OrderController@markPreparing` → `markReady` chain OR direct `markReady` |
| `ASSIGNED_TO_RIDER` | System | `CourierAssignmentService::assignPickup()` — auto-assigns pickup courier |
| `PICKED_UP` | Logistics | `IncomingParcelsController@pickup` — releases pickup courier |
| `AT_SORTING_CENTER` | Logistics | `IncomingParcelsController@toSortingCenter` |
| `SORTED` | Logistics | `ParcelSortingController@sort` |
| `ASSIGNED_TO_RIDER` (delivery) | System | `CourierAssignmentService::assignDelivery()` |
| `OUT_FOR_DELIVERY` | Logistics | `DeliveryAssignmentsController@start` |
| `DELIVERED` | Logistics | `DeliveryAssignmentsController@delivered` — releases delivery courier |
| `DELIVERY_FAILED` | Logistics | `DeliveryAssignmentsController@failed` (two-step form) |
| `COMPLETED` | Buyer | `Buyer\OrderController@confirmReceived` (`POST /buyer/orders/{order}/confirm-received`) |

### Service Layer (Key Files)

- **`app/Services/SellerOrderStatusService.php`** — single writer for `seller_orders.status`; enforces `ALLOWED` transition graph; creates `StatusHistory` rows; fires notifications via `maybeNotify()`.
- **`app/Services/CourierAssignmentService.php`** — split into `assignPickup()` (matches pickup courier: `READY_FOR_PICKUP → ASSIGNED_TO_RIDER`) and `assignDelivery()` (matches delivery courier: `SORTED → ASSIGNED_TO_RIDER`). Uses `SellerOrder::parcelWeightGrams()` + `buyerMunicipality()` for candidate matching. Deprecated `assign()` delegates to `assignDelivery()`.

### State Machine Rules

```php
// app/Services/SellerOrderStatusService.php — ALLOWED graph (forward-only)
self::Placed             => [self::Confirmed, self::Preparing, self::ReadyForPickup],
self::ReadyForPickup     => [self::AssignedToRider, self::AtSortingCenter],
self::AssignedToRider    => [self::PickedUp],
self::PickedUp           => [self::AtSortingCenter],
self::AtSortingCenter    => [self::Sorted],
self::Sorted             => [self::AssignedToRider],
self::OutForDelivery     => [self::Delivered, self::DeliveryFailed],
self::DeliveryFailed     => [self::Returned],
```

### `status_histories` Table

- Columns: `id`, `seller_order_id`, `from_status`, `to_status`, `changed_by` (string), `actor_id` + `actor_type` (polymorphic, nullable), `notes`, `created_at`.
- Every transition appends one row inside the same transaction.
- `changed_by` = actor's name/role. `actor_id`/`actor_type` = polymorphic relation to the acting model (Buyer, Seller, LogisticsProvider, or NULL for system).

---

## 4. Roles & Flows by Actor

### Buyer Flow (Working)

1. **Register** → OTP via email → `pending_approval` → admin approves → `approved`
2. **Browse** catalog (public), **add to cart** (stock-capped, no reservation)
3. **Buy Now** — `POST /buyer/checkout/buy-now` (`buyer.checkout.buy-now`): creates a single-item cart line (sets, not adds, quantity) and redirects to `/buyer/checkout?items[0]=...` scoped to that one line. Guest → opens the login modal via sessionStorage-backed pending action (10-minute TTL, cleared on modal close).
3. **Checkout** → one `DB::transaction`: locks product rows (`lockForUpdate` ordered by id), re-validates stock, creates `orders` + `seller_orders` + `order_items` + `payments` (pending COD) + `inventory_transactions` (reason=`checkout`), clears cart lines; sets `orders.status` to `Placed` via `forceFill`
4. **Track** orders on `/buyer/orders/{order}` — full `status_histories` timeline, tracking number, courier info
5. **Confirm received** → each Delivered seller_order becomes Completed via `SellerOrderStatusService::transition()`; gated by `Order::canBeConfirmed()` (every slice Delivered-or-Completed, at least one Delivered); runs inside `DB::transaction` in `OrderController@confirmReceived`; 404s if order doesn't belong to buyer

**Implemented (2026-10-05):** parent order status rollup (`Order::rollUpStatus()` / `computeRollupStatus()`) now aggregates seller_order progress; COD payment settlement (`Payment::markPaid()` called in `SellerOrderStatusService` on Delivered/Completed); multi-seller confirm gate (`Order::canBeConfirmed()`) prevents stranding slices.

### Seller Flow (Working)

1. **Register** → OTP → admin approve → `approved`
2. **Dashboard** (`/seller/dashboard`) — real product/stock/order/sales stats, 7-day sales trend, low-stock alerts
3. **Products** — full CRUD + images + status toggle + ownership checks (`Seller\ProductController`)
4. **Orders** — `/seller/orders` lists `seller_orders` scoped to `seller_id` with buckets (by status)
5. **Accept order** (`POST /seller/orders/{sellerOrder}/accept`) → `PLACED → CONFIRMED → PREPARING`
6. **Mark ready** (`POST /seller/orders/{sellerOrder}/mark-ready`) → `→ READY_FOR_PICKUP`; auto-triggers `CourierAssignmentService::assignPickup()` (may warn if no courier matches)

**Known gaps:** No seller notification for new orders (bell reads DB channel, but seller notifications are mail-only → bell always empty); stock edits bypass transaction/ledger.

### Logistics Flow (Implemented — Phase 1-6)

All behind `auth:logistics` + `logistics.approved`:

| Route | Controller | Action |
|-------|------------|--------|
| `GET /logistics` | `DashboardController@index` | Placeholder dashboard (stats show "—") |
| `GET|POST /logistics/incoming-parcels` | `IncomingParcelsController` | List unassigned `READY_FOR_PICKUP` + pick up (`PICKED_UP`) + handoff to sorting (`AT_SORTING_CENTER`) |
| `GET|POST /logistics/parcel-sorting` | `ParcelSortingController` | Two tabs: "At Sorting Center" (`SORTED` candidates) + "Assigned for Delivery"; `sort()` + `assignDelivery()` |
| `GET|POST /logistics/delivery-assignments` | `DeliveryAssignmentsController` | List `ASSIGNED_TO_RIDER` parcels for own fleet; `start()` (`OUT_FOR_DELIVERY`), `delivered()` (`DELIVERED`), `failed.form()` + `failed()` (two-step delivery failure) |
| `GET /logistics/delivery-monitoring` | `DeliveryMonitoringController` | Read-only list of in-progress deliveries (own fleet only) |

**Courier Assignment (Provider Isolation):**

- Only the logistics provider that **claims** a parcel can see/act on it.
- `CourierAssignmentService` accepts `?LogisticsProvider $provider`; validates `assertCourierBelongsToProvider()`.
- Uses `lockForUpdate()` + re-checks concurrent claims.
- Incoming Parcels page **redacts** buyer name until this provider's fleet claims it.
- Unclaimed rows visible to any provider (first-come-first-served); claimed rows invisible to others (403, no state change, no history row).

**Failed Delivery:**

- `DeliveryFailureReason` enum: `address_not_found`, `recipient_unavailable`, `refused_by_recipient`, `parcel_damaged`, `wrong_address`, `other`.
- Two-step: `failed.form` (GET, shows form) → `failed` (POST, validates + transitions → `DELIVERY_FAILED` with reason + notes).
- Flash message: `warning` (deliberate — failure is not a success).

### Admin Flow (Working)

- **Register** → unified login via `/admin/login` (separate `admin` session guard)
- **Dashboard** (`/admin/dashboard`) — real counts of active/approved users + pending registrations
- **Registrations** (`/admin/registrations`) — unified queue with `?filter=buyer|seller|logistics` + `?q=` search; approve/reject with required `rejection_reason`
- **Document Streaming** (`GET /admin/documents/{type}/{id}/{document}`) — serves files from private disk via `Admin\DocumentController@stream` (behind `auth:admin`, headers: `X-Content-Type-Options: nosniff`)

---

## 5. Notifications

`app/Notifications/` — **9 classes**:

| Channel | Notifications | Purpose |
|---------|--------------|--------|
| **Mail** | `Buyer\BuyerVerifyOtp`, `Buyer\BuyerRegistrationDecision`, `Seller\SellerRegistrationOtp`, `Seller\SellerRegistrationDecision`, `Logistics\LogisticsRegistrationOtp`, `Logistics\LogisticsRegistrationDecision` | Registration flow + OTP |
| **Database** | `Buyer\CourierAssigned`, `Buyer\OrderOutForDelivery`, `Buyer\OrderDelivered` | Buyer milestone notifications (written but no buyer UI reads them) |

**Fired by** `SellerOrderStatusService::maybeNotify()`:
- `CourierAssigned` → when transitions into `ASSIGNED_TO_RIDER` (pickup)
- `OrderOutForDelivery` → when transitions into `OUT_FOR_DELIVERY`
- `OrderDelivered` → when transitions into `DELIVERED`

---

## 6. Testing

- **341 tests, 1840 assertions, all green**
- Run: `php artisan test --compact` (or `vendor/bin/phpunit`)
- Format: `vendor/bin/pint --dirty --format agent` after any PHP change
- Key test files:
  - `tests/Feature/FulfillmentLifecycleTest.php` — full 13-state pipeline
  - `tests/Feature/CourierAssignmentTest.php` — assignment + provider isolation + concurrency
   - `tests/Feature/Http/Controllers/Buyer/CheckoutControllerTest.php` — checkout (10 tests)
   - `tests/Feature/Http/Controllers/Buyer/BuyNowTest.php` — Buy Now single-item checkout (6 tests)
   - `tests/Feature/Http/Controllers/Buyer/CartControllerTest.php` — cart behavior
  - `tests/Feature/Buyer/BuyerAccountPagesTest.php` — account pages (10 tests)
  - `tests/Feature/AdminDocumentStreamingTest.php` — admin document access
  - `tests/Feature/Console/OrdersResyncCommandTest.php` — resync command (--dry-run, real run, idempotency)
  - `tests/Feature/Buyer/BuyerOrderLifecycleTest.php` — confirm gate + rollback behavior

---

## 7. Conventions & Rules

### Code Style (Pint)

- `no_unused_imports` + `ordered_imports` enforced
- Run `vendor/bin/pint --dirty --format agent` after PHP changes
- Curly braces on all control structures, even single-line

### File Locations

```
app/
  Enums/         — OrderStatus, PaymentStatus, VoucherType, DeliveryFailureReason, ...
  Http/
    Controllers/{Buyer,Seller,Logistics,Admin,Auth}/
    Requests/     — Form requests (StoreBuyerRegistrationRequest, StoreProductRequest, ...)
    Middleware/{Buyer,Seller,Logistics,Admin}/
  Models/         — Order, SellerOrder, OrderItem, Payment, Product, CartItem, ...
  Models/{Buyer,Seller,Logistics,Admin,Shared}/
  Policies/       — SellerOrderPolicy, BuyerOrderPolicy, SellerOrderFleetPolicy
  Services/       — CourierAssignmentService, SellerOrderStatusService, CartSummary
  Notifications/  — {Buyer,Seller,Logistics}/...
  Exceptions/     — CheckoutException
  Support/        — RuntimeSchema

database/
  migrations/     — 2026_MM_DD_HHMMSS_snake_case_name.php
  factories/      — {Model}Factory
  seeders/        — DatabaseSeeder, ProductSeeder, VoucherSeeder, ...

routes/           — web.php, buyer.php, seller.php, logistics.php, admin.php
resources/
  views/{Buyer,Seller,Logistics,Admin,Shared,Auth,Components}/
  views/Components/logistics-flash.blade.php  — reusable flash
  css/           — app.css (Tailwind config), login-modal.css, auth.css
  js/{buyer,seller,logistics}/
docs/            — coding checklists, folder-structure, Context.md
.memory-bank/    — project-overview.md, mistakes.md
```

### Security Rules

- `$fillable` allow-lists only; never `$guarded = []`
- `status`/`courier_id` never mass-assignable from requests
- Stock ops: `DB::transaction()` + `lockForUpdate()` + `inventory_transactions` row
- Ownership scoping: buyer sees own cart/orders; seller sees own seller_orders; logistics sees own fleet
- Vouchers always re-validated server-side

### Frontend

- No unlayered CSS; custom CSS only in `@layer components` in `app.css`
- PSGC address lookup: `resources/js/buyer/address-lookup.js` (shared module); must be loaded BEFORE consuming scripts
- Shared `Components/logistics-flash.blade.php` for flash messages in logistics views
- Flash keys: `success` for success, `warning` for failures

---

## 8. Outstanding Items (Not Yet Implemented)

### Critical (blocks core flow)

1. **Courier as login actor** — `couriers` has no email/password columns, no `courier` guard, no routes, no UI. The entire courier lifecycle is simulated by the Logistics panel.
2. **Courier lifecycle** — `courier_applications → couriers` flow (Logistics-owned) has no tables, model, or UI.
3. **Order cancellation/returns** — no statuses wired, no routes, no restock path.

### High

1. **Buyer notification UI** — database notifications written but no bell/page reads them.
2. **Seller new-order notification** — bell reads DB channel but seller notifications are mail-only → bell always empty.
3. **Vouchers `times_used`** — never incremented; `usage_limit` inert.
4. **`admin.role` middleware** — declared but applied to zero routes (all admins are effectively super-admins).

### Medium

1. **Admin dashboard stats** — shows "—" (placeholders); needs real implementation.
2. **Logistics dashboard stats** — all placeholder values.
3. **Logistics sidebar dead links** — reduced but some items may still be stubs.
4. **Seller stock edits** — bypass transaction/ledger.
5. **Reviews/ratings** — PDP has no mock data; the reviews table, rating columns, and review UI are not yet implemented.
6. **Seller storefront** — "Visit Boutique" link removed; no storefront page exists.
7. **Real chat** — `buyer.chat.index`/`buyer.chat.show` routes exist but `ChatController` redirects to `buyer.home` with a "Chat is coming soon" flash message; the static chat panel was removed from the layout.
8. **Wishlist** — no routes or handler exist; the wishlist heart was removed from the navbar, product cards, and JS.
9. **Shipping-fee model** — checkout says "Calculated at checkout"; no shipping-fee column or logic exists yet.
10. **Returns policy** — no returns flow (see Outstanding Item §1).

### Recently removed from the PDP (2026-10-05)

- Quick-view modal and its JS (`openQuickView`/`closeQuickView`, `.swatch`, `.size-opt`, `#qvQty`) — deleted from `app.blade.php`, `buyer.js`, `home.js`; product-card `QUICK VIEW` button replaced with a plain PDP link.
- Static chat panel (`toggleChat`, hardcoded conversation, "Chat with Seller" / "Messages" links) — removed from `app.blade.php`, `buyer.js`, footer, and product-card docblock.
- Wishlist heart (navbar, product-card, `home.js`) — no route exists.
- PDP mockup data: old price/discount, rating/review-count/sold-count, features array, fake reviews, seller badge/rating/followers, trust-badge row ("Complimentary Shipping"/"Secure Checkout"/"Easy Returns"), "Editor's Exclusive" badge, placeholder thumbnail icons.

---

## 9. Key Files Reference

### Services
- `app/Services/SellerOrderStatusService.php` — single-writer state machine
- `app/Services/CourierAssignmentService.php` — courier matching (assignPickup/assignDelivery)
- `app/Services/CartSummary.php` — cart math (source of truth for money)

### Console Commands
- `app/Console/Commands/OrdersResyncCommand.php` — `orders:resync` with `--dry-run` for reconciling stale parent order statuses + settling COD payments

- `app/Http/Controllers/Buyer/OrderController.php` — `show()` (loads sellerOrders.payment, passes `canConfirm` to view) + `confirmReceived()` (ownership gate + `canBeConfirmed()` check + `DB::transaction` wrapping all slice transitions)
- `app/Http/Controllers/Seller/OrderController.php` — acceptOrder, markPreparing, markReady
- `app/Http/Controllers/Logistics/IncomingParcelsController.php` — rider pickup flow
- `app/Http/Controllers/Logistics/ParcelSortingController.php` — sorting center flow
- `app/Http/Controllers/Logistics/DeliveryAssignmentsController.php` — delivery + failed delivery
- `app/Http/Controllers/Logistics/DeliveryMonitoringController.php` — read-only monitoring
- `app/Http/Controllers/Admin/DocumentController.php` — document streaming

### Models
- `app/Models/SellerOrder.php` — deliveryCourier(), parcelWeightGrams(), buyerMunicipality()
- `app/Models/Courier.php` — is_available, max_weight_grams, service_area
- `app/Models/OrderStatus.php` — (enum) 13 states with label() + bucket()
- `app/Models/DeliveryFailureReason.php` — (enum) 6 failure reasons

### Policies
- `app/Policies/SellerOrderPolicy.php`
- `app/Policies/BuyerOrderPolicy.php`
- `app/Policies/SellerOrderFleetPolicy.php` (gate: `advance-shipment`)

### Routes
- `routes/web.php` — landing, auth, Google, home
- `routes/buyer.php` — all buyer routes (auth:buyer)
- `routes/seller.php` — all seller routes (auth:seller + seller.approved)
- `routes/logistics.php` — all logistics routes (auth:logistics + logistics.approved)
- `routes/admin.php` — all admin routes (auth:admin)

---

## 10. Verification Commands

```bash
php artisan test --compact        # 341 passed
vendor/bin/pint --dirty          # format check
php artisan route:list --name=buyer.cart
php artisan config:show app.name
php artisan migrate:status
npm run build                    # frontend assets
```
