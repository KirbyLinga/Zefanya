# 4 — Flow

> Step-by-step behaviour of every flow. For each step: **actor**, **route → controller method**, **DB writes**, **notifications**, **what the next role sees**, and a **status** with a file path as evidence.
> **Written:** 2026-10-16. Status legend: **WORKS** (end-to-end), **PARTIAL**, **PLACEHOLDER** (page/route only), **MISSING**, **BROKEN** (defect — see `1-Context.md §7`).
> Companions: `1-Context.md`, `2-Design.md`, `3-Folder-Structure.md`.

---

## 0. Connectivity map

| Flow | Reads | Writes | Services | Notifications |
|---|---|---|---|---|
| Registration (×3) | — | `buyers`/`sellers`/`logistics_providers` | — | `*RegistrationOtp` |
| OTP verify | that table + **cache** (`RateLimiter`) | same row (`email_verified_at`, `status=pending`, `otp_attempts`) + cache failure/resend counters | — | — |
| Admin decision | that table | `status`, audit fields | — | `*RegistrationDecision` |
| Login / Google | that table | session | — | — |
| Checkout | `cart_items`, `vouchers`, `product_variants`, `products` | `orders`, `seller_orders`, `order_items`, `payments`, `inventory_transactions`, `vouchers.times_used`, `checkout_tokens` | `CartSummary` | `Seller\NewOrderReceived` (×seller_order) |
| Seller fulfilment | `seller_orders` | `seller_orders.status`, `status_histories`, `orders.status` | `SellerOrderStatusService` | — |
| Pickup / sorting | `seller_orders`, `areas`, `couriers`, order `shipping_address` json | `seller_orders.courier_id`/`destination_area_id`, `couriers.is_available`, `delivery_assignments`, `status_histories` | `CourierAssignmentService`, `SellerOrderStatusService` | `Buyer\CourierAssigned` (on assign) |
| Delivery | `seller_orders`, `payments` | `seller_orders.status`/`delivery_attempts`/`delivery_failure_*`, `payments`, `product_variants`, `inventory_transactions` | `SellerOrderStatusService`, `CourierAssignmentService` | `Buyer\OrderOutForDelivery`, `Buyer\OrderDelivered`, `Buyer\DeliveryFailed`, `Seller\DeliveryFailed`, `Seller\ParcelReturned` |
| Admin | `buyers`/`sellers`/`logistics_providers` | same | — | `*RegistrationDecision` |
| Messaging | `conversations`, `messages` | same | — | — |
| Sessions | — | session | — | — |

**Every** `seller_orders.status` change goes through `SellerOrderStatusService::transition()` (`app/Services/SellerOrderStatusService.php:85-194`), which re-reads under `lockForUpdate`, enforces the graph + role gate, writes `status_histories`, and rolls up the parent order — all in one transaction.

---

## 1. Buyer registration → OTP → approval → login

| # | Actor | Route → method | DB writes | Notified | Next sees | Status / evidence |
|---|---|---|---|---|---|---|
| 1 | Guest | `GET /register` → closure | — | — | role picker with modals | WORKS — `routes/web.php:34`, `Auth/Register-Type.blade.php` |
| 2 | Guest | `POST /register/buyer` → `Buyer\RegisterBuyerController@store` (`throttle:register` = 5/email+IP, 30/IP) | **archive** any `unverified`/`rejected` buyer w/ same email; create `buyers` row `status=unverified`, `otp_attempts=0`, keyed-HMAC OTP + `expires_at=+10min` | **`Buyer\BuyerVerifyOtp`** (mail) | OTP entry (modal) or the GET OTP page | WORKS — `RegisterBuyerController.php` |
| 3 | Guest | `GET /register/buyer/verify-otp/{buyer}` → `@show` (**signed** + `throttle:otp-show`) **or** `POST …` → `@verify` (**signed** + `throttle:otp-verify`) | unsigned → **403**; mismatch → `otp_attempts++` + **per-email failure hit**; **5 wrong codes → 15-min lock (HTTP 429)**; success → `email_verified_at`, `status=pending`, clear failure window | — | pending page | WORKS — `VerifyBuyerOtpController.php`, `SignedOtpLink` |
| 4 | Guest | `POST …/verify-otp/{buyer}/resend` → `@resend` (**signed** + `throttle:otp-resend`) | new code **only after** mail succeeds; **max 3 resends / 10 min per email**; never resets the lock | `Buyer\BuyerVerifyOtp` (**mail**) | new OTP | WORKS — `VerifyBuyerOtpController.php` |
| 5 | Admin | `POST /admin/registrations/buyer/{id}/{approve,reject}` → `Admin\RegistrationController@approveBuyer`/`@rejectBuyer` | `status=approved` + `approved_by`/`approved_at` (forceFill) **or** `status=rejected` + reason (≤500) | **`Buyer\BuyerRegistrationDecision`** | login allowed / rejection notice | WORKS — `RegistrationController.php:309-351` |
| 6 | Buyer | `POST /login` → `Auth\UnifiedLoginController@login` (throttle 5/1) | session; clears other guards | — | buyer home | WORKS — `UnifiedLoginController.php:33-99` |

**Divergences / stops.** A re-registration with a `rejected`/abandoned `unverified` email is allowed (old row **archived**, ID file deleted; OTP lock survives because it is keyed by email). Cross-role emails are rejected (`EmailRegistry`). The verify route is throttled (`throttle:10,1`) **and** carries the per-email 5-failure/15-minute lock; no OTP/email is logged. A **non-XHR** form submit redirects to the buyer's OTP step. A **GET** to the signed verify path renders the resumable OTP page.

---

## 2. Seller registration → OTP → approval → login

| # | Actor | Route → method | DB writes | Notified | Next sees | Status / evidence |
|---|---|---|---|---|---|---|
| 1 | Guest | `POST /register/seller` → `Seller\RegisterSellerController@store` (`throttle:register`) | **archive** `unverified`/`rejected` seller; create `sellers` row `status=unverified`, OTP | **`Seller\SellerRegistrationOtp`** (no secrets logged) | OTP entry | WORKS — `RegisterSellerController.php` |
| 2 | Guest | `GET /register/seller/verify-otp/{seller}` → `@show` (**signed** + `otp-show`) **or** `POST …` → `@verify` (**signed** + `otp-verify`) | unsigned → **403**; fail → per-email hit; **5 wrong → 15-min lock**; success → `status=pending` | — | pending page | WORKS — `VerifySellerOtpController.php`, `SignedOtpLink` |
| 3 | Guest | `POST …/resend` → `@resend` (**signed** + `otp-resend`) | new code after mail success; **max 3 resends / 10 min**; never resets the lock | `Seller\SellerRegistrationOtp` (**mail**) | new OTP | WORKS — `VerifySellerOtpController.php` |
| 4 | Admin | `admin.registrations.seller.{approve,reject}` → `RegistrationController@approveSeller`/`@rejectSeller` | `status` + audit/reason | **`Seller\SellerRegistrationDecision`** | login / notice | WORKS — `RegistrationController.php:354-395` |
| 5 | Seller | `POST /seller/login` **or** `POST /login` | session | — | `/seller/dashboard` | WORKS — `Seller\AuthController`, `UnifiedLoginController.php:42` |

**Divergences / stops.** None blocking. `EnsureSellerApproved` logs out and bounces non-approved sellers with a status message (unverified sellers also get a link to their GET OTP page). A non-XHR seller registration submit now goes to the OTP step; it previously jumped straight to "pending", skipping verification.

---

## 3. Logistics registration → OTP → approval → login

| # | Actor | Route → method | DB writes | Notified | Next sees | Status / evidence |
|---|---|---|---|---|---|---|
| 1 | Guest | `POST /register/logistics` → `Logistics\RegisterLogisticsController@store` (`throttle:register`) | **archive** leftover; create `logistics_providers` row `status=Unverified` | **`Logistics\LogisticsRegistrationOtp`** | OTP page | WORKS — `RegisterLogisticsController.php` |
| 2 | Guest | `GET/POST /register/logistics/verify-otp/{provider}` → `@show`/`@verify` (**signed** + `otp-show` / `otp-verify`) | unsigned → **403**; **5 wrong → 15-min lock**; success → `Pending` | — | pending page | WORKS — `VerifyLogisticsOtpController.php`, `SignedOtpLink` |
| 3 | Guest | `POST …/resend` → `@resend` (**signed** + `otp-resend`) | new code after mail success; **max 3 resends / 10 min**; never resets the lock | `LogisticsRegistrationOtp` (**mail**) | new OTP | WORKS |
| 4 | Admin | `admin.registrations.logistics.{approve,reject}` → `@approveLogistics`/`@rejectLogistics` | `status` + audit/reason (`update()`, fields are fillable) | **`Logistics\LogisticsRegistrationDecision`** | login / notice | WORKS — `RegistrationController.php:398-435` |
| 5 | Provider | `POST /login` | session | — | `/logistics` dashboard | WORKS — `UnifiedLoginController.php:47` |

---

## 4. Rider self-registration → approval → API

| # | Actor | Route → method | DB writes | Status / evidence |
|---|---|---|---|---|
| 1 | Rider | *(none)* self-register | — | **MISSING** — no courier registration route/controller (was described in the now-removed rider-management contract); unbuilt (Batch D) |
| 2 | Logistics | *(none)* approve/activate/deactivate rider | — | **MISSING** — no rider `status` column; `couriers` has no account fields (no email/password/token) |
| 3 | Rider | *(none)* login / token | — | **MISSING** — no `courier` guard; `routes/api.php` does not exist; **no Sanctum installed** |
| 4 | Rider | *(none)* list parcels / update status via API | — | **MISSING** — the rider actions exist server-side but are driven by the **logistics web panel** (`DeliveryAssignmentsController`) |

**What exists instead:** `couriers` records are created only by seeders (`DemoRidersSeeder`, `TestCouriersSeeder`) or logistics fleet tooling; they carry `logistics_provider_id`, `area_id`, `is_available`, `vehicle_type`, `max_weight_grams`. Logistics assigns them to parcels by area (§7). This is the **largest missing ERP area** (Batch D).

---

## 5. Buyer shopping and checkout

| # | Actor | Route → method | DB writes | Notified | Next sees | Status / evidence |
|---|---|---|---|---|---|---|
| 1 | Guest/Buyer | `GET /buyer/…` browsing → `Home/Category/Product/SearchController` | — | — | catalog | WORKS |
| 2 | Buyer | `POST /buyer/cart/add` → `CartController@add` | `cart_items` (merge/coalesce) | — | cart | WORKS — `CartController.php` |
| 3 | Buyer | `POST /buyer/cart/voucher` → `@applyVoucher` | session voucher | — | cart total | WORKS — `CartController.php`; discount via `Voucher::discountCentavos()` (`CartSummary.php:87-89`) |
| 4 | Buyer | `POST /buyer/checkout/buy-now` → `@buyNow` | session (single line) | — | checkout | WORKS |
| 5 | Buyer | `GET /buyer/checkout` → `CheckoutController@index` | `checkout_tokens` row; parks `checkout_item_ids` + `checkout_form_token` | — | checkout page (address card + payment radios) | WORKS — `CheckoutController.php:49-103` |
| 6 | Buyer | `POST /buyer/checkout` → `CheckoutController@store` → `placeOrder` | one transaction: claim token; create `orders` (address json snapshot, subtotal/discount/total, `status=PLACED`); `voucher.times_used++`; one `seller_orders` per shop (proportional discount, `status=PLACED`, **tracking minted now**); `order_items` (price snapshot); `payments` (method, `PENDING`); decrement `product_variants.stock_quantity`; `inventory_transactions` reason `checkout`; refresh rollups | after commit: **`Seller\NewOrderReceived`** per seller | success page | WORKS — `CheckoutController.php:121-424` |
| 7 | Buyer | `GET /buyer/checkout/success/{order}` → `@success` | — | — | order summary | WORKS — `CheckoutController.php:543` |

**Payment:** `payment_method` defaults to `Cod` when absent (`CheckoutController.php:188-189`); only COD is integrated (`PaymentMethod::isIntegrated()`). **Address:** a saved-address confirmation card replaces the old free-text fields; the snapshot includes `postal_code` (`addressSnapshot()` `:514-538`).

**Purchase option snapshots (T06, verified locally 2026-10-18):** Checkout saves `order_items.variant_options` as ordered option name/value pairs from the locked variant and its transaction-loaded option names. `PurchasedVariantSnapshot` captures these values; buyer order list, details and checkout confirmation render only this JSON through `OrderItem::variantDescription()`. Existing product-name, quantity and integer-price snapshots are reused. Later catalog edits/disabling/deletion do not rewrite the saved options. Optionless purchases store []; historical rows remain NULL and omit option metadata without guessing from current variants. The additive migration must be applied through the normal release process; it has only been exercised in isolated SQLite tests here.

**Cart count contract (verified 2026-10-18):** The navbar badge and `cart_count` count all saved cart lines, including unavailable lines. `available_lines` counts purchasable lines; `selected_lines` and the legacy JSON alias `selected_qty` count selected purchasable lines. Separate variants are separate lines even for the same product. Cart and checkout item headings follow these line counts. Each line retains its unit quantity, which multiplies the variant price for totals and determines stock deductions; quantities are never replaced by line counts. Example: one line of quantity five shows one item and charges for five units.

**Guest checkout:** HTML requests redirect to `route('home', ['login' => 1])` (`/?login=1`), rendering the shared login modal; JSON requests return 401. The modal submits to `unified.login` (`POST /login`). There is no named `login` GET route in the current implementation.

**Divergences / stops.** Cart voucher is **not cleared on a failed** checkout (bug C16).

---

## 6. Seller fulfilment

| # | Actor | Route → method | DB writes | Notified | Next sees | Status / evidence |
|---|---|---|---|---|---|---|
| 1 | System→Seller | (checkout) | `seller_orders.status=PLACED` | `Seller\NewOrderReceived` | new order in list | WORKS |
| 2 | Seller | `POST /seller/orders/{id}/accept` → `OrderController@acceptOrder` | `PLACED→CONFIRMED` then `CONFIRMED→PREPARING` (2 `status_histories` rows + parent rollup) | — | order accepted | WORKS — `Seller/OrderController.php:79-119` |
| 3 | Seller | `POST /seller/orders/{id}/mark-ready` → `@markReady` | `PREPARING→READY_FOR_PICKUP` (or `PLACED→READY_FOR_PICKUP` skip) | — | parcel ready | WORKS — `OrderController.php:132-184` |
| 4 | Seller | `GET /seller/shipments` → `ShipmentController@index` | — (read-only; excludes `Returned`) | — | shipment list | WORKS — `ShipmentController.php:25-39` |
| 5 | Seller | `GET /seller/shipments/{id}/label` → `@label` | — | — | printable waybill (tracking exists from placement) | WORKS — `ShipmentController.php:50-64` |

**Divergences / stops.** Two seller paths produce different histories (`PLACED→READY_FOR_PICKUP` skips Confirmed/Preparing — bug-severity LOW, documented as intentional). Seller **Vouchers/Reports/Feedback/Chat/Account** are placeholders (routes at `routes/seller.php:47-73`).

---

## 7. Pickup and sorting (area-based assignment)

| # | Actor | Route → method | DB writes | Notified | Next sees | Status / evidence |
|---|---|---|---|---|---|---|
| 1 | Logistics | `GET /logistics/incoming-parcels` → `IncomingParcelsController@index` | — | — | parcels `READY_FOR_PICKUP`/`PICKED_UP` | WORKS |
| 2 | Logistics | `POST …/{id}/pickup` → `@pickup` | `assignPickup`: match rider by **seller** municipality; set `courier_id`, `couriers.is_available=false`, `delivery_assignments` row (`pickup`); transition `READY_FOR_PICKUP→PICKED_UP` (**mints tracking if absent**); set `handover_at` | — | parcel picked up | WORKS — `IncomingParcelsController.php:65-92`, `CourierAssignmentService.php:35-99` |
| 3 | Logistics | `POST …/{id}/to-sorting-center` → `@toSortingCenter` | transition `PICKED_UP→AT_SORTING_CENTER`; release pickup courier | — | parcel at sorting center | WORKS — `IncomingParcelsController.php:99-128` |
| 4 | Logistics | `POST /logistics/parcel-sorting/{id}/sort` → `ParcelSortingController@sort` | compute destination area (buyer municipality vs provider's **active** areas, case-insensitive); set `destination_area_id`; transition `AT_SORTING_CENTER→SORTED` | — | sorted, awaiting rider | WORKS — `ParcelSortingController.php:102-138`, `:184-197` |
| 5 | Logistics | `POST …/{id}/assign-delivery` → `@assignDelivery` | `assignDelivery`: prefer area-linked rider (manual `courier_id` override); set `delivery_courier_id`, courier busy, `delivery_assignments` (`delivery`); transition `SORTED→ASSIGNED_TO_RIDER` | **`Buyer\CourierAssigned`** | rider assigned | WORKS — `ParcelSortingController.php:146-177`, `CourierAssignmentService.php:102-149` |
| 6 | Logistics | `GET/POST/PATCH/DELETE /logistics/areas…` → `AreaController` | area CRUD + link riders to areas | — | Delivery Areas screen | WORKS — `Logistics/AreaController.php` |

**Divergences / stops.** Destination area determination **never fails fatally** — an unmatched buyer municipality leaves `destination_area_id` null (`ParcelSortingController.php:136-138`). Rider selection weight filter uses `max_weight_grams`; **parcel weight must be ≤ the courier limit**.

---

## 8. Delivery, failed delivery, retry/return, and completion

| # | Actor | Route → method | DB writes | Notified | Next sees | Status / evidence |
|---|---|---|---|---|---|---|
| 1 | Logistics | `POST …/delivery-assignments/{id}/start` → `@startDelivery` | `ASSIGNED_TO_RIDER→OUT_FOR_DELIVERY` | **`Buyer\OrderOutForDelivery`** | out for delivery | WORKS — `DeliveryAssignmentsController.php:60-84` |
| 2 | Logistics | `POST …/{id}/delivered` → `@markDelivered` | `OUT_FOR_DELIVERY→DELIVERED`; **settle COD** if method COD & pending; release delivery courier | **`Buyer\OrderDelivered`** | delivered | WORKS — `DeliveryAssignmentsController.php:90-119`, `SellerOrderStatusService.php:336-346` |
| 3 | Logistics | `GET/POST …/{id}/failed` → `@showMarkFailedForm`/`@markFailed` | set `delivery_failure_reason`/`notes`, **`delivery_attempts++`**; transition `OUT_FOR_DELIVERY→DELIVERY_FAILED`; release courier | **`Buyer\DeliveryFailed`** + **`Seller\DeliveryFailed`** | failed | WORKS — `DeliveryAssignmentsController.php:147-193` |
| 4a | Logistics | `POST …/{id}/reschedule` → `@reschedule` | **guarded `delivery_attempts < 2`**; clear `delivery_courier_id`; `assignDelivery` again → `DELIVERY_FAILED→ASSIGNED_TO_RIDER` | **`Buyer\CourierAssigned`** | rider reassigned | WORKS — `DeliveryAssignmentsController.php:201-224` |
| 4b | Logistics | `POST …/{id}/return` → `@returnToSeller` | `DELIVERY_FAILED→RETURNED`; **restoreStock()** adds item qty back to variants + `inventory_transactions` reason `return` | **`Seller\ParcelReturned`** | returned to seller | WORKS — `DeliveryAssignmentsController.php:231-255`, `SellerOrderStatusService.php:258-287` |
| 5 | Buyer | `POST /buyer/orders/{order}/confirm-received` | ownership required (foreign order: 404); selected Delivered shipment becomes Completed via BuyerShipmentConfirmation and SellerOrderStatusService; omitted seller_order_id confirms all Delivered shipments, leaving other siblings unchanged | - | shipment completed | WORKS - BuyerShipmentConfirmation |

**Retry rule:** after the **3rd** failure (`delivery_attempts >= 2`), only RETURNED is available (`DeliveryAssignmentsController.php:211-213`).
**Divergences / stops.**
- **Fixed (C7):** Returned stays terminal and does not block confirmation of Delivered siblings; confirmation eligibility is per shipment.
- **Rollup (C12):** `DELIVERY_FAILED`/`RETURNED` rank after `COMPLETED`, so a mixed order shows `DELIVERED` as the bottleneck.
- **No buyer notification on `RETURNED`;** no notification on `COMPLETED`/`SORTED`/pickup.
- Refund into a gateway is **missing** (stock is restored, payment is not reversed).

---

## 9. Admin

### 9.1 Registration approvals — WORKS
| Step | Route → method | Effects | Evidence |
|---|---|---|---|
| Queue/filter | `GET /admin/registrations` → `RegistrationController@index` | filters below; manual paginator | `RegistrationController.php:30-99` |
| Buyer approve/reject | `…/buyer/{id}/{approve,reject}` | `status` + audit/reason; notify buyer | `:309-351` |
| Seller approve/reject | `…/seller/{id}/…` | `status` + audit/reason; notify seller | `:354-395` |
| Logistics approve/reject | `…/logistics/{id}/…` | `status` + audit/reason; notify provider | `:398-435` |
| Stream document | `GET /admin/documents/{type}/{id}/{document}` → `DocumentController@stream` | private-disk download, `nosniff` | `DocumentController.php:20-56` |

**Queue filter parameters** (verified, `RegistrationController.php`):
| Key | Values | Default |
|---|---|---|
| `search` | free text (trimmed, max 100 chars) — LIKE on first/last/email/contact (+`business_name` for seller/logistics) | `''` |
| `type` | `buyer` \| `seller` \| `logistics` (empty = all) | `''` |
| `status` | `pending` \| `approved` \| `rejected` \| `suspended` (invalid → `pending`) | `pending` |
| `date_range` | integer days (`7`/`30`/`90` in UI; `0` = all time) | **`0` (all time)** — pending older than 7 days stays visible with the pending stat card |
| `per_page` | `10` \| `25` \| `50` (invalid → 25) | `25` |
| `page` | ≥1 | `1` |

Stat cards (pending/approved/rejected) are **unfiltered**; trends compare the last 7 days vs the prior 7. Approval actions require the row to still be `pending`. The list is a **SQL UNION ALL + paginate** (not a full in-memory merge). **View details** opens a modal with address + document stream links (C22 fixed). Approve/reject notify via **`mail`**.

### 9.2 Back-office modules
| Module | Status | Evidence |
|---|---|---|
| User accounts | **Implemented (minimal)** | `UserAccountController` — list approved/suspended; suspend / reactivate (mail notice) |
| Seller compliance | **Placeholder** | sidebar "Soon" label (no `#` link) |
| Complaints & disputes | **Placeholder** | sidebar "Soon" |
| **Commissions** | **MISSING (engine + UI)** | no commission code in `app/` |
| Reports | **Placeholder** | sidebar "Soon" |
| Platform settings | **Placeholder** | sidebar "Soon" |
| Chat/messaging | **Placeholder** | sidebar "Soon" |
| Account management | **Placeholder** | sidebar "Soon" |

Admin role gate: `admin.role:admin,moderator`; `super_admin` always passes; **`support` cannot reach the queue or users** (`routes/admin.php`). `admins.role` is validated against `AdminRole` enum on save.

---

## 10. Messaging and notifications

| # | Actor | Route → method | DB writes | Status / evidence |
|---|---|---|---|---|
| 1 | Buyer/Seller | `GET /buyer/messages`, `/seller/messages` → `MessageController@index` | — | WORKS |
| 2 | Buyer/Seller | `GET …/messages/unread-count` → `@unreadCount` | — | WORKS (literal route ordered before `{conversation}`) |
| 3 | Buyer/Seller | `GET …/messages/{conversation}` → `@show` (policy-gated) | — | WORKS — `ConversationPolicy` |
| 4 | Buyer/Seller | `POST …/messages` → `@store` | `messages` row; updates `conversations.last_message_at` | WORKS |
| 5 | Buyer/Seller | `POST …/messages/get-or-create` → `@getOrCreateConversation` | `conversations` row (unique buyer+seller) | WORKS |
| 6 | Buyer | `GET /buyer/chat`, `/buyer/chat/{id}` → `ChatController@index`/`@show` | — | **PLACEHOLDER** — redirects "Chat is coming soon" (`ChatController.php:20-32`); views are comments |
| 7 | Any | notifications endpoints → `Buyer\NotificationController@index/count/markRead/markAllRead` | `notifications.read_at` | WORKS (buyer). **No seller or logistics notification centre** |

**Notification delivery** is database-channel only (`notifications` table). Status-change notifications are emitted centrally in `SellerOrderStatusService::maybeNotify()` (`:224-252`). Delivery-failure notifies **buyer + seller**; return notifies **seller only**; checkout notifies **seller**.

---

## 11. Sessions and logout

| # | Actor | Route → method | Effects | Status / evidence |
|---|---|---|---|---|
| 1 | Any | `POST /login`, `/seller/login`, `/admin/login`, Google callback → respective controllers | on success: `clearOtherGuards($keep)` logs out the other three guards; `session()->regenerate()` | WORKS — `ClearsOtherGuards.php:29-36`; callers `UnifiedLoginController.php:96`, `Seller\AuthController.php:56`, `Admin\AuthController.php:32`, `GoogleAuthController.php:152` |
| 2 | Any | `POST /logout` or `/buyer/logout` → `UnifiedLoginController@logout` | `clearAllGuards()` + `invalidate()` + `regenerateToken()` | WORKS — `UnifiedLoginController.php:131-134` |
| 3 | Seller/Admin | `POST /seller/logout`, `/admin/logout` | same all-guard clear | WORKS |

Participating guards: `['admin','seller','logistics','buyer']`. The unused `web` guard is **not** cleared. `POST /logout` is reachable without a session (harmless). Guest-only routes use `guest.role` so a signed-in account cannot open login/register (must logout to switch). See `2-Design.md §4.7`.

---

## 12. Data that can drift (values stored twice)

| Value | Stored in | Risk |
|---|---|---|
| Product price / stock | `products.price`/`stock_quantity` (rollups) **and** `product_variants.price`/`stock_quantity` (authoritative) | rollups must be refreshed after every variant change (`VariantSyncService`, `StockService`, `SellerOrderStatusService`) |
| Order totals | `orders.subtotal_minor`/`discount_minor`/`total_minor` **and** `order_items.price_minor` + `seller_orders.subtotal_minor` + `payments.amount_minor` | snapshots; a change to one without the others desyncs accounting |
| Courier identity | `seller_orders.courier_id`, `seller_orders.delivery_courier_id`, and `delivery_assignments` rows | three representations; `delivery_assignments` is history, the columns are live |
| Coverage area | `couriers.service_area` (json, NOT NULL) **and** `areas` + `couriers.area_id` | two parallel models; the areas table supersedes the JSON |
| Order status | `orders.status` (rollup) **and** each `seller_orders.status` | rollup recomputed on every transition; a direct write desyncs it |
| Conversation recency | `conversations.last_message_at` **and** the latest `messages.created_at` | denormalized |
| Voucher usage | `vouchers.times_used` **and** the count of orders using it | no link table exists to recompute |
| Buyer/seller address | registration columns **and** the checkout `orders.shipping_address` json snapshot | snapshot is immutable by design |

---

## 13. Invariants (must always hold)

1. **Stock never negative.** Checkout decrements only after locking each variant and rejecting insufficient stock (`CheckoutController` + `CheckoutStockConcurrencyTest`). `StockService::set()` clamps ≥0.
2. **Every stock change has a ledger row.** `inventory_transactions` covers `checkout`, `return`, `seller_initial`, `seller_adjustment`.
3. **Voucher `times_used` never exceeds `usage_limit`.** Checked before apply; incremented once per placed order inside the checkout transaction.
4. **No seller order past `PICKED_UP` without a courier.** Pickup requires a matched `courier_id`; delivery assignment requires `delivery_courier_id` (`CourierAssignmentService` returns null → controller errors instead of advancing).
5. **Status changes are forward-only and role-gated.** Enforced in `SellerOrderStatusService::transition()`; illegal/actor-denied moves throw `InvalidArgumentException` and write nothing.
6. **Every status change writes a `status_histories` row** (with `actor_type`/`source`).
7. **`orders.status` is derived**, never set directly — `rollUpParentOrder()` recomputes it inside the transition transaction.
8. **COD settles exactly once.** `settleCodPayment()` only flips `pending`→`paid` (idempotent).
9. **One seller order per (order, seller).** DB unique `(order_id, seller_id)`.
10. **One payment per seller order.** DB unique `seller_order_id`.
11. **Returning a parcel restores stock exactly once.** `restoreStock()` runs only on the `RETURNED` transition, which is forward-only.
12. **One login guard per browser.** Any login clears the other three (`ClearsOtherGuards`).
13. **Registration is gated on `approved`.** `EnsureSellerApproved` / `EnsureLogisticsApproved` log out and bounce non-approved users; admin queue only acts on `pending` rows.
14. **Checkout is single-submit.** A one-time `checkout_tokens` row is claimed inside the transaction (`CheckoutDoubleSubmitTest`).
15. **Uploaded documents are private.** Stored on the `local` disk and streamed only through the admin-guarded controller with `nosniff`.

---

## 14. Where each flow currently stops (summary)

| Flow | Works up to | Stops / diverges at |
|---|---|---|
| Buyer registration | login + shopping | non-XHR submit (C3); GET verify page (C19) |
| Seller registration | full | — |
| Logistics registration | full | — |
| **Rider** | — | **nothing** (Batch D) |
| Checkout | order placed + COD | non-COD methods only recorded; no shipping fee; voucher not cleared on failure (C16) |
| Seller fulfilment | parcel ready + waybill | seller stub pages |
| Pickup & sorting | rider assigned by area | unmatched municipality leaves area null |
| Delivery | Delivered → Completed | 3rd failure → Returned is a **dead end** (C7); no gateway refund |
| Admin | approvals + documents | **all** other back-office modules |
| Messaging | buyer↔seller threads | Chat pages (placeholder); no seller/logistics notification centre |
| Sessions | all-guard one-per-browser | unused `web` guard not cleared |

---


## Updated buyer confirmation and review flows (2026-10-17)

1. A delivered shipment exposes its own Confirm Received form with seller_order_id. Other shipments may still be in transit or returned.
2. POST buyer.orders.confirmReceived authorizes ownership and calls BuyerShipmentConfirmation. Only the selected Delivered shipment transitions to Completed; omitting seller_order_id confirms all currently Delivered shipments.
3. Returned shipments stay Returned. The parent becomes Completed once every shipment is Completed/Returned and at least one is Completed; all-returned parents remain Returned.
4. Completed shipment items link to product details #reviews. The buyer submits rating and optional feedback to POST /buyer/products/{product}/reviews. The service verifies a completed purchase before inserting/updating the buyer/product review.
5. The product page lists paginated escaped feedback. Product and store summaries calculate ratings from real reviews; empty summaries say No reviews yet.
6. Option-bearing/multi-SKU card adds open details. Both cart-add and Buy Now reject omitted, foreign, disabled or sold-out variants on the server. Product details reset dependent selections and disable impossible combinations.


## Seller five-step product editor (2026-10-17)

1. **Information:** enter product name and description, review the locked seller category, upload general photos and choose a cover (up to six JPG/PNG/WebP files, 3 MB each).
2. **Variations:** enable optional variations, name one or two groups, and add/remove option chips. Color Black/White/Beige with Size S/M/L/XL generates 12 rows; 50 combinations is the limit. Disabling variations keeps a single-SKU price/stock form.
3. **Color images:** upload photos per Color/Colour value; all sizes share the color's uploads. Unchanged color cards retain selected files while other options are edited.
4. **Inventory:** edit price, integer stock, optional SKU and selling availability for each combination. Bulk price/stock entry is available. Total stock is the real sum of the rows (94 only when those rows sum to 94).
5. **Review and publish:** inspect the cover, options, variant count, minimum price and total stock. Publish, save a draft, or save changes. The final action is unavailable on earlier steps, and submission revalidates every panel.

The multipart payload posts to the existing seller product store/update routes. `ProductPublishingService` saves details, calls `VariantSyncService`, stores general photos and associates `color_images[i][files][]` with all variants matching `color_images[i][value]`. Invalid/duplicate/incomplete combinations fail before writes. A failed persistence operation rolls back database records and removes newly stored files. Buyers see one listing and choose a variant; color selection switches the main photo. This was verified locally with PHPUnit and rendered-Blade Playwright fixtures; no production product was created or deployed.

## Seller product listing: prices and archive (2026-10-18)

The Price heading and values align left. Seller prices show the minimum and maximum across all variants, including paused and sold-out variants; equal prices display once.

Archive uses the existing soft-delete endpoint (`seller.products.destroy`): it sets `products.deleted_at`, hides the listing from seller and buyer catalog queries, and preserves variants, images, stock, order items, and inventory transactions. Order-item and inventory-transaction product relationships include archived products. Records remain restorable through Eloquent; a seller restoration screen is not implemented. The bulk actions menu remains a UI placeholder.
