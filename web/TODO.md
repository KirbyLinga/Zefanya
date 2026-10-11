# Order Fulfillment Flow — Progress Tracker

> **Rule**: Every checkbox must be verified against actual code (models, migrations, controllers, routes, views, tests) before marking. Re-verify on each update — do not trust prior claims.

## Status Legend
- [x] Done & verified (file/route/test confirmed to exist and pass)
- [~] Partially done (exists but incomplete/broken — note what's missing)
- [ ] Not started

---

## Phase 1: Data Layer

- [x] **tracking_number + courier_id on seller_orders**
  - Migration: `2026_09_25_000003_add_tracking_and_courier_to_seller_orders_table.php`
  - Model: `app/Models/SellerOrder.php` — `$fillable` includes both, relationships `courier()`, `statusHistories()`
  - Evidence: app/Models/SellerOrder.php + database/migrations/2026_09_25_000003_add_tracking_and_courier_to_seller_orders_table.php — confirmed, both columns + both relationships present

- [x] **weight_grams + dimensions on products**
  - Migration: `2026_09_25_000001_add_parcel_dimensions_to_products_table.php` — adds `weight_grams` (unsigned int, default 0) + a single JSON `dimensions` column (not four separate length/width/height columns as previously claimed)
  - Model: `app/Models/Product.php` — `$fillable` + `$casts` include both (`dimensions` cast to array)
  - Evidence: database/migrations/2026_09_25_000001_add_parcel_dimensions_to_products_table.php + app/Models/Product.php — confirmed, weight_grams + JSON dimensions present

- [x] **status_histories table (append-only audit log)**
  - Migration: `2026_09_25_000004_create_status_histories_table.php` (seller_order_id, from_status, to_status, changed_by, note)
  - Model: `app/Models/StatusHistory.php` — static `record()` method writes one row per transition
  - Evidence: database/migrations/2026_09_25_000004_create_status_histories_table.php + app/Models/StatusHistory.php — confirmed, `record()` called from `SellerOrderStatusService::transition()` inside a DB transaction

- [x] **couriers table + seeder**
  - Migration: `2026_09_25_000002_create_couriers_table.php` (logistics_provider_id, name, phone, vehicle_type, max_weight_grams, is_available, service_area JSON)
  - Model: `app/Models/Courier.php` — `HasFactory`, relationships `provider()` + `sellerOrders()`, `servesMunicipality()` helper (no `available()` query scope — availability is filtered in `CourierAssignmentService`)
  - Seeder: `database/seeders/CourierSeeder.php` — 5 couriers across 1 logistics provider (not 3 as previously claimed), varied service_area + max_weight
  - Factory: `database/factories/CourierFactory.php`
  - Evidence: database/migrations/2026_09_25_000002_create_couriers_table.php + app/Models/Courier.php + database/seeders/CourierSeeder.php + database/factories/CourierFactory.php — all confirmed to exist

---

## Phase 2: Status Transition Service

- [x] **SellerOrderStatusService with legal transitions map**
  - File: `app/Services/SellerOrderStatusService.php`
  - `ALLOWED` const defines forward-only graph: Placed→ReadyForPickup→AssignedToRider→PickedUp→AtSortingCenter→OutForDelivery→Delivered→Completed (plus branches: Confirmed/Preparing, Sorted, DeliveryFailed/Returned)
  - `transition()` validates, persists status + tracking_number (auto on PickedUp), records `StatusHistory`, fires buyer notifications
  - Throws `InvalidArgumentException` on illegal hops
  - Evidence: app/Services/SellerOrderStatusService.php — confirmed, ALLOWED graph + validation + tracking auto-gen + notifications all present; exercised by FulfillmentLifecycleTest (7-hop chain) passing

- [x] **StatusHistory::record() on every transition**
  - Method: `StatusHistory::record(SellerOrder $so, OrderStatus $from, OrderStatus $to, ?string $changedBy, ?string $note)`
  - Called inside `SellerOrderStatusService::transition()` within DB transaction
  - Evidence: app/Models/StatusHistory.php + app/Services/SellerOrderStatusService.php — confirmed, called inside DB::transaction; FulfillmentLifecycleTest passes asserting 2/6/7 history rows per stage

---

## Phase 3: Seller Side

- [x] **Seller orders index with status filter tabs**
  - Controller: `app/Http/Controllers/Seller/OrderController.php` — `index()` with `bucket` query param (to_ship/ready/shipped/delivered)
  - View: `resources/views/Seller/Orders/index.blade.php` — tabs render active bucket, table with order ref, buyer, status badge, tracking, actions
  - Route: `routes/seller.php` — `GET /seller/orders` → `Seller\OrderController@index` (name `seller.orders.index`)
  - Evidence: app/Http/Controllers/Seller/OrderController.php + resources/views/Seller/Orders/index.blade.php + routes/seller.php — all confirmed; SellerAuthRedirectTest hits index (200); note: CourierAssignmentTest exercises markReady, not index

- [x] **"Mark as Ready for Pickup" action**
  - Controller: `OrderController::markReady()` — calls `SellerOrderStatusService::transition($so, ReadyForPickup)` then `CourierAssignmentService::assign($so)`
  - View: `resources/views/Seller/Orders/show.blade.php` — "Mark as ready for pickup" button with JS confirm, POSTs to `seller.orders.markReady` (also on index rows)
  - Route: `routes/seller.php` — `POST /seller/orders/{sellerOrder}/mark-ready` → `OrderController@markReady` (name `seller.orders.markReady`)
  - Evidence: app/Http/Controllers/Seller/OrderController.php + resources/views/Seller/Orders/show.blade.php + routes/seller.php — all confirmed; CourierAssignmentTest 3/3 passing through this route

- [x] **Seller dashboard real counts (not null placeholders)**
  - Controller: `app/Http/Controllers/Seller/DashboardController.php` — `index()` computes `totalOrders`, `pendingOrders` (Placed/Confirmed/Preparing), `totalSales` (delivered+completed subtotal, nullable float), `recentOrders` (8 latest), `salesTrend` (7-day array)
  - View: `resources/views/Seller/Dashboard/index.blade.php` — renders stat cards, recent orders with actions
  - Route: `routes/seller.php` — `GET /seller/dashboard` → `DashboardController@index` (name `seller.dashboard`)
  - Evidence: app/Http/Controllers/Seller/DashboardController.php + resources/views/Seller/Dashboard/index.blade.php + routes/seller.php — all confirmed; SellerDashboardHeroTest 6/6 passing

---

## Phase 4: Courier Assignment

- [x] **CourierAssignmentService (weight + service_area + availability match)**
  - File: `app/Services/CourierAssignmentService.php`
  - `assign(SellerOrder $so)` — scopes available couriers by `max_weight_grams >= parcelWeightGrams()`, `service_area` contains `buyerMunicipality()`, orders by weight ASC, picks first
  - Uses PHP-side filtering (no `JSON_CONTAINS`) for SQLite/MySQL portability
  - On match: sets `sellerOrder.courier_id`, marks `courier.is_available = false`, transitions to `AssignedToRider` via `SellerOrderStatusService`
  - Returns assigned `Courier` or `null`
  - Evidence: app/Services/CourierAssignmentService.php — confirmed, availability + max_weight_grams + PHP-side service_area match all present; CourierAssignmentTest 3/3 passing

- [x] **Auto-trigger on ReadyForPickup**
  - Called from `Seller\OrderController::markReady()` immediately after ReadyForPickup transition
  - Evidence: app/Http/Controllers/Seller/OrderController.php — confirmed, `assign()` called right after transition; CourierAssignmentTest asserts 2 history rows (Placed→Ready, Ready→Assigned)

- [x] **"No courier available" state visible in seller UI (not silent)**
  - `OrderController::markReady()` checks `$assigned === null` → flashes `warning` session: "Order marked ready, but no available courier serves this area right now." (wording differs from prior claim)
  - View `show.blade.php` renders session warning if present
  - Evidence: app/Http/Controllers/Seller/OrderController.php + resources/views/Seller/Orders/show.blade.php — confirmed; CourierAssignmentTest::test_marking_ready_with_no_matching_courier_stays_ready passes asserting session warning + status stays ReadyForPickup

---

## Phase 5: Courier Simulation

- [x] **Internal logistics screen to advance PickedUp→InTransit→OutForDelivery→Delivered**
  - Controller: `app/Http/Controllers/Logistics/ShipmentController.php`
    - `index()` — lists seller_orders in `[AssignedToRider, PickedUp, AtSortingCenter, OutForDelivery]` scoped to `courier.logistics_provider_id = auth provider`
    - `advance()` — single canonical next hop per status: AssignedToRider→PickedUp→AtSortingCenter→OutForDelivery→Delivered; on Delivered sets `courier.is_available = true`
  - View: `resources/views/Logistics/Shipments/index.blade.php` — table with order ref, seller, buyer, tracking, courier, current status, action button (label matches next step)
  - Route: `routes/logistics.php` — `GET /logistics/shipments` (`logistics.shipments.index`), `POST /logistics/shipments/{seller_order}/advance` (`logistics.shipments.advance`)
  - Middleware: `auth:logistics`, `logistics.approved` (provider must be approved; alias registered in bootstrap/app.php → EnsureLogisticsApproved)
  - Sidebar: `resources/views/Components/logistics/sidebar.blade.php` — "Delivery Monitoring" wired to `logistics.shipments.index` (path is `Components/`, lowercase `components/` as previously written does not exist)
  - Evidence: app/Http/Controllers/Logistics/ShipmentController.php + routes/logistics.php + resources/views/Logistics/Shipments/index.blade.php + resources/views/Components/logistics/sidebar.blade.php — all confirmed; LogisticsShipmentSimTest 4/4 passing (scoping, tracking, full pipeline, courier release, no-advance-at-Delivered)

- [x] **Auto-generate tracking_number on PickedUp**
  - `SellerOrder::generateUniqueTrackingNumber()` — format `TRK-YYYYMMDD-XXXXXX` (6-char random, collision-safe loop)
  - Called in `SellerOrderStatusService::transition()` when `$to === OrderStatus::PickedUp && !$sellerOrder->tracking_number`
  - Evidence: app/Models/SellerOrder.php + app/Services/SellerOrderStatusService.php — confirmed; LogisticsShipmentSimTest + FulfillmentLifecycleTest passing, both assert tracking starts with `TRK-`

---

## Phase 6: Buyer-Facing + Notifications

- [x] **Buyer OrderController index/show (real data, not stub)**
  - Controller: `app/Http/Controllers/Buyer/OrderController.php`
    - `index()` — buyer's orders with `sellerOrders.courier` loaded, paginated
    - `show(Order $order)` — loads `sellerOrders.courier`, `orderItems.product`, `statusHistories`; computes `$canConfirm` (all Delivered)
  - Views:
    - `resources/views/Buyer/Orders/index.blade.php` — card per order, overall status badge, per-seller-order rows with status, tracking, courier
    - `resources/views/Buyer/Orders/show.blade.php` — shipping address, items grouped by seller, status timeline per seller_order (history + current), tracking/courier panel, "Confirm Received" button when `$canConfirm`
  - Routes: `routes/buyer.php` — `GET /buyer/orders` (`buyer.orders.index`), `GET /buyer/orders/{order}` (`buyer.orders.show`), `POST /buyer/orders/{order}/confirm-received` (`buyer.orders.confirmReceived`)
  - Navbar: `resources/views/Components/navbar.blade.php` — "My Orders" link in buyer dropdown (`Route::has('buyer.orders.index') ? route(...) : '#'`, line ~219)
  - Evidence: app/Http/Controllers/Buyer/OrderController.php + resources/views/Buyer/Orders/{index,show}.blade.php + routes/buyer.php + resources/views/Components/navbar.blade.php — all confirmed; BuyerOrderLifecycleTest 4/4 passing (index, show, buyer isolation, confirmReceived)

- [x] **Confirm Received action (Delivered→Completed)**
  - Controller: `OrderController::confirmReceived()` — validates ownership, transitions each Delivered seller_order to Completed via `SellerOrderStatusService`
  - View: `show.blade.php` — form POSTs to `buyer.orders.confirmReceived` with confirm dialog
  - Evidence: app/Http/Controllers/Buyer/OrderController.php + resources/views/Buyer/Orders/show.blade.php — confirmed; BuyerOrderLifecycleTest::test_confirm_received_transitions_delivered_to_completed_and_fires_notification passing (asserts Completed status + OrderDelivered notification row)

- [x] **CourierAssigned / OrderOutForDelivery / OrderDelivered notifications**
  - Classes in `app/Notifications/Buyer/`:
    - `CourierAssigned.php` — `via: ['database']`, payload includes seller_order_id, order_reference, courier_name, tracking_number
    - `OrderOutForDelivery.php` — same channel, payload with courier + tracking
    - `OrderDelivered.php` — same channel, payload with tracking
  - Fired in `SellerOrderStatusService::maybeNotify()` on transitions to `AssignedToRider`, `OutForDelivery`, `Delivered` (null-safe via `$sellerOrder->order?->buyer`)
  - Evidence: app/Notifications/Buyer/{CourierAssigned,OrderOutForDelivery,OrderDelivered}.php + app/Services/SellerOrderStatusService.php — all confirmed, `via: ['database']` + match-based hook present; FulfillmentLifecycleTest asserts CourierAssigned row (OrderOutForDelivery/OrderDelivered assertions are commented out in that test), BuyerOrderLifecycleTest asserts OrderDelivered row

- [x] **notifications table migrated**
  - Migration: `2026_09_25_035515_create_notifications_table.php` (standard Laravel notifications table)
  - Evidence: database/migrations/2026_09_25_035515_create_notifications_table.php — confirmed; notification DB assertions passing in BuyerOrderLifecycleTest + FulfillmentLifecycleTest

---

## Resolved Bugs

- [x] **Checkout page (GET /buyer/checkout?items[]=X) renders header/footer but blank content area — likely silent 500 or missing view variable**
  - **Root cause (from storage/logs/laravel.log, entries at 04:58–04:59)**: two compounding errors — `Undefined variable $buyer (View: Buyer/Checkout/index.blade.php)` because `CheckoutController::index()` didn't pass `$buyer`, followed by `Unable to locate file in Vite manifest: resources/js/buyer/checkout.js` when the view was given one. (The "stub view" claim in the old note was not what the log showed.)
  - **Fixed**: Full checkout view built (address form pre-filled from buyer profile, per-seller order summary, COD radio, Place Order button); `$buyer` passed from `CheckoutController::index()`; missing `@vite` reference removed.
  - **Edge case added (this session)**: `?items[]=` containing only invalid/foreign/stale IDs previously rendered a dead-end ₱0.00 checkout page. Now redirects to the cart with a visible error flash. Files: `app/Http/Controllers/Buyer/CheckoutController.php` (redirect guard + widened return type to `View|RedirectResponse`), `resources/views/Buyer/Cart/index.blade.php` (flash banner — the buyer layout had no server-side flash renderer).
  - **Tests**: `CheckoutControllerTest::test_a_selection_of_only_invalid_ids_redirects_back_to_the_cart_with_an_error`, `::test_a_selection_of_only_foreign_ids_redirects_back_to_the_cart_with_an_error`, `::test_a_partially_invalid_selection_keeps_the_valid_items_without_error` — all passing; full suite 157/157 (639 assertions).
  - **Verified in a real browser** (not just feature tests): logged in as a buyer against `php artisan serve`, loaded `/buyer/checkout` — full form + order summary render; loaded `/buyer/checkout?items[]=999` — redirects to cart showing "The items you selected are no longer in your cart. Please review your cart and try again."

---

## End-to-End Verification (manual, not just automated tests)

All five steps executed on 2026-09-25 against a live `php artisan serve` instance driven through a real browser (buyer, seller, and logistics sessions). Order: `ORD-20260925-0SV8BL`, tracking: `TRK-20260925-I22ISY`, seller_order #1 ended COMPLETED with 7 history rows.

- [x] Place a real order as buyer through the actual checkout UI (not a feature test) and confirm it succeeds
  - Evidence: logged in via buyer login form, added item to cart, cart → "Proceed to Checkout" → filled address form → Place Order → landed on `/buyer/checkout/success/1` showing "ORDER PLACED", reference `ORD-20260925-0SV8BL`, COD ₱250.00; cart badge dropped to 0.
- [x] Seller marks Ready for Pickup, courier auto-assigns from seeded pool
  - Evidence: seller session hit `POST /seller/orders/1/mark-ready` → status ASSIGNED_TO_RIDER, courier "E2E Rider Juan" auto-matched (Quezon City service area, 2000g ≤ 10000g limit), `is_available` flipped to false, 2 history rows, `CourierAssigned` notification stored.
- [x] Logistics user advances shipment through all stages at `/logistics/shipments`
  - Evidence: logistics session advanced 4× via the panel: ASSIGNED_TO_RIDER → PICKED_UP → AT_SORTING_CENTER → OUT_FOR_DELIVERY → DELIVERED; tracking `TRK-20260925-I22ISY` generated on PickedUp; courier released (available=true) on Delivered.
- [x] Buyer sees live tracking/status update at each stage without refreshing manually into a different flow
  - Evidence: `/buyer/orders/1` rendered the full 6-step status timeline (with changed_by + notes), tracking number, courier name/phone/vehicle, and current status Delivered — all without any manual intervention beyond loading the page.
- [x] Buyer clicks Confirm Received after Delivered
  - Evidence: "CONFIRM RECEIVED" button in the browser → status Delivered → Completed, timeline shows the 7th row "Delivered → Completed", DB confirms `COMPLETED` + 7 history rows.

---

## Additional Bugs Found & Fixed During E2E Walkthrough (2026-09-25)

- [x] **Unified no-JS login page (`GET /login`, also hit by `/seller/login` deep-link) 500s**
  - **Root cause**: `resources/views/Auth/login-choice.blade.php` referenced `resources/js/app.js` via `@vite`, but `app.js` is not a Vite input in `vite.config.js` — `Unable to locate file in Vite manifest` (also present in historical log entries).
  - **Fixed**: replaced with the built `resources/css/login-modal.css` entry. Verified: `GET /seller/login` now returns 200 with the form + CSRF token.
- [x] **`Seller/Orders/index.blade.php` 500s when any order exists**
  - **Root cause**: `{{ $sellerOrder->status->label }}` — `label` is a method (`label()`) on the `OrderStatus` enum, not a property; historical log shows the same error from an earlier session.
  - **Fixed**: changed to `$sellerOrder->status->label()`. Verified: seller orders index renders (200) with the order row + "Mark ready" button.
- [x] **`Seller/Orders/show.blade.php` 500s when a status history exists**
  - **Root cause**: same property-vs-method bug on `$hist->to_status->label` (line 83).
  - **Fixed**: changed to `->label()` on both `from_status` and `to_status`. Verified: order detail renders (200).
- [x] **`buyerMunicipality()` throws "Undefined array key" for orders whose snapshot lacks municipality keys**
  - **Root cause**: `app/Models/SellerOrder.php` used `??` between two array accesses, but PHP still emits an undefined-key warning (escalated to exception) when the key is absent entirely; the null-coalesce on the outer expression didn't cover the missing-key case.
  - **Fixed**: appended `?? null` so a missing key resolves cleanly. Verified: mark-ready on an order without municipality now falls through to the honest "no courier" path instead of a 500.
- [x] **Dev database missing the `notifications` table**
  - **Root cause**: migration `2026_09_25_035515_create_notifications_table.php` existed but had never been run against the dev MySQL DB (tests use a separate SQLite DB, so the suite stayed green while the live app 500'd on the first notification).
  - **Fixed**: ran `php artisan migrate --path=...`. Verified: `CourierAssigned`, `OrderOutForDelivery`, and `OrderDelivered` rows now persist in the live DB.

---

## Summary

**All 157 tests pass (639 assertions).** Every Phase 1–6 item verified against codebase. The checkout blank-page bug is fixed and moved to Resolved Bugs (with a new edge-case guard for stale selections). All five End-to-End Verification items were executed 2026-09-25 in a real browser against `php artisan serve` — see the E2E section for per-step evidence; four additional bugs found during that walkthrough are fixed and documented above it. The flow is end-to-end wired: buyer checkout → seller dashboard → mark ready → auto courier assignment → logistics simulation screen → buyer notifications + order detail → confirm received.

**Evidence files per phase:**
- Phase 1: 4 migrations, `SellerOrder.php`, `Product.php`, `Courier.php`, `StatusHistory.php`, `CourierSeeder.php`
- Phase 2: `SellerOrderStatusService.php`, `StatusHistory::record()`
- Phase 3: `Seller/OrderController.php` (index/show/markReady), `DashboardController.php`, `seller.php` routes, `Seller/Orders/index.blade.php`, `Seller/Orders/show.blade.php`, `Seller/Dashboard/index.blade.php`
- Phase 4: `CourierAssignmentService.php`, `CourierAssignmentTest.php` (3 tests)
- Phase 5: `Logistics/ShipmentController.php`, `Logistics/Shipments/index.blade.php`, `logistics.php` routes, `LogisticsShipmentSimTest.php` (4 tests)
- Phase 6: `Buyer/OrderController.php`, `Buyer/Orders/index.blade.php`, `Buyer/Orders/show.blade.php`, `buyer.php` routes, 3 notification classes, notifications migration, `BuyerOrderLifecycleTest.php` (4 tests), `FulfillmentLifecycleTest.php` (1 test)
- Checkout fix: `Buyer/CheckoutController.php` (passes `$buyer` + stale-selection redirect guard), `Buyer/Checkout/index.blade.php` (full form), `Buyer/Cart/index.blade.php` (flash banner)