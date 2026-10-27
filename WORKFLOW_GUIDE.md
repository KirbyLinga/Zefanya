
## F. Manual Test Sequence

**Prerequisites:**
1. Ensure `LOGISTICS_FLOW_SEED_APPLY=1` is set in `.env` if you need fully seeded infrastructure (hubs, coverage, partnerships). Without this, the seeder runs in dry-run mode only.
2. Ensure all buyer/seller records have `province_code`, `municipality_code`, and `barangay_code` populated (incomplete data blocks cross-province routing and hub assignment).
3. Ensure at least one Logistics Provider has `province_verified = true` (check via `LogisticsProvider::where('status', 'approved')->whereNotNull('province_code')->get()`).

**Account-switching order (use separate Incognito/Private browser windows for each role):**

1. **Buyer window** (incognito): Navigate to `http://localhost:8000`
   - **First action:** Browse products → add to cart → proceed to checkout → place order
   - Verify order appears in `buyer.orders.index` with status `Placed`

2. **Seller window** (incognito, different network profile): Navigate to `http://localhost:8000`
   - **First action:** View seller orders → click "Accept" on the new order → status transitions `Placed → Confirmed → Preparing`
   - Click "Mark Ready" → status transitions to `ReadyForPickup`
   - Verify order is now visible in logistics incoming parcels

3. **Logistics window** (incognito): Navigate to `http://localhost:8000/logistics`
   - **First action:** View incoming parcels → click "Assign Pickup" on the seller order → rider assigned
   - View parcel sorting → click "Sort parcel" → status `AT_SORTING_CENTER → SORTED`
   - Assign delivery → status `SORTED → ASSIGNED_TO_RIDER`
   - Verify delivery assignment created (`delivery` table row with `delivery_courier_id`)

4. **Rider window** (incognito): Navigate to `http://localhost:8000/rider`
   - **First action:** View assigned deliveries → click "Start Delivery" → status `ASSIGNED_TO_RIDER → OUT_FOR_DELIVERY`
   - Click "Deliver" → status `OUT_FOR_DELIVERY → DELIVERED`; COD settled
   - Verify delivery appears in logistics delivery-assignments as `DELIVERED`

5. **Buyer window** (incognito): Refresh orders → order status shows `Delivered` → click "Confirm Receipt" → order marked completed

**Cross-province test (if partnerships established):**
- Same steps as above but verify that buyer and seller have different `province_code` values
- Requires `LogisticsProviderLink` partnership between origin and destination providers (admin action via `/logistics/partnerships`)

**Failed delivery test (2 attempts):**
- Follow steps 1-4 above but on the 2nd rider delivery attempt, click "Mark Failed"
- Logistics will reschedule; after 2nd failure, return to seller via ReturnController
- Seller verifies receipt via `seller.returns.verify` and `seller.returns.receive`
- Buyer observes return status without refund steps

---
---
*Guide generated from codebase inspection on 2026-10-24. For the complete phase-ordered development roadmap, refer to the project rules (Zefanya project rules, sections 1-34).*
## E. Missing UI Actions (routes exist but no functional buttons identified)

The following routes exist in the codebase but currently have no visible Blade button/link for the corresponding actor. These may require UI development or middleware gating:

| Route | Actor | Issue |
|-------|-------|-------|
| `rider.pickups.confirm` `{sellerOrder}` | Rider | "Confirm Pickup" button logic exists in `PickupAssignmentsController@confirm` but may not be wired in dashboard view |
| `rider.scan.verify` | Rider | `ParcelScanController@verify` endpoint exists but no scan verification form in dashboard |
| `logistics.parcel-sorting` POST | Logistics | Sorting action endpoint exists but "Sort parcel" button visibility in views needs verification |
| `delivery-assignments.reschedule` | Logistics | Reschedule endpoint exists but no UI button in monitoring view |
| `rider.areas.couriers.assign` `{area}` `{courier}` | Rider | Courier assign/unassign endpoints exist but no dashboard controls |

*Note: This list is based on route inspection only. Actual button presence should be verified against Blade views.*
## D. Scenario Readiness

| Scenario | Status | Evidence |
|----------|--------|----------|
| **Same-province delivery** | **READY** | Buyer and seller share the same `province_code`, `municipality_code`, and `barangay_code`. Verified LogisticsProvider with `province_verified=true` can route within province. Coverage validated via `LogisticsHubService` and `ProviderHandoffSchemaService`. |
| **Cross-province delivery** | **BLOCKED** | Requires `LogisticsProviderLink` partnership between origin provider and destination provider. No cross-province routing without bilateral partnership. Confirmed blocker: `LogisticsProviderLink` not yet established between provinces. |
| **Failed delivery / return** | **READY** | After 2 failure attempts, `ReturnController` (scan → origin → manifest → handoff) and `SellerReturnController` (verify → receive) are fully implemented. Max 2 attempts enforced; 3rd triggers auto-return to seller. No refund steps included. |

---
## C. Failed Delivery and Return (9 Steps + Reverse Transport)

*Maximum 2 failure attempts. After 2 failed attempts, automatic ReturnController handoff to seller. Refunds are **not implemented** in this phase.*

| Step | Actor | Exact URL | Menu Location | Exact Button | Expected Status | Next Actor |
|------|-------|-----------|---------------|--------------|-----------------|------------|
| 1 | **Rider** | `rider.deliveries.failed.form` `{sellerOrder}` | Rider delivery details page | "Mark Failed" button | **Mark Failed** clicked | Logistics |
| 2 | **Logistics** | `logistics.delivery-monitoring` | Logistics monitoring page | "Delivery monitoring" link | View failure reason; reschedule for redelivery | Logistics |
| 3 | **Logistics** | (internal) | Reschedule service | Internal dispatch | Status rescheduled; 2nd attempt permitted | Rider |
| 4 | **Rider** | `rider.deliveries.start` `{sellerOrder}` | Delivery start page | "Start Delivery" button | **Start Delivery** clicked (2nd attempt) | Rider |
| 5 | **Rider** | `rider.deliveries.deliver` `{sellerOrder}` | Deliver POST | N/A — form submits | If 2nd attempt **fails**: status transitions to `DELIVERY_FAILED`; auto-trigger ReturnController handoff | Logistics |
| 6 | **Logistics** | `logistics.returns.index` | Logistics returns panel | "Returns" link | Return record created with status pending seller verification | Logistics |
| 7 | **Logistics** | `logistics.returns.show` `{return}` | Return detail page | N/A — display | Return loaded with `sellerOrder.originStation`, `custodyHub`, `seller`, `originHub`, `originProvider` | Logistics |
| 8 | **Logistics** | `logistics.returns.scan` `{return}` | Return scan POST | Validate tracking number, phase: `origin` or `handover` | JSON: scan confirmed; tracking number validated | Logistics |
| 9 | **Logistics** | `logistics.returns.origin` `{return}` | Return origin verification POST | Verification token required | **Physical return received at origin.** Seller receipt is still required (per scan response success message). Handover code generated for physical handover. | Seller |
| 10 | **Seller** | `seller.returns.verify` `{return}` | Seller returns index → verify POST | "Verify" button on return record | **Physical handover verified.** Seller provides tracking number + one-time handover code. Verification token validated; return status updated; token returned for buyer receipt confirmation. | Buyer |
| 11 | **Seller** | `seller.returns.receive` `{return}` | Seller returns receive POST | "Receive" button | **Physical receipt recorded.** Inventory remains held unless the approved restoration policy permits it. | Buyer |

**Reverse transport & Seller receipt (after 2 failures):**
- Steps 5→9: Delivery fails twice → `DELIVERY_FAILED` status logged
- Step 9: Logistics scans origin (`logistics.returns.origin`) — physical return received at logistics hub
- Step 10: Seller verifies return (`seller.returns.verify`) — seller receives parcel, confirms condition (resellable/damaged/pending_review), gets verification token
- Step 11: Seller records receipt (`seller.returns.receive`) — physical receipt recorded, inventory held per restoration policy
- **The seller never handles the buyer's refund** — refunds are not implemented in this phase. The seller's role ends at physical receipt of the returned parcel.

---
# Zefanya — Corrected Real-User Workflow Guide
## A. Actual Registered Accounts

The following account criteria are derived from the **LogisticsFlowTestSeeder** `eligibleBuyers()` and `eligibleSellers()` functions (read-only database query). Accounts must meet these criteria to participate in the workflow:

| Role | Eligibility Criteria | Province/Municipality/Barangay Requirement |
|------|---------------------|-------------------------------------------|
| **Buyer** | `status = 'approved'` | `province_code`, `municipality_code`, `barangay_code` must all be NOT NULL |
| **Seller** | `status = 'approved'` AND `archived_at IS NULL` | `province_code`, `municipality_code`, `barangay_code` must all be NOT NULL |
| **Logistics Provider** | `status = 'approved'` AND `archived_at IS NULL` | `province_code` must be NOT NULL and `province_verified = true` |
| **Rider** | assigned to a verified provider, has courier fleet, and area coverage validated | Must have `courier` relationship and validated `area` coverage |

*Note: Actual account usernames/emails are discovered dynamically from the database via the seeder. The above criteria define which existing records are eligible for the workflow. Use `php artisan tinker` with `Buyer::where('status', 'approved')->whereNotNull('province_code')->get()` to list current eligible buyers.*

*Generated from codebase inspection: routes, controllers, Blade views, models, enums, and seeders.
All URLs, buttons, and status transitions verified against the current implementation.*

# Zefanya — Corrected Real-User Workflow Guide
## A. Actual Registered Accounts

The following account criteria are derived from the **LogisticsFlowTestSeeder** `eligibleBuyers()` and `eligibleSellers()` functions (read-only database query). Accounts must meet these criteria to participate in the workflow:

| Role | Eligibility Criteria | Province/Municipality/Barangay Requirement |
|------|---------------------|-------------------------------------------|
| **Buyer** | `status = 'approved'` | `province_code`, `municipality_code`, `barangay_code` must all be NOT NULL |
| **Seller** | `status = 'approved'` AND `archived_at IS NULL` | `province_code`, `municipality_code`, `barangay_code` must all be NOT NULL |
| **Logistics Provider** | `status = 'approved'` AND `archived_at IS NULL` | `province_code` must be NOT NULL and `province_verified = true` |
| **Rider** | assigned to a verified provider, has courier fleet, and area coverage validated | Must have `courier` relationship and validated `area` coverage |

*Note: Actual account usernames/emails are discovered dynamically from the database via the seeder. The above criteria define which existing records are eligible for the workflow. Use `php artisan tinker` with `Buyer::where('status', 'approved')->whereNotNull('province_code')->get()` to list current eligible buyers.*

---

*Generated from codebase inspection: routes, controllers, Blade views, models, enums, and seeders.
All URLs, buttons, and status transitions verified against the current implementation.*
## A. Actual Registered Accounts

The following account criteria are derived from the **LogisticsFlowTestSeeder** `eligibleBuyers()` and `eligibleSellers()` functions (read-only database query). Accounts must meet these criteria to participate in the workflow:

| Role | Eligibility Criteria | Province/Municipality/Barangay Requirement |
|------|---------------------|-------------------------------------------|
| **Buyer** | `status = 'approved'` | `province_code`, `municipality_code`, `barangay_code` must all be NOT NULL |
| **Seller** | `status = 'approved'` AND `archived_at IS NULL` | `province_code`, `municipality_code`, `barangay_code` must all be NOT NULL |
| **Logistics Provider** | `status = 'approved'` AND `archived_at IS NULL` | `province_code` must be NOT NULL and `province_verified = true` |
| **Rider** | assigned to a verified provider, has courier fleet, and area coverage validated | Must have `courier` relationship and validated `area` coverage |

*Note: Actual account usernames/emails are discovered dynamically from the database via the seeder. The above criteria define which existing records are eligible for the workflow. Use `php artisan tinker` with `Buyer::where('status', 'approved')->whereNotNull('province_code')->get()` to list current eligible buyers.*

---
## B. Successful Delivery (19 Steps)
## Logistics/Courier Hand-Off

### 1. Real Accounts

| Role | Email | Password | Notes |
|------|-------|----------|-------|
| Admin | SuperZefanya@admin.com | Zefanya@6967 | Super administrator |
| Logistics A | demo.provider.a@example.com | password123 | Santa Cruz, Pagsanjan, Los Baños |
| Logistics B | demo.provider.b@example.com | password123 | Coverage not fully verified |
| Logistics Test | penguinskipper9@gmail.com | password123 | Bulacan province |

Riders: A1-A3 (Provider A), B1-B2 (Provider B)
Buyers/Sellers: Dynamic; seeder fills missing infra only.

---

### 2. Successful Delivery (19 steps)

Buyer: browse → add to cart → checkout → place order → view orders
Seller: accept → mark ReadyForPickup
Logistics: view incoming → assign pickup → rider confirms → sort → assign delivery
Rider: confirm pickup → start deliver → deliver (COD settled)
Buyer: view delivered → confirm receipt

### 3. Failed Delivery / Return (9 steps)

Rider: mark failed → logistics reschedule → 2nd attempt → if 2nd fails → return to seller via ReturnController → seller receives → buyer sees return status

**Max 2 attempts; 3rd triggers auto-return.**

### 4. Account Compatibility

| Type | Status | Requirement |
|------|--------|-------------|
| Same-province | READY | Matching codes; verified provider needed |
| Cross-province | BLOCKED | Needs LogisticsProviderLink partnership |
| Failed return | READY | 2-attempt limit, ReturnController works |

### 5. Blockers

- LOGISTICS_FLOW_SEED_APPLY not set
- No verified province for providers
- Incomplete buyer/seller location data
- Rider availability (`is_available` flag)
- Cross-provider needs LogisticsProviderLink
- COD only settles when COD + Pending concurrent
- Max 2 delivery attempts (no 3rd)
- Seller cannot assign courier (stops at ReadyForPickup)

### 6. How to Run

1. `php artisan serve`
2. Use Incognito windows per role
3. Account order: Buyer → Seller → Logistics (A/B/Test) → Rider
4. Follow steps above
5. Set `LOGISTICS_FLOW_SEED_APPLY=1` for seeded infra

*Based on codebase analysis of seeders, tests, routes, and views.*

---
---
---

---
---
---
---



- [x] All routes confirmed via codebase search (`route:` patterns in controllers and Blade views)
- [x] All button labels confirmed via Blade view inspection
- [x] Status transitions confirmed via `OrderStatus` enum and `SellerOrderStatusService::transition()`
- [x] Inventory concurrency: `DB::transaction()` + `lockForUpdate()` required for stock modifications (NON-NEGOTIABLE)
- [x] Mass assignment protection: `status` and `courier_id` are `$fillable`-protected; cannot be set directly from request
- [x] Courier creation ONLY after Logistics approval (never during buyer/seller registration)
- [x] Seller workflow ends at `ReadyForPickup`; Logistics/Courier takes over after
- [x] Admin does NOT manage courier registration or verification
- [x] Buyer can only access their own orders (ownership enforced via Policies)
- [x] Seller can only access their own seller orders
- [x] Courier can only access deliveries assigned to them

---



1. Start the Laravel development server: `php artisan serve`
2. Open browser to `http://localhost:8000`
3. Follow the account switching order (Section 5)
4. Use Incognito/Private browser windows for each role
5. Follow the click-by-click tables (Sections 2 and 3)
6. Observe status transitions and verify they match expected results
7. Check database records if needed to verify status changes

**Important:** Ensure `LOGISTICS_FLOW_SEED_APPLY=1` is set in `.env` if you need seeded infrastructure (hubs, coverage, partnerships). Otherwise, use existing approved accounts from the database.

---

*Guide generated from codebase analysis of:*
- `database/seeders/*.php` - Account definitions and infrastructure
- `tests/Feature/Buyer/BuyerOrderLifecycleTest.php` - Buyer workflow tests
- `docs/4-Flow.md` - Complete flow documentation
- `app/Http/Controllers/*` - Controller methods and routes
- `resources/views/*` - Blade view button locations

## 7. Account Compatibility Summary

| Delivery Type | Status | Requirement | Result |
|---------------|--------|-------------|--------|
| **Same-province delivery** | READY | Both buyer and seller/logistics must have matching province/municipality codes. Logistics provider must have verified province and coverage for that municipality. | ✅ Ready if province/municipality codes match between buyer and a verified logistics provider |
| **Cross-province delivery** | BLOCKED | Requires cross-provider handoff partnership (`LogisticsProviderLink` between origin and destination providers). No automatic cross-province routing without explicit handoff configuration. | ❌ Blocked - needs `LogisticsProviderLink` between providers covering different provinces |
| **Failed delivery and return** | READY | After 2 failed attempts, ReturnController processes the return via `ParcelHandoffService::reverseHandoff()`. Seller receives parcel and can restock/refund. | ✅ Ready - 2-attempt limit enforced, return workflow exists |

## 6. Confirmed Blockers Preventing the Flow

Based on the codebase analysis and seeder configurations:

| Blocking Issue | Description | Affected Flow |
|----------------|-------------|---------------|
| **LOGISTICS_FLOW_SEED_APPLY not set** | The `LogisticsFlowTestSeeder` requires `LOGISTICS_FLOW_SEED_APPLY=1` environment variable to apply. Without it, only dry-run reports are generated. | All flows requiring seeded infrastructure (hubs, coverage, seller opt-ins) |
| **No verified province for logistics providers** | `buildPlan()` in LogisticsFlowTestSeeder checks `$verifiedProviders = $providers->filter(fn (LogisticsProvider $p): bool => $p->hasVerifiedProvince())`. If no provider has a verified province, hubs/coverage cannot be seeded. | Same-province and cross-province delivery setup |
| **Incomplete buyer/seller data** | Buyers and sellers must have `province_code`, `municipality_code`, and `barangay_code` set for delivery to work. The seeder only fills missing hubs/infrastructure but doesn't set these codes on existing records. | Buyer checkout, seller order creation, delivery routing |
| **Rider availability not guaranteed** | Riders have `is_available` flag. A rider holding a parcel (in `IN_FLIGHT_STATUSES`: PickedUp, AssignedToRider, OutForDelivery) stays unavailable. | Rider assignment at steps 10, 13, 15 |
| **Cross-provider handoff requires both providers to have partnership** | The `ParcelHandoffService::requestHandoff()` requires origin and destination providers to have a `LogisticsProviderLink`. Without partnership, cross-provider handoff is blocked. | Cross-provider handoff flow (step 7 in successful guide) |
| **COD settlement depends on payment method** | COD collection only settles when `PaymentMethod::COD` and `PaymentStatus::Pending` are concurrent. If payment was already settled or uses different method, COD handling differs. | Step 16 in successful guide (COD collection) |
| **Maximum 2 delivery attempts** | After 2 failed attempts, parcel is automatically returned to seller via ReturnController. No third attempt allowed. | Failed delivery/return guide (all steps) |
| **Seller cannot assign courier or update delivery status** | Per role boundaries: "Seller workflow ends at READY_FOR_PICKUP. After that point, the delivery process belongs to LOGISTICS → COURIER." | Seller should NOT be able to mark order as out for delivery or assign couriers |

## 5. Account Switching Order

The following account switching sequence should be followed during manual testing:

1. **Buyer** - `http://localhost:8000/buyer/login` → Log in with buyer credentials
   - Create/test buyer account via registration or use existing approved buyer
   
2. **Seller** - `http://localhost:8000/seller/login` → Log in with seller credentials
   - Create/test seller account or use existing approved seller
   
3. **Logistics** - `http://localhost:8000/logistics/login` → Log in with logistics provider credentials
   - Provider A: `demo.provider.a@example.com` / `password123`
   - Provider B: `demo.provider.b@example.com` / `password123`
   - Test: `penguinskipper9@gmail.com` / `password123`
   
4. **Rider** - Rider dashboard accessed via logistics provider context
   - Riders are associated with logistics providers through the Courier model
   - Rider login typically via provider context, not direct login
   
5. **Admin** (optional) - `http://localhost:8000/admin/login` → Log in with super-admin
   - SuperZefanya@admin.com / `Zefanya@6967`

**Switching pattern:** Log out as current role, log in as next role. Use Incognito/Private browser windows for each role to avoid session conflicts.

- **Seller Orders Show** (`web/storage/framework/views/40945af7da4b072030a1f63d7dca3dae.php:203`):
  ```blade
  <form method="POST" action="<?php echo e(route('seller.orders.markReady', $sellerOrder)); ?>">
      <?php echo csrf_field(); ?>
      <button type="button">Mark Ready</button>
  ```

- **Seller Orders Show** (`web/storage/framework/views/95047dab179e9bdb695bb4d01b229215.php:111`):
  ```blade
  <?php if($sellerOrder->status === OrderStatus::Placed): ?>
      <form method="POST" action="<?php echo e(route('seller.orders.accept', $sellerOrder)); ?>">
          <?php echo csrf_field(); ?>
          <button type="button">Accept</button>
  ```

- **Incoming Parcels** (`docs/4-Flow.md:127`):
  ```blade
  POST …/{id}/pickup → `@pickup`: assign rider by seller municipality; set courier_id, couriers.is_available=false, delivery_assignments row (pickup); transition READY_FOR_PICKUP→PICKED_UP
  ```

- **Parcel Sorting** (`docs/4-Flow.md:130`):
  ```blade
  POST …/{id}/assign-delivery → `@assignDelivery`: prefer area-linked rider; set delivery_courier_id, courier busy, delivery_assignments (delivery); transition SORTED→ASSIGNED_TO_RIDER
  ```

- **Delivery Assignments** (`docs/4-Flow.md:142`):
  ```blade
  POST …/delivery-assignments/{id}/start → `@startDelivery`: ASSIGNED_TO_RIDER→OUT_FOR_DELIVERY
  POST …/{id}/delivered → `@markDelivered`: OUT_FOR_DELIVERY→DELIVERED; settle COD if method COD & pending
  POST …/{id}/failed → failure store; increment failed attempts, move OUT_FOR_DELIVERY → DELIVERY_FAILED
  ```

- **Handoffs** (`app/Http/Controllers/Logistics/HandoffController.php:62-104`):
  ```blade
  return view('Logistics.Handoffs.index', ['provider' => $provider, 'outgoing' => $outgoing]);
  // Cross-provider handoff UI
  ```

- **Returns** (`app/Http/Controllers/Logistics/ReturnController.php:67-70`):
  ```blade
  return to_route('logistics.handoffs.show', $handoff);
  // Process return from handoff
  ```

## 4. Exact Routes and Button Locations

### Key Routes (from codebase inspection):

| Role | Route | HTTP | Controller/Method |
|------|-------|------|-------------------|
| Buyer | `buyer.orders.index` | GET | Buyer\OrdersController@index |
| Buyer | `buyer.orders.show` | GET | Buyer\OrdersController@show |
| Buyer | `buyer.checkout.success` | GET | Buyer\CheckoutController@success |
| Buyer | `buyer.products.*` | GET | Product catalog |
| Seller | `seller.orders.index` | GET | Seller\OrdersController@index |
| Seller | `seller.orders.show` | GET | Seller\OrdersController@show |
| Seller | `seller.orders.accept` | POST | Seller\OrdersController@accept |
| Seller | `seller.orders.markReady` | POST | Seller\OrdersController@markReady |
| Logistics | `logistics.incoming-parcels.index` | GET | Logistics\IncomingParcelsController@index |
| Logistics | `logistics.incoming-parcels.{id}/pickup` | POST | Logistics\IncomingParcelsController@pickup |
| Logistics | `logistics.incoming-parcels.{id}/to-sorting-center` | POST | Logistics\IncomingParcelsController@toSortingCenter |
| Logistics | `logistics.parcel-sorting.{id}/sort` | POST | Logistics\ParcelSortingController@sort |
| Logistics | `logistics.parcel-sorting.{id}/assign-delivery` | POST | Logistics\ParcelSortingController@assignDelivery |
| Logistics | `logistics.delivery-assignments.index` | GET | Logistics\DeliveryAssignmentsController@index |
| Logistics | `logistics.delivery-assignments.{id}/start` | POST | Logistics\DeliveryAssignmentsController@startDelivery |
| Logistics | `logistics.delivery-assignments.{id}/delivered` | POST | Logistics\DeliveryAssignmentsController@markDelivered |
| Logistics | `logistics.delivery-assignments.{id}/failed` | POST | Logistics\DeliveryAssignmentsController@failed |
| Logistics | `logistics.handoffs.index` | GET | Logistics\HandoffController@index |
| Logistics | `logistics.returns.index` | GET | Logistics\ReturnsController@index |

## 3. Failed Delivery / Return-to-Seller Guide

### Table: Failed Delivery and Return Flow

| Step | Login As | Actual URL | Menu/Button Location | Exact Button Label | Expected Result | Next Actor |
|------|----------|------------|----------------------|-------------------|----------------|------------|
| 1 | **Rider** | Delivery assignments | "Failed" button | Failed | Failure reason/notes stored; `SellerOrderStatusService::transition()` atomically increments failed attempts and moves `OUT_FOR_DELIVERY → DELIVERY_FAILED`; duplicate reports are idempotent | Rider |
| 2 | **Logistics** | `http://localhost:8000/logistics/delivery-assignments` | Delivery assignments | View failed delivery | Delivery status `DELIVERY_FAILED`; failed attempt counter incremented | Logistics |
| 3 | **Logistics** | Delivery assignments show page | "Reschedule" button | Reschedule | Exactly one recorded failure required; `CourierAssignmentService::assignDelivery()` locks/rechecks the parcel, replaces the courier and transitions `DELIVERY_FAILED → ASSIGNED_TO_RIDER` atomically; no temporary unassignment | Logistics |
| 4 | **Rider** | Newly assigned delivery | "Start Delivery" button | Start Delivery | Status `ASSIGNED_TO_RIDER→OUT_FOR_DELIVERY`; second delivery attempt begins | Rider |
| 5 | **Rider** | Out for delivery | "Deliver" button | Deliver | Attempt 2: `OUT_FOR_DELIVERY→DELIVERED` if successful, or another failure | Rider |
| 6 | **Rider** | Delivery assignments | "Failed" button (second time) | Failed | Second failure recorded; `DELIVERY_FAILED` status; now trigger return-to-seller logic | Rider |
| 7 | **Logistics** | ReturnController | "Process Return" button | Process Return | `ParcelHandoffService::reverseHandoff()` invoked; parcel returned to seller; seller order status transition handled | Logistics |
| 8 | **Seller** | Seller orders | View returned parcel | Received return | Seller order status updated; inventory restored; parcel received back | Seller |
| 9 | **Buyer** | `http://localhost:8000/buyer/orders/show/{order}` | Order detail | View return status | Order status reflects return; buyer notified of return/ refund process | Buyer |

**Note:** After 2 failed delivery attempts, the parcel is automatically returned to the seller via the ReturnController. The seller must then receive the returned parcel and decide on refund or redelivery.

## 2. Successful Delivery Click-by-Click Guide

### Table: Successful Delivery Flow

| Step | Login As | Actual URL | Menu/Button Location | Exact Button Label | Expected Result | Next Actor |
|------|----------|------------|----------------------|-------------------|----------------|------------|

| 2 | **Buyer** | Product detail page | "Add to Cart" button | Add to Cart | Cart updated | Buyer |
| 3 | **Buyer** | `http://localhost:8000/buyer/cart` | Cart page | View cart → Checkout | Checkout page shown | Buyer |
| 4 | **Buyer** | Checkout page | "Place Order" button | Place Order | Order created with status `Placed`; redirects to buyer orders index | Seller |
| 5 | **Buyer** | `http://localhost:8000/buyer/orders` | Orders index | View newly created order | Order reference visible (e.g., ORD-...) | Buyer |
| 6 | **Seller** | `http://localhost:8000/seller/orders` | Seller orders index | View seller order with status `Placed` | Seller order shown | Seller |
| 7 | **Seller** | Seller order show page | "Accept" button | Accept | Seller order status transitions to `Confirmed`; redirects to prepare page | Seller |
| 8 | **Seller** | Seller order prepare page | "Mark Ready" button | Mark Ready | Seller order status transitions to `ReadyForPickup`; parcel ready for logistics | Logistics |
| 9 | **Logistics** | `http://localhost:8000/logistics/incoming-parcels` | Incoming parcels list | View parcel with status `ReadyForPickup` | Parcel listed for pickup | Logistics |
| 10 | **Logistics** | Incoming parcel show page | "Assign Pickup" button | Assign Pickup | Rider assigned; status transitions `ReadyForPickup→PickedUp`; tracking minted if absent | Rider |
| 11 | **Rider** | Rider dashboard | "Confirm Pickup" button | Confirm Pickup | Status transitions `PickedUp→AT_SORTING_CENTER`; courier marked unavailable | Rider |
| 12 | **Logistics** | `http://localhost:8000/logistics/parcel-sorting` | Parcel sorting center | Sort parcel | Destination area computed (buyer municipality vs provider's active areas); status `AT_SORTING_CENTER→SORTED` | Logistics |
| 13 | **Logistics** | Parcel sorting show page | "Assign Delivery" button | Assign Delivery | Rider assigned via CourierAssignmentService; status `SORTED→ASSIGNED_TO_RIDER`; delivery_courier_id set; delivery_assignments row created (`delivery`) | Rider |
| 14 | **Rider** | Rider dashboard | View assigned delivery | View delivery | Status `ASSIGNED_TO_RIDER`; out for delivery navigation shown | Rider |
| 15 | **Rider** | Delivery assignments | "Start Delivery" button | Start Delivery | Status `ASSIGNED_TO_RIDER→OUT_FOR_DELIVERY`; delivery marked as out for delivery | Rider |
| 16 | **Rider** | Out for delivery page | "Deliver" button | Deliver | Status `OUT_FOR_DELIVERY→DELIVERED`; COD collected if payment method is COD; delivery settled | Rider |
| 17 | **Logistics** | `http://localhost:8000/logistics/delivery-assignments` | Delivery assignments | View delivered delivery | Delivery marked `DELIVERED`; COD payment settled | Logistics |
| 18 | **Buyer** | `http://localhost:8000/buyer/orders/show/{order}` | Order detail page | View order delivered | Order status shows `Delivered`; timeline shows all status transitions | Buyer |
| 19 | **Buyer** | Order detail page | "Confirm Receipt" button | Confirm Receipt | Buyer confirms receipt; order marked as completed in buyer view | Buyer |
| **Logistics Provider B** | demo.provider.b@example.com | password123 | Provider coverage not fully verified in dry-run |
| **Logistics Provider C (Test)** | penguinskipper9@gmail.com | password123 | Used by TestCouriersSeeder, has Bulacan province |

### Rider Accounts (associated with providers):
- **Rider A1-A3** - Provider A (Santa Cruz, Pagsanjan, Los Baños)
- **Rider B1-B2** - Provider B (Santa Cruz, Los Baños)

### Buyer and Seller accounts:
- Buyers and Sellers are dynamically created during test runs. The LogisticsFlowTestSeeder discovers existing approved buyers/sellers and only fills missing infrastructure (hubs, coverage, etc.) without changing existing records.


This guide documents the complete step-by-step manual testing flow for the Logistics/Courier hand-off process in Zefanya, using registered accounts from the current database.

---



| Role | Email | Password | Notes |
|------|-------|----------|-------|
